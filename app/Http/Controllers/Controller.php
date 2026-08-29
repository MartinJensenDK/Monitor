<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

abstract class Controller
{
    /** @param array<string,mixed> $data */
    protected function view(Request $request, string $template, array $data = [], string $layout = 'layouts/app'): Response
    {
        App::shareViewData($request);

        return Response::html(View::render($template, $data, $layout));
    }

    protected function redirect(string $to): Response
    {
        return Response::redirect($to);
    }

    protected function back(Request $request): Response
    {
        $referer = $request->header('Referer');
        $path = is_string($referer) ? (parse_url($referer, PHP_URL_PATH) ?: '/') : '/';

        return Response::redirect($path);
    }

    protected function success(string $message): void
    {
        Session::flash('success', $message);
    }

    protected function warn(string $message): void
    {
        Session::flash('warning', $message);
    }

    protected function error(string $message): void
    {
        Session::flash('error', $message);
    }
}
