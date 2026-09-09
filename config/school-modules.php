<?php

return [
    'academic_years' => [
        'label' => 'Academic years',
        'singular' => 'academic year',
        'help' => 'Set the start and end dates before creating classes for a new school year.',
        'read' => ['owner', 'admin'],
        'write' => ['owner', 'admin'],
        'fields' => [[
            'name' => 'name',
            'label' => 'Name',
            'type' => 'text',
        ], [
            'name' => 'starts_on',
            'label' => 'Starts on',
            'type' => 'date',
        ], [
            'name' => 'ends_on',
            'label' => 'Ends on',
            'type' => 'date',
        ]],
    ],
    'classes' => [
        'label' => 'Classes & sections',
        'singular' => 'class',
        'help' => 'Create one class for each section, for example Grade 5 A. Each class belongs to an academic year.',
        'read' => ['owner', 'admin', 'teacher', 'student', 'parent', 'accountant'],
        'write' => ['owner', 'admin'],
        'fields' => [[
            'name' => 'name',
            'label' => 'Class and section',
            'type' => 'text',
        ], [
            'name' => 'year_id',
            'label' => 'Academic year',
            'type' => 'relation',
            'relation' => 'academic_years',
        ], [
            'name' => 'capacity',
            'label' => 'Capacity',
            'type' => 'number',
            'min' => 1,
            'max' => 500,
        ]],
    ],
    'subjects' => [
        'label' => 'Subjects',
        'singular' => 'subject',
        'help' => 'Create the subjects taught at your school, then assign teachers to class subjects.',
        'read' => ['owner', 'admin', 'teacher', 'student', 'parent', 'accountant'],
        'write' => ['owner', 'admin'],
        'fields' => [[
            'name' => 'name',
            'label' => 'Subject name',
            'type' => 'text',
        ], [
            'name' => 'code',
            'label' => 'Subject code',
            'type' => 'text',
        ]],
    ],
    'staff' => [
        'label' => 'Staff directory',
        'singular' => 'staff member',
        'help' => 'Keep staff employment information here. A staff profile and a sign-in account are separate; link an invited account when available.',
        'read' => ['owner', 'admin'],
        'write' => ['owner', 'admin'],
        'fields' => [[
            'name' => 'name',
            'label' => 'Full name',
            'type' => 'text',
        ], [
            'name' => 'employee_number',
            'label' => 'Employee number',
            'type' => 'text',
        ], [
            'name' => 'department',
            'label' => 'Department',
            'type' => 'text',
        ], [
            'name' => 'designation',
            'label' => 'Job title',
            'type' => 'text',
        ], [
            'name' => 'user_id',
            'label' => 'Sign-in account',
            'type' => 'relation',
            'relation' => 'users',
            'optional' => true,
        ], [
            'name' => 'joined_on',
            'label' => 'Joining date',
            'type' => 'date',
        ], [
            'name' => 'status',
            'label' => 'Status',
            'type' => 'select',
            'choices' => ['active', 'left'],
        ]],
    ],
    'students' => [
        'label' => 'Students',
        'singular' => 'student',
        'help' => 'Register a student and assign a class. Student sign-in is optional. Connect guardians separately after verifying the relationship.',
        'read' => ['owner', 'admin', 'teacher', 'student', 'parent', 'accountant'],
        'write' => ['owner', 'admin'],
        'fields' => [[
            'name' => 'name',
            'label' => 'Full name',
            'type' => 'text',
        ], [
            'name' => 'admission_number',
            'label' => 'Admission number',
            'type' => 'text',
        ], [
            'name' => 'class_id',
            'label' => 'Current class',
            'type' => 'relation',
            'relation' => 'classes',
        ], [
            'name' => 'date_of_birth',
            'label' => 'Date of birth',
            'type' => 'date',
            'optional' => true,
        ], [
            'name' => 'emergency_contact',
            'label' => 'Emergency contact',
            'type' => 'text',
            'optional' => true,
        ], [
            'name' => 'user_id',
            'label' => 'Student sign-in account',
            'type' => 'relation',
            'relation' => 'users',
            'optional' => true,
        ], [
            'name' => 'status',
            'label' => 'Status',
            'type' => 'select',
            'choices' => ['active', 'withdrawn', 'graduated'],
        ]],
    ],
    'guardian_links' => [
        'label' => 'Guardian links',
        'singular' => 'guardian link',
        'help' => 'Verify the guardian with the school before connecting an account to a child. This link gives that guardian access to the child’s published records.',
        'read' => ['owner', 'admin'],
        'write' => ['owner', 'admin'],
        'fields' => [[
            'name' => 'student_id',
            'label' => 'Student',
            'type' => 'relation',
            'relation' => 'students',
        ], [
            'name' => 'user_id',
            'label' => 'Guardian account',
            'type' => 'relation',
            'relation' => 'users',
        ], [
            'name' => 'relationship',
            'label' => 'Relationship',
            'type' => 'text',
        ], [
            'name' => 'status',
            'label' => 'Access',
            'type' => 'select',
            'choices' => ['active', 'revoked'],
        ]],
    ],
    'teacher_assignments' => [
        'label' => 'Teaching assignments',
        'singular' => 'teaching assignment',
        'help' => 'Assign a teacher to a class and subject. Teachers can enter attendance for their classes and marks for their subjects.',
        'read' => ['owner', 'admin', 'teacher'],
        'write' => ['owner', 'admin'],
        'fields' => [[
            'name' => 'user_id',
            'label' => 'Teacher account',
            'type' => 'relation',
            'relation' => 'users',
        ], [
            'name' => 'class_id',
            'label' => 'Class',
            'type' => 'relation',
            'relation' => 'classes',
        ], [
            'name' => 'subject_id',
            'label' => 'Subject',
            'type' => 'relation',
            'relation' => 'subjects',
        ], [
            'name' => 'status',
            'label' => 'Assignment',
            'type' => 'select',
            'choices' => ['active', 'ended'],
        ]],
    ],
    'attendance' => [
        'label' => 'Attendance',
        'singular' => 'attendance record',
        'help' => 'Choose a student, date and status. One record is allowed per student per day. Teachers can correct today’s records; administrators handle older corrections.',
        'read' => ['owner', 'admin', 'teacher', 'student', 'parent'],
        'write' => ['owner', 'admin', 'teacher'],
        'fields' => [[
            'name' => 'student_id',
            'label' => 'Student',
            'type' => 'relation',
            'relation' => 'students',
        ], [
            'name' => 'date',
            'label' => 'Date',
            'type' => 'date',
        ], [
            'name' => 'status',
            'label' => 'Attendance',
            'type' => 'select',
            'choices' => ['present', 'absent', 'late', 'excused'],
        ], [
            'name' => 'note',
            'label' => 'Note',
            'type' => 'textarea',
            'optional' => true,
        ]],
    ],
    'timetables' => [
        'label' => 'Timetable',
        'singular' => 'lesson',
        'help' => 'Assign lessons to a weekday, class, subject and teacher. The system checks overlapping teacher, class and room times.',
        'read' => ['owner', 'admin', 'teacher', 'student', 'parent', 'accountant'],
        'write' => ['owner', 'admin'],
        'fields' => [[
            'name' => 'class_id',
            'label' => 'Class',
            'type' => 'relation',
            'relation' => 'classes',
        ], [
            'name' => 'subject_id',
            'label' => 'Subject',
            'type' => 'relation',
            'relation' => 'subjects',
        ], [
            'name' => 'teacher_id',
            'label' => 'Teacher',
            'type' => 'relation',
            'relation' => 'users',
        ], [
            'name' => 'weekday',
            'label' => 'Day',
            'type' => 'select',
            'choices' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
        ], [
            'name' => 'starts_at',
            'label' => 'Start time',
            'type' => 'time',
        ], [
            'name' => 'ends_at',
            'label' => 'End time',
            'type' => 'time',
        ], [
            'name' => 'room',
            'label' => 'Room',
            'type' => 'text',
        ]],
    ],
    'exams' => [
        'label' => 'Exams',
        'singular' => 'exam',
        'help' => 'Schedule an exam for a class. Results are drafts until an administrator publishes the exam.',
        'read' => ['owner', 'admin', 'teacher', 'student', 'parent'],
        'write' => ['owner', 'admin'],
        'fields' => [[
            'name' => 'name',
            'label' => 'Exam name',
            'type' => 'text',
        ], [
            'name' => 'class_id',
            'label' => 'Class',
            'type' => 'relation',
            'relation' => 'classes',
        ], [
            'name' => 'date',
            'label' => 'Exam date',
            'type' => 'date',
        ], [
            'name' => 'status',
            'label' => 'Results',
            'type' => 'select',
            'choices' => ['draft', 'published'],
        ]],
    ],
    'grades' => [
        'label' => 'Marks & results',
        'singular' => 'mark',
        'help' => 'Enter marks for an exam and subject. Marks cannot exceed the maximum. Published exams are locked until an administrator returns them to draft.',
        'read' => ['owner', 'admin', 'teacher', 'student', 'parent'],
        'write' => ['owner', 'admin', 'teacher'],
        'fields' => [[
            'name' => 'exam_id',
            'label' => 'Exam',
            'type' => 'relation',
            'relation' => 'exams',
        ], [
            'name' => 'student_id',
            'label' => 'Student',
            'type' => 'relation',
            'relation' => 'students',
        ], [
            'name' => 'subject_id',
            'label' => 'Subject',
            'type' => 'relation',
            'relation' => 'subjects',
        ], [
            'name' => 'marks',
            'label' => 'Marks obtained',
            'type' => 'decimal',
        ], [
            'name' => 'maximum',
            'label' => 'Maximum marks',
            'type' => 'decimal',
        ], [
            'name' => 'remarks',
            'label' => 'Remarks',
            'type' => 'textarea',
            'optional' => true,
        ]],
    ],
    'invoices' => [
        'label' => 'Fee invoices',
        'singular' => 'invoice',
        'help' => 'Create an invoice for a student. Record received payments in Payments; balances are calculated automatically. Amounts cannot change after payments are recorded.',
        'read' => ['owner', 'admin', 'accountant', 'parent', 'student'],
        'write' => ['owner', 'admin', 'accountant'],
        'fields' => [[
            'name' => 'reference',
            'label' => 'Invoice number',
            'type' => 'text',
        ], [
            'name' => 'student_id',
            'label' => 'Student',
            'type' => 'relation',
            'relation' => 'students',
        ], [
            'name' => 'description',
            'label' => 'Description',
            'type' => 'text',
        ], [
            'name' => 'amount',
            'label' => 'Amount',
            'type' => 'money',
        ], [
            'name' => 'due_on',
            'label' => 'Due date',
            'type' => 'date',
        ]],
    ],
    'payments' => [
        'label' => 'Payments & receipts',
        'singular' => 'payment',
        'help' => 'Record a payment only after the school has received it. A unique receipt reference prevents duplicates. Recorded payments cannot be edited or deleted.',
        'read' => ['owner', 'admin', 'accountant', 'parent', 'student'],
        'write' => ['owner', 'admin', 'accountant'],
        'immutable' => true,
        'fields' => [[
            'name' => 'invoice_id',
            'label' => 'Invoice',
            'type' => 'relation',
            'relation' => 'invoices',
        ], [
            'name' => 'reference',
            'label' => 'Receipt reference',
            'type' => 'text',
        ], [
            'name' => 'amount',
            'label' => 'Amount received',
            'type' => 'money',
        ], [
            'name' => 'paid_on',
            'label' => 'Payment date',
            'type' => 'date',
        ], [
            'name' => 'method',
            'label' => 'Method',
            'type' => 'select',
            'choices' => ['cash', 'bank_transfer', 'cheque'],
        ], [
            'name' => 'note',
            'label' => 'Note',
            'type' => 'textarea',
            'optional' => true,
        ]],
    ],
    'expenses' => [
        'label' => 'Expenses',
        'singular' => 'expense',
        'help' => 'Record school operating expenses with a reference and category for review.',
        'read' => ['owner', 'admin', 'accountant'],
        'write' => ['owner', 'admin', 'accountant'],
        'fields' => [[
            'name' => 'reference',
            'label' => 'Reference',
            'type' => 'text',
        ], [
            'name' => 'description',
            'label' => 'Description',
            'type' => 'text',
        ], [
            'name' => 'category',
            'label' => 'Category',
            'type' => 'text',
        ], [
            'name' => 'amount',
            'label' => 'Amount',
            'type' => 'money',
        ], [
            'name' => 'paid_on',
            'label' => 'Date',
            'type' => 'date',
        ]],
    ],
    'leave_requests' => [
        'label' => 'Staff leave',
        'singular' => 'leave request',
        'help' => 'Staff can request leave. Administrators approve or reject requests. Overlapping requests are not allowed.',
        'read' => ['owner', 'admin', 'teacher', 'accountant'],
        'write' => ['owner', 'admin', 'teacher', 'accountant'],
        'fields' => [[
            'name' => 'user_id',
            'label' => 'Staff member',
            'type' => 'relation',
            'relation' => 'users',
        ], [
            'name' => 'starts_on',
            'label' => 'First day',
            'type' => 'date',
        ], [
            'name' => 'ends_on',
            'label' => 'Last day',
            'type' => 'date',
        ], [
            'name' => 'reason',
            'label' => 'Reason',
            'type' => 'textarea',
        ], [
            'name' => 'status',
            'label' => 'Decision',
            'type' => 'select',
            'choices' => ['pending', 'approved', 'rejected'],
        ]],
    ],
    'payroll' => [
        'label' => 'Payroll records',
        'singular' => 'payroll record',
        'help' => 'Record a monthly payroll calculation. Net pay is basic pay plus allowances minus deductions. Each staff member can have one record per month.',
        'read' => ['owner', 'admin', 'accountant'],
        'write' => ['owner', 'admin', 'accountant'],
        'immutable' => true,
        'fields' => [[
            'name' => 'staff_id',
            'label' => 'Staff member',
            'type' => 'relation',
            'relation' => 'staff',
        ], [
            'name' => 'month',
            'label' => 'Month',
            'type' => 'month',
        ], [
            'name' => 'basic',
            'label' => 'Basic pay',
            'type' => 'money',
        ], [
            'name' => 'allowances',
            'label' => 'Allowances',
            'type' => 'money',
        ], [
            'name' => 'deductions',
            'label' => 'Deductions',
            'type' => 'money',
        ]],
    ],
    'notices' => [
        'label' => 'Noticeboard',
        'singular' => 'notice',
        'help' => 'Publish announcements for everyone or for a particular role. Draft notices are visible only to administrators.',
        'read' => ['owner', 'admin', 'teacher', 'student', 'parent', 'accountant'],
        'write' => ['owner', 'admin'],
        'fields' => [[
            'name' => 'title',
            'label' => 'Title',
            'type' => 'text',
        ], [
            'name' => 'body',
            'label' => 'Message',
            'type' => 'textarea',
        ], [
            'name' => 'audience',
            'label' => 'Audience',
            'type' => 'select',
            'choices' => ['all', 'teacher', 'student', 'parent', 'accountant'],
        ], [
            'name' => 'status',
            'label' => 'Visibility',
            'type' => 'select',
            'choices' => ['draft', 'published'],
        ]],
    ],
];
