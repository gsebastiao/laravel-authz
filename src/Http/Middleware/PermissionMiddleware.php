<?php

namespace Gsebastiao\LaravelAuthz\Http\Middleware;

use Closure;
use Gsebastiao\LaravelAuthz\Models\Authorization;
use Illuminate\Http\Request;

class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        if (!app(Authorization::class)->hasPermission($permission)) {
            abort(403, 'Unauthorized');
        }

        return $next($request);
    }
}
