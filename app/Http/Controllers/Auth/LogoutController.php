<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogoutController extends Controller
{
    /**
     * Revoke ALL tokens for this user (logout from all devices).
     *
     * Why ALL tokens instead of just the current one:
     *   - currentAccessToken()->delete() only removes the token used
     *     in this request. If the same user is logged in on another
     *     device or browser, those sessions remain active.
     *   - tokens()->delete() wipes every token in the DB for this user,
     *     giving a clean slate across all devices on logout.
     *   - If a token was stolen, this also invalidates it immediately.
     *
     * This route is protected by auth:sanctum middleware in api.php,
     * so $request->user() is always a valid authenticated User instance.
     */
    public function __invoke(Request $request): JsonResponse
    {
        // Revoke all tokens — logs out from every device
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ], 200);
    }
}