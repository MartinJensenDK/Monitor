<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Domain\AuditLog;
use App\Domain\Groups;
use App\Domain\Users;

final class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        $user = Auth::user() ?? [];

        return $this->view($request, 'pages/profile', [
            'title' => 'Your profile',
            'user' => $user,
            'groups' => Groups::forUser((int) $user['id']),
        ]);
    }

    public function update(Request $request): Response
    {
        $user = Auth::user() ?? [];
        $id = (int) $user['id'];
        $email = strtolower((string) $request->input('email', ''));
        $password = (string) $request->raw('password');
        $current = (string) $request->raw('current_password');

        $validator = Validator::make($request->all() + ['password' => $password])
            ->required('name', 'Name')
            ->required('email', 'Email')
            ->email('email', 'Email')
            ->custom('email', !Users::emailTaken($email, $id), 'Someone already uses that email address.')
            ->custom(
                'timezone',
                in_array((string) $request->input('timezone', 'UTC'), timezone_identifiers_list(), true),
                'Pick a time zone from the list.'
            );

        if ($password !== '') {
            $validator->password('password')->matches('password_confirmation', 'password');

            if (Auth::attempt((string) $user['email'], $current) === null) {
                $validator->fail('current_password', 'Your current password is not correct.');
            }
        }

        if ($validator->fails()) {
            Session::flashInput($request->all());
            $this->error((string) $validator->firstError());

            return $this->redirect('/profile');
        }

        Users::update($id, [
            'name' => (string) $request->input('name', ''),
            'email' => $email,
            'timezone' => (string) $request->input('timezone', 'UTC'),
        ]);

        if ($password !== '') {
            Users::setPassword($id, $password);
            AuditLog::record('user.password_changed', 'user', $id, 'Changed their own password');
        }

        $this->success('Profile saved.');

        return $this->redirect('/profile');
    }
}
