<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Domain\AuditLog;
use App\Domain\PasswordResets;
use App\Domain\Settings;
use App\Domain\Users;
use App\Entra\Entra;
use App\Http\Middleware\RateLimit;
use App\Notifications\Mailer;

final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        return $this->view($request, 'pages/login', [
            'title' => 'Sign in',
            'cron' => Session::get('install_cron'),
        ], 'layouts/bare');
    }

    public function login(Request $request): Response
    {
        if (!Entra::localLoginAllowed()) {
            $this->error('This site signs in through Microsoft only.');

            return $this->redirect('/login');
        }

        $email = strtolower((string) $request->input('email', ''));
        $password = (string) $request->raw('password');
        $ip = $request->ip();

        if ($email === '' || $password === '') {
            $this->error('Enter your email and password.');

            return $this->redirect('/login');
        }

        if (RateLimit::tooManyAttempts($email, $ip)) {
            $this->error(sprintf(
                'Too many sign-in attempts. Wait %d minutes and try again.',
                RateLimit::windowMinutes()
            ));

            return $this->redirect('/login');
        }

        $user = Auth::attempt($email, $password);
        RateLimit::record($email, $ip, $user !== null);

        if ($user === null) {
            // Same answer whether the account exists or the password was wrong.
            $this->error('That email and password do not match an account.');

            return $this->redirect('/login');
        }

        Auth::login($user, $request->boolean('remember'), $request->isSecure());
        Session::forget('install_cron');
        AuditLog::record('auth.login', 'user', (int) $user['id'], $user['email'] . ' signed in');

        $intended = Session::get('intended');
        Session::forget('intended');

        return $this->redirect(is_string($intended) && str_starts_with($intended, '/') ? $intended : '/');
    }

    public function showForgot(Request $request): Response
    {
        if (!Entra::localLoginAllowed()) {
            return $this->redirect('/login');
        }

        return $this->view($request, 'pages/forgot-password', [
            'title' => 'Reset your password',
        ], 'layouts/bare');
    }

    /**
     * Sends the link. The answer is the same whether or not the address has an
     * account here — which addresses are registered is the one thing this form
     * could give away to a stranger.
     */
    public function sendReset(Request $request): Response
    {
        if (!Entra::localLoginAllowed()) {
            return $this->redirect('/login');
        }

        $email = strtolower(trim((string) $request->input('email', '')));
        $ip = $request->ip();

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Enter the email address you sign in with.');

            return $this->redirect('/forgot-password');
        }

        // Counted on the same table as sign-in attempts, under its own
        // identifier: asking for a link is another way of working on an
        // account, and both the address and the IP are capped.
        if (RateLimit::tooManyAttempts('reset:' . $email, $ip)) {
            $this->error(sprintf(
                'Too many reset requests. Wait %d minutes and try again.',
                RateLimit::windowMinutes()
            ));

            return $this->redirect('/forgot-password');
        }

        RateLimit::record('reset:' . $email, $ip, false);

        // Checked before the account is looked up, so that a site with no mail
        // set up answers the same way for every address.
        if (!Mailer::isConfigured()) {
            $this->error('This site cannot send email yet, so no link can be sent. Ask an administrator.');

            return $this->redirect('/forgot-password');
        }

        $user = PasswordResets::accountFor($email);

        if ($user !== null) {
            $token = PasswordResets::create((int) $user['id']);
            $result = Mailer::sendPasswordReset((string) $user['email'], $this->resetUrl($request, $token));

            // A failed send is only visible to an administrator, in the
            // activity log. Saying it here would answer the question the
            // generic message exists to avoid.
            AuditLog::record(
                $result['ok'] ? 'auth.reset_sent' : 'auth.reset_failed',
                'user',
                (int) $user['id'],
                $result['ok']
                    ? 'Sent a password reset link to ' . $email
                    : 'Could not send a password reset link to ' . $email . ': ' . $result['error']
            );
        }

        $this->success('If that address has an account here, a link to choose a new password is on its way.');

        return $this->redirect('/login');
    }

    public function showReset(Request $request): Response
    {
        $token = (string) $request->param('token', '');

        if (PasswordResets::resolve($token) === null) {
            $this->error('That link has expired or has already been used. Ask for a new one.');

            return $this->redirect('/forgot-password');
        }

        return $this->view($request, 'pages/reset-password', [
            'title' => 'Choose a new password',
            'token' => $token,
        ], 'layouts/bare');
    }

    public function resetPassword(Request $request): Response
    {
        $token = (string) $request->param('token', '');
        $found = PasswordResets::resolve($token);

        if ($found === null) {
            $this->error('That link has expired or has already been used. Ask for a new one.');

            return $this->redirect('/forgot-password');
        }

        $password = (string) $request->raw('password');
        $validator = Validator::make([
            'password' => $password,
            'password_confirmation' => (string) $request->raw('password_confirmation'),
        ])
            ->required('password', 'Password')
            ->password('password')
            ->matches('password_confirmation', 'password');

        if ($validator->fails()) {
            $this->error((string) $validator->firstError());

            return $this->redirect('/reset-password/' . rawurlencode($token));
        }

        $user = $found['user'];
        $id = (int) $user['id'];

        Users::setPassword($id, $password);
        PasswordResets::consume((int) $found['reset']['id'], $id);

        // Whoever holds the mailbox has proved as much as a password would, so
        // a lockout from earlier guessing should not keep them out now.
        RateLimit::clear((string) $user['email']);
        RateLimit::clear('reset:' . (string) $user['email']);

        AuditLog::record('user.password_reset', 'user', $id, $user['email'] . ' set a new password from a reset link');

        $this->success('Your password has been changed. Sign in with it.');

        return $this->redirect('/login');
    }

    /**
     * Built from the configured site URL rather than the Host header the
     * visitor sent: a poisoned Host would otherwise mail a working link that
     * points at someone else's server.
     */
    private function resetUrl(Request $request, string $token): string
    {
        $base = rtrim(Settings::get('site_url'), '/');
        if ($base === '') {
            $base = $request->baseUrl();
        }

        return $base . '/reset-password/' . rawurlencode($token);
    }

    public function logout(Request $request): Response
    {
        $user = Auth::user();
        if ($user !== null) {
            AuditLog::record('auth.logout', 'user', (int) $user['id'], $user['email'] . ' signed out');
        }

        Auth::logout($request->isSecure());

        return $this->redirect('/login');
    }

    /** Theme choice lives in a cookie so the server can set it before first paint. */
    public function theme(Request $request): Response
    {
        $theme = (string) $request->input('theme', 'light');
        if (!in_array($theme, ['light', 'dark'], true)) {
            $theme = 'light';
        }

        setcookie('monitor_theme', $theme, [
            'expires' => time() + 31536000,
            'path' => '/',
            'secure' => $request->isSecure(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);

        if ($request->wantsJson()) {
            return Response::json(['theme' => $theme]);
        }

        return $this->back($request);
    }
}
