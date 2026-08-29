<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Domain\Groups;
use App\Domain\Incidents;
use App\Domain\Monitors;
use App\Domain\Stats;

final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $range = (string) $request->query('range', '24h');
        if (!isset(Stats::RANGES[$range])) {
            $range = '24h';
        }

        $monitors = Monitors::visible([
            'status' => (string) $request->query('status', 'all'),
            'group' => (int) $request->query('group', '0'),
        ]);

        $ids = array_map(static fn (array $m): int => (int) $m['id'], $monitors);

        return $this->view($request, 'pages/dashboard', [
            'title' => 'Dashboard',
            'needsCharts' => true,
            'monitors' => $monitors,
            'tapes' => Monitors::tapes($ids, 48),
            'counts' => Monitors::statusCounts(),
            'fleetUptime' => Stats::fleetUptime(24),
            'fleetLatency' => Stats::fleetLatency(),
            'downtime' => Incidents::downtimeSeconds(30),
            'openIncidents' => Incidents::openCount(),
            'incidents' => Incidents::recent(6),
            'series' => Stats::fleetSeries($range),
            'range' => $range,
            'groups' => Groups::assignable(),
            'activeGroup' => (int) $request->query('group', '0'),
            'activeStatus' => (string) $request->query('status', 'all'),
        ]);
    }
}
