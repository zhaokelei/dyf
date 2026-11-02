<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function editPassword(): Response
    {
        return Inertia::render('Profile/Password');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:100', 'confirmed'],
        ], [], [
            'current_password' => '当前密码',
            'password' => '新密码',
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => '当前密码不正确。',
            ]);
        }

        $user->forceFill([
            'password' => $data['password'],
            'remember_token' => Str::random(60),
        ])->save();

        Auth::logout();
        $this->forgetSessions($user->id);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', '密码修改成功，请使用新密码重新登录。');
    }

    private function forgetSessions(int $userId): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $userId)
            ->delete();
    }
}
