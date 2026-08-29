<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Domain\AuditLog;
use App\Domain\Settings;
use App\Entra\Entra;
use App\Entra\Oidc;
use Throwable;
use App\Core\App;

/**
 * Signing in with a Microsoft account.
 *
 * Two routes: one that sends the person to Microsoft, one that catches them on
 * the way back. The state, nonce and PKCE verifier live in the session between
 * the two, and all three have to line up before anyone gets a session here.
 */
final class EntraController extends Controller
{
    public function start(Request $request): Response
    {
        if (!Entra::ssoEnabled()) {
            throw HttpException::notFound('Microsoft sign-in is not enabled.');
        }

        try {
            $flow = Oidc::begin();
        } catch (Throwable $e) {
            App::logError($e);
            $this->error($e->getMessage());

            return $this->redirect('/login');
        }

        Session::put('entra_state', $flow['state']);
        Session::put('entra_nonce', $flow['nonce']);
        Session::put('entra_verifier', $flow['verifier']);

        return $this->redirect($flow['url']);
    }

    public function callback(Request $request): Response
    {
        if (!Entra::ssoEnabled()) {
            throw HttpException::notFound('Microsoft sign-in is not enabled.');
        }

        $state = Session::get('entra_state');
        $nonce = Session::get('entra_nonce');
        $verifier = Session::get('entra_verifier');

        Session::forget('entra_state');
        Session::forget('entra_nonce');
        Session::forget('entra_verifier');

        // Microsoft reports a refused consent or a cancelled sign-in here.
        $error = (string) $request->query('error', '');
        if ($error !== '') {
            $this->error(self::readable($error, (string) $request->query('error_description', '')));

            return $this->redirect('/login');
        }

        $returnedState = (string) $request->query('state', '');
        if (!is_string($state) || $state === '' || !hash_equals($state, $returnedState)) {
            $this->error('That sign-in did not match the request that started it. Try again from this page.');

            return $this->redirect('/login');
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            $this->error('Microsoft did not return an authorization code.');

            return $this->redirect('/login');
        }

        try {
            $claims = Oidc::complete($code, (string) $verifier, (string) $nonce);
            $profile = Oidc::profile($claims);
        } catch (Throwable $e) {
            App::logError($e);
            $this->error($e->getMessage());

            return $this->redirect('/login');
        }

        $user = $this->resolveUser($profile);
        if ($user === null) {
            $this->error(sprintf(
                'There is no account here for %s yet. An administrator can add it, or add you to a group that syncs from Entra ID.',
                $profile['email']
            ));

            return $this->redirect('/login');
        }

        if ($user['status'] !== 'active') {
            $this->error('That account is disabled here. Ask an administrator to enable it.');

            return $this->redirect('/login');
        }

        Auth::login($user, false, $request->isSecure());
        AuditLog::record('auth.login', 'user', (int) $user['id'], $user['email'] . ' signed in with Microsoft');

        $intended = Session::get('intended');
        Session::forget('intended');

        return $this->redirect(is_string($intended) && str_starts_with($intended, '/') ? $intended : '/');
    }

    /**
     * Find the account behind a verified Microsoft identity — by object id
     * first, then by address so an existing local account is adopted rather
     * than duplicated.
     *
     * @param array{external_id:string,email:string,name:string} $profile
     * @return array<string,mixed>|null
     */
    private function resolveUser(array $profile): ?array
    {
        $now = gmdate('Y-m-d H:i:s');

        $user = Db::selectOne('SELECT * FROM {{users}} WHERE `external_id` = ? LIMIT 1', [$profile['external_id']]);

        if ($user === null) {
            $user = Db::selectOne('SELECT * FROM {{users}} WHERE `email` = ? LIMIT 1', [$profile['email']]);
        }

        if ($user !== null) {
            Db::update('users', [
                'name' => $profile['name'],
                'email' => $profile['email'],
                'auth_provider' => 'entra',
                'external_id' => $profile['external_id'],
                'synced_at' => $now,
                'updated_at' => $now,
            ], ['id' => (int) $user['id']]);

            return Db::selectOne('SELECT * FROM {{users}} WHERE `id` = ? LIMIT 1', [(int) $user['id']]);
        }

        if (!Settings::bool('entra_auto_provision')) {
            return null;
        }

        $id = Db::insert('users', [
            'name' => $profile['name'],
            'email' => $profile['email'],
            'password_hash' => null,
            'role' => Entra::defaultRole(),
            'status' => 'active',
            'timezone' => Settings::get('default_timezone', 'UTC'),
            'locale' => Settings::get('default_locale', 'en'),
            'theme' => 'system',
            'auth_provider' => 'entra',
            'external_id' => $profile['external_id'],
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        AuditLog::record('user.provisioned', 'user', $id, 'Created ' . $profile['email'] . ' from a Microsoft sign-in');

        return Db::selectOne('SELECT * FROM {{users}} WHERE `id` = ? LIMIT 1', [$id]);
    }

    private static function readable(string $error, string $description): string
    {
        return match ($error) {
            'access_denied' => 'The sign-in was cancelled, or consent was refused.',
            'consent_required', 'interaction_required' => 'An administrator has to grant consent for this application first.',
            default => $description !== '' ? trim(explode("\n", $description)[0]) : 'Microsoft refused the sign-in (' . $error . ').',
        };
    }
}
