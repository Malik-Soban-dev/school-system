<?php

namespace App\Http\Controllers;

use App\Services\SchoolNotifications;
use App\Support\SchoolEntitlements;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request, SchoolNotifications $notifications, SchoolEntitlements $entitlements): JsonResponse
    {
        $entitlements->assertFeature('notifications');
        $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:100000']]);

        $rows = $notifications->visible($request->user())->orderByDesc('id')->paginate(30);
        $deliveries = app(TenantContext::class)->table('school_notification_deliveries')->whereIn('notification_id', $rows->pluck('id'))->get(['notification_id', 'channel', 'status'])->groupBy('notification_id');
        $rows->through(function (object $row) use ($deliveries): object {
            $row->deliveries = $deliveries->get($row->id, collect())->values();

            return $row;
        });

        return response()->json(['rows' => $rows,
            'unread' => $notifications->visible($request->user())->whereNull('read_at')->count()]);
    }

    public function read(Request $request, SchoolNotifications $notifications, SchoolEntitlements $entitlements, int $id): JsonResponse
    {
        $entitlements->assertFeature('notifications');
        $query = $notifications->visible($request->user())->where('id', $id);
        abort_unless($query->exists(), 404);
        $query->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => 'Notification marked as read.']);
    }
}
