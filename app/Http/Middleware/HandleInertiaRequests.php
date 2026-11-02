<?php

namespace App\Http\Middleware;

use App\Models\College;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Setting;
use App\Support\AccessScope;
use App\Support\SemesterStore;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();
        $access = app(AccessScope::class);
        $permissions = $user ? [
            'is_admin' => $user->isAdmin(),
            'is_college_admin' => $user->isCollegeAdmin(),
            'is_class_admin' => $user->isClassAdmin(),
            'is_manager' => $user->isManager(),
            'can_manage_org' => $user->isAdmin() || $user->isCollegeAdmin(),
            'can_assign_college_admin' => $user->isAdmin(),
            'can_manage_classes' => $user->isAdmin() || $user->isCollegeAdmin(),
            'can_view_users' => $user->isManager(),
            'can_create_users' => $user->isAdmin() || $user->isCollegeAdmin(),
            'can_import_users' => $user->isAdmin(),
            'can_review' => $user->isManager(),
            'can_review_groups' => $user->isAdmin() || $user->isCollegeAdmin(),
            'can_manage_scores' => $user->isManager(),
            'can_manage_manual' => $user->isAdmin(),
            'can_view_statistics' => $user->isManager(),
            'can_manage_semesters' => $user->isAdmin(),
            'can_manage_categories' => $user->isAdmin(),
            'can_manage_settings' => $user->isAdmin(),
            'can_manage_announcements' => $user->isAdmin(),
            'can_view_audit' => $user->isAdmin(),
            'is_class_officer' => $user->isStudent() && $access->isClassOfficer($user),
        ] : [];
        $scopeCollegeIds = $user && $user->isManager() ? $access->collegeIds($user) : [];
        $scopeClassIds = $user && $user->isManager() ? $access->classIds($user) : [];
        $gradeScopes = $user && $user->isClassAdmin() ? $access->gradeScopes($user) : [];
        $semesters = app(SemesterStore::class)->all();
        $current = $semesters->firstWhere('is_current', true);
        $academicYears = $semesters->pluck('academic_year')->unique()->values();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'username' => $user->username,
                    'name' => $user->name,
                    'role' => $user->role,
                    'role_label' => $user->roleLabel(),
                    'student_id' => $user->student_id,
                    'employee_id' => $user->employee_id,
                    'class_name' => $user->class_name,
                    'college' => $user->college,
                    'grade' => $user->grade,
                    'teacher_college' => $user->teacher_college,
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'appSettings' => [
                'name' => Setting::get('system_name', config('app.name')),
                'base_score' => (int) Setting::get('base_score', '40'),
                'current_semester' => $current?->name,
                'current_semester_id' => $current?->id,
                'registration_enabled' => Setting::get('registration_enabled', '1') === '1',
                'teacher_registration_enabled' => Setting::get('teacher_registration_enabled', '0') === '1',
            ],
            'permissions' => $permissions,
            'scopeInfo' => [
                'college_names' => $scopeCollegeIds === [] ? [] : College::when($scopeCollegeIds !== null, fn ($q) => $q->whereIn('id', $scopeCollegeIds))->orderBy('name')->pluck('name'),
                'class_names' => $scopeClassIds === [] ? [] : SchoolClass::when($scopeClassIds !== null, fn ($q) => $q->whereIn('id', $scopeClassIds))->orderBy('name')->pluck('name'),
                'grade_scopes' => $gradeScopes,
            ],
            'semesters' => $semesters,
            'periodOptions' => [
                'semesters' => $semesters->map(fn (Semester $semester) => [
                    'value' => "semester:{$semester->id}",
                    'label' => $semester->name,
                    'is_current' => $semester->is_current,
                ])->values(),
                'years' => $academicYears->map(fn ($year) => [
                    'value' => "year:{$year}",
                    'label' => "{$year} 学年（汇总）",
                ])->values(),
            ],
        ];
    }
}
