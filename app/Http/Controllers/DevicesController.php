<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Domain\AuditLog;
use App\Domain\DeviceCommands;
use App\Domain\DeviceLogs;
use App\Domain\DeviceNotices;
use App\Domain\Devices;
use App\Domain\EnrollmentKeys;
use App\Domain\Groups;
use App\Domain\Locations;

/**
 * The two machine pages and everything you can do to one machine.
 *
 * Servers and Clients are the same page with a different value in one column,
 * which is why they share a controller: whatever is true of one list has to be
 * true of the other, and two implementations would eventually disagree.
 */
final class DevicesController extends Controller
{
    public function servers(Request $request): Response
    {
        return $this->index($request, Devices::KIND_SERVER);
    }

    public function clients(Request $request): Response
    {
        return $this->index($request, Devices::KIND_CLIENT);
    }

    private function index(Request $request, string $kind): Response
    {
        $this->requireTable();

        $filters = [
            'status' => (string) $request->query('status', ''),
            'os' => (string) $request->query('os', ''),
            'location' => (int) $request->query('location', '0'),
            'group' => (int) $request->query('group', '0'),
            'q' => (string) $request->query('q', ''),
        ];

        $isServers = $kind === Devices::KIND_SERVER;

        return $this->view($request, 'pages/devices', [
            'title' => $isServers ? t('nav.servers') : t('nav.clients'),
            'kind' => $kind,
            'devices' => Devices::visible($kind, $filters),
            'summary' => Devices::summary($kind),
            'filters' => $filters,
            'view' => $this->deviceView($request),
            'locations' => Devices::locationsWithDevices($kind),
            'groups' => Groups::assignable(),
            // Nothing has enrolled yet and there is no way to start: the empty
            // state should say how, not just that the list is empty.
            'hasKeys' => EnrollmentKeys::anyActive(),
        ]);
    }

    public function show(Request $request): Response
    {
        $device = $this->findOrFail((string) $request->param('uuid'));
        $id = (int) $device['id'];
        $search = trim((string) $request->query('q', ''));

        return $this->view($request, 'pages/device-show', [
            'title' => (string) $device['name'],
            'device' => $device,
            'metrics' => Devices::metrics($id, (int) $request->query('hours', '24')),
            'disks' => Devices::disks($id),
            'updates' => Devices::updates($id),
            'packages' => Devices::packages($id, $search, 400),
            'packageTotal' => Devices::packageCount($id, $search),
            'packageSearch' => $search,
            'services' => Devices::services($id),
            'ports' => Devices::ports($id),
            'events' => Devices::events($id),
            'logs' => DeviceLogs::tail($id, 200),
            'logBusy' => DeviceCommands::isBusy($id),
            'commands' => DeviceCommands::recent($id),
            'catalogue' => DeviceCommands::catalogue(),
            'canEdit' => Devices::canEdit($device),
            'needsCharts' => true,
        ]);
    }

