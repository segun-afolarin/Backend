<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class RegisterController extends Controller
{
    /**
     * Handle a new user registration request.
     *
     * Flow:
     *  1. RegisterRequest validates and sanitises input before this runs.
     *  2. User::create() mass-assigns only $fillable fields (name, email, password).
     *  3. Because password is cast as 'hashed' in the User model, Laravel
     *     automatically runs bcrypt — we never touch the raw string here.
     *  4. A Sanctum personal access token is issued and returned once.
     *     The plain-text token is only available at this moment; after this
     *     it is stored as a hash and cannot be retrieved again.
     */
    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => $request->password, // bcrypt applied automatically by model cast
        ]);

        // Token scoped with a device name — useful for multi-device token management.
        $token = $user->createToken(
            name: 'auth_token',
            abilities: ['*'] // full access; restrict per-ability for role-based auth
        )->plainTextToken;

        return response()->json([
            'message' => 'Account created successfully.',
            'user'    => [
                'id'           => $user->id,
                'name'         => $user->name,
                'email'        => $user->email,
                'is_new_user'  => $user->isNewUser(), // true for 24h after account creation
                'has_location' => $user->has_location, // always false right after signup
                // FIX: these are null/empty for a brand new account, but included
                // anyway so the user object shape is identical across register,
                // login, /user, and /user/location responses. Frontend code should
                // never have to guess whether a field is "missing" vs "empty".
                'address'      => $user->address,
                'state'        => $user->state,
                'country'      => $user->country,
                'latitude'     => $user->latitude,
                'longitude'    => $user->longitude,
            ],
            'token'      => $token,
            'token_type' => 'Bearer',
        ], 201);
    }
}