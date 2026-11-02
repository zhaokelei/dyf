<?php

namespace App\Http\Controllers;

use App\Models\College;
use App\Models\Major;
use App\Models\SchoolClass;
use App\Models\ScoreApplicationEvidence;
use App\Models\User;
use App\Services\ExportService;
use App\Services\ImportService;
use App\Services\ScoreService;
use App\Support\AccessScope;
use App\Support\PageSize;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserController extends Controller
{
    public function __construct(
        private readonly ImportService $imports,
        private readonly ScoreService $scores,
        private readonly AccessScope $access,
        private readonly ExportService $exports
    ) {
    }

    public function index(Request $request): Response
    {
        $actor = $request->user();
        $page = PageSize::resolve($request, 20);
        $base = User::query();
        $this->access->scopeManagedUsers($base, $actor);

        $query = (clone $base)
            ->with('major:id,name')
            ->when($request->input('role'), fn ($q, $role) => $q->where('role', $role))
            ->when($request->input('status'), fn ($q, $status) => $q->where('is_active', $status === 'active'))
            ->when($request->input('college'), fn ($q, $value) => $q->where('college', $value))
            ->when($request->input('major_id'), fn ($q, $value) => $q->where('major_id', $value))
            ->when($request->input('grade'), fn ($q, $value) => $q->where('grade', $value))
            ->when($request->input('class_name'), fn ($q, $value) => $q->where('class_name', $value))
            ->when($request->input('search'), function ($q, $value) {
                $q->where(function ($sub) use ($value) {
                    $sub->where('name', 'like', "%{$value}%")
                        ->orWhere('username', 'like', "%{$value}%")
                        ->orWhere('student_id', 'like', "%{$value}%")
                        ->orWhere('employee_id', 'like', "%{$value}%");
                });
            })
            ->orderBy('role')
            ->orderBy('student_id')
            ->orderBy('employee_id');

        $collegeIds = $this->access->collegeIds($actor);
        $classIds = $this->access->classIds($actor);

        return Inertia::render('Users/Index', [
            'users' => $query->paginate($page['limit'])->withQueryString(),
            'perPage' => $page['per_page'],
            'stats' => [
                'students' => (clone $base)->where('role', 'student')->count(),
                'teachers' => (clone $base)->where('role', 'teacher')->count(),
                'managers' => (clone $base)->whereIn('role', ['college_admin', 'class_admin'])->count(),
                'inactive' => (clone $base)->where('is_active', false)->count(),
                'pending_teachers' => (clone $base)
                    ->where('role', 'teacher')
                    ->where('is_active', false)
                    ->whereNull('last_login_at')
                    ->count(),
            ],
            'filterOptions' => [
                'colleges' => (clone $base)->whereNotNull('college')->distinct()->orderBy('college')->pluck('college'),
                'grades' => (clone $base)->whereNotNull('grade')->distinct()->orderBy('grade')->pluck('grade'),
                'classes' => (clone $base)->whereNotNull('class_name')->distinct()->orderBy('class_name')->pluck('class_name'),
                'majors' => Major::query()
                    ->whereIn('id', (clone $base)->whereNotNull('major_id')->distinct()->pluck('major_id'))
                    ->orderBy('name')
                    ->pluck('name'),
            ],
            'majors' => Major::query()
                ->when($collegeIds !== null, fn ($q) => $q->whereIn('college_id', $collegeIds))
                ->orderBy('name')
                ->get(['id', 'college_id', 'name']),
            'colleges' => College::query()
                ->when($collegeIds !== null, fn ($q) => $q->whereIn('id', $collegeIds))
                ->orderBy('name')
                ->get(['id', 'name']),
            'classes' => SchoolClass::query()
                ->when($classIds !== null, fn ($q) => $q->whereIn('id', $classIds))
                ->orderBy('name')
                ->get(['id', 'name', 'grade', 'college_id', 'major_id']),
            'canCreate' => $this->isSuper($actor) || $actor->isCollegeAdmin(),
            'creatableRoles' => $this->isSuper($actor)
                ? ['student', 'teacher', 'admin']
                : ($actor->isCollegeAdmin() ? ['student', 'teacher'] : []),
            'filters' => $request->only(['role', 'status', 'college', 'major_id', 'grade', 'class_name', 'search']),
            'scopeLabel' => $this->scopeLabel($actor),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $data = $request->validate([
            'role' => ['required', 'string'],
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'max:80', 'unique:users,username'],
            'student_id' => ['nullable', 'string', 'max:30', 'unique:users,student_id'],
            'employee_id' => ['nullable', 'string', 'max:30', 'unique:users,employee_id'],
            'class_name' => ['nullable', 'string', 'max:50'],
            'class_id' => ['nullable', Rule::exists('classes', 'id')],
            'major_id' => ['nullable', Rule::exists('majors', 'id')],
            'college' => ['nullable', 'string', 'max:100'],
            'college_id' => ['nullable', Rule::exists('colleges', 'id')],
            'grade' => ['nullable', 'string', 'max:20'],
            'teacher_college' => ['nullable', 'string', 'max:100'],
            'teacher_college_id' => ['nullable', Rule::exists('colleges', 'id')],
            'password' => ['nullable', 'string', 'min:8', 'max:100'],
            'is_active' => ['boolean'],
        ], [], [
            'username' => '登录账号',
            'student_id' => '学号',
            'employee_id' => '工号',
        ]);

        if (! $this->access->canCreateRole($actor, $data['role'])) {
            abort(403, '无权创建该角色。');
        }

        if (! $this->isSuper($actor)) {
            $collegeIds = $this->access->collegeIds($actor) ?? [];
            $collegeId = (int) ($data['college_id'] ?? 0);

            if (! in_array($collegeId, $collegeIds, true)) {
                throw ValidationException::withMessages(['college_id' => '只能创建授权学院内的用户。']);
            }
        }

        if (! $this->isSuper($actor) && ! empty($data['class_id']) && ! $this->access->canAccessClass($actor, (int) $data['class_id'])) {
            throw ValidationException::withMessages(['class_id' => '只能分配授权范围内的班级。']);
        }

        $data = $this->prepareOrganizationFields($data);
        $data['password'] = Hash::make($data['password'] ?? $data['username']);
        $data['is_active'] = $data['is_active'] ?? true;

        $user = User::create($data);
        $this->scores->audit($actor, 'create_user', $user, ['role' => $user->role]);

        return back()->with('success', "用户 {$user->name} 已创建。");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($this->access->canManageUser($actor, $user), 403);

        $data = $request->validate([
            'role' => ['required', 'string'],
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'max:80', Rule::unique('users', 'username')->ignore($user->id)],
            'student_id' => ['nullable', 'string', 'max:30', Rule::unique('users', 'student_id')->ignore($user->id)],
            'employee_id' => ['nullable', 'string', 'max:30', Rule::unique('users', 'employee_id')->ignore($user->id)],
            'class_name' => ['nullable', 'string', 'max:50'],
            'class_id' => ['nullable', Rule::exists('classes', 'id')],
            'major_id' => ['nullable', Rule::exists('majors', 'id')],
            'college' => ['nullable', 'string', 'max:100'],
            'college_id' => ['nullable', Rule::exists('colleges', 'id')],
            'grade' => ['nullable', 'string', 'max:20'],
            'teacher_college' => ['nullable', 'string', 'max:100'],
            'teacher_college_id' => ['nullable', Rule::exists('colleges', 'id')],
            'password' => ['nullable', 'string', 'min:8', 'max:100'],
            'is_active' => ['boolean'],
        ]);

        if (in_array($user->role, ['admin', 'college_admin', 'class_admin'], true)) {
            if ($data['role'] !== $user->role) {
                throw ValidationException::withMessages([
                    'role' => '管理员角色请在组织管理中调整。',
                ]);
            }
        } elseif ($data['role'] !== $user->role && ! $this->access->canCreateRole($actor, $data['role'])) {
            abort(403, '无权修改为该角色。');
        }

        if ($user->id === $actor->id) {
            if ($request->has('is_active') && ! $data['is_active']) {
                throw ValidationException::withMessages(['is_active' => '不能禁用当前登录账号。']);
            }

            if ($data['role'] !== 'admin' && $actor->isAdmin()) {
                throw ValidationException::withMessages(['role' => '不能修改当前登录账号的管理员角色。']);
            }
        }

        if ($actor->isCollegeAdmin()) {
            $collegeIds = $this->access->collegeIds($actor) ?? [];
            $collegeId = (int) ($data['college_id'] ?? $user->college_id);

            if (! in_array($collegeId, $collegeIds, true)) {
                throw ValidationException::withMessages(['college_id' => '只能管理授权学院内的用户。']);
            }

            $data['college_id'] = $collegeId;
        } elseif ($actor->isClassAdmin()) {
            $data['role'] = $user->role;
            $data['college_id'] = $user->college_id;
            $data['major_id'] = $user->major_id;
            $data['student_id'] = $data['student_id'] ?? $user->student_id;
            $data['class_id'] = $data['class_id'] ?? $user->class_id;
        }

        if (! $this->isSuper($actor) && ! empty($data['class_id']) && ! $this->access->canAccessClass($actor, (int) $data['class_id'])) {
            throw ValidationException::withMessages(['class_id' => '只能分配授权范围内的班级。']);
        }

        $data = $this->prepareOrganizationFields($data);

        $passwordChanged = ! empty($data['password']);

        if ($passwordChanged) {
            $data['password'] = Hash::make($data['password']);
            $user->forceFill(['remember_token' => Str::random(60)]);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        if ($passwordChanged) {
            $this->forgetSessions($user->id);
        }

        $this->scores->audit($actor, 'update_user', $user, ['role' => $user->role]);

        return back()->with(
            'success',
            $passwordChanged ? '用户信息已更新，该用户已被强制退出登录。' : '用户信息已更新。'
        );
    }

    public function toggle(Request $request, User $user): RedirectResponse
    {
        abort_unless($this->access->canManageUser($request->user(), $user), 403);

        if ($user->id === $request->user()->id) {
            return back()->with('error', '不能禁用当前登录账号。');
        }

        $user->update(['is_active' => ! $user->is_active]);

        if (! $user->is_active) {
            $this->forgetSessions($user->id, true);
        }

        $this->scores->audit(
            $request->user(),
            $user->is_active ? 'enable_user' : 'disable_user',
            $user
        );

        return back()->with('success', $user->is_active ? '账号已启用。' : '账号已禁用。');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor->isAdmin(), 403, '仅系统管理员可以删除用户。');

        if ($user->id === $actor->id) {
            return back()->with('error', '不能删除当前登录账号。');
        }

        $meta = [
            'name' => $user->name,
            'username' => $user->username,
            'role' => $user->role,
            'student_id' => $user->student_id,
        ];

        $paths = ScoreApplicationEvidence::query()
            ->whereIn('score_application_id', $user->applications()->select('id'))
            ->pluck('path')
            ->merge($user->applications()->whereNotNull('evidence_path')->pluck('evidence_path'))
            ->merge($user->groupApplications()->whereNotNull('evidence_path')->pluck('evidence_path'))
            ->merge($user->appeals()->whereNotNull('evidence_path')->pluck('evidence_path'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        DB::transaction(fn () => $user->delete());

        if ($paths !== []) {
            Storage::disk('local')->delete($paths);
        }

        $this->forgetSessions($user->id, true);
        $this->scores->audit($actor, 'delete_user', null, $meta);

        return back()->with('success', "用户 {$meta['name']} 已删除。");
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        abort_unless($this->access->canManageUser($request->user(), $user), 403);

        $actor = $request->user();
        $isSelf = (int) $actor->id === (int) $user->id;
        $account = $user->student_id ?: $user->employee_id ?: $user->username;

        $user->forceFill([
            'password' => Hash::make($account),
            'remember_token' => Str::random(60),
        ])->save();

        $this->scores->audit($actor, 'reset_password', $user);

        if ($isSelf) {
            Auth::logout();
        }

        $this->forgetSessions($user->id, true);

        if ($isSelf) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('success', "密码已重置为账号 {$account}，请重新登录。");
        }

        return back()->with('success', "密码已重置为账号：{$account}，该用户已被强制退出登录。");
    }

    public function importTemplate(): StreamedResponse
    {
        return $this->exports->userImportTemplate();
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:10240'],
        ], [], [
            'file' => '用户信息表',
        ]);

        $counts = $this->imports->importUsers($request->file('file'));

        $this->scores->audit($request->user(), 'import_users', null, $counts);

        if ($counts['students'] + $counts['teachers'] + $counts['admins'] + $counts['skipped'] === 0) {
            return back()->with(
                'error',
                '未识别到可导入的数据：请确认文件名或工作表名包含“学生/教师/审核”，或在表格中增加“角色”列后重试。'
            );
        }

        return back()->with(
            'success',
            "导入完成：新增学生 {$counts['students']} 人、教师 {$counts['teachers']} 人、管理员 {$counts['admins']} 人；已存在 {$counts['skipped']} 人。"
        );
    }

    private function forgetSessions(int $userId, bool $includeCurrent = false): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        $query = DB::table(config('session.table', 'sessions'))->where('user_id', $userId);

        if (! $includeCurrent) {
            $query->where('id', '!=', session()->getId());
        }

        $query->delete();
    }

    private function prepareOrganizationFields(array $data): array
    {
        $role = $data['role'] ?? 'student';
        $collegeId = $data['college_id'] ?? null;
        $majorId = $data['major_id'] ?? null;
        $classId = $data['class_id'] ?? null;

        if ($classId) {
            $class = SchoolClass::find($classId);

            if ($class) {
                $collegeId = $collegeId ?: $class->college_id;
                $majorId = $class->major_id;

                if ((int) $class->college_id !== (int) $collegeId) {
                    throw ValidationException::withMessages([
                        'class_id' => '班级不属于所选学院。',
                    ]);
                }
            }
        }

        if ($role === 'student' && ($collegeId || $majorId || $classId)) {
            $class = $classId ? SchoolClass::find($classId) : null;

            if ($class) {
                $collegeId = $class->college_id;
                $majorId = $class->major_id;
            }

            $major = $majorId ? Major::find($majorId) : null;

            if ($major && $collegeId && (int) $major->college_id !== (int) $collegeId) {
                throw ValidationException::withMessages([
                    'major_id' => '专业不属于所选学院。',
                ]);
            }

            $college = College::find($major?->college_id ?? $collegeId);

            $data['college_id'] = $college?->id;
            $data['college'] = $college?->name;
            $data['major_id'] = $major?->id;
            $data['class_id'] = $class?->id;
            $data['class_name'] = $class?->name;
            $data['grade'] = $class?->grade;
            $data['teacher_college_id'] = null;
            $data['teacher_college'] = null;
        } elseif ($role === 'teacher') {
            $teacherCollegeId = $data['teacher_college_id'] ?? $collegeId;
            $college = $teacherCollegeId ? College::find($teacherCollegeId) : null;
            $data['teacher_college_id'] = $college?->id;
            $data['college_id'] = $college?->id;
            $data['teacher_college'] = $college?->name;
            $data['college'] = $college?->name;
            $data['class_id'] = null;
            $data['class_name'] = null;
            $data['grade'] = null;
            $data['major_id'] = null;
        } elseif ($role === 'admin') {
            $data['college_id'] = null;
            $data['class_id'] = null;
            $data['college'] = null;
            $data['class_name'] = null;
            $data['grade'] = null;
            $data['teacher_college_id'] = null;
            $data['teacher_college'] = null;
            $data['major_id'] = null;
        } else {
            $data['college_id'] = $collegeId;
            $data['major_id'] = $majorId;
            $data['class_id'] = $classId;
        }

        return $data;
    }

    private function isSuper(User $user): bool
    {
        return $user->isAdmin();
    }

    private function scopeLabel(User $user): string
    {
        if ($user->isAdmin()) {
            return '全校';
        }

        if ($user->isCollegeAdmin()) {
            return College::whereIn('id', $this->access->collegeIds($user) ?? [])->pluck('name')->implode('、') ?: '未授权学院';
        }

        return SchoolClass::whereIn('id', $this->access->classIds($user) ?? [])->pluck('name')->implode('、') ?: '未授权班级';
    }
}
