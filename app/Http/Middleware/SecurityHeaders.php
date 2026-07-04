<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Attach security headers to every HTTP response.
     *
     * What each header does:
     *
     *  X-Content-Type-Options: nosniff
     *    — Prevents browsers from MIME-sniffing a response away from the
     *      declared Content-Type. Stops content-injection attacks.
     *
     *  X-Frame-Options: DENY
     *    — Prevents your app from being embedded in an <iframe>.
     *      Blocks clickjacking attacks entirely.
     *
     *  X-XSS-Protection: 1; mode=block
     *    — Legacy IE/Chrome XSS filter. Still useful as a fallback.
     *
     *  Referrer-Policy: strict-origin-when-cross-origin
     *    — Only sends the origin (no path/query) as the Referer header
     *      on cross-origin requests. Prevents leaking sensitive URL params.
     *
     *  Strict-Transport-Security
     *    — Forces HTTPS for 1 year (31536000 seconds). includeSubDomains
     *      covers all subdomains. Remove in local dev if you're on HTTP.
     *
     *  Content-Security-Policy
     *    — Restricts which sources can load scripts, styles, images etc.
     *      Adjust the values below to match your actual React origin in prod.
     *
     *  Permissions-Policy
     *    — Disables browser features your app doesn't use (camera, mic, GPS).
     *      Reduces the attack surface if JS is ever compromised.
     *
     *  Cache-Control: no-store
     *    — Tells browsers and proxies never to cache API responses.
     *      Prevents a logged-out user's data from being read from cache.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set(
            'Strict-Transport-Security',
            'max-age=31536000; includeSubDomains; preload'
        );
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; " .
            "script-src 'self'; " .
            "style-src 'self' 'unsafe-inline'; " .
            "img-src 'self' data:; " .
            "font-src 'self'; " .
            "connect-src 'self'; " .
            "frame-ancestors 'none';"
        );
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=()'
        );
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');

        return $response;
    }
}