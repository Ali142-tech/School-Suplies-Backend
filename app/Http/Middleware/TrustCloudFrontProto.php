<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CloudFront -> Elastic Beanstalk ALB over HTTP makes the ALB overwrite X-Forwarded-Proto with "http".
 * CloudFront still reports the viewer's protocol in CloudFront-Forwarded-Proto (it must be forwarded to the origin),
 * so honour it, but only when the request really arrived through a trusted proxy.
 */
class TrustCloudFrontProto
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isFromTrustedProxy() && strtolower((string) $request->header('CloudFront-Forwarded-Proto')) === 'https') {
            $request->headers->set('X-Forwarded-Proto', 'https');
        }

        return $next($request);
    }
}
