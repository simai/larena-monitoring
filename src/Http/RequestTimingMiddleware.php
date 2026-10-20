<?php

declare(strict_types=1);

namespace Larena\Monitoring\Http;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Larena\Monitoring\Contracts\Metrics;
use Larena\Monitoring\Runtime\MetricGuard;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records `http.request` (duration, ms) for every request when the response has been sent (terminate).
 * Tags carry codes only: the route NAME (never the URI, path or parameters; 'unnamed' when the route has none),
 * the method, the status class (http_2xx … http_5xx) and the outcome (error for 5xx, ok otherwise).
 * Recording never breaks the request; with the kill switch on, Metrics is NullMetrics and nothing is written.
 *
 * Root appends it as global middleware in bootstrap/app.php:
 *     ->withMiddleware(function (Middleware $middleware): void {
 *         $middleware->append(\Larena\Monitoring\Http\RequestTimingMiddleware::class);
 *     })
 */
final class RequestTimingMiddleware
{
    public const string METRIC = 'http.request';

    /** Request attribute holding the hrtime() start of the request. */
    public const string STARTED_AT = '_larena_monitoring_started_at';

    public function __construct(private readonly Container $container)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $request->attributes->set(self::STARTED_AT, hrtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $started = $request->attributes->get(self::STARTED_AT);
            if (!is_int($started)) {
                return;
            }
            $this->container->make(Metrics::class)->timing(
                self::METRIC,
                (hrtime(true) - $started) / 1_000_000,
                self::tags($request, $response->getStatusCode()),
            );
        } catch (Throwable) {
            // Monitoring never breaks the request.
        }
    }

    /** @return array{route: string, method: string, status_class: string, outcome: string} */
    public static function tags(Request $request, int $status): array
    {
        // The resolver gives null when no route matched (404 before routing); Request::route() hides that in its type.
        $route = ($request->getRouteResolver())();
        $name = $route instanceof Route ? $route->getName() : null;
        $class = intdiv($status, 100);

        return [
            'route' => MetricGuard::code($name),
            'method' => MetricGuard::code($request->getMethod(), 'other'),
            'status_class' => $class >= 1 && $class <= 5 ? 'http_'.$class.'xx' : 'http_other',
            'outcome' => $status >= 500 ? 'error' : 'ok',
        ];
    }
}
