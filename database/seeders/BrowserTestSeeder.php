<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\SchoolPortal;
use Illuminate\Database\Seeder;

class BrowserTestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('testing') || basename(config('database.connections.sqlite.database')) !== 'browser-testing.sqlite') {
            throw new \RuntimeException('Browser fixtures require the isolated browser testing database.');
        }
        $people = [];
        foreach (['owner', 'teacher', 'student', 'parent', 'accountant'] as $role) {
            $person = new User;
            $person->forceFill(['name' => ucfirst($role).' Test', 'username' => 'test.'.$role, 'email' => $role.'@example.test', 'password' => 'Browser-test-12345', 'roles' => [$role], 'is_active' => true])->save();
            $people[$role] = $person;
        }
        $portal = app(SchoolPortal::class);
        $save = fn (string $module, array $data): int => $portal->save($module, $people['owner'], $data);
        $year = $save('academic_years', ['name' => '2026 school year', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $class = $save('classes', ['name' => 'Grade 5 A', 'year_id' => $year, 'capacity' => 30]);
        $subject = $save('subjects', ['name' => 'Mathematics', 'code' => 'MATH']);
        $student = $save('students', ['name' => 'Alex Student', 'admission_number' => 'ADM-001', 'class_id' => $class, 'user_id' => $people['student']->id, 'status' => 'active']);
        $save('students', ['name' => 'Private Student', 'admission_number' => 'ADM-002', 'class_id' => $class, 'status' => 'active']);
        $save('guardian_links', ['student_id' => $student, 'user_id' => $people['parent']->id, 'relationship' => 'Parent', 'status' => 'active']);
        $save('teacher_assignments', ['user_id' => $people['teacher']->id, 'class_id' => $class, 'subject_id' => $subject, 'status' => 'active']);
        $save('invoices', ['student_id' => $student, 'reference' => 'INV-001', 'description' => 'Tuition', 'amount' => '100.10', 'due_on' => today()->toDateString()]);
    }
}
