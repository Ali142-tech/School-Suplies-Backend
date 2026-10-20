<?php

namespace App\Http\Middleware;

use App\Models\Vendor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveVendor
{
    public function handle(Request $request, Closure $next): Response
    {
        $vendor = Vendor::where('slug', $request->route('vendor'))->firstOrFail();
        $tenant = $request->attributes->get('tenant_vendor');
        abort_if($tenant && $tenant->id !== $vendor->id, 403, 'You do not belong to this store.');
        $user = $request->user();
        $membership = $vendor->memberships()->where('user_id', $user->id)->first();
        abort_unless($vendor->owner_id === $user->id || $membership, 403, 'You do not belong to this store.');
        $request->attributes->set('vendor', $vendor);
        $request->attributes->set('vendor_role', $vendor->owner_id === $user->id ? 'owner' : $membership->role);

        return $next($request);
    }
}
