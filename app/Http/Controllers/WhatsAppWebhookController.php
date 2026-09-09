<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $token = (string) config('services.whatsapp.verify_token');
        abort_unless($token !== '' && $request->query('hub_mode', $request->query('hub.mode')) === 'subscribe'
            && hash_equals($token, (string) $request->query('hub_verify_token', $request->query('hub.verify_token'))), 403);

        return response((string) $request->query('hub_challenge', $request->query('hub.challenge')), 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request): Response
    {
        $secret = (string) config('services.whatsapp.app_secret');
        abort_unless($secret !== '' && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), (string) $request->header('X-Hub-Signature-256')), 403);
        $data = $request->validate(['entry' => ['required', 'array', 'max:100'], 'entry.*.changes' => ['required', 'array', 'max:100']]);
        foreach ($data['entry'] as $entry) {
            foreach ($entry['changes'] as $change) {
                foreach (array_slice($change['value']['statuses'] ?? [], 0, 100) as $status) {
                    if (! is_string($status['id'] ?? null) || ! in_array($status['status'] ?? null, ['sent', 'delivered', 'read', 'failed'], true)) {
                        continue;
                    }
                    $state = $status['status'] === 'sent' ? 'accepted' : $status['status'];
                    $allowed = match ($state) {
                        'read' => ['pending', 'processing', 'accepted', 'delivered', 'unknown', 'failed'],
                        'delivered' => ['pending', 'processing', 'accepted', 'unknown', 'failed'],
                        default => ['pending', 'processing', 'accepted', 'unknown'],
                    };
                    DB::table('school_notification_deliveries')->where('channel', 'whatsapp')->where('provider_id', $status['id'])->whereIn('status', $allowed)
                        ->update(['status' => $state, 'error_code' => $state === 'failed' ? 'provider_delivery_failed' : null, 'updated_at' => now()]);
                }
            }
        }

        return response('EVENT_RECEIVED', 200);
    }
}
