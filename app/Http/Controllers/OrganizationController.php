<?php

namespace App\Http\Controllers;

use App\Models\College;
use App\Models\GradeAdminScope;
use App\Models\Major;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\ScoreService;
use App\Support\AccessScope;
use App\Support\ClassNaming;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function __construct(
        private readonly AccessScope $access,
        private readonly ScoreService $scores
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $collegeIds = $this->access->collegeIds($user);

        $colleges = College::query()
            ->with([
                'admins:id,name,username',
                'majors:id,college_id,name,code,is_active',
                'classes' => fn ($query) => $query
                    ->withCount(['users' => fn ($q) => $q->where('role', 'student')])
                    ->with(['admins:id,name,username', 'major:id,name', 'officers:id,name,username,student_id']),
            ])
            ->when($collegeIds !== null, fn ($q) => $q->whereIn('id', $collegeIds))
            ->orderBy('name')
            ->get()
            ->map(fn (College $college) => [
                'id' => $college->id,
                'name' => $college->name,
                'code' => $college->code,
                'description' => $college->description,
                'is_active' => $college->is_active,
                'admins' => $college->admins->map(fn (User $admin) => [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'username' => $admin->username,
                ]),
                'majors' => $college->majors->map(fn (Major $major) => [
                    'id' => $major->id,
                    'name' => $major->name,
                    'code' => $major->code,
                    'is_active' => $major->is_active,
                ]),
                'classes' => $college->classes->map(fn (SchoolClass $class) => [
                    'id' => $class->id,
                    'name' => $class->name,
                    'grade' => $class->grade,
                    'major_id' => $class->major_id,
                    'major_name' => $class->major?->name,
                    'is_active' => $class->is_active,
                    'users_count' => $class->users_count,
                    'admins' => $class->admins->map(fn (User $admin) => [
                        'id' => $admin->id,
                        'name' => $admin->name,
                        'username' => $admin->username,
                    ]),
                    'officers' => $class->officers->map(fn (User $officer) => [
                        'id' => $officer->id,
                        'name' => $officer->name,
                        'username' => $officer->username,
                        'student_id' => $officer->student_id,
                        'title' => $officer->pivot->title,
                    ]),
                ]),
                'grade_admins' => GradeAdminScope::query()
                    ->with(['user:id,name,username', 'major:id,name'])
                    ->where('college_id', $college->id)
                    ->orderBy('grade')
                    ->get()
                    ->map(fn (GradeAdminScope $scope) => [
                        'id' => $scope->id,
                        'user' => $scope->user?->only(['id', 'name', 'username']),
                        'major' => $scope->major?->only(['id', 'name']),
                        'grade' => $scope->grade,
                    ]),
            ]);

        $userQuery = User::query()
            ->where('is_active', true)
            ->whereIn('role', ['student', 'teacher', 'class_admin']);

        if ($collegeIds !== null) {
            $userQuery->whereIn('college_id', $collegeIds);
        }

        return Inertia::render('Organization/Index', [
            'colleges' => $colleges,
            'assignableUsers' => $userQuery
                ->orderBy('name')
                ->get(['id', 'name', 'username', 'student_id', 'employee_id', 'role', 'college_id', 'major_id', 'class_id']),
            'canManageColleges' => $user->isAdmin(),
            'canAssignCollegeAdmins' => $user->isAdmin(),
            'canManageMajors' => $user->isAdmin() || $user->isCollegeAdmin(),
            'canManageClasses' => $user->isAdmin() || $user->isCollegeAdmin(),
            'canAssignClassAdmins' => $user->isAdmin() || $user->isCollegeAdmin(),
            'canAssignGradeAdmins' => $user->isAdmin() || $user->isCollegeAdmin(),
            'canAssignClassOfficers' => $user->isAdmin() || $user->isCollegeAdmin(),
        ]);
    }

    public function storeCollege(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:colleges,name'],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ], [], [
            'name' => '学院名称',
            'code' => '学院编码',
        ]);

        $college = College::create($data);
        $this->scores->audit($request->user(), 'create_college', $college);

        return back()->with('success', '学院已创建。');
    }

    public function updateCollege(Request $request, College $college): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('colleges', 'name')->ignore($college->id)],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ], [], [
            'name' => '学院名称',
            'code' => '学院编码',
        ]);

        $college->update($data);
        $this->scores->audit($request->user(), 'update_college', $college);

        return back()->with('success', '学院信息已更新。');
    }

    public function destroyCollege(Request $request, College $college): RedirectResponse
    {
        if ($college->users()->exists() || $college->majors()->exists() || $college->classes()->exists()) {
            return back()->with('error', '该学院下仍有用户、专业或班级，不能删除。');
        }

        $college->delete();
        $this->scores->audit($request->user(), 'delete_college', null, ['name' => $college->name]);

        return back()->with('success', '学院已删除。');
    }

    public function storeMajor(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'college_id' => ['required', Rule::exists('colleges', 'id')],
            'name' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ], [], [
            'college_id' => '所属学院',
            'name' => '专业名称',
        ]);

        abort_unless($this->access->canAccessCollege($request->user(), (int) $data['college_id']), 403);

        if (Major::where('college_id', $data['college_id'])->where('name', $data['name'])->exists()) {
            throw ValidationException::withMessages(['name' => '该学院下已存在同名专业。']);
        }

        $major = Major::create($data);
        $this->scores->audit($request->user(), 'create_major', $major);

        return back()->with('success', '专业已创建。');
    }

    public function updateMajor(Request $request, Major $major): RedirectResponse
    {
        abort_unless($this->access->canAccessCollege($request->user(), $major->college_id), 403);

        $data = $request->validate([
            'college_id' => ['required', Rule::exists('colleges', 'id')],
            'name' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ]);

        abort_unless($this->access->canAccessCollege($request->user(), (int) $data['college_id']), 403);

        if (Major::where('college_id', $data['college_id'])->where('name', $data['name'])->whereKeyNot($major->id)->exists()) {
            throw ValidationException::withMessages(['name' => '该学院下已存在同名专业。']);
        }

        $major->update($data);
        $this->scores->audit($request->user(), 'update_major', $major);

        return back()->with('success', '专业信息已更新。');
    }

    public function destroyMajor(Request $request, Major $major): RedirectResponse
    {
        abort_unless($this->access->canAccessCollege($request->user(), $major->college_id), 403);

        if ($major->classes()->exists() || $major->users()->exists()) {
            return back()->with('error', '该专业下仍有班级或学生，不能删除。');
        }

        $major->delete();
        $this->scores->audit($request->user(), 'delete_major', null, ['name' => $major->name]);

        return back()->with('success', '专业已删除。');
    }

    public function storeClass(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'college_id' => ['required', Rule::exists('colleges', 'id')],
            'major_id' => ['required', Rule::exists('majors', 'id')],
            'name' => ['required', 'string', 'max:50'],
            'grade' => ['nullable', 'string', 'max:20'],
            'is_active' => ['boolean'],
        ], [], [
            'college_id' => '所属学院',
            'major_id' => '所属专业',
            'name' => '班级名称',
        ]);

        abort_unless($this->access->canAccessCollege($request->user(), (int) $data['college_id']), 403);

        $major = Major::findOrFail($data['major_id']);

        if ((int) $major->college_id !== (int) $data['college_id']) {
            throw ValidationException::withMessages(['major_id' => '专业不属于所选学院。']);
        }

        $data['grade'] = ClassNaming::normalizeGrade($data['grade'] ?? null)
            ?? ClassNaming::parse($data['name'])['grade'];

        $exists = SchoolClass::where('college_id', $data['college_id'])
            ->where('major_id', $data['major_id'])
            ->where('name', $data['name'])
            ->where('grade', $data['grade'] ?: null)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => '该学院下已存在同名班级。']);
        }

        $class = SchoolClass::create($data);
        $this->scores->audit($request->user(), 'create_class', $class);

        return back()->with('success', '班级已创建。');
    }

    public function updateClass(Request $request, SchoolClass $schoolClass): RedirectResponse
    {
        abort_unless($this->access->canAccessClass($request->user(), $schoolClass->id), 403);

        $data = $request->validate([
            'college_id' => ['required', Rule::exists('colleges', 'id')],
            'major_id' => ['required', Rule::exists('majors', 'id')],
            'name' => ['required', 'string', 'max:50'],
            'grade' => ['nullable', 'string', 'max:20'],
            'is_active' => ['boolean'],
        ]);

        abort_unless($this->access->canAccessCollege($request->user(), (int) $data['college_id']), 403);

        $major = Major::findOrFail($data['major_id']);

        if ((int) $major->college_id !== (int) $data['college_id']) {
            throw ValidationException::withMessages(['major_id' => '专业不属于所选学院。']);
        }

        $data['grade'] = ClassNaming::normalizeGrade($data['grade'] ?? null)
            ?? ClassNaming::parse($data['name'])['grade'];

        $exists = SchoolClass::where('college_id', $data['college_id'])
            ->where('major_id', $data['major_id'])
            ->where('name', $data['name'])
            ->where('grade', $data['grade'] ?: null)
            ->whereKeyNot($schoolClass->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => '该学院下已存在同名班级。']);
        }

        $schoolClass->update($data);
        $this->scores->audit($request->user(), 'update_class', $schoolClass);

        return back()->with('success', '班级信息已更新。');
    }

    public function destroyClass(Request $request, SchoolClass $schoolClass): RedirectResponse
    {
        abort_unless($this->access->canAccessClass($request->user(), $schoolClass->id), 403);

        if ($schoolClass->users()->exists()) {
            return back()->with('error', '该班级仍有学生，不能删除。');
        }

        $schoolClass->delete();
        $this->scores->audit($request->user(), 'delete_class', null, ['name' => $schoolClass->name]);

        return back()->with('success', '班级已删除。');
    }

    public function assignCollegeAdmin(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')],
            'college_id' => ['required', Rule::exists('colleges', 'id')],
        ], [], [
            'user_id' => '用户',
            'college_id' => '学院',
        ]);

        $user = User::findOrFail($data['user_id']);
        $college = College::findOrFail($data['college_id']);

        if ($user->isAdmin()) {
            throw ValidationException::withMessages(['user_id' => '超级管理员无需授权学院。']);
        }

        $user->update([
            'role' => 'college_admin',
            'college_id' => $college->id,
            'college' => $college->name,
            'class_id' => null,
            'class_name' => null,
        ]);

        $user->managedClasses()->detach();
        $user->managedColleges()->syncWithoutDetaching([
            $college->id => ['granted_by' => $request->user()->id],
        ]);

        $this->scores->audit($request->user(), 'assign_college_admin', $user, [
            'college' => $college->name,
        ]);

        return back()->with('success', "已将 {$user->name} 授权为 {$college->name} 学院管理员。");
    }

    public function revokeCollegeAdmin(Request $request, User $user, College $college): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $user->managedColleges()->detach($college->id);

        if ($user->managedColleges()->count() === 0 && $user->isCollegeAdmin()) {
            $user->update([
                'role' => $this->fallbackRole($user),
            ]);
        }

        $this->scores->audit($request->user(), 'revoke_college_admin', $user, [
            'college' => $college->name,
        ]);

        return back()->with('success', '学院管理员授权已撤销。');
    }

    private function fallbackRole(User $user): string
    {
        if ($user->student_id) {
            return 'student';
        }

        return $user->employee_id ? 'teacher' : 'student';
    }

    public function assignGradeAdmin(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')],
            'major_id' => ['required', Rule::exists('majors', 'id')],
            'grade' => ['required', 'string', 'max:20'],
        ], [], [
            'user_id' => '用户',
            'major_id' => '专业',
            'grade' => '年级',
        ]);

        $major = Major::findOrFail($data['major_id']);
        $user = User::findOrFail($data['user_id']);

        abort_unless($this->access->canAccessCollege($request->user(), $major->college_id), 403);

        if ($user->isAdmin() || $user->isCollegeAdmin()) {
            throw ValidationException::withMessages(['user_id' => '管理员账号无需再授权辅导员。']);
        }

        if ((int) $user->college_id !== (int) $major->college_id) {
            throw ValidationException::withMessages(['user_id' => '只能授权该学院内的用户。']);
        }

        $classExists = SchoolClass::where('major_id', $major->id)->where('grade', $data['grade'])->exists();

        if (! $classExists) {
            throw ValidationException::withMessages(['grade' => '该专业下没有此年级的班级。']);
        }

        $user->update([
            'role' => 'class_admin',
            'college_id' => $major->college_id,
            'college' => $major->college?->name,
            'major_id' => null,
        ]);

        GradeAdminScope::firstOrCreate(
            [
                'user_id' => $user->id,
                'college_id' => $major->college_id,
                'major_id' => $major->id,
                'grade' => $data['grade'],
            ],
            ['granted_by' => $request->user()->id]
        );

        $this->scores->audit($request->user(), 'assign_grade_admin', $user, [
            'major' => $major->name,
            'grade' => $data['grade'],
        ]);

        return back()->with('success', "已将 {$user->name} 授权为 {$major->name} {$data['grade']} 辅导员。");
    }

    public function revokeGradeAdmin(Request $request, GradeAdminScope $gradeScope): RedirectResponse
    {
        abort_unless($this->access->canAccessCollege($request->user(), $gradeScope->college_id), 403);

        $user = $gradeScope->user;
        $gradeScope->delete();

        if ($user && $user->managedClasses()->count() === 0 && $user->gradeAdminScopes()->count() === 0 && $user->isClassAdmin()) {
            $user->update(['role' => $this->fallbackRole($user)]);
        }

        $this->scores->audit($request->user(), 'revoke_grade_admin', $user, [
            'college_id' => $gradeScope->college_id,
            'major_id' => $gradeScope->major_id,
            'grade' => $gradeScope->grade,
        ]);

        return back()->with('success', '年级辅导员授权已撤销。');
    }

    public function assignClassAdmin(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')],
            'class_id' => ['required', Rule::exists('classes', 'id')],
        ], [], [
            'user_id' => '用户',
            'class_id' => '班级',
        ]);

        $class = SchoolClass::findOrFail($data['class_id']);
        $user = User::findOrFail($data['user_id']);

        abort_unless($this->access->canAssignClassAdmin($request->user(), $class), 403);

        if ($user->isAdmin() || $user->isCollegeAdmin()) {
            throw ValidationException::withMessages(['user_id' => '管理员账号无需再授权班级。']);
        }

        if ((int) $user->college_id !== (int) $class->college_id) {
            throw ValidationException::withMessages(['user_id' => '只能授权该班级所属学院内的用户。']);
        }

        $user->update([
            'role' => 'class_admin',
            'college_id' => $class->college_id,
            'college' => $class->college?->name,
        ]);

        $user->managedClasses()->syncWithoutDetaching([
            $class->id => ['granted_by' => $request->user()->id],
        ]);

        $this->scores->audit($request->user(), 'assign_class_admin', $user, [
            'class' => $class->name,
        ]);

        return back()->with('success', "已将 {$user->name} 授权为 {$class->name} 班级管理员。");
    }

    public function syncClassAdmins(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')],
            'college_id' => ['required', Rule::exists('colleges', 'id')],
            'class_ids' => ['nullable', 'array'],
            'class_ids.*' => ['integer', Rule::exists('classes', 'id')],
        ], [], [
            'user_id' => '辅导员',
            'college_id' => '学院',
            'class_ids' => '负责班级',
        ]);

        abort_unless($this->access->canAccessCollege($request->user(), (int) $data['college_id']), 403);

        $user = User::findOrFail($data['user_id']);
        $college = College::findOrFail($data['college_id']);

        if ($user->isAdmin() || $user->isCollegeAdmin()) {
            throw ValidationException::withMessages(['user_id' => '管理员账号无需再配置辅导员班级。']);
        }

        if ((int) $user->college_id !== (int) $college->id) {
            throw ValidationException::withMessages(['user_id' => '只能配置该学院内的用户。']);
        }

        $classIds = array_values(array_unique(array_map('intval', $data['class_ids'] ?? [])));
        $classes = $classIds === []
            ? collect()
            : SchoolClass::whereIn('id', $classIds)->get();

        if ($classes->count() !== count($classIds)) {
            throw ValidationException::withMessages(['class_ids' => '所选班级不存在。']);
        }

        if ($classes->contains(fn (SchoolClass $class) => (int) $class->college_id !== (int) $college->id)) {
            throw ValidationException::withMessages(['class_ids' => '所选班级不属于该学院。']);
        }

        $sync = $classes->mapWithKeys(fn (SchoolClass $class) => [
            $class->id => ['granted_by' => $request->user()->id],
        ])->all();

        $user->managedClasses()->sync($sync);

        if ($classIds !== [] || $user->gradeAdminScopes()->exists()) {
            $user->update([
                'role' => 'class_admin',
                'college_id' => $college->id,
                'college' => $college->name,
            ]);
        } elseif ($user->isClassAdmin()) {
            $user->update(['role' => $this->fallbackRole($user)]);
        }

        $this->scores->audit($request->user(), 'sync_class_admin', $user, [
            'college' => $college->name,
            'classes' => $classes->pluck('name')->all(),
        ]);

        return back()->with('success', "已更新 {$user->name} 的辅导员班级范围，共 ".count($classIds).' 个班。');
    }

    public function revokeClassAdmin(Request $request, User $user, SchoolClass $schoolClass): RedirectResponse
    {
        abort_unless($this->access->canAssignClassAdmin($request->user(), $schoolClass), 403);
        $user->managedClasses()->detach($schoolClass->id);

        if ($user->managedClasses()->count() === 0 && $user->gradeAdminScopes()->count() === 0 && $user->isClassAdmin()) {
            $user->update([
                'role' => $this->fallbackRole($user),
            ]);
        }

        $this->scores->audit($request->user(), 'revoke_class_admin', $user, [
            'class' => $schoolClass->name,
        ]);

        return back()->with('success', '班级管理员授权已撤销。');
    }

    public function assignClassOfficer(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')],
            'class_id' => ['required', Rule::exists('classes', 'id')],
            'title' => ['required', 'string', 'max:30'],
        ], [], [
            'user_id' => '学生',
            'class_id' => '班级',
            'title' => '职务',
        ]);

        $class = SchoolClass::findOrFail($data['class_id']);
        $user = User::findOrFail($data['user_id']);

        abort_unless($this->access->canAssignClassOfficer($request->user(), $class), 403);

        if (! $user->isStudent()) {
            throw ValidationException::withMessages(['user_id' => '只能将学生设置为班干部。']);
        }

        if ((int) $user->class_id !== (int) $class->id) {
            throw ValidationException::withMessages(['user_id' => '只能在本班学生中任命班干部。']);
        }

        $user->officerClasses()->syncWithoutDetaching([
            $class->id => [
                'title' => trim($data['title']),
                'granted_by' => $request->user()->id,
            ],
        ]);

        $this->scores->audit($request->user(), 'assign_class_officer', $user, [
            'class' => $class->name,
            'title' => trim($data['title']),
        ]);

        return back()->with('success', "已将 {$user->name} 设置为 {$class->name} {$data['title']}。");
    }

    public function revokeClassOfficer(Request $request, User $user, SchoolClass $schoolClass): RedirectResponse
    {
        abort_unless($this->access->canAssignClassOfficer($request->user(), $schoolClass), 403);
        $user->officerClasses()->detach($schoolClass->id);

        $this->scores->audit($request->user(), 'revoke_class_officer', $user, [
            'class' => $schoolClass->name,
        ]);

        return back()->with('success', '班干部任命已撤销。');
    }
}
