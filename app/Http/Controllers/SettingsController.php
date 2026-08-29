<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Db;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Domain\AuditLog;
use App\Domain\Settings;
use App\Install\Installer;
use App\Core\App;

final class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view($request, 'pages/settings', [
            'title' => 'Settings',
            'settings' => Settings::all(),
            'timezones' => timezone_identifiers_list(),
            'locales' => Lang::available(App::basePath('resources/lang')),
            'cron' => (new Installer(App::basePath()))->cronLine(),
            'schedulerLastRun' => Db::value('SELECT MAX(`checked_at`) FROM {{checks}}'),
            'counts' => [
                'checks' => (int) Db::value('SELECT COUNT(*) FROM {{checks}}'),
                'minutes' => (int) Db::value('SELECT COUNT(*) FROM {{stats_minute}}'),
                'hours' => (int) Db::value('SELECT COUNT(*) FROM {{stats_hour}}'),
            ],
        ]);
    }

    public function update(Request $request): Response
    {
        $timezone = (string) $request->input('default_timezone', 'UTC');
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = 'UTC';
        }

        Settings::setMany([
            'site_name' => (string) $request->input('site_name', 'Monitor'),
            'site_url' => rtrim((string) $request->input('site_url', ''), '/'),
            'default_timezone' => $timezone,
            'default_locale' => in_array($request->input('default_locale'), Lang::available(App::basePath('resources/lang')), true)
                ? (string) $request->input('default_locale')
                : 'en',
            'default_role' => in_array($request->input('default_role'), ['admin', 'editor', 'viewer'], true)
                ? (string) $request->input('default_role')
                : 'viewer',
            'theme_default' => in_array($request->input('theme_default'), ['light', 'dark', 'system'], true)
                ? (string) $request->input('theme_default')
                : 'system',
            'allow_private_targets' => $request->boolean('allow_private_targets') ? '1' : '0',
            'check_concurrency' => (string) max(1, min(100, $request->int('check_concurrency', 20))),
            'retention_checks_days' => (string) max(1, min(365, $request->int('retention_checks_days', 14))),
            'retention_minutes_days' => (string) max(1, min(730, $request->int('retention_minutes_days', 30))),
            'retention_hours_days' => (string) max(7, min(3650, $request->int('retention_hours_days', 400))),
        ]);

        AuditLog::record('settings.updated', 'system', null, 'Updated site settings');
        $this->success('Settings saved.');

        return $this->redirect('/settings');
    }

    public function activity(Request $request): Response
    {
        return $this->view($request, 'pages/activity', [
            'title' => 'Activity log',
            'entries' => AuditLog::recent(150),
        ]);
    }
}
