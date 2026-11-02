<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\College;
use App\Models\Major;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\User;
use App\Services\ScoreService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisterController extends Controller
{
    public function __construct(private readonly ScoreService $scores)
    {
    }

    public function choice(): Response
    {
        return Inertia::render('Auth/RegisterChoice', [
            'studentEnabled' => Setting::get('registration_enabled', '1') === '1',
            'teacherEnabled' => Setting::get('teacher_registration_enabled', '0') === '1',
            'hasClasses' => SchoolClass::query()->where('is_active', true)->exists(),
        ]);
    }

    public function create(): Response
    {
        if (Setting::get('registration_enabled', '1') !== '1') {
            return Inertia::render('Auth/Register', [
                'enabled' => false,
                'hasClasses' => false,
                'colleges' => [],
                'classes' => [],
                'requiresApproval' => false,
            ]);
        }

        $colleges = College::query()
            ->where('is_active', true)
            ->whereHas('majors', fn ($query) => $query->where('is_active', true))
            ->with(['majors' => fn ($query) => $query->where('is_active', true)->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(fn (College $college) => [
                'id' => $college->id,
                'name' => $college->name,
                'majors' => $college->majors->map(fn (Major $major) => [
                    'id' => $major->id,
                    'name' => $major->name,
                ]),
            ]);

        $classes = SchoolClass::query()
            ->where('is_active', true)
            ->whereNotNull('major_id')
            ->orderBy('grade')
            ->orderBy('name')
            ->get(['id', 'college_id', 'major_id', 'name', 'grade']);

        return Inertia::render('Auth/Register', [
            'enabled' => true,
            'hasClasses' => $classes->isNotEmpty(),
            'colleges' => $colleges,
            'classes' => $classes,
            'requiresApproval' => Setting::get('registration_requires_approval', '0') === '1',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (Setting::get('registration_enabled', '1') !== '1') {
            throw ValidationException::withMessages([
                'student_id' => '学生自主注册暂未开放，请联系管理员。',
            ]);
        }

        $key = 'register|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'student_id' => '注册尝试过于频繁，请稍后再试。',
            ]);
        }

        $data = $request->validate([
            'student_id' => [
                'required',
                'string',
                'max:30',
                Rule::unique('users', 'username'),
                Rule::unique('users', 'student_id'),
            ],
            'name' => ['required', 'string', 'max:100'],
            'college_id' => ['required', Rule::exists('colleges', 'id')->where('is_active', true)],
            'major_id' => ['required', Rule::exists('majors', 'id')->where('is_active', true)],
            'grade' => ['required', 'string', 'max:20'],
            'class_id' => ['required', Rule::exists('classes', 'id')->where('is_active', true)],
            'password' => ['required', 'string', 'min:8', 'max:100', 'confirmed'],
        ], [], [
            'student_id' => '学号',
            'name' => '姓名',
            'college_id' => '学院',
            'major_id' => '专业',
            'grade' => '年级',
            'class_id' => '班级',
            'password' => '密码',
        ]);

        $studentId = trim($data['student_id']);
        $class = SchoolClass::query()
            ->whereKey($data['class_id'])
            ->where('college_id', $data['college_id'])
            ->where('major_id', $data['major_id'])
            ->where('grade', $data['grade'])
            ->first();

        if (! $class) {
            throw ValidationException::withMessages([
                'class_id' => '所选班级与学院、专业、年级不匹配。',
            ]);
        }

        $college = College::find($data['college_id']);
        $major = Major::find($data['major_id']);
        $requiresApproval = Setting::get('registration_requires_approval', '0') === '1';

        $user = User::create([
            'username' => $studentId,
            'student_id' => $studentId,
            'name' => $data['name'],
            'role' => 'student',
            'college_id' => $college->id,
            'college' => $college->name,
            'major_id' => $major->id,
            'class_id' => $class->id,
            'class_name' => $class->name,
            'grade' => $class->grade,
            'password' => $data['password'],
            'is_active' => ! $requiresApproval,
        ]);

        RateLimiter::hit($key, 3600);
        $this->scores->audit($user, 'register_student', $user, [
            'college' => $college->name,
            'major' => $major->name,
            'class' => $class->name,
        ]);

        if ($requiresApproval) {
            return redirect()
                ->route('login')
                ->with('success', '注册成功，请等待管理员审核后登录。');
        }

        Auth::login($user);

        return redirect()->route('dashboard')->with('success', '注册成功，欢迎使用德育分管理系统。');
    }
}
