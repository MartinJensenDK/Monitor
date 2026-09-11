<?php

declare(strict_types=1);

use App\Core\Router;
use App\Http\Controllers\AgentApiController;
use App\Http\Controllers\AgentScriptController;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DevicesController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\EntraController;
use App\Http\Controllers\GroupsController;
use App\Http\Controllers\IncidentsController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\LocationsController;
use App\Http\Controllers\MonitorsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UsersController;

return static function (Router $router): void {
    // Setup wizard — only reachable until storage/installed.lock exists.
    $router->get('/install', [InstallController::class, 'show'])->public();
    $router->post('/install/test-database', [InstallController::class, 'testDatabase'])->public();
    $router->post('/install', [InstallController::class, 'run'])->public();

    // Session
    $router->get('/login', [AuthController::class, 'showLogin'])->guest();
    $router->post('/login', [AuthController::class, 'login'])->guest();
    $router->post('/logout', [AuthController::class, 'logout']);

    // Password reset. Guest-only: someone already signed in changes their
    // password on the profile page instead.
    $router->get('/forgot-password', [AuthController::class, 'showForgot'])->guest();
    $router->post('/forgot-password', [AuthController::class, 'sendReset'])->guest();
    $router->get('/reset-password/{token}', [AuthController::class, 'showReset'])->guest();
    $router->post('/reset-password/{token}', [AuthController::class, 'resetPassword'])->guest();
    $router->post('/theme', [AuthController::class, 'theme'])->public();

    // Microsoft Entra ID single sign-on
    $router->get('/auth/entra', [EntraController::class, 'start'])->guest();
    $router->get('/auth/entra/callback', [EntraController::class, 'callback'])->guest();

    // Dashboard and live data
    $router->get('/', [DashboardController::class, 'index'])->can('dashboard.view');
    $router->get('/api/live', [ApiController::class, 'live'])->can('dashboard.view');
    $router->get('/api/monitors/{id}/series', [ApiController::class, 'series'])->can('monitors.view');

    // Monitors
    $router->get('/monitors', [MonitorsController::class, 'index'])->can('monitors.view');
    $router->get('/monitors/new', [MonitorsController::class, 'create'])->can('monitors.create');
    $router->post('/monitors', [MonitorsController::class, 'store'])->can('monitors.create');
    $router->get('/monitors/{id}', [MonitorsController::class, 'show'])->can('monitors.view');
    $router->get('/monitors/{id}/edit', [MonitorsController::class, 'edit'])->can('monitors.edit');
    $router->post('/monitors/{id}', [MonitorsController::class, 'update'])->can('monitors.edit');
    $router->post('/monitors/{id}/toggle', [MonitorsController::class, 'toggle'])->can('monitors.pause');
    $router->post('/monitors/{id}/check-now', [MonitorsController::class, 'checkNow'])->can('monitors.edit');
    $router->post('/monitors/{id}/delete', [MonitorsController::class, 'destroy'])->can('monitors.delete');

    // Machines that report about themselves. Servers and Clients are the same
    // page filtered two ways; a machine's own page is addressed by its uuid, so
    // nobody can walk the list by counting.
    $router->get('/servers', [DevicesController::class, 'servers'])->can('devices.view');
    $router->get('/clients', [DevicesController::class, 'clients'])->can('devices.view');

    // Declared before /devices/{uuid}, which would otherwise swallow it.
    $router->get('/devices/enrollment', [EnrollmentController::class, 'index'])->can('devices.enroll');
    $router->post('/devices/enrollment', [EnrollmentController::class, 'store'])->can('devices.enroll');
    $router->post('/devices/enrollment/{id}/revoke', [EnrollmentController::class, 'revoke'])->can('devices.enroll');
    $router->post('/devices/enrollment/{id}/delete', [EnrollmentController::class, 'destroy'])->can('devices.enroll');

    $router->get('/devices/{uuid}', [DevicesController::class, 'show'])->can('devices.view');
    $router->get('/api/devices/{uuid}/logs', [DevicesController::class, 'logs'])->can('devices.view');
    $router->get('/devices/{uuid}/edit', [DevicesController::class, 'edit'])->can('devices.manage');
    $router->post('/devices/{uuid}', [DevicesController::class, 'update'])->can('devices.manage');
    $router->post('/devices/{uuid}/commands', [DevicesController::class, 'command'])->can('devices.command');
    $router->post('/devices/{uuid}/commands/{id}/cancel', [DevicesController::class, 'cancelCommand'])->can('devices.command');
    $router->post('/devices/{uuid}/revoke', [DevicesController::class, 'revoke'])->can('devices.manage');
    $router->post('/devices/{uuid}/delete', [DevicesController::class, 'destroy'])->can('devices.delete');

    // The agent and its installer. Open, because a machine has to fetch the
    // agent before it has anything to prove itself with, and neither script
    // holds a secret.
    $router->get('/agent/{platform}/{script}', [AgentScriptController::class, 'show'])->public();

    // What the agent posts to. No session and no CSRF token: these authenticate
    // with a bearer token, which is not something a browser can be tricked into
    // attaching to a cross-site request.
    $router->post('/api/agent/enroll', [AgentApiController::class, 'enroll'])->api();
    $router->post('/api/agent/report', [AgentApiController::class, 'report'])->api();

    // The live channel: small, frequent, and answering one question only --
    // is there anything waiting for this machine.
    $router->post('/api/agent/poll', [AgentApiController::class, 'poll'])->api();

    // Lines from the agent, sent while it works rather than after.
    $router->post('/api/agent/log', [AgentApiController::class, 'log'])->api();

    // Incidents
    $router->get('/incidents', [IncidentsController::class, 'index'])->can('incidents.view');
    $router->post('/incidents/{id}/acknowledge', [IncidentsController::class, 'acknowledge'])->can('incidents.acknowledge');

    $router->post('/incidents/acknowledge-all', [IncidentsController::class, 'acknowledgeAll'])->can('incidents.acknowledge');
    // People and access
    $router->get('/users', [UsersController::class, 'index'])->can('users.view');
    $router->get('/users/new', [UsersController::class, 'create'])->can('users.manage');
    $router->post('/users', [UsersController::class, 'store'])->can('users.manage');
    $router->get('/users/{id}/edit', [UsersController::class, 'edit'])->can('users.manage');
    $router->post('/users/{id}', [UsersController::class, 'update'])->can('users.manage');
    $router->post('/users/{id}/delete', [UsersController::class, 'destroy'])->can('users.manage');
    // Signed in is enough: the controller decides whose photo you may see.
    $router->get('/users/{id}/photo', [UsersController::class, 'photo']);

    // Places. Editors keep the list, because whoever adds the monitor is the
    // one who knows where the thing actually stands.
    $router->get('/locations', [LocationsController::class, 'index'])->can('locations.manage');
    $router->get('/locations/new', [LocationsController::class, 'create'])->can('locations.manage');
    $router->post('/locations', [LocationsController::class, 'store'])->can('locations.manage');
    $router->get('/locations/{id}', [LocationsController::class, 'edit'])->can('locations.manage');
    $router->post('/locations/{id}', [LocationsController::class, 'update'])->can('locations.manage');
    $router->post('/locations/{id}/delete', [LocationsController::class, 'destroy'])->can('locations.manage');

    $router->get('/groups', [GroupsController::class, 'index'])->can('groups.view');
    $router->get('/groups/new', [GroupsController::class, 'create'])->can('groups.manage');
    $router->post('/groups', [GroupsController::class, 'store'])->can('groups.manage');
    $router->get('/groups/{id}', [GroupsController::class, 'show'])->can('groups.view');
    $router->post('/groups/{id}', [GroupsController::class, 'update'])->can('groups.manage');
    $router->post('/groups/{id}/delete', [GroupsController::class, 'destroy'])->can('groups.manage');

    // Settings and profile
    $router->get('/settings', [SettingsController::class, 'index'])->can('settings.view');
    $router->post('/settings', [SettingsController::class, 'update'])->can('settings.manage');
    $router->post('/settings/agent', [SettingsController::class, 'updateAgent'])->can('settings.manage');
    $router->post('/settings/agent/apply', [SettingsController::class, 'applyAgentDefaults'])->can('settings.manage');
    $router->post('/settings/email', [SettingsController::class, 'updateEmail'])->can('settings.manage');
    $router->post('/settings/test-email', [SettingsController::class, 'testEmail'])->can('settings.manage');
    $router->get('/settings/channels', [SettingsController::class, 'channels'])->can('settings.view');
    $router->post('/settings/channels', [SettingsController::class, 'storeChannel'])->can('settings.manage');
    $router->post('/settings/channels/{id}', [SettingsController::class, 'updateChannel'])->can('settings.manage');
    $router->post('/settings/channels/{id}/delete', [SettingsController::class, 'destroyChannel'])->can('settings.manage');
    $router->post('/settings/entra', [SettingsController::class, 'updateEntra'])->can('settings.manage');
    $router->post('/settings/entra/test', [SettingsController::class, 'testEntra'])->can('settings.manage');
    $router->get('/settings/entra/groups', [SettingsController::class, 'entraGroups'])->can('settings.manage');
    $router->post('/settings/entra/sync', [SettingsController::class, 'syncEntra'])->can('settings.manage');
    $router->get('/settings/activity', [SettingsController::class, 'activity'])->can('audit.view');

    $router->get('/profile', [ProfileController::class, 'show'])->can('profile.edit');
    $router->post('/profile', [ProfileController::class, 'update'])->can('profile.edit');
};
