<?php

namespace Database\Seeders;

use App\Models\College;
use App\Models\Major;
use App\Models\ScoreCategory;
use App\Models\Semester;
use App\Models\Setting;
use App\Models\User;
use App\Support\SemesterStore;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSettings();
        $this->seedSemesters();
        $this->seedCategories();
        $this->seedAdmin();
        $this->seedOrganizationCatalog();
        app(SemesterStore::class)->forget();

        $this->command?->info('教师注册邀请码：'.Setting::get('teacher_registration_code'));
    }

    private function seedSettings(): void
    {
        Setting::put('base_score', '40');
        Setting::put('system_name', '郑州工商学院德育分管理系统');
        Setting::put('registration_enabled', '1');
        Setting::put('registration_requires_approval', '1');
        Setting::put('teacher_registration_enabled', '1');

        if (! Setting::get('teacher_registration_code')) {
            Setting::put('teacher_registration_code', Str::upper(Str::random(10)));
        }
    }

    private function seedSemesters(): void
    {
        foreach ([1, 2] as $term) {
            $semester = Semester::firstOrCreate(
                ['academic_year' => '2026-2027', 'term' => $term],
                [
                    'name' => '2026-2027学年'.($term === 1 ? '第一学期' : '第二学期'),
                    'is_current' => false,
                ]
            );

            if ($term === 1 && ! Semester::where('is_current', true)->exists()) {
                $semester->update(['is_current' => true]);
            }
        }
    }

    private function seedCategories(): void
    {
        $categories = [
            ['name' => '校级活动', 'type' => 'add', 'description' => '校级活动加分', 'sort_order' => 10],
            ['name' => '学院级活动', 'type' => 'add', 'description' => '学院级活动加分', 'sort_order' => 20],
            ['name' => '志愿服务', 'type' => 'add', 'description' => '志愿服务加分', 'sort_order' => 30],
            ['name' => '扣分', 'type' => 'deduct', 'description' => '违纪或不良行为扣分', 'sort_order' => 90],
        ];

        foreach ($categories as $category) {
            ScoreCategory::updateOrCreate(
                ['name' => $category['name']],
                $category + ['is_active' => true]
            );
        }
    }

    private function seedAdmin(): void
    {
        if (User::query()->where('role', 'admin')->exists()) {
            return;
        }

        User::updateOrCreate(
            ['username' => 'admin'],
            [
                'name' => '系统管理员',
                'role' => 'admin',
                'password' => Hash::make('admin123'),
                'is_active' => true,
            ]
        );
    }

    private function seedOrganizationCatalog(): void
    {
        $catalog = [
            '信息工程学院' => [
                '计算机科学与技术',
                '软件工程',
                '网络工程',
                '物联网工程',
                '数据科学与大数据技术',
                '电子信息工程',
            ],
            '工学院' => [
                '机械设计制造及其自动化',
                '机械电子工程',
                '电气工程及其自动化',
                '土木工程',
                '地理信息科学',
                '宝石及材料工艺学',
            ],
            '商学院' => [
                '工商管理',
                '市场营销',
                '电子商务',
                '旅游管理',
                '物流管理',
                '人力资源管理',
            ],
            '财税学院' => [
                '会计学',
                '审计学',
                '财务管理',
            ],
            '文法学院' => [
                '法学',
                '英语',
                '小学教育',
                '学前教育',
            ],
            '艺术学院' => [
                '视觉传达设计',
                '环境设计',
                '产品设计',
                '服装与服饰设计',
                '广告学',
                '音乐表演',
                '舞蹈表演',
                '播音与主持艺术',
                '表演',
            ],
            '体育学院' => [
                '运动康复',
                '社会体育指导与管理',
            ],
        ];

        foreach ($catalog as $collegeName => $majorNames) {
            $college = College::firstOrCreate(
                ['name' => $collegeName],
                ['is_active' => true]
            );

            foreach ($majorNames as $majorName) {
                Major::firstOrCreate(
                    ['college_id' => $college->id, 'name' => $majorName],
                    ['is_active' => true]
                );
            }
        }
    }
}
