<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Support\AdminPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminCan
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $admin = $request->user();

        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        foreach ($permissions as $permission) {
            if (AdminPermissions::allows($admin, $permission)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'You do not have access to this function.',
        ], 403);
    }
}
