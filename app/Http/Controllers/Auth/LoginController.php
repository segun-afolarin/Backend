<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class LoginController extends Controller
{
    /**
     * Authenticate an existing user and return a fresh token.
     *
     * Security decisions made here:
     *
     *  1. Auth::attempt() uses Hash::check() internally — it is constant-time
     *     and safe against timing attacks. Never compare passwords manually.
     *
     *  2. On failure we return a generic 401 message. We deliberately do NOT
     *     say "email not found" or "wrong password" — that leaks account info.
     *
     *  3. Token rotation: all previous tokens for this user are revoked before
     *     issuing a new one. This invalidates any stolen or stale tokens.
     *     Wrapped in a DB transaction to prevent race conditions under load balancing.
     *
     *  4. We return only safe, non-sensitive user fields in the response.
     *     The password hash never leaves the server.
     */
    public function __invoke(LoginRequest $request): JsonResponse
    {
        // 1. Attempt authentication
        if (! Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'message' => 'The credentials you provided are incorrect.',
            ], 401);
        }

        // 2. Retrieve the authenticated user
        $user = Auth::user();

        // 3. Atomic token rotation — wrapped in transaction to prevent
        //    race conditions when multiple servers handle concurrent logins
        $token = DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            return $user->createToken(
                name: 'auth_token',
                abilities: ['*']
            )->plainTextToken;
        });

        return response()->json([
            'message' => 'Login successful.',
            'user'    => [
                'id'           => $user->id,
                'name'         => $user->name,
                'email'        => $user->email,
                'avatar'       => $user->avatar,        // ← FIX: was missing; avatar disappeared after login
                'phone'        => $user->phone,
                'is_new_user'  => false,                // logging in always shows "Welcome Back"
                'has_location' => $user->has_location,  // tells frontend where to redirect
                // FIX: a returning user who already completed LocationSetup on a
                // previous visit needs these fields in the login response too —
                // otherwise the cached user object briefly loses state/country
                // until the next GET /user call fires.
                'address'      => $user->address,
                'state'        => $user->state,
                'country'      => $user->country,
                'latitude'     => $user->latitude,
                'longitude'    => $user->longitude,
            ],
            'token'      => $token,
            'token_type' => 'Bearer',
        ], 200);
    }
}