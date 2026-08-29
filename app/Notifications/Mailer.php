<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Settings;
use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * Sends the mail. Two drivers: SMTP for anything you would actually deliver
 * with, and the local sendmail binary for servers that already relay properly.
 */
final class Mailer
{
    /**
     * @param array<int,string> $recipients
     * @return array{ok:bool,error:string}
     */
    public static function send(array $recipients, string $subject, string $html, string $text): array
    {
        $recipients = array_values(array_filter(
            array_map('trim', $recipients),
            static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_EMAIL) !== false
        ));

        if ($recipients === []) {
            return ['ok' => false, 'error' => 'No valid recipient address.'];
        }

        $from = trim(Settings::get('mail_from_address'));
        if ($from === '') {
            return ['ok' => false, 'error' => 'No sender address is set. Add one under Settings → Email.'];
        }

        $mail = new PHPMailer(true);

        try {
            self::configure($mail);

            $mail->setFrom($from, Settings::get('mail_from_name', 'Monitor'));
            foreach ($recipients as $address) {
                $mail->addAddress($address);
            }

            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Body = $html;
            $mail->AltBody = $text;

            $mail->send();

            return ['ok' => true, 'error' => ''];
        } catch (MailException $e) {
            return ['ok' => false, 'error' => self::explain($mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage())];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => self::explain($e->getMessage())];
        }
    }

    private static function configure(PHPMailer $mail): void
    {
        $mail->Timeout = 15;
        $mail->SMTPDebug = SMTP::DEBUG_OFF;

        if (Settings::get('mail_driver', 'smtp') === 'sendmail') {
            $mail->isSendmail();

            return;
        }

        $mail->isSMTP();
        $mail->Host = Settings::get('smtp_host');
        $mail->Port = Settings::int('smtp_port', 587);

        $encryption = Settings::get('smtp_encryption', 'tls');
        if ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        $username = Settings::get('smtp_username');
        if ($username !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = $username;
            $mail->Password = Settings::get('smtp_password');
        }
    }

    /** Turn SMTP's phrasing into something an administrator can act on. */
    private static function explain(string $raw): string
    {
        $message = trim($raw);

        return match (true) {
            str_contains($message, 'authenticate') || str_contains($message, '535') =>
                'The mail server rejected the user name or password.',
            str_contains($message, 'Could not connect') || str_contains($message, 'Connection refused') =>
                'Could not reach the mail server. Check the host and port, and that outgoing SMTP is not blocked.',
            str_contains($message, 'certificate') =>
                'The mail server\'s TLS certificate was rejected: ' . $message,
            str_contains($message, 'timed out') =>
                'The mail server did not answer in time.',
            str_contains($message, 'sendmail') =>
                'The local sendmail command failed: ' . $message . ' Try the SMTP driver instead.',
            default => $message !== '' ? $message : 'The message could not be sent.',
        };
    }

    /**
     * @return array{ok:bool,error:string}
     */
    public static function sendTest(string $recipient): array
    {
        $siteName = Settings::get('site_name', 'Monitor');
        [$html, $text] = EmailTemplate::test($siteName);

        return self::send([$recipient], $siteName . ' — test message', $html, $text);
    }

    /** Whether enough is configured to attempt a send at all. */
    public static function isConfigured(): bool
    {
        if (trim(Settings::get('mail_from_address')) === '') {
            return false;
        }

        if (Settings::get('mail_driver', 'smtp') === 'sendmail') {
            return true;
        }

        return trim(Settings::get('smtp_host')) !== '';
    }
}
