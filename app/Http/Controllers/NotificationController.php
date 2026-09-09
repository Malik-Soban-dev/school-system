<?php

namespace App\Http\Controllers;

use App\Services\SchoolNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request, SchoolNotifications $notifications): JsonResponse
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:100000']]);

        $rows = $notifications->visible($request->user())->orderByDesc('id')->paginate(30);
        $deliveries = DB::table('school_notification_deliveries')->whereIn('notification_id', $rows->pluck('id'))->get(['notification_id', 'channel', 'status'])->groupBy('notification_id');
        $rows->through(function (object $row) use ($deliveries): object {
            $row->deliveries = $deliveries->get($row->id, collect())->values();

            return $row;
        });

        return response()->json(['rows' => $rows,
            'unread' => $notifications->visible($request->user())->whereNull('read_at')->count()]);
    }

    public function read(Request $request, SchoolNotifications $notifications, int $id): JsonResponse
    {
        $query = $notifications->visible($request->user())->where('id', $id);
        abort_unless($query->exists(), 404);
        $query->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => 'Notification marked as read.']);
    }
}
