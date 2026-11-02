<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\College;
use App\Models\Setting;
use App\Models\User;
use App\Services\ScoreService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TeacherRegisterController extends Controller
{
    public function __construct(private readonly ScoreService $scores)
    {
    }

    public function create(): Response
    {
        $enabled = Setting::get('teacher_registration_enabled', '0') === '1';

        return Inertia::render('Auth/TeacherRegister', [
            'enabled' => $enabled,
            'colleges' => $enabled ? $this->collegeOptions() : [],
            'requiresApproval' => false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (Setting::get('teacher_registration_enabled', '0') !== '1') {
            throw ValidationException::withMessages([
                'employee_id' => '教师自主注册暂未开放，请联系管理员。',
            ]);
        }

        $key = 'register-teacher|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'employee_id' => '注册尝试过于频繁，请稍后再试。',
            ]);
        }

        $data = $request->validate([
            'employee_id' => [
                'required',
                'string',
                'max:30',
                Rule::unique('users', 'username'),
                Rule::unique('users', 'employee_id'),
            ],
            'name' => ['required', 'string', 'max:100'],
            'college_id' => ['required', Rule::exists('colleges', 'id')->where('is_active', true)],
            'invite_code' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:8', 'max:100', 'confirmed'],
        ], [], [
            'employee_id' => '工号',
            'name' => '姓名',
            'college_id' => '学院',
            'invite_code' => '教师邀请码',
            'password' => '密码',
        ]);

        $inviteCode = (string) Setting::get('teacher_registration_code', '');

        if ($inviteCode === '' || ! hash_equals($inviteCode, trim($data['invite_code']))) {
            RateLimiter::hit($key, 3600);

            throw ValidationException::withMessages([
                'invite_code' => '教师邀请码不正确，请向管理员获取。',
            ]);
        }

        $college = College::findOrFail($data['college_id']);
        $employeeId = trim($data['employee_id']);

        $user = User::create([
            'username' => $employeeId,
            'employee_id' => $employeeId,
            'name' => $data['name'],
            'role' => 'teacher',
            'college_id' => $college->id,
            'college' => $college->name,
            'major_id' => null,
            'teacher_college_id' => $college->id,
            'teacher_college' => $college->name,
            'class_id' => null,
            'class_name' => null,
            'grade' => null,
            'password' => $data['password'],
            'is_active' => true,
        ]);

        RateLimiter::hit($key, 3600);
        $this->scores->audit($user, 'register_teacher', $user, [
            'employee_id' => $employeeId,
            'college' => $college->name,
        ]);

        return redirect()
            ->route('login')
            ->with('success', '教师注册成功，请使用工号和密码登录。');
    }

    private function collegeOptions(): array
    {
        return College::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (College $college) => [
                'id' => $college->id,
                'name' => $college->name,
            ])
            ->all();
    }
}
