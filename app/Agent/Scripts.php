<?php

declare(strict_types=1);

namespace App\Agent;

use App\Core\App;
use App\Core\Config;

/**
 * The agent and its installer, as they are handed to a machine.
 *
 * They are served rather than copied by hand so that an install always points
 * at the right address and always gets the current version. Nothing secret is
 * in them -- the enrolment key is passed as an argument by whoever runs the
 * installer -- which is why they need no authentication: a machine has to be
 * able to fetch the agent before it has any way of proving who it is.
 */
final class Scripts
{
    /** @var array<string,array{file:string,type:string}> */
    private const FILES = [
        'linux/install.sh' => ['file' => 'install.sh', 'type' => 'text/x-shellscript'],
        'linux/agent.sh' => ['file' => 'agent.sh', 'type' => 'text/x-shellscript'],
        'windows/install.ps1' => ['file' => 'install.ps1', 'type' => 'text/plain'],
        'windows/agent.ps1' => ['file' => 'agent.ps1', 'type' => 'text/plain'],
    ];

    public static function exists(string $name): bool
    {
        return isset(self::FILES[$name]);
    }

    public static function contentType(string $name): string
    {
        return (self::FILES[$name]['type'] ?? 'text/plain') . '; charset=UTF-8';
    }

    /**
     * The script with this install's address baked in, so the command a person
     * copies out of the interface needs no editing.
     */
    public static function contents(string $name): ?string
    {
        if (!self::exists($name)) {
            return null;
        }

        $path = App::basePath('resources/agent/' . self::FILES[$name]['file']);
        $body = @file_get_contents($path);

        return $body === false ? null : str_replace('__MONITOR_URL__', self::baseUrl(), $body);
    }

    /**
     * Printed next to the install command so it can be checked before it is
     * run. Downloading a script and executing it on trust is the usual way
     * this is done; being able to compare a hash is the least this can offer
     * somebody who would rather not.
     */
    public static function checksum(string $name): string
    {
        $body = self::contents($name);

        return $body === null ? '' : hash('sha256', $body);
    }

    /**
     * The version of the agent this install would hand out.
     *
     * Read out of the script itself rather than kept in a constant here, so
     * there is one place a version can be wrong instead of two that can
     * disagree.
     */
    public static function version(string $platform): string
    {
        static $cache = [];
        if (isset($cache[$platform])) {
            return $cache[$platform];
        }

        $name = $platform === 'windows' ? 'windows/agent.ps1' : 'linux/agent.sh';
        $body = self::contents($name);

        if ($body !== null && preg_match('/AGENT_VERSION\s*=\s*"([0-9][0-9.]*)"/', $body, $m) === 1) {
            return $cache[$platform] = $m[1];
        }
        if ($body !== null && preg_match('/\$AgentVersion\s*=\s*\'([0-9][0-9.]*)\'/', $body, $m) === 1) {
            return $cache[$platform] = $m[1];
        }

        return $cache[$platform] = '0.0.0';
    }

    /**
     * What an agent needs to decide whether to replace itself, and to check
     * that what it downloaded is what this server meant to send.
     *
     * The checksum is not a defence against this server: whoever controls it
     * controls the agent by design. It catches a truncated or tampered
     * download between here and the machine, which is the failure that is
     * actually likely.
     *
     * @return array<string,string>
     */
    public static function manifest(string $osFamily): array
    {
        $platform = $osFamily === 'windows' ? 'windows' : 'linux';
        $name = $platform === 'windows' ? 'windows/agent.ps1' : 'linux/agent.sh';

        return [
            'version' => self::version($platform),
            'url' => '/agent/' . $name,
            'sha256' => self::checksum($name),
        ];
    }

    public static function baseUrl(): string
    {
        $url = rtrim(Config::string('app.url'), '/');

        return $url === '' ? 'https://monitor.example.com' : $url;
    }
}
