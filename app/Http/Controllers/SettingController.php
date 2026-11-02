<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\ScoreService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SettingController extends Controller
{
    public function __construct(private readonly ScoreService $scores)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Settings/Index', [
            'settings' => [
                'base_score' => (int) Setting::get('base_score', '40'),
                'system_name' => Setting::get('system_name', config('app.name')),
                'registration_enabled' => Setting::get('registration_enabled', '1') === '1',
                'registration_requires_approval' => Setting::get('registration_requires_approval', '0') === '1',
                'teacher_registration_enabled' => Setting::get('teacher_registration_enabled', '0') === '1',
                'teacher_registration_code' => Setting::get('teacher_registration_code', ''),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'base_score' => ['required', 'integer', 'min:0', 'max:1000'],
            'system_name' => ['required', 'string', 'max:100'],
            'registration_enabled' => ['boolean'],
            'registration_requires_approval' => ['boolean'],
            'teacher_registration_enabled' => ['boolean'],
            'teacher_registration_code' => ['nullable', 'string', 'max:50'],
        ], [], [
            'base_score' => '基础分',
            'system_name' => '系统名称',
            'teacher_registration_code' => '教师注册邀请码',
        ]);

        $teacherRegistrationEnabled = ! empty($data['teacher_registration_enabled']);
        $teacherRegistrationCode = trim((string) ($data['teacher_registration_code'] ?? ''));

        if ($teacherRegistrationCode !== '' && ! preg_match('/^[A-Za-z0-9_-]{6,50}$/', $teacherRegistrationCode)) {
            throw ValidationException::withMessages([
                'teacher_registration_code' => '教师邀请码需为 6-50 位字母、数字、下划线或短横线。',
            ]);
        }

        if ($teacherRegistrationEnabled && $teacherRegistrationCode === '') {
            throw ValidationException::withMessages([
                'teacher_registration_code' => '开放教师注册前，请设置教师邀请码。',
            ]);
        }

        Setting::put('base_score', (string) $data['base_score']);
        Setting::put('system_name', $data['system_name']);
        Setting::put('registration_enabled', ! empty($data['registration_enabled']) ? '1' : '0');
        Setting::put('registration_requires_approval', ! empty($data['registration_requires_approval']) ? '1' : '0');
        Setting::put('teacher_registration_enabled', $teacherRegistrationEnabled ? '1' : '0');
        Setting::put('teacher_registration_code', $teacherRegistrationCode);

        $audit = $data;
        unset($audit['teacher_registration_code']);

        $this->scores->audit($request->user(), 'update_settings', null, $audit);

        return back()->with('success', '系统设置已保存。');
    }
}
