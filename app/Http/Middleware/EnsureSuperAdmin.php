<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user();

        if (! $admin instanceof Admin || ! $admin->hasFullAccess()) {
            return response()->json([
                'message' => 'Only a super admin can manage roles.',
            ], 403);
        }

        return $next($request);
    }
}
