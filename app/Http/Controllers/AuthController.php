<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Domain\AuditLog;
use App\Http\Middleware\RateLimit;

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
        $theme = (string) $request->input('theme', 'system');
        if (!in_array($theme, ['light', 'dark', 'system'], true)) {
            $theme = 'system';
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
