<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasKsefCertificate
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $routeName = $request->route()?->getName();

        if (
            !$user
            || in_array($routeName, ['ksef.setup.create', 'ksef.setup.store', 'logout'], true)
        ) {
            return $next($request);
        }

        $profile = $user->ksefProfile()
            ->with('offlineCertificate', 'onlineCertificate')
            ->first();

        if (!$profile?->offlineCertificate || !$profile?->onlineCertificate) {
            return redirect()->route('ksef.setup.create');
        }

        return $next($request);
    }
}