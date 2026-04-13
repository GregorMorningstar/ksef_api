<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RefreshSessionTimeout
{
    /**
     * Handle an incoming request.
     *
     * Resets session lifetime to 30 minutes on every request.
     * Also tracks session start time for per-user timeout.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            $sessionKey = 'user_session_' . $request->user()->id;
            $lastActivity = session($sessionKey);

            // Set session timeout to 30 minutes
            config(['session.lifetime' => 30]);

            // Track session start time for this user
            session()->put($sessionKey, now()->timestamp);
            session()->save();
        }

        return $next($request);
    }
}
