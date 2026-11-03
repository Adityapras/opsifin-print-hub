<?php

use App\Console\Commands\BootstrapAgentSiteCommand;
use App\Console\Commands\GrantTenantUserCommand;
use App\Console\Commands\ProcessCallbacksCommand;
use App\Console\Commands\PullSourcesCommand;
use App\Http\Middleware\AuthenticateAgent;
use App\Http\Middleware\EnsureAgentVersionIsSupported;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        BootstrapAgentSiteCommand::class,
        GrantTenantUserCommand::class,
        PullSourcesCommand::class,
        ProcessCallbacksCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'agent.auth' => AuthenticateAgent::class,
            'agent.version' => EnsureAgentVersionIsSupported::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('agent/v1/*') || $request->expectsJson(),
        );
    })->create();
