<?php
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\ContentPostController;
use App\Http\Controllers\Api\V1\ContentTemplateController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\SocialAccountController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])->middleware('throttle:10,1');
    // Meta returns the browser here after OAuth; identity is proven by the one-time state value.
    Route::get('/social/meta/callback', [SocialAccountController::class, 'callback'])->middleware('throttle:30,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::get('/organizations', [OrganizationController::class, 'index']);
        Route::post('/organizations', [OrganizationController::class, 'store']);

        Route::middleware('tenant')->group(function () {
            Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);
            Route::put('/organizations/{organization}', [OrganizationController::class, 'update']);
            Route::delete('/organizations/{organization}', [OrganizationController::class, 'destroy']);
            Route::get('/organizations/{organization}/members', [MemberController::class, 'index']);
            Route::put('/organizations/{organization}/members/{member}', [MemberController::class, 'update']);
            Route::delete('/organizations/{organization}/members/{member}', [MemberController::class, 'destroy']);
            Route::post('/organizations/{organization}/invitations', [InvitationController::class, 'store']);
            Route::get('/organizations/{organization}/settings', [SettingController::class, 'show']);
            Route::put('/organizations/{organization}/settings', [SettingController::class, 'update']);
            Route::get('/organizations/{organization}/audit-logs', [AuditLogController::class, 'index']);
            Route::get('/organizations/{organization}/dashboard', [DashboardController::class, 'show']);
            // Phase 2: social account connections
            Route::get('/organizations/{organization}/social/platforms', [SocialAccountController::class, 'platforms']);
            Route::get('/organizations/{organization}/social/accounts', [SocialAccountController::class, 'index']);
            Route::get('/organizations/{organization}/social/accounts/{account}', [SocialAccountController::class, 'show']);
            Route::post('/organizations/{organization}/social/accounts/{account}/test', [SocialAccountController::class, 'test']);
            Route::delete('/organizations/{organization}/social/accounts/{account}', [SocialAccountController::class, 'disconnect']);
            Route::get('/organizations/{organization}/social/connect/{platform}', [SocialAccountController::class, 'connect']);
            Route::get('/organizations/{organization}/social/logs', [SocialAccountController::class, 'logs']);
            // Phase 3: media, composer, approvals, publishing, calendar, templates
            Route::get('/organizations/{organization}/media', [MediaController::class, 'index']);
            Route::post('/organizations/{organization}/media', [MediaController::class, 'store']);
            Route::delete('/organizations/{organization}/media/{media}', [MediaController::class, 'destroy']);
            Route::get('/organizations/{organization}/content/destinations', [ContentPostController::class, 'destinations']);
            Route::get('/organizations/{organization}/content/calendar', [ContentPostController::class, 'calendar']);
            Route::get('/organizations/{organization}/content/posts', [ContentPostController::class, 'index']);
            Route::post('/organizations/{organization}/content/posts', [ContentPostController::class, 'store']);
            Route::get('/organizations/{organization}/content/posts/{post}', [ContentPostController::class, 'show']);
            Route::put('/organizations/{organization}/content/posts/{post}', [ContentPostController::class, 'update']);
            Route::delete('/organizations/{organization}/content/posts/{post}', [ContentPostController::class, 'destroy']);
            Route::post('/organizations/{organization}/content/posts/{post}/duplicate', [ContentPostController::class, 'duplicate']);
            Route::get('/organizations/{organization}/content/posts/{post}/previews', [ContentPostController::class, 'previews']);
            Route::post('/organizations/{organization}/content/posts/{post}/submit', [ContentPostController::class, 'submit']);
            Route::post('/organizations/{organization}/content/posts/{post}/approve', [ContentPostController::class, 'approve']);
            Route::post('/organizations/{organization}/content/posts/{post}/reject', [ContentPostController::class, 'reject']);
            Route::post('/organizations/{organization}/content/posts/{post}/request-changes', [ContentPostController::class, 'requestChanges']);
            Route::post('/organizations/{organization}/content/posts/{post}/publish', [ContentPostController::class, 'publish']);
            Route::post('/organizations/{organization}/content/posts/{post}/schedule', [ContentPostController::class, 'schedule']);
            Route::post('/organizations/{organization}/content/posts/{post}/cancel-schedule', [ContentPostController::class, 'cancelSchedule']);
            Route::post('/organizations/{organization}/content/posts/{post}/targets/{target}/retry', [ContentPostController::class, 'retryTarget']);
            Route::get('/organizations/{organization}/templates', [ContentTemplateController::class, 'index']);
            Route::post('/organizations/{organization}/templates', [ContentTemplateController::class, 'store']);
            Route::put('/organizations/{organization}/templates/{template}', [ContentTemplateController::class, 'update']);
            Route::delete('/organizations/{organization}/templates/{template}', [ContentTemplateController::class, 'destroy']);
            Route::post('/organizations/{organization}/templates/seed-starters', [ContentTemplateController::class, 'seedStarters']);
        });
    });
});
