<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    /**
     * Update the authenticated user's profile.
     * POST /api/profile
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'name'     => ['sometimes', 'string', 'max:255'],
            'phone'    => ['sometimes', 'nullable', 'string', 'max:20'],
            'location' => ['sometimes', 'nullable', 'string', 'max:500'],
            'avatar'   => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        try {
            DB::transaction(function () use ($request, $user, $validated) {

                if (isset($validated['name']))                $user->name     = $validated['name'];
                if (array_key_exists('phone',    $validated)) $user->phone    = $validated['phone'];
                if (array_key_exists('location', $validated)) $user->location = $validated['location'];

                if ($request->hasFile('avatar') && $request->file('avatar')->isValid()) {

                    // Delete old avatar from storage if it exists
                    if ($user->avatar) {
                        $oldPath = $this->extractStoragePath($user->avatar);
                        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
                            Storage::disk('public')->delete($oldPath);
                        }
                    }

                    // Store new avatar — throws on failure, rolls back transaction
                    $path = $request->file('avatar')->store('avatars', 'public');

                    if (!$path) {
                        throw new \RuntimeException('Avatar upload failed — storage returned no path.');
                    }

                    // Save full public URL to DB using asset()
                    $user->avatar = asset('storage/' . $path);
                }

                $user->save();
            });

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Already handled by Laravel — re-throw so it returns a 422
            throw $e;

        } catch (\RuntimeException $e) {
            Log::error('Profile update failed — upload error', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Avatar upload failed. Please try again.',
            ], 500);

        } catch (\Throwable $e) {
            Log::error('Profile update failed — unexpected error', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return response()->json([
                'message' => 'Something went wrong. Please try again.',
            ], 500);
        }

        $fresh = $user->fresh();

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user'    => $this->userPayload($fresh),
        ], 200);
    }

    /**
     * Delete the authenticated user's avatar.
     * DELETE /api/profile/avatar
     */
    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        try {
            DB::transaction(function () use ($user) {

                if ($user->avatar) {
                    $path = $this->extractStoragePath($user->avatar);

                    // Delete file from disk — non-fatal if already missing
                    if ($path && Storage::disk('public')->exists($path)) {
                        Storage::disk('public')->delete($path);
                    }

                    $user->avatar = null;
                    $user->save();
                }
            });

        } catch (\Throwable $e) {
            Log::error('Avatar delete failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Failed to remove avatar. Please try again.',
            ], 500);
        }

        return response()->json([
            'message' => 'Avatar removed successfully.',
            'user'    => $this->userPayload($user),
        ], 200);
    }

    /**
     * Update the authenticated user's password.
     * PUT /api/profile/password
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $request->validate([
            'current_password' => ['required', 'current_password'],
            'new_password'     => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            DB::transaction(function () use ($request, $user) {
                $user->password = $request->new_password; // bcrypt applied by model cast
                $user->save();

                // Revoke all tokens — forces re-login on all devices after password change
                $user->tokens()->delete();
            });

        } catch (\Throwable $e) {
            Log::error('Password update failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Failed to update password. Please try again.',
            ], 500);
        }

        return response()->json([
            'message' => 'Password updated successfully.',
        ], 200);
    }

    /**
     * Update the authenticated user's notification sound preference.
     * PUT /api/profile/notification-sound
     */
    public function updateNotificationSound(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'notification_sound' => ['required', 'string', 'in:default,chime,alert,bell,pulse,silent'],
        ]);

        try {
            DB::transaction(function () use ($user, $validated) {
                $user->notification_sound = $validated['notification_sound'];
                $user->save();
            });

        } catch (\Throwable $e) {
            Log::error('Notification sound update failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Failed to update notification sound. Please try again.',
            ], 500);
        }

        return response()->json([
            'message' => 'Notification sound updated successfully.',
            'user'    => $this->userPayload($user->fresh()),
        ], 200);
    }

    /**
     * Consistent user payload shape returned by every endpoint.
     * Single source of truth — change once, updates everywhere.
     */
    private function userPayload($user): array
    {
        return [
            'id'                  => $user->id,
            'name'                => $user->name,
            'email'               => $user->email,
            'phone'               => $user->phone,
            'location'            => $user->location,
            'avatar'              => $user->avatar,
            'notification_sound'  => $user->notification_sound,
            'is_new_user'         => $user->isNewUser(),
            'has_location'        => $user->has_location,
            'address'             => $user->address,
            'state'               => $user->state,
            'country'             => $user->country,
            'latitude'            => $user->latitude,
            'longitude'           => $user->longitude,
        ];
    }

    /**
     * Extract the relative storage path from whatever format is saved in DB.
     *
     * Handles both formats gracefully:
     *   - Full URL:  "http://127.0.0.1:8000/storage/avatars/file.jpg" → "avatars/file.jpg"
     *   - Relative:  "/storage/avatars/file.jpg"                      → "avatars/file.jpg"
     */
    private function extractStoragePath(?string $avatar): ?string
    {
        if (!$avatar) return null;

        if (str_contains($avatar, '/storage/')) {
            return ltrim(substr($avatar, strpos($avatar, '/storage/') + strlen('/storage/')), '/');
        }

        return null;
    }
}