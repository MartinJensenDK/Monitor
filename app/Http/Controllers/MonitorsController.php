<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Domain\AuditLog;
use App\Domain\Gate;
use App\Domain\Groups;
use App\Domain\Incidents;
use App\Domain\Monitors;
use App\Domain\Stats;
use App\Scheduler\Runner;
use App\Checks\ApiChecker;
use App\Checks\Assertions;
use App\Checks\DnsResolver;
use App\Checks\DomainChecker;
use App\Checks\DomainRegistry;
use App\Checks\KeywordChecker;
use App\Checks\PingTransport;
use App\Checks\PingChecker;
use App\Checks\SslChecker;
use App\Notifications\Channels;
use App\Support\Crypto;

final class MonitorsController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'status' => (string) $request->query('status', 'all'),
            'type' => (string) $request->query('type', 'all'),
            'group' => (int) $request->query('group', '0'),
            'q' => trim((string) $request->query('q', '')),
        ];

        $monitors = Monitors::visible($filters);
        $ids = array_map(static fn (array $m): int => (int) $m['id'], $monitors);

        return $this->view($request, 'pages/monitors', [
            'title' => 'Monitors',
            'monitors' => $monitors,
            'tapes' => Monitors::tapes($ids, 48),
            'filters' => $filters,
            'groups' => Groups::assignable(),
            'counts' => Monitors::statusCounts(),
        ]);
    }

    public function show(Request $request): Response
    {
        $monitor = $this->findOrFail($request->intParam('id'));
        $id = (int) $monitor['id'];

        $range = (string) $request->query('range', '24h');
        if (!isset(Stats::RANGES[$range])) {
            $range = '24h';
        }

        return $this->view($request, 'pages/monitor-show', [
            'title' => (string) $monitor['name'],
            'needsCharts' => true,
            'monitor' => $monitor,
            'config' => Monitors::config($monitor),
            'tape' => Monitors::tapes([$id], 96)[$id] ?? [],
            'checks' => Monitors::recentChecks($id, 15),
            'incidents' => Incidents::forMonitor($id, 8),
            'daily' => Stats::dailyUptime($id, 30),
            'latency' => Stats::latency($id, 24),
            'series' => Stats::series($id, $range),
            'range' => $range,
            'groups' => Monitors::groups($id),
            'canEdit' => Gate::canEdit($monitor),
            'canDelete' => Gate::canDelete($monitor),
            'notifications' => Channels::activeFor($id),
            'pingTransport' => PingTransport::detect(),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view($request, 'pages/monitor-form', [
            'title' => 'New monitor',
            'monitor' => null,
            'config' => [],
            'assignable' => Groups::assignable(),
            'assigned' => [],
            'channels' => Channels::all(),
            'rules' => [],
            'pingTransport' => PingTransport::detect(),
        ]);
    }

    public function edit(Request $request): Response
    {
        $monitor = $this->findOrFail($request->intParam('id'));
        Gate::authorizeEdit($monitor);

        $assigned = [];
        foreach (Monitors::groups((int) $monitor['id']) as $group) {
            $assigned[$group['group_id']] = $group['access'];
        }

        return $this->view($request, 'pages/monitor-form', [
            'title' => 'Edit ' . $monitor['name'],
            'monitor' => $monitor,
            'config' => Monitors::config($monitor),
            'assignable' => Groups::assignable(),
            'assigned' => $assigned,
            'channels' => Channels::all(),
            'rules' => Channels::rulesFor((int) $monitor['id']),
            'pingTransport' => PingTransport::detect(),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        if ($data instanceof Response) {
            return $data;
        }

        $id = Monitors::create($data['monitor'], $data['groups']);

        if ($data['rules'] === []) {
            Channels::attachDefault($id);
        } else {
            Channels::syncMonitorRules($id, $data['rules']);
        }

        AuditLog::record('monitor.created', 'monitor', $id, 'Created monitor ' . $data['monitor']['name']);
        $this->success('Monitor created. The first check runs within a minute.');

        return $this->redirect('/monitors/' . $id);
    }

    public function update(Request $request): Response
    {
        $monitor = $this->findOrFail($request->intParam('id'));
        Gate::authorizeEdit($monitor);

        $data = $this->validated($request, (int) $monitor['id']);
        if ($data instanceof Response) {
            return $data;
        }

        // Keep the stored basic-auth password when the field is left blank.
        if (($data['monitor']['config']['auth_password'] ?? '') === '') {
            $existing = Monitors::config($monitor);
            if (($existing['auth_password'] ?? '') !== '') {
                $data['monitor']['config']['auth_password'] = $existing['auth_password'];
            }
        }

        Monitors::update((int) $monitor['id'], $data['monitor'], $data['groups']);
        Channels::syncMonitorRules((int) $monitor['id'], $data['rules']);
        AuditLog::record('monitor.updated', 'monitor', (int) $monitor['id'], 'Updated monitor ' . $data['monitor']['name']);
        $this->success('Monitor saved.');

        return $this->redirect('/monitors/' . $monitor['id']);
    }

    public function toggle(Request $request): Response
    {
        $monitor = $this->findOrFail($request->intParam('id'));
        Gate::authorizeEdit($monitor);

        $enabled = !(bool) $monitor['enabled'];
        Monitors::setEnabled((int) $monitor['id'], $enabled);
        AuditLog::record(
            $enabled ? 'monitor.resumed' : 'monitor.paused',
            'monitor',
            (int) $monitor['id'],
            ($enabled ? 'Resumed ' : 'Paused ') . $monitor['name']
        );

        $this->success($enabled ? 'Monitor resumed.' : 'Monitor paused. Checks stop until you resume it.');

        return $this->back($request);
    }

    public function checkNow(Request $request): Response
    {
        $monitor = $this->findOrFail($request->intParam('id'));
        Gate::authorizeEdit($monitor);

        if (!(bool) $monitor['enabled']) {
            $this->warn('This monitor is paused. Resume it to run a check.');

            return $this->back($request);
        }

        (new Runner())->tick((int) $monitor['id']);
        $this->success('Checked just now.');

        return $this->redirect('/monitors/' . $monitor['id']);
    }

    public function destroy(Request $request): Response
    {
        $monitor = $this->findOrFail($request->intParam('id'));

        Monitors::delete((int) $monitor['id']);
        AuditLog::record('monitor.deleted', 'monitor', (int) $monitor['id'], 'Deleted monitor ' . $monitor['name']);
        $this->success('Monitor deleted, along with its history.');

        return $this->redirect('/monitors');
    }

    /** @return array<string,mixed> */
    private function findOrFail(int $id): array
    {
        $monitor = Monitors::findVisible($id);
        if ($monitor === null) {
            throw HttpException::notFound('That monitor does not exist, or it is not shared with any of your groups.');
        }

        return $monitor;
    }

    /**
     * @return array{monitor:array<string,mixed>,groups:array<int,array{group_id:int,access:string}>}|Response
     */
    private function validated(Request $request, int $monitorId = 0): array|Response
    {
        $type = (string) $request->input('type', 'http');
        $target = trim((string) $request->input('target', ''));

        $groups = [];
        foreach ($request->raw('group_access') ?? [] as $groupId => $access) {
            if (!in_array($access, ['view', 'edit'], true)) {
                continue;
            }
            $groups[] = ['group_id' => (int) $groupId, 'access' => (string) $access];
        }

        // A non-admin can only share with groups they belong to.
        if (!Auth::isAdmin()) {
            $allowed = Auth::groupIds();
            $groups = array_values(array_filter(
                $groups,
                static fn (array $g): bool => in_array($g['group_id'], $allowed, true)
            ));
        }

        $shape = self::targetShape($type);

        $validator = Validator::make($request->all())
            ->required('name', 'Name')
            ->maxLength('name', 'Name', 120)
            ->required('target', self::targetLabel($type))
            ->in('type', 'Type', Monitors::AVAILABLE_TYPES)
            ->between('interval_seconds', 'Interval', 30, 86400)
            ->between('timeout_seconds', 'Timeout', 1, 120)
            ->between('retries', 'Retries', 0, 5)
            ->custom('group_access', $groups !== [], 'Share the monitor with at least one group, otherwise nobody can see it.');

        if ($shape === 'url') {
            $validator->url('target', 'URL');
        } elseif ($shape === 'domain') {
            $validator->custom(
                'target',
                $target === '' || preg_match('/^[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/', DomainRegistry::normalise($target)) === 1,
                'Enter a registrable domain, such as example.com, without http:// in front of it.'
            );
        } elseif ($shape === 'dns') {
            // DNS names legitimately carry underscores and a leading wildcard.
            $validator->custom(
                'target',
                $target === '' || preg_match('/^[A-Za-z0-9._*-]+$/', $target) === 1,
                'Enter the name to look up, such as example.com or _dmarc.example.com.'
            );
        } else {
            $validator->custom(
                'target',
                $target === '' || preg_match('/^[A-Za-z0-9._:\[\]-]+$/', $target) === 1,
                'Enter a host name or IP address, without http:// in front of it.'
            );
        }

        if ($type === 'port') {
            $port = $request->int('port', 0);
            $validator->custom('port', $port >= 1 && $port <= 65535, 'Enter the port to connect to, between 1 and 65535.');
        }

        if ($type === 'ping') {
            $fallback = $request->int('fallback_port', PingChecker::DEFAULT_FALLBACK_PORT);
            $validator->custom('fallback_port', $fallback >= 1 && $fallback <= 65535, 'The fallback port must be between 1 and 65535.');
        }

        if ($type === 'ssl') {
            $port = $request->int('ssl_port', SslChecker::DEFAULT_PORT);
            $validator
                ->custom('ssl_port', $port >= 1 && $port <= 65535, 'The TLS port must be between 1 and 65535.')
                ->custom(
                    'warn_days',
                    $request->int('warn_days', SslChecker::DEFAULT_WARN_DAYS) >= $request->int('critical_days', SslChecker::DEFAULT_CRITICAL_DAYS),
                    'The warning must come at least as early as the failure, so give it the larger number of days.'
                );
        }

        if ($type === 'domain') {
            $validator->custom(
                'interval_seconds',
                $request->int('interval_seconds', 60) >= Monitors::SLOW_MIN_INTERVAL,
                'Registries rate-limit lookups. Check a domain once an hour at most.'
            )->custom(
                'warn_days',
                $request->int('warn_days', DomainChecker::DEFAULT_WARN_DAYS) >= $request->int('critical_days', DomainChecker::DEFAULT_CRITICAL_DAYS),
                'The warning must come at least as early as the failure, so give it the larger number of days.'
            );
        }

        if ($type === 'dns') {
            $validator->in('record_type', 'Record type', array_keys(DnsResolver::TYPES));

            foreach ($this->lines((string) $request->input('resolvers', '')) as $resolver) {
                $validator->custom(
                    'resolvers',
                    DnsResolver::isValidServer($resolver),
                    sprintf('"%s" is not an IP address. Name resolvers by address, such as 1.1.1.1.', $resolver)
                );
            }
        }

        if ($type === 'keyword') {
            $validator->custom(
                'keywords',
                $this->lines((string) $request->input('keywords', '')) !== [],
                'Give the check at least one word or phrase to look for.'
            )->in('keyword_mode', 'Match', array_keys(KeywordChecker::MODES));
        }

        $steps = $this->parseSteps($request);
        if ($type === 'api') {
            $validator->custom('steps', $steps !== [], 'An API check needs at least one request. Add a step.');

            foreach ($steps as $index => $step) {
                $number = $index + 1;

                $validator->custom(
                    'steps',
                    trim((string) $step['path']) !== '',
                    'Step ' . $number . ' has no path or URL to call.'
                );

                foreach ($step['assertions'] as $assertion) {
                    $validator->custom(
                        'steps',
                        Assertions::exists($assertion['operator']),
                        'Step ' . $number . ' uses an operator that does not exist.'
                    );
                }

                foreach ($step['captures'] as $capture) {
                    $validator->custom(
                        'steps',
                        preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $capture['name']) === 1,
                        sprintf('Step %d captures into "%s". Use letters, digits and underscores so it can be written as {{%s}}.', $number, $capture['name'], $capture['name'])
                    );
                }
            }
        }

        $assertions = $this->parseAssertions($request);
        if ($type === 'endpoint') {
            foreach ($assertions as $index => $assertion) {
                $validator->custom(
                    'assertions',
                    Assertions::exists($assertion['operator']),
                    'Assertion ' . ($index + 1) . ' uses an operator that does not exist.'
                );
            }
        }

        $timeout = $request->int('timeout_seconds', 10);
        $interval = $request->int('interval_seconds', 60);
        $validator->custom(
            'timeout_seconds',
            $timeout < $interval,
            'The timeout must be shorter than the interval, or checks will pile up.'
        );

        if ($validator->fails()) {
            Session::flashInput($request->all());
            $this->error((string) $validator->firstError());

            return $this->redirect($monitorId > 0 ? '/monitors/' . $monitorId . '/edit' : '/monitors/new');
        }

        return [
            'monitor' => [
                'name' => (string) $request->input('name', ''),
                'type' => $type,
                'target' => $target,
                'enabled' => $request->boolean('enabled'),
                'interval_seconds' => $interval,
                'timeout_seconds' => $timeout,
                'retries' => $request->int('retries', 2),
                'degraded_ms' => $request->int('degraded_ms', 0) > 0 ? $request->int('degraded_ms', 0) : null,
                'tags' => (string) $request->input('tags', '') ?: null,
                'config' => $this->configFor($type, $request, $assertions, $steps),
            ],
            'groups' => $groups,
            'rules' => $this->parseRules($request),
        ];
    }

    /**
     * Type-specific settings. Everything a checker needs beyond the shared
     * columns lives here, which is why a new monitor type needs no migration.
     *
     * @param array<int,array{path:string,operator:string,value:string}> $assertions
     * @return array<string,mixed>
     */
    private function configFor(string $type, Request $request, array $assertions, array $steps = []): array
    {
        if ($type === 'ping') {
            return ['fallback_port' => $request->int('fallback_port', PingChecker::DEFAULT_FALLBACK_PORT)];
        }

        if ($type === 'port') {
            return [
                'port' => $request->int('port', 0),
                'banner' => (string) $request->input('banner', ''),
            ];
        }

        if ($type === 'ssl') {
            return [
                'port' => $request->int('ssl_port', SslChecker::DEFAULT_PORT),
                'sni_host' => trim((string) $request->input('sni_host', '')),
                'warn_days' => $request->int('warn_days', SslChecker::DEFAULT_WARN_DAYS),
                'critical_days' => $request->int('critical_days', SslChecker::DEFAULT_CRITICAL_DAYS),
                'verify_chain' => $request->boolean('verify_chain'),
                'check_hostname' => $request->boolean('check_hostname'),
                'expected_issuer' => trim((string) $request->input('expected_issuer', '')),
            ];
        }

        if ($type === 'domain') {
            return [
                'warn_days' => $request->int('warn_days', DomainChecker::DEFAULT_WARN_DAYS),
                'critical_days' => $request->int('critical_days', DomainChecker::DEFAULT_CRITICAL_DAYS),
                'watch_status' => $request->boolean('watch_status'),
                'expected_registrar' => trim((string) $request->input('expected_registrar', '')),
                'expected_nameservers' => $this->lines((string) $request->input('expected_nameservers', '')),
            ];
        }

        if ($type === 'dns') {
            return [
                'record_type' => strtoupper((string) $request->input('record_type', 'A')),
                'resolvers' => $this->lines((string) $request->input('resolvers', '')),
                'expected_records' => $this->lines((string) $request->input('expected_records', '')),
                'dns_mode' => (string) $request->input('dns_mode', 'contains'),
            ];
        }

        if ($type === 'api') {
            return [
                'verify_ssl' => $request->boolean('verify_ssl'),
                'follow_redirects' => $request->boolean('follow_redirects'),
                'user_agent' => (string) $request->input('user_agent', ''),
                'steps' => $steps,
            ];
        }

        $authPassword = (string) $request->raw('auth_password');

        $config = [
            'method' => strtoupper((string) $request->input('method', 'GET')),
            'expected_status' => (string) $request->input('expected_status', '200-299'),
            'keyword' => (string) $request->input('keyword', ''),
            'keyword_absent' => $request->boolean('keyword_absent'),
            'follow_redirects' => $request->boolean('follow_redirects'),
            'verify_ssl' => $request->boolean('verify_ssl'),
            'headers' => $this->parseHeaders((string) $request->input('headers', '')),
            'body' => (string) $request->input('body', ''),
            'auth_username' => (string) $request->input('auth_username', ''),
            'auth_password' => $authPassword === '' ? '' : Crypto::encrypt($authPassword),
            'user_agent' => (string) $request->input('user_agent', ''),
        ];

        if ($type === 'endpoint') {
            $config['assertions'] = $assertions;
        }

        if ($type === 'keyword') {
            $config['keywords'] = $this->lines((string) $request->input('keywords', ''));
            $config['keyword_mode'] = (string) $request->input('keyword_mode', 'all');
            $config['case_sensitive'] = $request->boolean('case_sensitive');
            $config['strip_html'] = $request->boolean('strip_html');

            // The single-keyword field belongs to the website check; a keyword
            // monitor keeps its words in the list above.
            unset($config['keyword'], $config['keyword_absent']);
        }

        return $config;
    }

    /** What the shared target field holds for a given type. */
    private static function targetShape(string $type): string
    {
        return match ($type) {
            'http', 'keyword', 'endpoint', 'api' => 'url',
            'domain' => 'domain',
            'dns' => 'dns',
            default => 'host',
        };
    }

    private static function targetLabel(string $type): string
    {
        return match (self::targetShape($type)) {
            'url' => 'URL',
            'domain' => 'Domain',
            'dns' => 'Name',
            default => 'Host',
        };
    }

    /**
     * A textarea of one value per line, cleaned up.
     *
     * @return array<int,string>
     */
    private function lines(string $raw): array
    {
        $values = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $values[] = $line;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * The steps of an API check.
     *
     * Each step is posted under its own index — step[0][path], step[0][assert_path][]
     * and so on — so a step can hold repeatable rows of its own without the
     * indexes of the two levels running into each other.
     *
     * @return array<int,array<string,mixed>>
     */
    private function parseSteps(Request $request): array
    {
        $raw = $request->raw('step');
        if (!is_array($raw)) {
            return [];
        }

        $steps = [];
        foreach ($raw as $step) {
            if (!is_array($step)) {
                continue;
            }

            $path = trim((string) ($step['path'] ?? ''));
            $name = trim((string) ($step['name'] ?? ''));
            if ($path === '' && $name === '') {
                continue;
            }

            $assertions = [];
            $paths = (array) ($step['assert_path'] ?? []);
            $operators = (array) ($step['assert_operator'] ?? []);
            $values = (array) ($step['assert_value'] ?? []);
            foreach ($paths as $index => $assertPath) {
                $assertPath = trim((string) $assertPath);
                if ($assertPath === '') {
                    continue;
                }
                $assertions[] = [
                    'path' => $assertPath,
                    'operator' => (string) ($operators[$index] ?? 'equals'),
                    'value' => trim((string) ($values[$index] ?? '')),
                ];
            }

            $captures = [];
            $captureNames = (array) ($step['capture_name'] ?? []);
            $capturePaths = (array) ($step['capture_path'] ?? []);
            foreach ($captureNames as $index => $captureName) {
                $captureName = trim((string) $captureName);
                $capturePath = trim((string) ($capturePaths[$index] ?? ''));
                if ($captureName === '' || $capturePath === '') {
                    continue;
                }
                $captures[] = ['name' => $captureName, 'path' => $capturePath];
            }

            $steps[] = [
                'name' => mb_substr($name, 0, 60),
                'method' => strtoupper((string) ($step['method'] ?? 'GET')),
                'path' => $path,
                'expected_status' => trim((string) ($step['expected_status'] ?? '')) ?: '200-299',
                'headers' => $this->parseHeaders((string) ($step['headers'] ?? '')),
                'body' => (string) ($step['body'] ?? ''),
                'assertions' => $assertions,
                'captures' => $captures,
            ];

            if (count($steps) >= ApiChecker::MAX_STEPS) {
                break;
            }
        }

        return $steps;
    }

    /**
     * Assertion rows arrive as three parallel arrays from the form.
     *
     * @return array<int,array{path:string,operator:string,value:string}>
     */
    private function parseAssertions(Request $request): array
    {
        $paths = $request->arrayInput('assert_path');
        $operators = $request->arrayInput('assert_operator');
        $values = $request->arrayInput('assert_value');

        $assertions = [];
        foreach ($paths as $index => $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }

            $assertions[] = [
                'path' => $path,
                'operator' => $operators[$index] ?? 'equals',
                'value' => trim($values[$index] ?? ''),
            ];
        }

        return $assertions;
    }

    /**
     * Notification rules, one row per channel.
     *
     * @return array<int,array<string,mixed>>
     */
    private function parseRules(Request $request): array
    {
        $raw = $request->raw('notify');
        if (!is_array($raw)) {
            return [];
        }

        $rules = [];
        foreach ($raw as $channelId => $rule) {
            if (!is_array($rule) || empty($rule['enabled'])) {
                continue;
            }

            $rules[(int) $channelId] = [
                'enabled' => 1,
                'notify_down' => empty($rule['notify_down']) ? 0 : 1,
                'notify_up' => empty($rule['notify_up']) ? 0 : 1,
                'notify_degraded' => empty($rule['notify_degraded']) ? 0 : 1,
                'notify_cert_expiry' => empty($rule['notify_cert_expiry']) ? 0 : 1,
                'cert_expiry_days' => max(1, min(90, (int) ($rule['cert_expiry_days'] ?? 14))),
                'failure_threshold' => max(1, min(20, (int) ($rule['failure_threshold'] ?? 1))),
                'resend_after_minutes' => max(0, min(1440, (int) ($rule['resend_after_minutes'] ?? 0))),
                'quiet_hours_start' => self::time((string) ($rule['quiet_hours_start'] ?? '')),
                'quiet_hours_end' => self::time((string) ($rule['quiet_hours_end'] ?? '')),
            ];
        }

        return $rules;
    }

    private static function time(string $value): ?string
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim($value)) === 1 ? trim($value) . ':00' : null;
    }

    /** @return array<string,string> */
    private function parseHeaders(string $raw): array
    {
        $headers = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name = trim($name);
            if ($name !== '') {
                $headers[$name] = trim($value);
            }
        }

        return $headers;
    }
}
