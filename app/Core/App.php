<?php

declare(strict_types=1);

namespace App\Core;

use App\Domain\Settings;
use App\Support\Crypto;
use App\Support\Env;
use Throwable;

final class App
{
    private static string $basePath = '';

    private static bool $installed = false;

    private static bool $booted = false;

    public static function boot(string $basePath): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        self::$basePath = rtrim($basePath, '/');

        date_default_timezone_set('UTC');
        mb_internal_encoding('UTF-8');

        Env::load(self::$basePath . '/.env');

        Config::hydrate([
            'app' => [
                'env' => Env::get('APP_ENV', 'production'),
                'debug' => Env::bool('APP_DEBUG', false),
                'url' => rtrim((string) Env::get('APP_URL', ''), '/'),
                'timezone' => Env::get('APP_TIMEZONE', 'UTC'),
                'locale' => Env::get('APP_LOCALE', 'en'),
                'key' => Env::get('APP_KEY', ''),
            ],
            'db' => [
                'host' => Env::get('DB_HOST', '127.0.0.1'),
                'port' => Env::int('DB_PORT', 3306),
                'database' => Env::get('DB_NAME', ''),
                'username' => Env::get('DB_USER', ''),
                'password' => Env::get('DB_PASS', ''),
                'socket' => Env::get('DB_SOCKET', ''),
                'prefix' => Env::get('DB_PREFIX', ''),
            ],
            'paths' => [
                'base' => self::$basePath,
                'public' => self::$basePath . '/public',
                'storage' => self::$basePath . '/storage',
                'views' => self::$basePath . '/resources/views',
                'lang' => self::$basePath . '/resources/lang',
                'migrations' => self::$basePath . '/database/migrations',
            ],
        ]);

        View::setPath(Config::string('paths.views'));

        $key = Config::string('app.key');
        if ($key !== '') {
            Crypto::setKey($key);
        }

        self::$installed = is_file(self::lockFile()) && Config::string('db.database') !== '';

        if (self::$installed) {
            Db::connect((array) Config::get('db'));
            Config::set('app.timezone', Settings::get('default_timezone', 'UTC'));
        }

        Lang::load(Config::string('paths.lang'), self::$installed ? Settings::get('default_locale', 'en') : 'en');
    }

    public static function basePath(string $append = ''): string
    {
        return self::$basePath . ($append === '' ? '' : '/' . ltrim($append, '/'));
    }

    public static function lockFile(): string
    {
        return self::$basePath . '/storage/installed.lock';
    }

    public static function isInstalled(): bool
    {
        return self::$installed;
    }

    public static function markInstalled(): void
    {
        self::$installed = true;
    }

    public static function handle(Request $request): Response
    {
        $router = new Router();
        (require self::$basePath . '/app/routes.php')($router);

        try {
            if (!self::$installed && !str_starts_with($request->path, '/install') && !str_starts_with($request->path, '/assets')) {
                return Response::redirect('/install');
            }

            if (self::$installed && str_starts_with($request->path, '/install')) {
                throw HttpException::notFound();
            }

            // The session is needed before the database exists so the setup
            // wizard can carry CSRF tokens and its own step state.
            Session::start(Config::string('paths.storage') . '/sessions', $request->isSecure());

            if (self::$installed) {
                Auth::restore($request);
            }

            $match = $router->match($request->method, $request->path);
            if ($match === null) {
                throw HttpException::notFound();
            }
            if ($match['route'] === null) {
                throw new HttpException(405);
            }

            $route = $match['route'];
            $request->withParams($match['params']);

            if ($route->requiresAuth && !Auth::check()) {
                if ($request->wantsJson()) {
                    return Response::json(['error' => 'unauthenticated'], 401);
                }
                Session::put('intended', $request->path);

                return Response::redirect('/login');
            }

            if ($route->requiresGuest && Auth::check()) {
                return Response::redirect('/');
            }

            if ($route->permission !== null && !Auth::can($route->permission)) {
                throw HttpException::forbidden();
            }

            if (!$route->csrfExempt
                && in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
                && !Csrf::check($request->input('_csrf'))) {
                throw new HttpException(419);
            }

            return self::respond(self::call($route->handler, $request));
        } catch (HttpException $e) {
            return self::renderError($request, $e->status, $e->getMessage());
        } catch (Throwable $e) {
            self::logError($e);

            $message = Config::bool('app.debug')
                ? $e->getMessage() . ' — ' . $e->getFile() . ':' . $e->getLine()
                : 'Something went wrong on our side. The details are in storage/logs/app.log.';

            return self::renderError($request, 500, $message);
        }
    }

    private static function call(mixed $handler, Request $request): mixed
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $controller = new $class();

            return $controller->{$method}($request);
        }

        return $handler($request);
    }

    private static function respond(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }
        if (is_array($result)) {
            return Response::json($result);
        }

        return Response::html((string) $result);
    }

    private static function renderError(Request $request, int $status, string $message): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['error' => $message], $status);
        }

        $layout = self::$installed && Auth::check() ? 'layouts/app' : 'layouts/bare';
        self::shareViewData($request);

        return Response::html(View::render('pages/error', [
            'status' => $status,
            'message' => $message,
            'title' => (string) $status,
        ], $layout), $status);
    }

    public static function shareViewData(Request $request): void
    {
        View::share('currentPath', $request->path);
        View::share('flash', Session::takeFlash());
        View::share('old', Session::takeOld());
        View::share('authUser', Auth::user());
        View::share('siteName', self::$installed ? Settings::get('site_name', 'Monitor') : 'Monitor');
        View::share('theme', self::resolveTheme($request));
        View::share('errors', []);
        View::share('title', '');
    }

    /**
     * Light or dark, never anything else. Installs that predate the single
     * toggle may still hold 'system' in the cookie or the setting; both fall
     * through to light, which is what the stylesheet paints by default.
     */
    private static function resolveTheme(Request $request): string
    {
        $cookie = $request->cookie('monitor_theme');
        if (in_array($cookie, ['light', 'dark'], true)) {
            return $cookie;
        }

        $default = self::$installed ? Settings::get('theme_default', 'light') : 'light';

        return $default === 'dark' ? 'dark' : 'light';
    }

    /**
     * One line in the same log the exceptions go to, for something that is not
     * an exception but is still worth being able to read afterwards.
     */
    public static function logNote(string $message): void
    {
        self::appendLog(sprintf("[%s] %s\n", gmdate('Y-m-d H:i:s'), $message));
    }

    public static function logError(Throwable $e): void
    {
        $line = sprintf(
            "[%s] %s: %s in %s:%d\n%s\n\n",
            gmdate('Y-m-d H:i:s'),
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );

        self::appendLog($line);
    }

    private static function appendLog(string $line): void
    {
        $file = self::$basePath . '/storage/logs/app.log';
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0750, true);
        }
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}
