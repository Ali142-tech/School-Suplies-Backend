<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Local development only: build public file URLs from the address the API was actually reached on.
 * APP_URL often omits the dev-server port (e.g. http://localhost), which makes every uploaded-image URL point at the wrong server.
 * Never active outside APP_ENV=local, so a spoofed Host header cannot influence URLs in staging or production.
 */
class UseRequestUrlForLocalStorage
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('local')) {
            config(['filesystems.disks.public.url' => $request->getSchemeAndHttpHost().'/storage']);
            Storage::forgetDisk('public');
        }

        return $next($request);
    }
}
