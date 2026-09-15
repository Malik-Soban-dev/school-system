<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function createTable(string $name, Closure $definition): void
    {
        foreach (DB::connection()->pretend(fn () => Schema::create($name, $definition)) as $statement) {
            DB::statement(preg_replace('/^create (table|unique index|index) /i', 'create $1 if not exists ', $statement['query']), $statement['bindings']);
        }
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->createTable('school_grade_bands', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->decimal('minimum', 5, 2)->unique();
            $table->decimal('gpa', 4, 2);
            $table->timestamps();
        });
        $this->createTable('school_exam_subjects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_id')->constrained('school_exams')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained('school_subjects')->restrictOnDelete();
            $table->decimal('maximum', 8, 2);
            $table->decimal('weight', 8, 2);
            $table->timestamps();
            $table->unique(['exam_id', 'subject_id']);
        });
        if (! Schema::hasColumn('school_exams', 'grading_scale')) {
            Schema::table('school_exams', fn (Blueprint $table) => $table->json('grading_scale')->nullable());
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('school_exams', fn (Blueprint $table) => $table->dropColumn('grading_scale'));
        Schema::dropIfExists('school_exam_subjects');
        Schema::dropIfExists('school_grade_bands');
    }
};
