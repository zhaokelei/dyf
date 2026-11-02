<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\Major;
use App\Models\SchoolClass;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RegistrationChoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_hub_offers_student_and_teacher_identities(): void
    {
        Setting::put('registration_enabled', '1');
        Setting::put('teacher_registration_enabled', '1');

        $this->get('/register')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/RegisterChoice')
                ->where('studentEnabled', true)
                ->where('teacherEnabled', true)
                ->where('hasClasses', false));
    }

    public function test_student_registration_has_a_separate_route_and_reports_missing_classes(): void
    {
        Setting::put('registration_enabled', '1');

        $this->get('/register/student')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Register')
                ->where('enabled', true)
                ->where('hasClasses', false));

        $college = College::create(['name' => '信息工程学院', 'is_active' => true]);
        $major = Major::create([
            'college_id' => $college->id,
            'name' => '计算机科学与技术',
            'is_active' => true,
        ]);
        SchoolClass::create([
            'college_id' => $college->id,
            'major_id' => $major->id,
            'name' => '计算机科学与技术2601班',
            'grade' => '2026级',
            'is_active' => true,
        ]);

        $this->get('/register/student')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Register')
                ->where('hasClasses', true));
    }
}
