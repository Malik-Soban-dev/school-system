<?php

namespace App\Http\Controllers;

use App\Services\NotificationDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NotificationPreferenceController extends Controller
{
    public function show(Request $request, NotificationDelivery $delivery): JsonResponse
    {
        $preferences = DB::table('school_notification_preferences')->where('user_id', $request->user()->id)->first();

        return response()->json(['email' => $request->user()->email, 'whatsapp_phone' => $preferences?->whatsapp_phone ?? '',
            'whatsapp_enabled' => $preferences?->whatsapp_consented_at !== null, 'email_enabled' => $preferences?->email_consented_at !== null,
            'whatsapp_ready' => $delivery->ready('whatsapp'), 'email_ready' => $delivery->ready('email')]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'current_password'], 'whatsapp_phone' => ['nullable', 'regex:/^\+[1-9]\d{7,14}$/'],
            'whatsapp_enabled' => ['required', 'boolean'], 'email_enabled' => ['required', 'boolean']]);
        if ($data['whatsapp_enabled'] && empty($data['whatsapp_phone'])) {
            throw ValidationException::withMessages(['whatsapp_phone' => 'Enter your own WhatsApp number including the country code.']);
        }
        if ($data['email_enabled'] && ! filter_var($request->user()->email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email_enabled' => 'Ask your school to connect a valid email address to your account first.']);
        }
        $old = DB::table('school_notification_preferences')->where('user_id', $request->user()->id)->first();
        $phone = $data['whatsapp_phone'] ?? null;
        DB::table('school_notification_preferences')->updateOrInsert(['user_id' => $request->user()->id], [
            'whatsapp_phone' => $phone,
            'whatsapp_consented_at' => $data['whatsapp_enabled'] ? ($old?->whatsapp_phone === $phone ? ($old?->whatsapp_consented_at ?? now()) : now()) : null,
            'email_consented_at' => $data['email_enabled'] ? ($old?->email_consented_at ?? now()) : null,
            'created_at' => $old?->created_at ?? now(), 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Notification preferences saved. Delivery starts when your school connects the selected services.']);
    }
}
