<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\HelpCenterChatController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
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

// Public, unauthenticated AI chat — throttled per IP since anyone can reach
// it without a token and each request costs a Gemini call.
RateLimiter::for('help-center', function (Request $request) {
    return Limit::perMinute(20)->by($request->ip());
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

// Help Center AI chat — public by design (visitors don't need an account
// to ask how reporting works or to track a report by reference code + email).
Route::middleware(['throttle:help-center'])->group(function () {
    Route::post('/help-center/chat', [HelpCenterChatController::class, 'chat']);
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

    // Reports — called by report-related pages (My Reports, Confirmed, Nearby, submission, confirmation)
    // NOTE: /reports/stats, /reports/rank, and /reports/priority-issues must
    // come before /reports/{report}/... routes so Laravel doesn't try to
    // match "stats"/"rank"/"priority-issues" as a {report} wildcard
    // parameter — that mismatch is what produced a 405 instead of actually
    // hitting the intended controller method.
    Route::get('/reports/stats',               [ReportController::class, 'stats']);
    Route::get('/reports/rank',                [ReportController::class, 'rank']);
    Route::get('/reports/priority-issues',     [ReportController::class, 'priorityIssues']);
    Route::get('/reports/mine',                [ReportController::class, 'mine']);
    Route::get('/reports/confirmed',           [ReportController::class, 'confirmed']);
    Route::get('/reports/nearby',              [ReportController::class, 'nearby']);
    Route::post('/reports',                    [ReportController::class, 'store']);
    Route::post('/reports/{report}/confirm',   [ReportController::class, 'confirm']);
    Route::delete('/reports/{report}',         [ReportController::class, 'destroy']);

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