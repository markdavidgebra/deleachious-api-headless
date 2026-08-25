<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\User;
use App\Models\LoyaltyPoint;
use App\Services\AuditLogService;
use App\Support\AdminPaginator;
use App\Support\AdminPermissions;
use Illuminate\Http\Request;

class MemberController extends Controller
{
    // GET all members
    public function index(Request $request)
    {
        $admin = $this->actor($request);
        $hideEmail = AdminPermissions::restricts($admin, 'members.hide_email');
        $hidePhone = AdminPermissions::restricts($admin, 'members.hide_phone');
        $hidePoints = AdminPermissions::restricts($admin, 'members.hide_points');

        $query = User::query()
            ->when($request->filled('search'), function ($q) use ($request, $hideEmail, $hidePhone) {
                $term = '%'.$request->search.'%';
                $q->where(function ($inner) use ($term, $hideEmail, $hidePhone) {
                    $inner->where('name', 'like', $term);
                    if (! $hideEmail) {
                        $inner->orWhere('email', 'like', $term);
                    }
                    if (! $hidePhone) {
                        $inner->orWhere('phone', 'like', $term);
                    }
                });
            })
            ->orderByDesc('points');

        $stats = [
            'total'          => User::count(),
            'new_this_week'  => User::where('created_at', '>=', now()->subDays(7))->count(),
            'total_points'   => $hidePoints ? null : (int) User::sum('points'),
        ];

        if (AdminPaginator::requested($request)) {
            $paginator = $query->paginate(AdminPaginator::perPage($request))->withQueryString();
            $paginator->setCollection(
                $paginator->getCollection()->map(fn (User $user) => $this->present($user, $admin))
            );
            $payload = $paginator->toArray();
            $payload['stats'] = $stats;

            return response()->json($payload);
        }

        return response()->json(
            $query->get()->map(fn (User $user) => $this->present($user, $admin))
        );
    }

    // GET single member with full history
    public function show(Request $request, User $user)
    {
        $admin = $this->actor($request);
        $relations = ['redemptions.reward'];

        if (! AdminPermissions::restricts($admin, 'members.hide_points')) {
            $relations[] = 'loyaltyPoints';
        }

        $user->load($relations);

        return response()->json($this->present($user, $admin));
    }

    // ADD or DEDUCT points manually
    public function adjustPoints(Request $request, User $user)
    {
        $request->validate([
            'points'      => 'required|integer',
            'type'        => 'required|in:earned,redeemed,expired,bonus',
            'description' => 'nullable|string',
        ]);

        // Log the points
        LoyaltyPoint::create([
            'user_id'     => $user->id,
            'points'      => $request->points,
            'type'        => $request->type,
            'description' => $request->description,
        ]);

        // Update user total points
        $user->increment('points', $request->points);

        AuditLogService::log(
            'adjusted',
            'member',
            'Points adjusted for ' . $user->name . ': ' . $request->points . ' points (' . $request->type . ')',
            $user
        );

        return response()->json([
            'message' => 'Points adjusted successfully',
            'user'    => $this->present($user->fresh(), $this->actor($request)),
        ]);
    }

    // GET points history of a member
    public function pointsHistory(Request $request, User $user)
    {
        abort_if(AdminPermissions::restricts($this->actor($request), 'members.hide_points'), 403);

        $history = $user->loyaltyPoints()
            ->orderByDesc('created_at')
            ->get();

        return response()->json($history);
    }

    private function actor(Request $request): Admin
    {
        $admin = $request->user();
        abort_unless($admin instanceof Admin, 401);

        return $admin;
    }

    private function present(User $user, Admin $admin): array
    {
        $row = $user->toArray();

        if (AdminPermissions::restricts($admin, 'members.hide_email')) {
            unset($row['email']);
        }

        if (AdminPermissions::restricts($admin, 'members.hide_phone')) {
            unset($row['phone']);
        }

        if (AdminPermissions::restricts($admin, 'members.hide_points')) {
            unset($row['points'], $row['loyalty_points']);
        }

        return $row;
    }
}