    /**
     * GET /api/devices/{uuid}/logs
     *
     * What the live view reads. Only lines after the cursor it already has,
     * so a page left open all afternoon costs almost nothing, and it says
     * whether a command is still running so the page knows how eagerly to
     * keep asking.
     */
    public function logs(Request $request): Response
    {
        $device = $this->findOrFail((string) $request->param('uuid'));
        $id = (int) $device['id'];
        $since = (int) $request->query('since', '0');

        $lines = DeviceLogs::since($id, $since, 200);

        // Re-read rather than reuse the row the guard fetched: a report may
        // have landed between the two, and the whole point of this answer is
        // to carry what has changed.
        $fresh = Devices::find($id) ?? $device;

        return Response::json([
            'lines' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'level' => (string) $row['level'],
                'message' => (string) $row['message'],
                'at' => local_time((string) $row['received_at'], 'H:i:s'),
            ], $lines),
            'last' => $lines === [] ? $since : (int) $lines[count($lines) - 1]['id'],
            // While something is queued or collected the page polls quickly;
            // otherwise it idles.
            'busy' => DeviceCommands::isBusy($id),
            // What has been asked of the machine, rendered here rather than
            // described. The panel is a pill, a relative time and a cancel
            // form with a token in it, and building that twice -- once in PHP
            // and once in script -- is how the two come to disagree. The page
            // and this answer go through the same template.
            //
            // Behind the same condition the page puts it behind. Somebody who
            // may look at a machine but not act on it does not see this panel,
            // and an endpoint that handed it over anyway would be a way round
            // that -- history of who asked for what, and a cancel form they
            // have no business holding.
            'commands' => Devices::canEdit($device)
                ? View::partial('partials/device-commands', [
                    'commands' => DeviceCommands::recent($id),
                    'uuid' => (string) $device['uuid'],
                ])
                : null,
            // The panels that describe the machine rather than react to a
            // click. Every one of them is out of date the moment a report
            // lands, and a report lands while somebody is looking at the page.
            //
            // Rendered through the same templates the page used, so what
            // arrives an hour in cannot be shaped differently from what was
            // painted at the start. The readings cost a 24-hour query: on this
            // fleet, 49 to 280 rows depending on how often a machine reports,
            // in half a millisecond to two. Small enough that avoiding it would
            // cost more than paying it.
            'status' => View::partial('partials/device-status', ['device' => $fresh]),
            'gauges' => View::partial('partials/device-gauges', [
                'device' => $fresh,
                'metrics' => Devices::metrics($id, 24),
            ]),
            'facts' => View::partial('partials/device-facts', ['device' => $fresh]),
            // The standing facts about it, sent as their parts rather than
            // rendered whole, because the page has to decide whether to show
            // each one at all: dismissed against a value, and news again when
            // that value moves. Every one is sent, true or not -- an empty
            // value is what takes a notice off the screen and forgets the
            // dismissal with it.
            'notices' => array_map(
                static fn (array $notice): array => $notice + [
                    'html' => View::partial('partials/device-notice', ['notice' => $notice]),
                ],
                DeviceNotices::forDevice($fresh)
            ),
        ]);
    }

    public function edit(Request $request): Response
    {
        $device = $this->findEditableOrFail((string) $request->param('uuid'));

        return $this->view($request, 'pages/device-form', [
            'title' => (string) $device['name'],
            'device' => $device,
            'locations' => Locations::isReady() ? Locations::all() : [],
            'assignable' => Groups::assignable(),
            'assigned' => Devices::groupAccess((int) $device['id']),
        ]);
    }

    public function update(Request $request): Response
    {
        $device = $this->findEditableOrFail((string) $request->param('uuid'));

        $name = trim((string) $request->input('name', ''));
        $kind = Devices::normaliseKind((string) $request->input('kind', ''));
        $interval = $request->int('interval_seconds', (int) $device['interval_seconds']);
        $poll = $request->boolean('live') ? $request->int('poll_seconds', Devices::DEFAULT_POLL) : 0;
        $locationId = $request->int('location_id', 0);

        $validator = Validator::make($request->all())
            ->required('name', 'Name')
            ->maxLength('name', 'Name', 190)
            ->maxLength('notes', 'Notes', 500)
            ->custom(
                'interval_seconds',
                $interval >= Devices::MIN_INTERVAL && $interval <= Devices::MAX_INTERVAL,
                sprintf('Reporting interval runs from %d seconds to a day.', Devices::MIN_INTERVAL)
            )
            ->custom(
                'poll_seconds',
                $poll === 0 || ($poll >= Devices::MIN_POLL && $poll <= Devices::MAX_POLL),
                sprintf('Checking for commands runs from %d seconds to an hour.', Devices::MIN_POLL)
            )
            ->custom(
                'location_id',
                $locationId === 0 || !Locations::isReady() || Locations::find($locationId) !== null,
                'That location no longer exists. Pick another, or leave it unplaced.'
            );

        if ($validator->fails()) {
            Session::flashInput($request->all());
            $this->error((string) $validator->firstError());

            return $this->redirect('/devices/' . $device['uuid'] . '/edit');
        }

        $changes = [
            'name' => $name,
            'notes' => trim((string) $request->input('notes', '')) ?: null,
            'interval_seconds' => $interval,
            'poll_seconds' => $poll,
            'log_level' => in_array($request->input('log_level', 'info'), ['error', 'warn', 'info', 'debug'], true)
                ? (string) $request->input('log_level', 'info')
                : 'info',
            'location_id' => $locationId > 0 ? $locationId : null,
            'commands_enabled' => $request->boolean('commands_enabled') ? 1 : 0,
        ];

        // Moving a machine between the two pages is a decision, and it sticks:
        // from here on the agent's own guess stops overruling it.
        if ($kind !== (string) $device['kind']) {
            $changes['kind'] = $kind;
            $changes['kind_locked'] = 1;
        } elseif ($request->boolean('kind_auto')) {
            $changes['kind_locked'] = 0;
        }

        // Switching a machine off here also tells its agent to stop, so it is
        // not left posting into a void.
        $disabled = $request->boolean('disabled');
        if ($disabled && (string) $device['status'] !== 'disabled') {
            $changes['status'] = 'disabled';
            $changes['status_since'] = gmdate('Y-m-d H:i:s');
            Devices::recordEvent((int) $device['id'], 'disabled', $name . ' was switched off in Monitor.', 'warning');
        } elseif (!$disabled && (string) $device['status'] === 'disabled') {
            $changes['status'] = Devices::statusFor(
                $device['last_seen_at'] === null ? null : (string) $device['last_seen_at'],
                Devices::contactInterval(['poll_seconds' => $poll, 'interval_seconds' => $interval])
            );
            $changes['status_since'] = gmdate('Y-m-d H:i:s');
            Devices::recordEvent((int) $device['id'], 'enabled', $name . ' was switched back on.', 'info');
        }

        Devices::update((int) $device['id'], $changes);

        /** @var array<int,string> $access */
        $access = is_array($request->raw('group_access')) ? $request->raw('group_access') : [];
        Devices::setGroupAccess(
            (int) $device['id'],
            $access,
            Auth::isAdmin() ? null : Auth::groupIds()
        );

        AuditLog::record('device.updated', 'device', (int) $device['id'], 'Updated ' . $name);
        $this->success('Saved.');

        return $this->redirect('/devices/' . $device['uuid']);
    }

    public function destroy(Request $request): Response
    {
        $device = $this->findEditableOrFail((string) $request->param('uuid'));
        $kind = (string) $device['kind'];

        Devices::delete((int) $device['id']);
        AuditLog::record('device.deleted', 'device', (int) $device['id'], 'Deleted ' . $device['name']);

        // Deleting the row does not uninstall anything. The agent will try to
        // report, be told its token is unknown, and say so in its own log --
        // which is worth mentioning, because the machine is still out there.
        $this->success($device['name'] . ' removed. Run the installer with --uninstall on the machine itself to stop the agent.');

        return $this->redirect($kind === Devices::KIND_SERVER ? '/servers' : '/clients');
    }

    /**
     * Queue one of the four named commands.
     */
    public function command(Request $request): Response
    {
        $device = $this->findEditableOrFail((string) $request->param('uuid'));
        $command = (string) $request->input('command', '');

        if (!DeviceCommands::exists($command)) {
            throw HttpException::notFound('That is not a command this agent knows.');
        }

        if ((int) $device['commands_enabled'] !== 1) {
            $this->error('Commands are switched off for this machine.');

            return $this->redirect('/devices/' . $device['uuid']);
        }

        // The two that change a machine are an administrator's call, on top of
        // the machine's own consent at install time.
        if (DeviceCommands::changesThings($command) && !Auth::can('devices.command_changes')) {
            throw HttpException::forbidden('Only an administrator can ask a machine to change itself.');
        }

        DeviceCommands::queue((int) $device['id'], $command);

        AuditLog::record(
            'device.command',
            'device',
            (int) $device['id'],
            sprintf('Queued "%s" for %s', DeviceCommands::label($command), $device['name'])
        );

        Devices::recordEvent(
            (int) $device['id'],
            'command_queued',
            sprintf('%s queued "%s".', Auth::user()['name'] ?? 'Somebody', DeviceCommands::label($command)),
            'info'
        );

        // A queued command against a machine nobody has heard from looks
        // exactly like a queued command against one that is about to run it,
        // which is how "I pressed the button and nothing happened" happens.
        if ((string) $device['status'] === 'online') {
            $this->success(sprintf(
                '%s queued. It runs at the next check-in.',
                DeviceCommands::label($command)
            ));
        } else {
            $this->warn(sprintf(
                '%s queued, but %s was last heard from %s. It will wait until the machine comes back, and expires in an hour.',
                DeviceCommands::label($command),
                $device['name'],
                $device['last_seen_at'] === null ? 'when it enrolled' : format_since((string) $device['last_seen_at'])
            ));
        }

        return $this->redirect('/devices/' . $device['uuid']);
    }

    public function cancelCommand(Request $request): Response
    {
        $device = $this->findEditableOrFail((string) $request->param('uuid'));

        if (DeviceCommands::cancel((int) $device['id'], $request->intParam('id'))) {
            $this->success('Command cancelled.');
        } else {
            $this->warn('That command had already been collected.');
        }

        return $this->redirect('/devices/' . $device['uuid']);
    }

    /**
     * Cut a machine off without deleting what it reported.
     *
     * The token stops working immediately, which is the thing to reach for
     * when a machine is stolen or its config file leaked.
     */
    public function revoke(Request $request): Response
    {
        $device = $this->findEditableOrFail((string) $request->param('uuid'));

        Devices::update((int) $device['id'], [
            'revoked_at' => gmdate('Y-m-d H:i:s'),
            'status' => 'disabled',
            'status_since' => gmdate('Y-m-d H:i:s'),
        ]);

        Devices::recordEvent((int) $device['id'], 'revoked', $device['name'] . "'s token was revoked.", 'critical');
        AuditLog::record('device.revoked', 'device', (int) $device['id'], 'Revoked token for ' . $device['name']);

        $this->success($device['name'] . ' can no longer report. Run the installer again on the machine to enrol it afresh.');

        return $this->redirect('/devices/' . $device['uuid']);
    }

    /** @return array<string,mixed> */
    /**
     * Cards or a list.
     *
     * The address decides, so a link can carry a view and a filtered list can
     * be sent to somebody exactly as it looked. Failing that, whatever the
     * viewer last chose, which they keep in a cookie of their own the way they
     * keep the theme -- it is a preference about this screen, not a fact about
     * the fleet, and it has no business in the database.
     */
    private function deviceView(Request $request): string
    {
        $asked = (string) $request->query('view', '');
        if (in_array($asked, ['cards', 'list'], true)) {
            return $asked;
        }

        return $request->cookie('monitor_devices_view') === 'list' ? 'list' : 'cards';
    }

    /** @return array<string,mixed> */
    private function findOrFail(string $uuid): array
    {
        $this->requireTable();

        $device = Devices::findVisible($uuid);
        if ($device === null) {
            throw HttpException::notFound('That machine does not exist, or is not shared with you.');
        }

        return $device;
    }

    /** @return array<string,mixed> */
    private function findEditableOrFail(string $uuid): array
    {
        $device = $this->findOrFail($uuid);

        if (!Devices::canEdit($device)) {
            throw HttpException::forbidden('You can see this machine but not change it.');
        }

        return $device;
    }

    /**
     * Agents arrived in migration 007. Until it has run there is nothing to
     * read, so say that rather than letting a query fail.
     */
    private function requireTable(): void
    {
        if (!Devices::isReady()) {
            throw HttpException::notFound(
                'Servers and clients need a database update that has not run yet. Run php bin/migrate.php on the server.'
            );
        }
    }
}
