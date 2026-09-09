<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class NotificationDelivery
{
    public function ready(string $channel): bool
    {
        if ($channel === 'email') {
            return (bool) config('services.school_email.enabled') && ! in_array(config('mail.default'), ['log', 'array']);
        }

        return (bool) config('services.whatsapp.enabled') && preg_match('/^v\d+\.\d+$/', (string) config('services.whatsapp.version'))
            && ctype_digit((string) config('services.whatsapp.phone_number_id'))
            && config('services.whatsapp.token') && config('services.whatsapp.template')
            && config('services.whatsapp.app_secret') && config('services.whatsapp.verify_token');
    }

    public function process(): void
    {
        DB::table('school_notification_deliveries')->where('status', 'processing')->where('updated_at', '<', now()->subMinutes(10))
            ->update(['status' => 'unknown', 'error_code' => 'interrupted_attempt', 'updated_at' => now()]);
        foreach (['email', 'whatsapp'] as $channel) {
            if (! $this->ready($channel)) {
                continue;
            }
            $notifications = DB::table('school_notifications as n')->join('school_notification_preferences as p', 'p.user_id', '=', 'n.user_id')
                ->whereNotNull('p.'.$channel.'_consented_at')->whereColumn('n.created_at', '>=', 'p.'.$channel.'_consented_at')
                ->where('n.created_at', '>=', now()->subDays(2))
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('school_notification_deliveries as d')->whereColumn('d.notification_id', 'n.id')->where('d.channel', $channel))
                ->orderBy('n.id')->limit(100)->get(['n.id']);
            foreach ($notifications as $notification) {
                DB::table('school_notification_deliveries')->insertOrIgnore(['notification_id' => $notification->id, 'channel' => $channel, 'status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach (DB::table('school_notification_deliveries')->where('channel', $channel)->where('status', 'pending')->where('available_at', '<=', now())->orderBy('id')->limit(20)->get() as $delivery) {
                $this->send($delivery);
            }
        }
    }

    private function finish(int $id, string $status, ?string $error = null, ?string $providerId = null): void
    {
        DB::table('school_notification_deliveries')->where('id', $id)->where('status', 'processing')->update(['status' => $status, 'error_code' => $error, 'provider_id' => $providerId, 'updated_at' => now()]);
    }

    private function send(object $delivery): void
    {
        $claimed = DB::table('school_notification_deliveries')->where('id', $delivery->id)->where('status', 'pending')
            ->update(['status' => 'processing', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }
        $notification = DB::table('school_notifications')->find($delivery->notification_id);
        $user = $notification ? User::find($notification->user_id) : null;
        $preferences = $user ? DB::table('school_notification_preferences')->where('user_id', $user->id)->first() : null;
        $consent = $delivery->channel.'_consented_at';
        if (! $user?->is_active || ! $preferences?->$consent || $notification->created_at < $preferences->$consent
            || $notification->created_at < now()->subDays(2)->toDateTimeString()
            || ! app(SchoolNotifications::class)->visible($user)->where('id', $notification->id)->exists()) {
            $this->finish($delivery->id, 'skipped', 'access_or_consent_changed');

            return;
        }
        if ($notification->module === 'exams' && str_starts_with($notification->event_key, 'exam-reminder:')) {
            $exam = DB::table('school_exams')->find($notification->record_id);
            if ($exam->schedule_status !== 'announced' || ! str_contains($notification->event_key, ':'.$exam->date.':') || $exam->date <= app(SchoolPortal::class)->today()) {
                $this->finish($delivery->id, 'skipped', 'exam_changed');

                return;
            }
        }
        $url = rtrim(config('app.url'), '/').'/dashboard';
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            $this->finish($delivery->id, 'failed', 'https_app_url_required');

            return;
        }
        try {
            if ($delivery->channel === 'email') {
                if (! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                    $this->finish($delivery->id, 'skipped', 'email_missing');

                    return;
                }
                Mail::raw($notification->body."\n\nView your school account: ".$url, function ($message) use ($user, $notification): void {
                    $message->to($user->email)->subject($notification->title);
                });
                $this->finish($delivery->id, 'accepted');

                return;
            }
            if (! preg_match('/^\+[1-9]\d{7,14}$/', (string) $preferences->whatsapp_phone)) {
                $this->finish($delivery->id, 'skipped', 'phone_missing');

                return;
            }
            $response = Http::withToken(config('services.whatsapp.token'))->connectTimeout(10)->timeout(20)
                ->post('https://graph.facebook.com/'.config('services.whatsapp.version').'/'.config('services.whatsapp.phone_number_id').'/messages', [
                    'messaging_product' => 'whatsapp', 'to' => ltrim($preferences->whatsapp_phone, '+'), 'type' => 'template',
                    'template' => ['name' => config('services.whatsapp.template'), 'language' => ['code' => config('services.whatsapp.language')],
                        'components' => [['type' => 'body', 'parameters' => array_map(fn ($text) => ['type' => 'text', 'text' => $text], [Str::limit($notification->title, 200), Str::limit($notification->body, 800), $url])]]],
                ]);
            if ($response->successful() && is_string($response->json('messages.0.id'))) {
                $this->finish($delivery->id, 'accepted', null, $response->json('messages.0.id'));
            } elseif ($response->status() === 429 && $delivery->attempts < 4) {
                DB::table('school_notification_deliveries')->where('id', $delivery->id)->where('status', 'processing')
                    ->update(['status' => 'pending', 'available_at' => now()->addMinutes(2 ** ($delivery->attempts + 1)), 'error_code' => 'rate_limited', 'updated_at' => now()]);
            } else {
                $this->finish($delivery->id, $response->clientError() ? 'failed' : 'unknown', 'provider_http_'.$response->status());
            }
        } catch (Throwable) {
            $this->finish($delivery->id, 'unknown', 'delivery_outcome_unknown');
        }
    }
}
