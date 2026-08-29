<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Checks\PingTransport;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Domain\AuditLog;
use App\Domain\Settings;
use App\Install\Installer;
use App\Notifications\Channels;
use App\Notifications\Mailer;
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
            'mailConfigured' => Mailer::isConfigured(),
            'pingTransport' => PingTransport::detect(),
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
            'notifications_enabled' => $request->boolean('notifications_enabled') ? '1' : '0',
            'mail_driver' => $request->input('mail_driver') === 'sendmail' ? 'sendmail' : 'smtp',
            'mail_from_address' => strtolower((string) $request->input('mail_from_address', '')),
            'mail_from_name' => (string) $request->input('mail_from_name', 'Monitor'),
            'smtp_host' => (string) $request->input('smtp_host', ''),
            'smtp_port' => (string) max(1, min(65535, $request->int('smtp_port', 587))),
            'smtp_encryption' => in_array($request->input('smtp_encryption'), ['tls', 'ssl', 'none'], true)
                ? (string) $request->input('smtp_encryption')
                : 'tls',
            'smtp_username' => (string) $request->input('smtp_username', ''),
        ]);

        // An empty password field means "keep the one you already have".
        $smtpPassword = (string) $request->raw('smtp_password');
        if ($smtpPassword !== '') {
            Settings::set('smtp_password', $smtpPassword);
        }

        AuditLog::record('settings.updated', 'system', null, 'Updated site settings');
        $this->success('Settings saved.');

        return $this->redirect('/settings');
    }

    /** Notification channels: who hears about a monitor at all. */
    public function channels(Request $request): Response
    {
        return $this->view($request, 'pages/channels', [
            'title' => 'Notification channels',
            'channels' => Channels::all(),
            'log' => Channels::recentLog(30),
            'mailConfigured' => Mailer::isConfigured(),
            'notificationsEnabled' => Settings::bool('notifications_enabled'),
        ]);
    }

    public function storeChannel(Request $request): Response
    {
        [$name, $recipients, $error] = $this->channelInput($request);

        if ($error !== null) {
            $this->error($error);

            return $this->redirect('/settings/channels');
        }

        $id = Channels::create($name, $recipients, $request->boolean('enabled'));
        AuditLog::record('channel.created', 'channel', $id, 'Added notification channel ' . $name);
        $this->success('Channel added.');

        return $this->redirect('/settings/channels');
    }

    public function updateChannel(Request $request): Response
    {
        $channel = Channels::find($request->intParam('id'));
        if ($channel === null) {
            throw HttpException::notFound('That channel does not exist.');
        }

        [$name, $recipients, $error] = $this->channelInput($request);

        if ($error !== null) {
            $this->error($error);

            return $this->redirect('/settings/channels');
        }

        Channels::update((int) $channel['id'], $name, $recipients, $request->boolean('enabled'));
        AuditLog::record('channel.updated', 'channel', (int) $channel['id'], 'Updated notification channel ' . $name);
        $this->success('Channel saved.');

        return $this->redirect('/settings/channels');
    }

    public function destroyChannel(Request $request): Response
    {
        $channel = Channels::find($request->intParam('id'));
        if ($channel === null) {
            throw HttpException::notFound('That channel does not exist.');
        }

        Channels::delete((int) $channel['id']);
        AuditLog::record('channel.deleted', 'channel', (int) $channel['id'], 'Deleted notification channel ' . $channel['name']);
        $this->success('Channel deleted. Monitors that used it stop notifying through it.');

        return $this->redirect('/settings/channels');
    }

    /** Prove the mail settings work before an outage does it for you. */
    public function testEmail(Request $request): Response
    {
        $recipient = strtolower(trim((string) $request->input('recipient', '')));
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            $result = ['ok' => false, 'error' => 'Enter the address to send the test to.'];
        } elseif (!Mailer::isConfigured()) {
            $result = ['ok' => false, 'error' => 'Fill in the sender address and SMTP host first, and save.'];
        } else {
            $result = Mailer::sendTest($recipient);
            AuditLog::record(
                'settings.test_email',
                'system',
                null,
                ($result['ok'] ? 'Sent' : 'Failed to send') . ' a test email to ' . $recipient
            );
        }

        if ($request->wantsJson()) {
            return Response::json($result, $result['ok'] ? 200 : 422);
        }

        $result['ok']
            ? $this->success('Test email sent to ' . $recipient . '.')
            : $this->error($result['error']);

        return $this->redirect('/settings');
    }

    /** @return array{0:string,1:array<int,string>,2:?string} */
    private function channelInput(Request $request): array
    {
        $name = trim((string) $request->input('name', ''));
        $raw = preg_split('/[\s,;]+/', (string) $request->input('recipients', '')) ?: [];

        $recipients = [];
        foreach ($raw as $address) {
            $address = strtolower(trim($address));
            if ($address === '') {
                continue;
            }
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                return ['', [], sprintf('"%s" is not a valid email address.', $address)];
            }
            $recipients[] = $address;
        }

        if ($name === '') {
            return ['', [], 'Give the channel a name, so you can tell it apart on a monitor.'];
        }
        if ($recipients === []) {
            return ['', [], 'Add at least one recipient address.'];
        }

        return [$name, array_values(array_unique($recipients)), null];
    }

    public function activity(Request $request): Response
    {
        return $this->view($request, 'pages/activity', [
            'title' => 'Activity log',
            'entries' => AuditLog::recent(150),
        ]);
    }
}
