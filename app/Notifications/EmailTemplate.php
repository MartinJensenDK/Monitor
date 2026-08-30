<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Settings;

/**
 * The notification emails.
 *
 * Table layout and inline styles, because that is what mail clients render
 * reliably. The palette follows the interface — one saturated colour carrying
 * the state, everything else quiet — so an alert looks like it came from the
 * same product as the dashboard.
 */
final class EmailTemplate
{
    private const INK = '#12181d';
    private const MUTED = '#5c6874';
    private const LINE = '#d3d8dd';
    private const PAPER = '#eceef0';

    private const COLORS = [
        'down' => '#be3a2b',
        'up' => '#17795e',
        'degraded' => '#b5730a',
        'cert' => '#b5730a',
        'info' => '#0e7c8c',
    ];

    /**
     * @param array<string,mixed> $monitor
     * @return array{0:string,1:string} html, plain text
     */
    public static function down(array $monitor, string $error, string $startedAt, int $failedChecks): array
    {
        $name = (string) $monitor['name'];

        return self::render(
            'down',
            $name . ' is down',
            'Down',
            $error,
            [
                'Monitor' => $name,
                'Target' => (string) $monitor['target'],
                'Failing since' => $startedAt . ' UTC',
                'Failed checks' => (string) $failedChecks,
            ],
            self::monitorUrl($monitor),
            'Open the monitor'
        );
    }

    /**
     * @param array<string,mixed> $monitor
     * @return array{0:string,1:string}
     */
    public static function recovered(array $monitor, string $downtime, int $responseMs): array
    {
        $name = (string) $monitor['name'];

        return self::render(
            'up',
            $name . ' is back up',
            'Recovered',
            sprintf('It answered normally again after %s of downtime.', $downtime),
            [
                'Monitor' => $name,
                'Target' => (string) $monitor['target'],
                'Downtime' => $downtime,
                'Response now' => $responseMs . ' ms',
            ],
            self::monitorUrl($monitor),
            'Open the monitor'
        );
    }

    /**
     * @param array<string,mixed> $monitor
     * @return array{0:string,1:string}
     */
    public static function degraded(array $monitor, string $message, int $responseMs): array
    {
        $name = (string) $monitor['name'];

        return self::render(
            'degraded',
            $name . ' is slow',
            'Degraded',
            $message,
            [
                'Monitor' => $name,
                'Target' => (string) $monitor['target'],
                'Response' => $responseMs . ' ms',
                'Threshold' => ((int) ($monitor['degraded_ms'] ?? 0)) . ' ms',
            ],
            self::monitorUrl($monitor),
            'Open the monitor'
        );
    }

    /**
     * @param array<string,mixed> $monitor
     * @return array{0:string,1:string}
     */
    public static function certificateExpiring(array $monitor, int $days, string $expiresAt): array
    {
        $name = (string) $monitor['name'];
        $when = $days <= 0 ? 'has expired' : sprintf('expires in %d day%s', $days, $days === 1 ? '' : 's');

        // A domain monitor stores its registration date in the same column, so
        // the reminder has to be worded from the monitor's type.
        $isDomain = (string) ($monitor['type'] ?? '') === 'domain';

        return self::render(
            'cert',
            ($isDomain ? 'Domain registration for ' : 'TLS certificate for ') . $name . ' ' . $when,
            $isDomain ? 'Domain' : 'Certificate',
            $isDomain
                ? sprintf('The registration of %s %s. Renew it before it lapses.', (string) $monitor['target'], $when)
                : sprintf('The certificate served by %s %s. Renew it before it lapses.', (string) $monitor['target'], $when),
            [
                'Monitor' => $name,
                'Target' => (string) $monitor['target'],
                'Expires' => $expiresAt . ' UTC',
                ($isDomain ? 'Registrar' : 'Issuer') => (string) ($monitor['cert_issuer'] ?? '—'),
            ],
            self::monitorUrl($monitor),
            'Open the monitor'
        );
    }

    /** @return array{0:string,1:string} */
    public static function test(string $siteName): array
    {
        return self::render(
            'info',
            'Email is working',
            'Test',
            'If you are reading this, ' . $siteName . ' can send mail. Alerts will arrive the same way.',
            [
                'Driver' => Settings::get('mail_driver', 'smtp') === 'sendmail' ? 'Local sendmail' : 'SMTP',
                'From' => Settings::get('mail_from_address'),
                'Sent' => gmdate('Y-m-d H:i:s') . ' UTC',
            ],
            rtrim(Settings::get('site_url'), '/') . '/settings',
            'Open settings'
        );
    }

