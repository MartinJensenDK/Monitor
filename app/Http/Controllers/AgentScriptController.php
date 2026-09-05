<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Agent\Scripts;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/**
 * Hands out the agent and its installer.
 *
 * Open on purpose: a machine has to fetch the agent before it has a token to
 * fetch it with. There is nothing here worth protecting -- the scripts hold no
 * secrets, and the enrolment key is supplied by whoever runs the installer.
 */
final class AgentScriptController extends Controller
{
    public function show(Request $request): Response
    {
        $name = (string) $request->param('platform') . '/' . (string) $request->param('script');

        $body = Scripts::exists($name) ? Scripts::contents($name) : null;
        if ($body === null) {
            throw HttpException::notFound('No such agent script.');
        }

        return (new Response($body, 200, [
            'Content-Type' => Scripts::contentType($name),
            // Always the current version: an installer cached for a week would
            // enrol machines against an agent that has since been fixed.
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]));
    }
}
