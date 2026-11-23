<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\HttpFoundation\Response;

/**
 * A route id that is not a number is a 404, not a 500.
 *
 * About 130 API routes take `{id}` (or `{agentId}`, `{hire}`, …) without a
 * numeric constraint and hand it to a controller that declares it `int`. PHP
 * then refuses the call with a TypeError, which the app reports as a 500 and
 * writes to the error log with a stack trace. Nothing was ever going to be
 * found at `/api/flows/undefined`, so the 500 was not an error worth an alarm,
 * only noise in the place errors are looked for: on 23 Nov 2026 one client
 * script sent `DELETE /api/flows/undefined` every two minutes for two days,
 * 845 stack traces burying everything else.
 *
 * The rule is read off the controller rather than written per route: a
 * parameter the method declares `int` must arrive as digits. That only ever
 * changes requests that were going to fail anyway — a numeric id passes
 * untouched — so adding it cannot break a route that works today, and a route
 * added tomorrow is covered without anyone remembering `whereNumber()`.
 */
class RejectMalformedRouteIds
{
    /**
     * The int parameters of each controller action, by action name. Reflection
     * runs once per action per worker, not once per request.
     *
     * @var array<string, array<int, string>>
     */
    private static array $intParameters = [];

    /**
     * Longer than this, a run of digits no longer fits in a PHP int and the
     * call would fail the same way.
     */
    private const MAX_DIGITS = 18;

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($route instanceof Route) {
            foreach (self::intParameters($route) as $name) {
                $value = $route->parameter($name);

                if ($value === null || is_int($value)) {
                    continue;
                }

                if (! is_string($value) || ! ctype_digit($value) || strlen($value) > self::MAX_DIGITS) {
                    abort(404);
                }
            }
        }

        return $next($request);
    }

    /**
     * @return array<int, string>
     */
    private static function intParameters(Route $route): array
    {
        $action = $route->getActionName();

        if (array_key_exists($action, self::$intParameters)) {
            return self::$intParameters[$action];
        }

        $names = [];

        if (str_contains($action, '@')) {
            [$class, $method] = explode('@', $action, 2);

            if (method_exists($class, $method)) {
                $routeParameters = $route->parameterNames();

                foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
                    $type = $parameter->getType();

                    if ($type instanceof ReflectionNamedType
                        && $type->getName() === 'int'
                        && in_array($parameter->getName(), $routeParameters, true)) {
                        $names[] = $parameter->getName();
                    }
                }
            }
        }

        return self::$intParameters[$action] = $names;
    }
}
