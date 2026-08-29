<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Install\DbTester;
use App\Install\Installer;
use App\Install\RequirementsCheck;
use Throwable;

final class InstallController extends Controller
{
    public function show(Request $request): Response
    {
        View::share('old', Session::takeOld());
        View::share('theme', 'system');

        return Response::html(View::render('install/wizard', [
            'title' => 'Set up',
            'checks' => RequirementsCheck::run(App::basePath()),
            'ready' => RequirementsCheck::passes(App::basePath()),
            'defaults' => [
                'db_name' => 'monitor-db',
                'db_user' => 'user-db-monitor',
                'site_url' => $request->baseUrl(),
                'timezone' => date_default_timezone_get(),
            ],
            'timezones' => timezone_identifiers_list(),
            'errors' => Session::takeFlash(),
            'csrf' => \App\Core\Csrf::token(),
        ], 'layouts/install'));
    }

    /** Called by the "Test connection" button before the install can continue. */
    public function testDatabase(Request $request): Response
    {
        $result = DbTester::test([
            'host' => $request->boolean('same_host') ? '127.0.0.1' : (string) $request->input('db_host', '127.0.0.1'),
            'port' => $request->boolean('same_host') ? 3306 : $request->int('db_port', 3306),
            'database' => (string) $request->input('db_name', ''),
            'username' => (string) $request->input('db_user', ''),
            'password' => (string) $request->raw('db_password'),
        ]);

        return Response::json($result, $result['ok'] ? 200 : 422);
    }

    public function run(Request $request): Response
    {
        $sameHost = $request->boolean('same_host');

        $db = [
            'host' => $sameHost ? '127.0.0.1' : (string) $request->input('db_host', '127.0.0.1'),
            'port' => $sameHost ? 3306 : $request->int('db_port', 3306),
            'database' => (string) $request->input('db_name', ''),
            'username' => (string) $request->input('db_user', ''),
            'password' => (string) $request->raw('db_password'),
            'prefix' => preg_replace('/[^a-z0-9_]/i', '', (string) $request->input('db_prefix', '')) ?? '',
        ];

        $site = [
            'name' => (string) $request->input('site_name', 'Monitor'),
            'url' => rtrim((string) $request->input('site_url', $request->baseUrl()), '/'),
            'timezone' => (string) $request->input('timezone', 'UTC'),
            'locale' => 'en',
        ];

        $admin = [
            'name' => (string) $request->input('admin_name', ''),
            'email' => (string) $request->input('admin_email', ''),
            'password' => (string) $request->raw('admin_password'),
        ];

        $validator = Validator::make($request->all() + ['admin_password' => $admin['password']])
            ->required('db_name', 'Database name')
            ->required('db_user', 'Database user')
            ->required('site_name', 'Site name')
            ->required('admin_name', 'Your name')
            ->required('admin_email', 'Email')
            ->email('admin_email', 'Email')
            ->required('admin_password', 'Password')
            ->password('admin_password')
            ->matches('admin_password_confirmation', 'admin_password')
            ->custom('timezone', in_array($site['timezone'], timezone_identifiers_list(), true), 'Pick a time zone from the list.');

        if ($validator->fails()) {
            Session::flashInput($request->all());
            $this->error((string) $validator->firstError());

            return $this->redirect('/install');
        }

        try {
            $installer = new Installer(App::basePath());
            $installer->install($db, $site, $admin);
            $installer->lock();
            App::markInstalled();
        } catch (Throwable $e) {
            App::logError($e);
            Session::flashInput($request->all());
            $this->error($e->getMessage());

            return $this->redirect('/install');
        }

        Session::flash('success', 'Installed. Sign in to add your first monitor.');
        Session::put('install_cron', $installer->cronLine());

        return $this->redirect('/login');
    }
}
