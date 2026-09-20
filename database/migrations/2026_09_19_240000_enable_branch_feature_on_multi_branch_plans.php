<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $growth = DB::table('platform_plans')->where('code', 'growth')->first(['id', 'features']);
        if ($growth) {
            $features = json_decode((string) $growth->features, true) ?: [];
            if (! in_array('branches', $features, true)) {
                DB::table('platform_plans')->where('id', $growth->id)->update(['features' => json_encode([...$features, 'branches']), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        $growth = DB::table('platform_plans')->where('code', 'growth')->first(['id', 'features']);
        if ($growth) {
            $features = array_values(array_filter(json_decode((string) $growth->features, true) ?: [], fn (string $feature): bool => $feature !== 'branches'));
            DB::table('platform_plans')->where('id', $growth->id)->update(['features' => json_encode($features), 'updated_at' => now()]);
        }
    }
};
