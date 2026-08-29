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

        AuditLog::record('group.created', 'group', $id, 'Created group ' . $request->input('name', ''));
        $this->success('Group created.');

        return $this->redirect('/groups/' . $id);
    }

    public function update(Request $request): Response
    {
        $group = $this->findOrFail($request->intParam('id'));

        if (Groups::isManaged($group)) {
            $this->error('This group is maintained in Microsoft Entra ID. Change it there and it will sync back.');

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
