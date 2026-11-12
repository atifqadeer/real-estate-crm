<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InAppNotification;
use App\Models\Organization;
use App\Models\UserNotificationPreference;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request, Organization $organization)
    {
        $items = InAppNotification::where('organization_id', $organization->id)
            ->where('user_id', $request->user()->id)
            ->latest()->paginate(30);
        $unread = InAppNotification::where('organization_id', $organization->id)
            ->where('user_id', $request->user()->id)->whereNull('read_at')->count();
        return response()->json(['data' => $items->items(), 'unread_count' => $unread, 'total' => $items->total()]);
    }

    public function markRead(Request $request, Organization $organization, InAppNotification $notification)
    {
        abort_unless($notification->organization_id === $organization->id && $notification->user_id === $request->user()->id, 404);
        $notification->update(['read_at' => now()]);
        return response()->json(['data' => $notification]);
    }

    public function markAllRead(Request $request, Organization $organization)
    {
        InAppNotification::where('organization_id', $organization->id)
            ->where('user_id', $request->user()->id)->whereNull('read_at')
            ->update(['read_at' => now()]);
        return response()->json(['data' => ['message' => 'All notifications marked as read.']]);
    }

    public function preferences(Request $request, Organization $organization)
    {
        $pref = UserNotificationPreference::where('organization_id', $organization->id)
            ->where('user_id', $request->user()->id)->first();
        return response()->json(['data' => [
            'types' => NotificationService::TYPES,
            'disabled_types' => $pref?->disabled_types ?? [],
        ]]);
    }

    public function updatePreferences(Request $request, Organization $organization)
    {
        $data = $request->validate(['disabled_types' => ['present', 'array'], 'disabled_types.*' => ['string']]);
        $invalid = array_diff($data['disabled_types'], NotificationService::TYPES);
        abort_if($invalid, 422, 'Unknown notification types: ' . implode(', ', $invalid));
        $pref = UserNotificationPreference::updateOrCreate(
            ['organization_id' => $organization->id, 'user_id' => $request->user()->id],
            ['disabled_types' => array_values($data['disabled_types'])]
        );
        return response()->json(['data' => ['disabled_types' => $pref->disabled_types ?? []]]);
    }
}
