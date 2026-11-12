<?php
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\ContentPostController;
use App\Http\Controllers\Api\V1\ContentTemplateController;
use App\Http\Controllers\Api\V1\LeadAssignmentRuleController;
use App\Http\Controllers\Api\V1\LeadCatalogController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\LeadFollowUpController;
use App\Http\Controllers\Api\V1\LeadFormController;
use App\Http\Controllers\Api\V1\LeadImportController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\MetaWebhookController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PipelineController;
use App\Http\Controllers\Api\V1\LeadSavedFilterController;
use App\Http\Controllers\Api\V1\LeadTaskController;
use App\Http\Controllers\Api\V1\WhatsAppController;
use App\Http\Controllers\Api\V1\WhatsAppWebhookController;
use App\Http\Controllers\Api\V1\CampaignController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\AiContentController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\SocialAccountController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])->middleware('throttle:10,1');
    // Meta returns the browser here after OAuth; identity is proven by the one-time state value.
    Route::get('/social/meta/callback', [SocialAccountController::class, 'callback'])->middleware('throttle:30,1');
    Route::get('/social/linkedin/callback', [SocialAccountController::class, 'linkedInCallback'])->middleware('throttle:30,1');
    // Meta Webhooks (Phase 4): verification handshake + leadgen events (signature-checked).
    Route::get('/webhooks/meta', [MetaWebhookController::class, 'verify']);
    Route::post('/webhooks/meta', [MetaWebhookController::class, 'receive']);
    // Public website lead forms (Phase 4): honeypot + per-IP throttle inside.
    Route::get('/public/forms/{publicKey}', [LeadFormController::class, 'publicShow']);
    Route::post('/public/forms/{publicKey}/submit', [LeadFormController::class, 'publicSubmit'])->middleware('throttle:30,1');
    // WhatsApp Cloud API webhooks (Phase 5): same handshake + signature scheme.
    Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify']);
    Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive']);

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
            Route::post('/organizations/{organization}/social/accounts/{account}/subscribe-leads', [SocialAccountController::class, 'subscribeLeads']);
            // Phase 4: lead generation
            Route::get('/organizations/{organization}/leads/my-summary', [LeadController::class, 'mySummary']);
            Route::get('/organizations/{organization}/leads', [LeadController::class, 'index']);
            Route::post('/organizations/{organization}/leads', [LeadController::class, 'store']);
            Route::get('/organizations/{organization}/leads/{lead}', [LeadController::class, 'show']);
            Route::put('/organizations/{organization}/leads/{lead}', [LeadController::class, 'update']);
            Route::delete('/organizations/{organization}/leads/{lead}', [LeadController::class, 'destroy']);
            Route::post('/organizations/{organization}/leads/{lead}/notes', [LeadController::class, 'addNote']);
            Route::post('/organizations/{organization}/leads/meta/backfill', [LeadController::class, 'metaBackfill']);
            Route::get('/organizations/{organization}/lead-stages', [LeadCatalogController::class, 'stages']);
            Route::post('/organizations/{organization}/lead-stages', [LeadCatalogController::class, 'storeStage']);
            Route::delete('/organizations/{organization}/lead-stages/{stage}', [LeadCatalogController::class, 'destroyStage']);
            Route::get('/organizations/{organization}/lead-sources', [LeadCatalogController::class, 'sources']);
            Route::post('/organizations/{organization}/lead-sources', [LeadCatalogController::class, 'storeSource']);
            Route::get('/organizations/{organization}/lead-forms', [LeadFormController::class, 'index']);
            Route::post('/organizations/{organization}/lead-forms', [LeadFormController::class, 'store']);
            Route::put('/organizations/{organization}/lead-forms/{form}', [LeadFormController::class, 'update']);
            Route::delete('/organizations/{organization}/lead-forms/{form}', [LeadFormController::class, 'destroy']);
            Route::get('/organizations/{organization}/lead-imports', [LeadImportController::class, 'index']);
            Route::post('/organizations/{organization}/lead-imports', [LeadImportController::class, 'store']);
            Route::get('/organizations/{organization}/lead-rules', [LeadAssignmentRuleController::class, 'index']);
            Route::post('/organizations/{organization}/lead-rules', [LeadAssignmentRuleController::class, 'store']);
            Route::delete('/organizations/{organization}/lead-rules/{rule}', [LeadAssignmentRuleController::class, 'destroy']);
            Route::get('/organizations/{organization}/follow-ups', [LeadFollowUpController::class, 'index']);
            Route::post('/organizations/{organization}/follow-ups', [LeadFollowUpController::class, 'store']);
            Route::post('/organizations/{organization}/follow-ups/{followUp}/complete', [LeadFollowUpController::class, 'complete']);
            Route::post('/organizations/{organization}/follow-ups/{followUp}/cancel', [LeadFollowUpController::class, 'cancel']);
            // Phase 5: pipeline, saved filters, tasks, notifications
            Route::get('/organizations/{organization}/pipeline', [PipelineController::class, 'board']);
            Route::post('/organizations/{organization}/leads/{lead}/move', [PipelineController::class, 'move']);
            Route::get('/organizations/{organization}/lead-filters', [LeadSavedFilterController::class, 'index']);
            Route::post('/organizations/{organization}/lead-filters', [LeadSavedFilterController::class, 'store']);
            Route::delete('/organizations/{organization}/lead-filters/{filter}', [LeadSavedFilterController::class, 'destroy']);
            Route::get('/organizations/{organization}/tasks', [LeadTaskController::class, 'index']);
            Route::post('/organizations/{organization}/tasks', [LeadTaskController::class, 'store']);
            Route::put('/organizations/{organization}/tasks/{task}', [LeadTaskController::class, 'update']);
            Route::delete('/organizations/{organization}/tasks/{task}', [LeadTaskController::class, 'destroy']);
            Route::get('/organizations/{organization}/notifications', [NotificationController::class, 'index']);
            Route::post('/organizations/{organization}/notifications/read-all', [NotificationController::class, 'markAllRead']);
            Route::post('/organizations/{organization}/notifications/{notification}/read', [NotificationController::class, 'markRead']);
            Route::get('/organizations/{organization}/notification-preferences', [NotificationController::class, 'preferences']);
            Route::put('/organizations/{organization}/notification-preferences', [NotificationController::class, 'updatePreferences']);
            // Phase 5: WhatsApp
            Route::get('/organizations/{organization}/whatsapp/accounts', [WhatsAppController::class, 'accounts']);
            Route::post('/organizations/{organization}/whatsapp/accounts', [WhatsAppController::class, 'connect']);
            Route::post('/organizations/{organization}/whatsapp/accounts/{account}/disconnect', [WhatsAppController::class, 'disconnect']);
            Route::get('/organizations/{organization}/whatsapp/accounts/{account}/templates', [WhatsAppController::class, 'templates']);
            Route::post('/organizations/{organization}/whatsapp/accounts/{account}/sync-templates', [WhatsAppController::class, 'syncTemplates']);
            Route::get('/organizations/{organization}/whatsapp/conversations', [WhatsAppController::class, 'conversations']);
            Route::get('/organizations/{organization}/whatsapp/conversations/{conversation}', [WhatsAppController::class, 'conversation']);
            Route::post('/organizations/{organization}/whatsapp/conversations/start', [WhatsAppController::class, 'startForLead']);
            Route::post('/organizations/{organization}/whatsapp/conversations/{conversation}/send-text', [WhatsAppController::class, 'sendText']);
            Route::post('/organizations/{organization}/whatsapp/conversations/{conversation}/send-template', [WhatsAppController::class, 'sendTemplate']);
            // Phase 6: campaigns & analytics
            Route::get('/organizations/{organization}/campaigns', [CampaignController::class, 'index']);
            Route::post('/organizations/{organization}/campaigns', [CampaignController::class, 'store']);
            Route::get('/organizations/{organization}/campaigns/compare', [CampaignController::class, 'compare']);
            Route::post('/organizations/{organization}/campaigns/sync-meta', [CampaignController::class, 'syncMeta']);
            Route::get('/organizations/{organization}/campaigns/{campaign}', [CampaignController::class, 'show']);
            Route::put('/organizations/{organization}/campaigns/{campaign}', [CampaignController::class, 'update']);
            Route::delete('/organizations/{organization}/campaigns/{campaign}', [CampaignController::class, 'destroy']);
            Route::post('/organizations/{organization}/campaigns/{campaign}/ad-groups', [CampaignController::class, 'addAdGroup']);
            Route::post('/organizations/{organization}/campaigns/{campaign}/ads', [CampaignController::class, 'addAd']);
            Route::post('/organizations/{organization}/campaigns/{campaign}/spend', [CampaignController::class, 'addSpend']);
            Route::delete('/organizations/{organization}/campaigns/{campaign}/spend/{record}', [CampaignController::class, 'deleteSpend']);
            // Phase 7: billing + AI assistant
            Route::get('/organizations/{organization}/billing', [BillingController::class, 'overview']);
            Route::get('/organizations/{organization}/billing/packages', [BillingController::class, 'packages']);
            Route::post('/organizations/{organization}/billing/packages', [BillingController::class, 'packageStore']);
            Route::put('/organizations/{organization}/billing/packages/{package}', [BillingController::class, 'packageUpdate']);
            Route::delete('/organizations/{organization}/billing/packages/{package}', [BillingController::class, 'packageDestroy']);
            Route::get('/organizations/{organization}/billing/subscriptions', [BillingController::class, 'subscriptions']);
            Route::post('/organizations/{organization}/billing/subscriptions', [BillingController::class, 'subscribe']);
            Route::put('/organizations/{organization}/billing/subscriptions/{subscription}/status', [BillingController::class, 'subscriptionStatus']);
            Route::post('/organizations/{organization}/billing/invoices', [BillingController::class, 'invoiceStore']);
            Route::put('/organizations/{organization}/billing/invoices/{invoice}', [BillingController::class, 'invoiceUpdate']);
            Route::post('/organizations/{organization}/ai/caption-suggestions', [AiContentController::class, 'suggest']);
            Route::get('/organizations/{organization}/ai/generations', [AiContentController::class, 'history']);
            Route::get('/organizations/{organization}/analytics/overview', [AnalyticsController::class, 'overview']);
            Route::get('/organizations/{organization}/analytics/agency', [AnalyticsController::class, 'agencyOverview']);
            Route::get('/organizations/{organization}/analytics/export/{report}', [AnalyticsController::class, 'export']);
        });
    });
});
