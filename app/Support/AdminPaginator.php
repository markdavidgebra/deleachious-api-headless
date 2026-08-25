<?php

namespace App\Support;

use Illuminate\Http\Request;

class AdminPaginator
{
    public static function requested(Request $request): bool
    {
        return $request->has('page') || $request->has('per_page');
    }

    public static function perPage(Request $request, int $default = 25): int
    {
        return min(max($request->integer('per_page', $default), 1), 100);
    }
}
