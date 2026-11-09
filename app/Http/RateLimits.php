<?php

namespace App\Http;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The rate limits on what the world can reach (docs/api.md): the JSON API (`throttle:api`) and the MCP endpoint
 * (`throttle:mcp`) take 120 calls a minute per token or connector login; the connector's sign-up (`throttle:oauth`:
 * the client registration and the Allow page) far fewer per address. Each says no in its own protocol: JSON for the API,
 * a JSON-RPC error for MCP, an OAuth error for the registration, the error page for the Allow page a person sees.
 */
final class RateLimits
{
    public const PER_MINUTE = 120;

    public const REGISTER_PER_MINUTE = 10;

    public const REGISTER_PER_DAY = 100;

    public const AUTHORIZE_PER_MINUTE = 30;

    public static function register(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(self::PER_MINUTE)->by(self::caller($request))
            ->response(fn (Request $request, array $headers) => self::json(['message' => self::busy($headers, 'token')], $headers)));

        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(self::PER_MINUTE)->by(self::caller($request))
            ->response(fn (Request $request, array $headers) => self::json([
                'jsonrpc' => '2.0',
                'id' => is_string($request->input('id')) || is_int($request->input('id')) ? $request->input('id') : null,
                'error' => ['code' => -32000, 'message' => self::busy($headers, 'token or login')],
            ], $headers)));

        RateLimiter::for('oauth', function (Request $request) {
            $ip = $request->ip();
            if ($request->routeIs('mcp.oauth.register')) {
                $answer = fn (Request $request, array $headers) => self::json(['error' => 'temporarily_unavailable', 'error_description' => 'Too many client registrations from this address. Try again in '.($headers['Retry-After'] ?? 60).' seconds.'], $headers);

                return [
                    Limit::perMinute(self::REGISTER_PER_MINUTE)->by("register:$ip")->response($answer),
                    Limit::perDay(self::REGISTER_PER_DAY)->by("register-day:$ip")->response($answer),
                ];
            }

            return $request->routeIs('passport.authorizations.*')
                ? Limit::perMinute(self::AUTHORIZE_PER_MINUTE)->by("authorize:$ip")
                : Limit::none(); // the discovery documents, and /oauth/token, which keeps Passport's own throttle
        });
    }

    /** Who a call counts against: the Staff-page token, else the connector's login, else the bearer token sent, else the address. */
    private static function caller(Request $request): string
    {
        $token = $request->attributes->get('api_token');

        return match (true) {
            $token !== null => "token:{$token->id}",
            $request->user() !== null => 'user:'.$request->user()->getAuthIdentifier(),
            filled($request->bearerToken()) => 'bearer:'.hash('sha256', (string) $request->bearerToken()),
            default => 'ip:'.$request->ip(),
        };
    }

    private static function busy(array $headers, string $per): string
    {
        return 'Too many requests: '.self::PER_MINUTE." calls a minute per $per. Try again in ".($headers['Retry-After'] ?? 60).' seconds.';
    }

    private static function json(array $body, array $headers): JsonResponse
    {
        return response()->json($body, 429, $headers);
    }
}
