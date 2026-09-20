<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class PlatformErrorRecorder
{
    public function record(Throwable $exception, ?Request $request = null): void
    {
        if (! Schema::hasTable('platform_errors')) {
            return;
        }

        try {
            $request ??= app()->bound('request') ? request() : null;
            $errorType = get_class($exception);
            $status = method_exists($exception, 'getStatusCode') ? (int) $exception->getStatusCode() : 500;
            $method = $request?->method();
            $route = $request?->route()?->getName();
            $fingerprint = hash('sha256', implode('|', [$errorType, $status, $method, $route]));
            DB::table('platform_errors')->insert(['error_type' => $errorType, 'status' => $status, 'method' => $method, 'route' => $route, 'fingerprint' => $fingerprint, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('platform_errors')->where('occurred_at', '<', now()->subDays(90))->delete();
        } catch (Throwable) {
            // Error reporting must never replace the original application failure.
        }
    }
}