    /**
     * The one mail that is not about a monitor. It goes to someone who cannot
     * sign in, so it says as little as it can: no account name, no hint about
     * what else the site knows.
     *
     * @return array{0:string,1:string}
     */
    public static function passwordReset(string $url, int $minutes): array
    {
        $siteName = Settings::get('site_name', 'Monitor');

        return self::render(
            'info',
            'Choose a new password',
            'Password',
            sprintf(
                'Someone asked to reset the password for this address on %s. The link works once, and only for the next %d minutes.',
                $siteName,
                $minutes
            ),
            [
                'Requested' => gmdate('Y-m-d H:i:s') . ' UTC',
                'Link expires' => gmdate('Y-m-d H:i:s', time() + $minutes * 60) . ' UTC',
            ],
            $url,
            'Choose a new password',
            'If this was not you, ignore the mail. Nothing has changed, and the link expires on its own.'
        );
    }

    /**
     * @param array<string,string> $facts
     * @return array{0:string,1:string}
     */
    private static function render(
        string $state,
        string $subject,
        string $badge,
        string $summary,
        array $facts,
        string $url,
        string $action,
        string $footer = 'Change what you are told about in the monitor\'s notification settings.'
    ): array {
        $color = self::COLORS[$state] ?? self::COLORS['info'];
        $siteName = Settings::get('site_name', 'Monitor');

        $rows = '';
        foreach ($facts as $label => $value) {
            $rows .= sprintf(
                '<tr>
                    <td style="padding:7px 0;color:%s;font-size:13px;width:130px;vertical-align:top;">%s</td>
                    <td style="padding:7px 0;color:%s;font-size:13px;font-family:ui-monospace,Menlo,Consolas,monospace;word-break:break-word;">%s</td>
                </tr>',
                self::MUTED,
                self::escape($label),
                self::INK,
                self::escape($value)
            );
        }

        $button = $url === '' || $url === '/settings' ? '' : sprintf(
            '<tr><td style="padding-top:22px;">
                <a href="%s" style="display:inline-block;padding:10px 18px;background:%s;color:#ffffff;
                   text-decoration:none;border-radius:8px;font-size:14px;font-weight:600;">%s</a>
            </td></tr>',
            self::escape($url),
            $color,
            self::escape($action)
        );

        $html = sprintf(
            '<!DOCTYPE html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>%1$s</title></head>
<body style="margin:0;padding:24px 12px;background:%2$s;font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" style="max-width:540px;margin:0 auto;">
  <tr><td style="background:#ffffff;border:1px solid %3$s;border-radius:12px;overflow:hidden;">
    <table role="presentation" width="100%%" cellpadding="0" cellspacing="0">
      <tr><td style="height:4px;background:%4$s;font-size:0;line-height:0;">&nbsp;</td></tr>
      <tr><td style="padding:24px 26px 26px;">
        <p style="margin:0 0 14px;font-size:11px;letter-spacing:0.14em;text-transform:uppercase;color:%4$s;font-weight:700;">%5$s</p>
        <h1 style="margin:0 0 10px;font-size:20px;line-height:1.3;color:%6$s;font-weight:600;">%1$s</h1>
        <p style="margin:0 0 18px;font-size:14px;line-height:1.5;color:%7$s;">%8$s</p>
        <table role="presentation" width="100%%" cellpadding="0" cellspacing="0"
               style="border-top:1px solid %3$s;">%9$s%10$s</table>
      </td></tr>
    </table>
  </td></tr>
  <tr><td style="padding:16px 6px;text-align:center;font-size:12px;color:%7$s;">
    Sent by %11$s. %12$s
  </td></tr>
</table>
</body></html>',
            self::escape($subject),
            self::PAPER,
            self::LINE,
            $color,
            self::escape(strtoupper($badge)),
            self::INK,
            self::MUTED,
            self::escape($summary),
            $rows,
            $button,
            self::escape($siteName),
            self::escape($footer)
        );

        $lines = [strtoupper($badge) . ' — ' . $subject, '', $summary, ''];
        foreach ($facts as $label => $value) {
            $lines[] = sprintf('%-14s %s', $label . ':', $value);
        }
        if ($url !== '') {
            $lines[] = '';
            $lines[] = $url;
        }
        $lines[] = '';
        $lines[] = 'Sent by ' . $siteName . '. ' . $footer;

        return [$html, implode("\n", $lines)];
    }

    /** @param array<string,mixed> $monitor */
    private static function monitorUrl(array $monitor): string
    {
        $base = rtrim(Settings::get('site_url'), '/');

        return $base === '' ? '' : $base . '/monitors/' . (int) $monitor['id'];
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
