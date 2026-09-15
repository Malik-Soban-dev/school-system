<?php

namespace App\Http\Controllers;

use App\Services\SchoolPortal;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportCardController extends Controller
{
    public function show(Request $request, SchoolPortal $portal, int $exam, int $student): View
    {
        $user = $request->user();
        abort_unless($portal->can($user, ['owner', 'admin', 'parent', 'student']), 403);
        $tenant = app(TenantContext::class);
        $examRecord = $tenant->table('school_exams')->find($exam);
        $studentRecord = $tenant->table('school_students')->find($student);
        abort_unless($examRecord && $studentRecord, 404);
        if (! $portal->admin($user)) {
            $linked = $user->hasRole('student') && (int) $studentRecord->user_id === $user->id;
            $linked = $linked || ($user->hasRole('parent') && $tenant->table('school_guardian_links')->where('student_id', $student)->where('user_id', $user->id)->where('status', 'active')->exists());
            abort_unless($linked && $examRecord->status === 'published', 404);
        }
        abort_unless($tenant->table('school_enrollments')->where('student_id', $student)->where('class_id', $examRecord->class_id)->exists(), 404);
        $grades = $tenant->table('school_grades')->where('exam_id', $exam)->where('student_id', $student)->get()->keyBy('subject_id');
        $plans = $tenant->table('school_exam_subjects')->where('exam_id', $exam)->get()->keyBy('subject_id');
        $planned = $plans->isNotEmpty();
        $subjectIds = $plans->keys()->merge($grades->keys())->unique();
        $subjects = $tenant->table('school_subjects')->whereIn('id', $subjectIds)->pluck('name', 'id');
        $scale = $examRecord->status === 'published' ? collect(json_decode($examRecord->grading_scale ?? '[]', true)) : $tenant->table('school_grade_bands')->get(['name', 'minimum', 'gpa'])->map(fn ($band) => (array) $band);
        $scale = $scale->sortByDesc('minimum');
        $rows = $subjectIds->map(function ($subject) use ($plans, $grades, $subjects, $scale): array {
            $grade = $grades->get($subject);
            $percentage = $grade ? round((float) $grade->marks / (float) $grade->maximum * 100, 2) : null;
            $band = $percentage !== null ? $scale->first(fn ($band) => $percentage >= (float) $band['minimum']) : null;

            return ['name' => $subjects[$subject], 'marks' => $grade?->marks, 'maximum' => $plans->get($subject)?->maximum ?? $grade?->maximum,
                'weight' => (float) ($plans->get($subject)?->weight ?? 1), 'percentage' => $percentage, 'band' => $band['name'] ?? null,
                'gpa' => isset($band['gpa']) ? (float) $band['gpa'] : null, 'remarks' => $grade?->remarks];
        })->sortBy('name')->values();
        $complete = $planned && $rows->isNotEmpty() && $rows->every(fn ($row) => $row['marks'] !== null) && $grades->keys()->diff($plans->keys())->isEmpty();
        $percentage = $complete ? round($rows->sum(fn ($row) => $row['percentage'] * $row['weight']) / $rows->sum('weight'), 2) : null;
        $gpa = $complete && $rows->every(fn ($row) => $row['gpa'] !== null) ? $rows->sum(fn ($row) => $row['gpa'] * $row['weight']) / $rows->sum('weight') : null;
        $overallBand = $percentage !== null ? $scale->first(fn ($band) => $percentage >= (float) $band['minimum']) : null;

        return view('reports.report-card', ['exam' => $examRecord, 'student' => $studentRecord, 'rows' => $rows, 'complete' => $complete,
            'planned' => $planned, 'percentage' => $percentage, 'gpa' => $gpa, 'overallBand' => $overallBand['name'] ?? null,
            'school' => $tenant->table('school_settings')->where('key', 'school_name')->value('value') ?: 'School System',
            'class' => $tenant->table('school_classes')->where('id', $examRecord->class_id)->value('name')]);
    }
}
