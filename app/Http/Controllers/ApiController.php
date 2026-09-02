<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Incidents;
use App\Domain\Locations;
use App\Domain\Monitors;
use App\Domain\Stats;

/**
 * The live endpoint the pages poll. It answers from monitor_status and the
 * rollups only, and returns 304 when nothing changed, so an open dashboard
 * costs almost nothing between checks.
 */
final class ApiController extends Controller
{
    public function live(Request $request): Response
    {
        $scope = (string) $request->query('scope', 'dashboard');
        $monitorId = str_starts_with($scope, 'monitor:') ? (int) substr($scope, 8) : 0;

        $payload = $monitorId > 0 ? $this->monitorPayload($monitorId) : $this->dashboardPayload();

        $etag = '"' . substr(sha1((string) json_encode($payload)), 0, 20) . '"';
        if ($request->header('If-None-Match') === $etag) {
            return Response::notModified($etag);
        }

        return Response::json($payload, 200, [
            'ETag' => $etag,
            'Cache-Control' => 'private, no-cache',
        ]);
    }

    /** @return array<string,mixed> */
    private function dashboardPayload(): array
    {
        $monitors = Monitors::visible();
        $ids = array_map(static fn (array $m): int => (int) $m['id'], $monitors);
        $tapes = Monitors::tapes($ids, 48);

        $rows = [];
        foreach ($monitors as $monitor) {
            $id = (int) $monitor['id'];
            $rows[] = [
                'id' => $id,
                'name' => (string) $monitor['name'],
                'status' => (string) ($monitor['status'] ?? 'pending'),
                'since' => $monitor['status_since'],
                'last_check_at' => $monitor['last_check_at'],
                'response_ms' => $monitor['last_response_ms'] === null ? null : (int) $monitor['last_response_ms'],
                'http_code' => $monitor['last_http_code'] === null ? null : (int) $monitor['last_http_code'],
                'error' => $monitor['last_error'],
                'uptime_24h' => $monitor['uptime_24h'] === null ? null : (float) $monitor['uptime_24h'],
                'tape' => $this->tapePoints($tapes[$id] ?? []),
            ];
        }

        return [
            'generated_at' => gmdate('c'),
            'counts' => Monitors::statusCounts(),
            'fleet' => [
                'uptime_24h' => Stats::fleetUptime(24),
                'latency' => Stats::fleetLatency(),
                'open_incidents' => Incidents::openCount(),
            ],
            'monitors' => $rows,
            'locations' => Locations::overview(),
            'incidents' => array_map(static fn (array $i): array => [
                'id' => (int) $i['id'],
                'monitor_id' => (int) $i['monitor_id'],
                'monitor_name' => (string) $i['monitor_name'],
                'status' => (string) $i['status'],
                'started_at' => (string) $i['started_at'],
                'resolved_at' => $i['resolved_at'],
                'duration_seconds' => $i['duration_seconds'] === null ? null : (int) $i['duration_seconds'],
                'last_error' => $i['last_error'],
            ], Incidents::recent(6)),
        ];
    }

    /** @return array<string,mixed> */
    private function monitorPayload(int $monitorId): array
    {
        $monitor = Monitors::findVisible($monitorId);
        if ($monitor === null) {
            throw HttpException::notFound('That monitor does not exist, or it is not shared with any of your groups.');
        }

        $tapes = Monitors::tapes([$monitorId], 96);

        return [
            'generated_at' => gmdate('c'),
            'monitor' => [
                'id' => $monitorId,
                'status' => (string) ($monitor['status'] ?? 'pending'),
                'since' => $monitor['status_since'],
                'last_check_at' => $monitor['last_check_at'],
                'next_check_at' => $monitor['next_check_at'],
                'response_ms' => $monitor['last_response_ms'] === null ? null : (int) $monitor['last_response_ms'],
                'http_code' => $monitor['last_http_code'] === null ? null : (int) $monitor['last_http_code'],
                'error' => $monitor['last_error'],
                'uptime_24h' => $monitor['uptime_24h'] === null ? null : (float) $monitor['uptime_24h'],
                'uptime_7d' => $monitor['uptime_7d'] === null ? null : (float) $monitor['uptime_7d'],
                'uptime_30d' => $monitor['uptime_30d'] === null ? null : (float) $monitor['uptime_30d'],
                'avg_ms_24h' => $monitor['avg_ms_24h'] === null ? null : (int) $monitor['avg_ms_24h'],
                'p95_ms_24h' => $monitor['p95_ms_24h'] === null ? null : (int) $monitor['p95_ms_24h'],
                'cert_expires_at' => $monitor['cert_expires_at'],
                'tape' => $this->tapePoints($tapes[$monitorId] ?? []),
            ],
            'checks' => array_map(static fn (array $c): array => [
                'checked_at' => (string) $c['checked_at'],
                'status' => (string) $c['status'],
                'response_ms' => $c['response_ms'] === null ? null : (int) $c['response_ms'],
                'http_code' => $c['http_code'] === null ? null : (int) $c['http_code'],
                'error_message' => $c['error_message'],
            ], Monitors::recentChecks($monitorId, 15)),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function tapePoints(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'checked_at' => (string) $r['checked_at'],
            'status' => (string) $r['status'],
            'response_ms' => $r['response_ms'] === null ? null : (int) $r['response_ms'],
            'error_message' => $r['error_message'] ?? null,
        ], $rows);
    }

    /** Chart data for one monitor over a range. */
    public function series(Request $request): Response
    {
        $monitorId = $request->intParam('id');
        $monitor = Monitors::findVisible($monitorId);
        if ($monitor === null) {
            throw HttpException::notFound('That monitor does not exist, or it is not shared with any of your groups.');
        }

        $range = (string) $request->query('range', '24h');
        if (!isset(Stats::RANGES[$range])) {
            $range = '24h';
        }

        return Response::json([
            'range' => $range,
            'series' => Stats::series($monitorId, $range),
            'incidents' => array_map(static fn (array $i): array => [
                'started_at' => strtotime((string) $i['started_at'] . ' UTC'),
                'resolved_at' => $i['resolved_at'] === null ? null : strtotime((string) $i['resolved_at'] . ' UTC'),
            ], Incidents::forMonitor($monitorId, 40)),
        ]);
    }
}
