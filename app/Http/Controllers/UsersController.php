<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Rbac;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Domain\AuditLog;
use App\Domain\Groups;
use App\Domain\Users;

final class UsersController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view($request, 'pages/users', [
            'title' => 'People',
            'users' => Users::all(trim((string) $request->query('q', ''))),
            'search' => trim((string) $request->query('q', '')),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view($request, 'pages/user-form', [
            'title' => 'Add person',
            'user' => null,
            'groups' => Groups::all(),
            'memberOf' => [],
        ]);
    }

    public function edit(Request $request): Response
    {
        $user = $this->findOrFail($request->intParam('id'));

        return $this->view($request, 'pages/user-form', [
            'title' => (string) $user['name'],
            'user' => $user,
            'groups' => Groups::all(),
            'memberOf' => array_map(
                static fn (array $g): int => (int) $g['id'],
                Groups::forUser((int) $user['id'])
            ),
        ]);
    }

    public function store(Request $request): Response
    {
        $email = strtolower((string) $request->input('email', ''));

        $validator = Validator::make($request->all() + ['password' => (string) $request->raw('password')])
            ->required('name', 'Name')
            ->required('email', 'Email')
            ->email('email', 'Email')
            ->in('role', 'Role', Rbac::roles())
            ->required('password', 'Password')
            ->password('password')
            ->custom('email', !Users::emailTaken($email), 'Someone already uses that email address.');

        if ($validator->fails()) {
            Session::flashInput($request->all());
            $this->error((string) $validator->firstError());

            return $this->redirect('/users/new');
        }

        $id = Users::create([
            'name' => (string) $request->input('name', ''),
            'email' => $email,
            'password' => (string) $request->raw('password'),
            'role' => (string) $request->input('role', 'viewer'),
            'status' => $request->boolean('active') ? 'active' : 'disabled',
            'timezone' => (string) $request->input('timezone', 'UTC'),
        ]);

        Users::syncGroups($id, array_map('intval', $request->arrayInput('groups')));
        AuditLog::record('user.created', 'user', $id, 'Added ' . $email . ' as ' . $request->input('role', 'viewer'));
        $this->success('Person added.');

        return $this->redirect('/users');
    }

    public function update(Request $request): Response
    {
        $user = $this->findOrFail($request->intParam('id'));
        $id = (int) $user['id'];
        $email = strtolower((string) $request->input('email', ''));
        $password = (string) $request->raw('password');
        $role = (string) $request->input('role', $user['role']);
        $active = $request->boolean('active');

        $validator = Validator::make($request->all() + ['password' => $password])
            ->required('name', 'Name')
            ->required('email', 'Email')
            ->email('email', 'Email')
            ->in('role', 'Role', Rbac::roles())
            ->custom('email', !Users::emailTaken($email, $id), 'Someone already uses that email address.');

        if ($password !== '') {
            $validator->password('password');
        }

        // Never let the last active admin lose the keys.
        $losingAdmin = $user['role'] === Rbac::ROLE_ADMIN && ($role !== Rbac::ROLE_ADMIN || !$active);
        if ($losingAdmin && Users::adminCount() <= 1) {
            $validator->fail('role', 'This is the last active administrator. Promote someone else first.');
        }

        if ($validator->fails()) {
            Session::flashInput($request->all());
            $this->error((string) $validator->firstError());

            return $this->redirect('/users/' . $id . '/edit');
        }

        Users::update($id, [
            'name' => (string) $request->input('name', ''),
            'email' => $email,
            'role' => $role,
            'status' => $active ? 'active' : 'disabled',
            'timezone' => (string) $request->input('timezone', $user['timezone']),
        ]);

        if ($password !== '') {
            Users::setPassword($id, $password);
        }

        Users::syncGroups($id, array_map('intval', $request->arrayInput('groups')));
        AuditLog::record('user.updated', 'user', $id, 'Updated ' . $email);
        $this->success('Changes saved.');

        return $this->redirect('/users');
    }

    public function destroy(Request $request): Response
    {
        $user = $this->findOrFail($request->intParam('id'));
        $id = (int) $user['id'];

        if ($id === Auth::id()) {
            $this->error('You cannot delete your own account.');

            return $this->redirect('/users');
        }

        if ($user['role'] === Rbac::ROLE_ADMIN && Users::adminCount() <= 1) {
            $this->error('This is the last active administrator. Promote someone else first.');

            return $this->redirect('/users');
        }

        Users::delete($id);
        AuditLog::record('user.deleted', 'user', $id, 'Deleted ' . $user['email']);
        $this->success('Person removed.');

        return $this->redirect('/users');
    }

    /** @return array<string,mixed> */
    private function findOrFail(int $id): array
    {
        $user = Users::find($id);
        if ($user === null) {
            throw HttpException::notFound('That person does not exist.');
        }

        return $user;
    }
}
