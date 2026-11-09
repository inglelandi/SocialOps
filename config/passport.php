<?php

// Laravel Passport, the OAuth login of Claude's connector (routes/ai.php). Only what differs from Passport's own defaults,
// which it merges in: its routes (/oauth/authorize, /oauth/token) go through the connector's rate limit (App\Http\RateLimits),
// which limits the Allow page per address and leaves /oauth/token to Passport's own throttle.
return [
    'middleware' => ['throttle:oauth'],
];
