<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'account' => ['required', 'string', 'max:80'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ]);

        $key = Str::lower($credentials['account']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'account' => '尝试次数过多，请 1 分钟后再试。',
            ]);
        }

        $user = User::query()
            ->where('username', $credentials['account'])
            ->orWhere('student_id', $credentials['account'])
            ->orWhere('employee_id', $credentials['account'])
            ->first();

        if (! $user) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'account' => '该学号/工号尚未注册，请确认账号或前往学生注册。',
            ]);
        }

        if (! $user->is_active) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'account' => '账号已被禁用，请联系学院或学校管理员。',
            ]);
        }

        if (! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'password' => '密码错误，请重新输入。',
            ]);
        }

        RateLimiter::clear($key);

        Auth::login($user, (bool) ($credentials['remember'] ?? false));
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('dashboard'))
            ->with('success', "登录成功，欢迎回来，{$user->name}！");
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', '已安全退出登录。');
    }
}
