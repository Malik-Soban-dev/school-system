<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('school_submissions') || Schema::hasColumn('school_submissions', 'attachment_path')) {
            return;
        }

        Schema::table('school_submissions', function (Blueprint $table): void {
            $table->string('attachment_path')->nullable()->after('content');
            $table->string('attachment_name')->nullable()->after('attachment_path');
            $table->string('attachment_mime', 120)->nullable()->after('attachment_name');
            $table->unsignedBigInteger('attachment_size')->nullable()->after('attachment_mime');
            $table->index(['school_id', 'branch_id', 'attachment_path']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('school_submissions') || ! Schema::hasColumn('school_submissions', 'attachment_path')) {
            return;
        }

        Schema::table('school_submissions', function (Blueprint $table): void {
            $table->dropIndex(['school_id', 'branch_id', 'attachment_path']);
            $table->dropColumn(['attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size']);
        });
    }
};
