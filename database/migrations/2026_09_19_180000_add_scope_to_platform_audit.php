<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_audit', function (Blueprint $table): void {
            $table->unsignedBigInteger('school_id')->nullable()->after('user_id');
            $table->unsignedBigInteger('branch_id')->nullable()->after('school_id');
            $table->index(['school_id', 'created_at']);
            $table->index(['branch_id', 'created_at']);
        });

        DB::table('platform_audit')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $changes = json_decode((string) $row->changes, true) ?: [];
                $schoolId = $this->integerValue($changes['school_id'] ?? null);
                $branchId = $this->integerValue($changes['branch_id'] ?? null);

                if ($schoolId === null && is_array($changes['school_ids'] ?? null) && count($changes['school_ids']) === 1) {
                    $schoolId = $this->integerValue($changes['school_ids'][0]);
                }
                if ($branchId === null && is_array($changes['branch_ids'] ?? null) && count($changes['branch_ids']) === 1) {
                    $branchId = $this->integerValue($changes['branch_ids'][0]);
                }
                if ($row->entity_type === 'school' || $row->entity_type === 'subscription' || $row->entity_type === 'school_feature') {
                    $schoolId ??= (int) $row->entity_id;
                }
                if ($row->entity_type === 'branch' || $row->entity_type === 'workspace') {
                    $branchId ??= (int) $row->entity_id;
                }
                if ($schoolId === null && $branchId !== null) {
                    $schoolId = $this->integerValue(DB::table('school_branches')->where('id', $branchId)->value('school_id'));
                }

                if ($schoolId !== null || $branchId !== null) {
                    DB::table('platform_audit')->where('id', $row->id)->update(['school_id' => $schoolId, 'branch_id' => $branchId]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('platform_audit', function (Blueprint $table): void {
            $table->dropIndex('platform_audit_school_id_created_at_index');
            $table->dropIndex('platform_audit_branch_id_created_at_index');
            $table->dropColumn(['school_id', 'branch_id']);
        });
    }

    private function integerValue(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
};
