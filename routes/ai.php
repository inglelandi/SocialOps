<?php

use App\Http\Middleware\McpAuth;
use App\Mcp\SocialOpsServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

// Claude works on Social Ops through here: claude.ai and the desktop app as a custom connector (OAuth login below),
// Claude Code with the same login or an API token from the Staff page (docs/api.md)
Mcp::web('/mcp', SocialOpsServer::class)->middleware([McpAuth::class, 'throttle:mcp']); // 120 calls a minute per token or login (App\Http\RateLimits)
// the discovery documents and client registration Claude needs, the registration rate limited per address; Passport's
// /oauth/authorize and /oauth/token do the login (their limit: config/passport.php)
Route::middleware('throttle:oauth')->group(fn () => Mcp::oauthRoutes());

// The same server over stdio for a Claude Code running on the server itself: php artisan mcp:start socialops
Mcp::local('socialops', SocialOpsServer::class);
