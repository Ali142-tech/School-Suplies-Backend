<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApprovedVendor
{
    public function handle(Request $request, Closure $next): Response
    {
        $vendor = $request->attributes->get('vendor');
        abort_unless($vendor && $vendor->status === 'active', 403, 'This store must be approved and active before it can make changes.');

        return $next($request);
    }
}
