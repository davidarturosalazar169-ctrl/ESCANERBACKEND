
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
        $schedule->command('scanner:monitor')
            ->everyMinute()
            ->withoutOverlapping();
    })
->withMiddleware(function (Middleware $middleware): void {
    $middleware->validateCsrfTokens(except: ['api/*']);
    $middleware->alias([
        'api.token' => \App\Http\Middleware\ApiTokenAuthentication::class,
    ]);

$middleware->prepend(
    \Illuminate\Http\Middleware\HandleCors::class
);
})
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn ($request, $exception) =>
                $request->is('api/*') || $request->expectsJson()
        );
    })
    ->create();
