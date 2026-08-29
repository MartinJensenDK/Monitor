<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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
        ]);
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
