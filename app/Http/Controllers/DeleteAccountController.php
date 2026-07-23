<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeleteAccountRequest;
use App\Services\AccountDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class DeleteAccountController extends Controller
{
    public function __construct(
        private readonly AccountDeletionService $accountDeletion,
    ) {}

    /**
     * Public account-deletion information page (Google Play User Data policy).
     */
    public function show(): View
    {
        return view('delete-account');
    }

    /**
     * DELETE /api/account
     *
     * Authenticated users can permanently delete their account from the mobile app.
     */
    public function destroy(DeleteAccountRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $this->accountDeletion->delete($user, $request->validated('password'));

        return response()->json([
            'message' => 'Your account has been deleted successfully.',
        ]);
    }
}
