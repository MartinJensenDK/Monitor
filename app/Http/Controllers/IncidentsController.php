<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Domain\AuditLog;
use App\Domain\Gate;
use App\Domain\Incidents;
use App\Domain\Monitors;

final class IncidentsController extends Controller
{
    public function index(Request $request): Response
    {
        $status = (string) $request->query('status', 'all');
        if (!in_array($status, ['all', 'open', 'resolved'], true)) {
            $status = 'all';
        }

        return $this->view($request, 'pages/incidents', [
            'title' => 'Incidents',
            'incidents' => Incidents::recent(100, $status),
            'status' => $status,
            'openCount' => Incidents::openCount(),
            // Counted here rather than in the page, so the page only says it.
            'acknowledgeable' => Auth::can('incidents.acknowledge')
                ? Incidents::acknowledgeable($status)
                : ['count' => 0, 'upTo' => 0],
        ]);
    }

    /**
     * Acknowledge everything on the tab somebody was looking at.
     *
     * Only what they could have acknowledged one row at a time -- the domain
     * makes the same two checks as SQL -- and only up to the newest incident
     * the page showed them.
     */
    public function acknowledgeAll(Request $request): Response
    {
        $status = (string) $request->input('status', 'all');
        if (!in_array($status, ['all', 'open', 'resolved'], true)) {
            $status = 'all';
        }

        $done = Incidents::acknowledgeAll($status, (int) $request->input('up_to', '0'));

        if ($done['count'] === 0) {
            $this->warn('Nothing was left to acknowledge.');
        } else {
            $noun = $done['count'] === 1 ? 'incident' : 'incidents';
            AuditLog::record(
                'incident.acknowledged_all',
                'incident',
                null,
                sprintf('Acknowledged %d %s at once', $done['count'], $noun),
                ['ids' => $done['ids'], 'status' => $status]
            );
            $this->success(sprintf('%d %s acknowledged.', $done['count'], $noun));
        }

        return $this->redirect('/incidents?status=' . $status);
    }

    public function acknowledge(Request $request): Response
    {
        $incident = Incidents::findVisible($request->intParam('id'));
        if ($incident === null) {
            throw HttpException::notFound('That incident does not exist, or its monitor is not shared with your groups.');
        }

        $monitor = Monitors::find((int) $incident['monitor_id']);
        if ($monitor === null || !Gate::canEdit($monitor)) {
            throw HttpException::forbidden('Acknowledging needs edit access to the monitor.');
        }

        Incidents::acknowledge((int) $incident['id']);
        AuditLog::record('incident.acknowledged', 'incident', (int) $incident['id'], 'Acknowledged incident on ' . $incident['monitor_name']);
        $this->success('Incident acknowledged.');

        return $this->back($request);
    }
}
