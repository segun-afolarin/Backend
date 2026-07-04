<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Cache\RateLimiting\Limit;

/*
|--------------------------------------------------------------------------
| Rate Limiter
|--------------------------------------------------------------------------
*/

RateLimiter::for('auth', function (Request $request) {
    return Limit::perMinute(5)->by($request->ip());
});

/*
|--------------------------------------------------------------------------
| Public Routes — no token required
|--------------------------------------------------------------------------
*/

Route::middleware(['throttle:auth'])->group(function () {
    Route::post('/register', RegisterController::class);
    Route::post('/login',    LoginController::class);
});

/*
|--------------------------------------------------------------------------
| Protected Routes — valid Sanctum Bearer token required
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum'])->group(function () {

    Route::post('/logout', LogoutController::class);

    // Profile update — called by SettingsProfile.jsx
    Route::post('/profile', [ProfileController::class, 'update']);
    Route::delete('/profile/avatar', [ProfileController::class, 'deleteAvatar']);
    Route::put('/profile/notification-sound', [ProfileController::class, 'updateNotificationSound']);
    Route::put('/profile/password', [ProfileController::class, 'updatePassword']);

    // Notifications — polled every ~20s by DashboardHeader.jsx
    Route::get('/notifications',              [NotificationController::class, 'index']);
    Route::patch('/notifications/read-all',   [NotificationController::class, 'markAllRead']);
    Route::patch('/notifications/{id}/read',  [NotificationController::class, 'markRead']);
    Route::delete('/notifications/{id}',      [NotificationController::class, 'destroy']);

    // Returns the full fresh user object — called on every page load
    // by AuthContext to keep all components in sync with the database.
    Route::get('/user', function (Request $request) {
        $user = $request->user();
        return response()->json([
            'user' => [
                'id'                  => $user->id,
                'name'                => $user->name,
                'email'               => $user->email,
                'phone'               => $user->phone,
                'avatar'              => $user->avatar,   // ← FIX: was 'avatar_url', caused avatar to disappear on refresh
                'location'            => $user->location,
                'notification_sound'  => $user->notification_sound, // ← FIX: was missing, caused sound to reset to "default" on every refresh
                'is_new_user'         => $user->isNewUser(),
                'has_location'        => $user->has_location,
                'address'             => $user->address,
                'state'               => $user->state,
                'country'             => $user->country,
                'latitude'            => $user->latitude,
                'longitude'           => $user->longitude,
            ],
        ]);
    });

    // Location save — called by LocationSetup.jsx after GPS or manual entry
    Route::post('/user/location', [LocationController::class, 'store']);

});