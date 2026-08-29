<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Domain\AuditLog;
use App\Domain\Groups;
use App\Domain\Users;

final class GroupsController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view($request, 'pages/groups', [
            'title' => 'Groups',
            'groups' => Groups::all(),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view($request, 'pages/group-form', [
            'title' => 'New group',
            'group' => null,
            'members' => [],
            'users' => Users::all(),
            'monitors' => [],
            'grantsRole' => null,
        ]);
    }

    public function show(Request $request): Response
    {
        $group = $this->findOrFail($request->intParam('id'));

        return $this->view($request, 'pages/group-form', [
            'title' => (string) $group['name'],
            'group' => $group,
            'members' => Groups::memberIds((int) $group['id']),
            'users' => Users::all(),
            'monitors' => Groups::monitors((int) $group['id']),
            'grantsRole' => Groups::roleMapping((int) $group['id']),
        ]);
    }

    public function store(Request $request): Response
    {
        $validator = Validator::make($request->all())
            ->required('name', 'Group name')
            ->maxLength('name', 'Group name', 120);

        if ($validator->fails()) {
            Session::flashInput($request->all());
            $this->error((string) $validator->firstError());

            return $this->redirect('/groups/new');
        }

        $id = Groups::create((string) $request->input('name', ''), (string) $request->input('description', ''));
        Groups::syncMembers($id, array_map('intval', $request->arrayInput('members')));
        Groups::setRoleMapping($id, $this->roleInput($request));

        AuditLog::record('group.created', 'group', $id, 'Created group ' . $request->input('name', ''));
        $this->success('Group created.');

        return $this->redirect('/groups/' . $id);
    }

    public function update(Request $request): Response
    {
        $group = $this->findOrFail($request->intParam('id'));

        if (Groups::isManaged($group)) {
            // Name and members come from the directory, but which role the
            // group grants is this application's decision, so that still saves.
            Groups::setRoleMapping((int) $group['id'], $this->roleInput($request));
            $this->success('Role mapping saved. Name and members keep coming from Entra ID.');

            return $this->redirect('/groups/' . $group['id']);
        }

        $validator = Validator::make($request->all())
            ->required('name', 'Group name')
            ->maxLength('name', 'Group name', 120);

        if ($validator->fails()) {
            Session::flashInput($request->all());
            $this->error((string) $validator->firstError());

            return $this->redirect('/groups/' . $group['id']);
        }

        Groups::update((int) $group['id'], (string) $request->input('name', ''), (string) $request->input('description', ''));
        Groups::syncMembers((int) $group['id'], array_map('intval', $request->arrayInput('members')));
        Groups::setRoleMapping((int) $group['id'], $this->roleInput($request));

        AuditLog::record('group.updated', 'group', (int) $group['id'], 'Updated group ' . $request->input('name', ''));
        $this->success('Group saved.');

        return $this->redirect('/groups/' . $group['id']);
    }

    public function destroy(Request $request): Response
    {
        $group = $this->findOrFail($request->intParam('id'));

        if (Groups::isManaged($group)) {
            $this->error('Entra-managed groups are removed in Entra ID, not here.');

            return $this->redirect('/groups');
        }

        Groups::delete((int) $group['id']);
        AuditLog::record('group.deleted', 'group', (int) $group['id'], 'Deleted group ' . $group['name']);
        $this->success('Group deleted. Monitors shared only with it are now visible to admins only.');

        return $this->redirect('/groups');
    }

    /** Which role this group grants, or null for "no opinion". */
    private function roleInput(Request $request): ?string
    {
        $role = (string) $request->input('grants_role', '');

        return in_array($role, ['admin', 'editor', 'viewer'], true) ? $role : null;
    }

    /** @return array<string,mixed> */
    private function findOrFail(int $id): array
    {
        $group = Groups::find($id);
        if ($group === null) {
            throw HttpException::notFound('That group does not exist.');
        }

        return $group;
    }
}
