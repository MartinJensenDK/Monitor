<?php

declare(strict_types=1);

use App\Core\Router;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EntraController;
use App\Http\Controllers\GroupsController;
use App\Http\Controllers\IncidentsController;
use App\Http\Controllers\InstallController;
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

    // Incidents
    $router->get('/incidents', [IncidentsController::class, 'index'])->can('incidents.view');
    $router->post('/incidents/{id}/acknowledge', [IncidentsController::class, 'acknowledge'])->can('incidents.acknowledge');

    // People and access
    $router->get('/users', [UsersController::class, 'index'])->can('users.view');
    $router->get('/users/new', [UsersController::class, 'create'])->can('users.manage');
    $router->post('/users', [UsersController::class, 'store'])->can('users.manage');
    $router->get('/users/{id}/edit', [UsersController::class, 'edit'])->can('users.manage');
    $router->post('/users/{id}', [UsersController::class, 'update'])->can('users.manage');
    $router->post('/users/{id}/delete', [UsersController::class, 'destroy'])->can('users.manage');
    // Signed in is enough: the controller decides whose photo you may see.
    $router->get('/users/{id}/photo', [UsersController::class, 'photo']);

    $router->get('/groups', [GroupsController::class, 'index'])->can('groups.view');
    $router->get('/groups/new', [GroupsController::class, 'create'])->can('groups.manage');
    $router->post('/groups', [GroupsController::class, 'store'])->can('groups.manage');
    $router->get('/groups/{id}', [GroupsController::class, 'show'])->can('groups.view');
    $router->post('/groups/{id}', [GroupsController::class, 'update'])->can('groups.manage');
    $router->post('/groups/{id}/delete', [GroupsController::class, 'destroy'])->can('groups.manage');

    // Settings and profile
    $router->get('/settings', [SettingsController::class, 'index'])->can('settings.view');
    $router->post('/settings', [SettingsController::class, 'update'])->can('settings.manage');
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
