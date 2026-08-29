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
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        if ($data instanceof Response) {
            return $data;
        }

        $id = Monitors::create($data['monitor'], $data['groups']);
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
        $target = (string) $request->input('target', '');

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

        $validator = Validator::make($request->all())
            ->required('name', 'Name')
            ->maxLength('name', 'Name', 120)
            ->required('target', 'URL')
            ->in('type', 'Type', Monitors::AVAILABLE_TYPES)
            ->between('interval_seconds', 'Interval', 30, 86400)
            ->between('timeout_seconds', 'Timeout', 1, 120)
            ->between('retries', 'Retries', 0, 5)
            ->custom('group_access', $groups !== [], 'Share the monitor with at least one group, otherwise nobody can see it.');

        if ($type === 'http') {
            $validator->url('target', 'URL');
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

        $authPassword = (string) $request->raw('auth_password');

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
                'config' => [
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
                ],
            ],
            'groups' => $groups,
        ];
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
