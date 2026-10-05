<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Domain\AuditLog;
use App\Domain\Devices;
use App\Domain\UpdatePolicies;

/**
 * Making, changing and removing update policies, and choosing their machines.
 *
 * Every route here is held by devices.command_changes -- the same lock as the
 * Install updates and Restart buttons. A policy installs and restarts on a
 * schedule, so writing one is pressing those buttons in advance, and it is
 * held by the people who may press them now.
 */
final class UpdatePoliciesController extends Controller
{
    public function index(Request $request): Response
    {
        $this->requireTable();

        return $this->view($request, 'pages/update-policies', [
            'title' => t('policy.title'),
            'policies' => UpdatePolicies::all(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->requireTable();

        return $this->form($request, null);
    }

    public function store(Request $request): Response
    {
        $this->requireTable();

        [$data, $error] = $this->read($request);
        if ($error !== null) {
            Session::flashInput($request->all());
            $this->error($error);

            return $this->redirect('/devices/updates/new');
        }

        $id = UpdatePolicies::create($data);
        $members = UpdatePolicies::setMembers($id, $this->deviceIds($request));

        AuditLog::record(
            'update_policy.created',
            'update_policy',
            $id,
            sprintf('Created update policy "%s" with %d machine(s)', $data['name'], $members['added'])
        );
        $this->success('Policy created. It acts at its next scheduled time.');

        return $this->redirect('/devices/updates');
    }

    public function edit(Request $request): Response
    {
        return $this->form($request, $this->findOrFail($request->intParam('id')));
    }

    public function update(Request $request): Response
    {
        $policy = $this->findOrFail($request->intParam('id'));

        [$data, $error] = $this->read($request);
        if ($error !== null) {
            Session::flashInput($request->all());
            $this->error($error);

            return $this->redirect('/devices/updates/' . (int) $policy['id']);
        }

        UpdatePolicies::update((int) $policy['id'], $data);
        $members = UpdatePolicies::setMembers((int) $policy['id'], $this->deviceIds($request));

        AuditLog::record(
            'update_policy.updated',
            'update_policy',
            (int) $policy['id'],
            sprintf(
                'Updated update policy "%s": %d machine(s) added, %d removed',
                $data['name'],
                $members['added'],
                $members['removed']
            )
        );
        $this->success('Saved. A change to the schedule applies from its next time.');

        return $this->redirect('/devices/updates');
    }

    public function destroy(Request $request): Response
    {
        $policy = $this->findOrFail($request->intParam('id'));
        $machines = count(UpdatePolicies::memberIds((int) $policy['id']));

        UpdatePolicies::delete((int) $policy['id']);
        AuditLog::record(
            'update_policy.deleted',
            'update_policy',
            (int) $policy['id'],
            sprintf('Deleted update policy "%s"; %d machine(s) left without one', $policy['name'], $machines)
        );
        $this->success('Policy deleted.');

        return $this->redirect('/devices/updates');
    }

    /** @param array<string,mixed>|null $policy */
    private function form(Request $request, ?array $policy): Response
    {
        return $this->view($request, 'pages/update-policy-form', [
            'title' => $policy === null ? t('policy.new') : (string) $policy['name'],
            'policy' => $policy,
            'machines' => UpdatePolicies::machines(),
            'members' => $policy === null ? [] : UpdatePolicies::memberIds((int) $policy['id']),
            'zone' => Config::string('app.timezone', 'UTC'),
        ]);
    }

    /**
     * The form, read and checked. Days arrive as checkbox values 1 to 7 and
     * become the bitmask; a half that is switched on has to name a day, or it
     * would be "on" and never happen.
     *
     * @return array{0:array{name:string,enabled:bool,check_enabled:bool,check_days:int,check_time:string,install_enabled:bool,install_days:int,install_time:string,restart_after:bool},1:?string}
     */
    private function read(Request $request): array
    {
        $data = [
            'name' => trim((string) $request->input('name', '')),
            'enabled' => $request->boolean('enabled'),
            'check_enabled' => $request->boolean('check_enabled'),
            'check_days' => $this->days($request->arrayInput('check_days')),
            'check_time' => UpdatePolicies::cleanTime((string) $request->input('check_time', '06:00')),
            'install_enabled' => $request->boolean('install_enabled'),
            'install_days' => $this->days($request->arrayInput('install_days')),
            'install_time' => UpdatePolicies::cleanTime((string) $request->input('install_time', '03:00')),
            'restart_after' => $request->boolean('restart_after'),
        ];

        $validator = Validator::make($request->all())
            ->required('name', t('policy.name'))
            ->maxLength('name', t('policy.name'), 120)
            ->custom('check_days', !$data['check_enabled'] || $data['check_days'] !== 0,
                'Checking is switched on but no day is ticked, so it would never happen. Tick a day, or switch checking off.')
            ->custom('install_days', !$data['install_enabled'] || $data['install_days'] !== 0,
                'Installing is switched on but no day is ticked, so it would never happen. Tick a day, or switch installing off.')
            // A restart only ever follows an install this policy made. Allowing
            // it with installing off would be a setting that can never act.
            ->custom('restart_after', !$data['restart_after'] || $data['install_enabled'],
                'A restart follows an install this policy made, so it needs "Install on a schedule" switched on as well.');

        return [$data, $validator->fails() ? (string) $validator->firstError() : null];
    }

    /** @param array<int,string> $values */
    private function days(array $values): int
    {
        $mask = 0;
        foreach ($values as $value) {
            $day = (int) $value;
            if ($day >= 1 && $day <= 7) {
                $mask |= 1 << ($day - 1);
            }
        }

        return $mask;
    }

    /** @return array<int,int> */
    private function deviceIds(Request $request): array
    {
        return array_map('intval', $request->arrayInput('devices'));
    }

    /** @return array<string,mixed> */
    private function findOrFail(int $id): array
    {
        $this->requireTable();

        $policy = UpdatePolicies::find($id);
        if ($policy === null) {
            throw HttpException::notFound('That update policy does not exist.');
        }

        return $policy;
    }

    private function requireTable(): void
    {
        if (!UpdatePolicies::isReady() || !Devices::isReady()) {
            throw HttpException::notFound(
                'Automatic updates need a database update that has not run yet. Run php bin/migrate.php on the server.'
            );
        }
    }
}
