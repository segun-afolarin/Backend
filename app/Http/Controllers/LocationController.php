<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class LocationController extends Controller
{
    /**
     * Save the authenticated user's location.
     * POST /api/user/location
     *
     * Called by LocationSetup.jsx after GPS detection or manual entry.
     * Returns the full fresh user object so AuthContext can call
     * refreshUser() and every component immediately sees the updated
     * state/address/has_location fields without a page refresh.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'address'   => ['required', 'string', 'max:500'],
            'state'     => ['required', 'string', 'max:100'],
            'country'   => ['required', 'string', 'max:100'],
            'latitude'  => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $user->update([
            'address'      => $validated['address'],
            'state'        => $validated['state'],
            'country'      => $validated['country'],
            'latitude'     => $validated['latitude'],
            'longitude'    => $validated['longitude'],
            'has_location' => true,
        ]);

        // Return fresh user from DB — not the cached instance
        $fresh = $user->fresh();

        return response()->json([
            'message' => 'Location saved successfully.',
            'user'    => [
                'id'           => $fresh->id,
                'name'         => $fresh->name,
                'email'        => $fresh->email,
                'is_new_user'  => $fresh->isNewUser(),
                'has_location' => $fresh->has_location,
                'address'      => $fresh->address,
                'state'        => $fresh->state,
                'country'      => $fresh->country,
                'latitude'     => $fresh->latitude,
                'longitude'    => $fresh->longitude,
            ],
        ], 200);
    }
}