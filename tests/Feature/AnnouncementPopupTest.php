<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AnnouncementPopupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'username' => 'admin-announcement',
            'name' => '公告管理员',
            'password' => 'secret123',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->student = User::create([
            'username' => '3201',
            'name' => '公告学生',
            'password' => 'secret123',
            'role' => 'student',
            'student_id' => '3201',
            'is_active' => true,
        ]);

        Semester::create([
            'academic_year' => '2026-2027',
            'term' => 1,
            'name' => '2026-2027学年第一学期',
            'is_current' => true,
        ]);
    }

    public function test_creating_popup_announcement_sanitizes_html(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/announcements', [
                'title' => '安全公告',
                'content' => '<p onclick="alert(1)">正文<script>alert(1)</script>'
                    .'<a href="javascript:alert(1)">危险链接</a>'
                    .'<a href="https://example.com" target="_self">安全链接</a>'
                    .'<strong>加粗</strong><iframe src="https://evil.example"></iframe></p>'
                    .'<style>p{display:none}</style>',
                'is_popup' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $announcement = Announcement::where('title', '安全公告')->firstOrFail();

        $this->assertTrue($announcement->is_popup);
        $this->assertStringContainsString('<strong>加粗</strong>', $announcement->content);
        $this->assertStringContainsString('href="https://example.com"', $announcement->content);
        $this->assertStringContainsString('target="_blank"', $announcement->content);
        $this->assertStringNotContainsString('<script', $announcement->content);
        $this->assertStringNotContainsString('<style', $announcement->content);
        $this->assertStringNotContainsString('<iframe', $announcement->content);
        $this->assertStringNotContainsString('onclick', $announcement->content);
        $this->assertStringNotContainsString('javascript:', $announcement->content);
    }

    public function test_inline_styles_are_kept_while_dangerous_css_is_removed(): void
    {
        $html = '<div style="max-width:820px;margin:0 auto;background:#fff;border-radius:20px;'
            .'box-shadow:0 10px 40px rgba(37,99,235,0.12);overflow:hidden;position:relative;'
            .'font-family:system-ui,\'Microsoft YaHei\',sans-serif;">'
            .'<div style="background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:#fff;'
            .'display:flex;align-items:center;gap:12px;padding:28px 36px;">'
            .'<span style="position:fixed;top:0;left:0;width:expression(alert(1));'
            .'background:url(javascript:alert(1));font-size:22px;">⚠️</span>'
            .'<h2 style="margin:0;font-size:24px;font-weight:600;letter-spacing:1px;">安全通知</h2>'
            .'</div></div>';

        $this->actingAs($this->admin)
            ->post('/admin/announcements', [
                'title' => '样式公告',
                'content' => $html,
                'is_popup' => false,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $content = Announcement::where('title', '样式公告')->firstOrFail()->content;

        $this->assertStringContainsString('max-width:820px', $content);
        $this->assertStringContainsString('border-radius:20px', $content);
        $this->assertStringContainsString('box-shadow:0 10px 40px rgba(37,99,235,0.12)', $content);
        $this->assertStringContainsString('background:linear-gradient(135deg,#1d4ed8,#3b82f6)', $content);
        $this->assertStringContainsString('display:flex', $content);
        $this->assertStringContainsString('font-family:system-ui', $content);
        $this->assertStringContainsString('font-size:22px', $content);

        $this->assertStringNotContainsString('position:fixed', $content);
        $this->assertStringNotContainsString('url(', $content);
        $this->assertStringNotContainsString('expression', $content);
        $this->assertStringNotContainsString('javascript:', $content);
    }

    public function test_embedded_content_and_event_handlers_are_stripped(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/announcements', [
                'title' => '注入测试',
                'content' => '<p>正文</p><img src=x onerror="alert(1)">'
                    .'<svg onload="alert(1)"><circle r="10" /></svg>'
                    .'<a href="java&#x73;cript:alert(1)">链接</a>'
                    .'<p style="background:u/**/rl(javascript:alert(1));color:red">样式</p>',
                'is_popup' => false,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $content = Announcement::where('title', '注入测试')->firstOrFail()->content;

        $this->assertStringNotContainsString('<img', $content);
        $this->assertStringNotContainsString('<svg', $content);
        $this->assertStringNotContainsString('onerror', $content);
        $this->assertStringNotContainsString('onload', $content);
        $this->assertStringNotContainsString('javascript:', $content);
        $this->assertStringNotContainsString('u/**/rl', $content);
        $this->assertStringContainsString('color:red', $content);
    }

    public function test_popup_flag_can_be_disabled_when_updating(): void
    {
        $announcement = $this->makeAnnouncement(['is_popup' => true]);

        $this->actingAs($this->admin)
            ->put("/admin/announcements/{$announcement->id}", [
                'title' => $announcement->title,
                'content' => '<p>更新内容</p>',
                'is_popup' => false,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertFalse($announcement->refresh()->is_popup);
    }

    public function test_student_dashboard_receives_latest_popup_announcement(): void
    {
        $this->makeAnnouncement([
            'title' => '旧弹窗公告',
            'is_popup' => true,
            'published_at' => now()->subDay(),
        ]);

        $latest = $this->makeAnnouncement([
            'title' => '最新弹窗公告',
            'content' => '<p>最新内容 <strong>重点</strong></p>',
            'is_popup' => true,
            'published_at' => now(),
        ]);

        $this->makeAnnouncement([
            'title' => '更晚但非弹窗',
            'is_popup' => false,
            'published_at' => now()->addMinute(),
        ]);

        $this->actingAs($this->student)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('mode', 'student')
                ->where('popupAnnouncement.id', $latest->id)
                ->where('popupAnnouncement.title', '最新弹窗公告')
                ->where('popupAnnouncement.content', '<p>最新内容 <strong>重点</strong></p>'));
    }

    public function test_teacher_dashboard_does_not_receive_popup_announcement(): void
    {
        $teacher = User::create([
            'username' => 'teacher-announcement',
            'name' => '公告教师',
            'password' => 'secret123',
            'role' => 'teacher',
            'is_active' => true,
        ]);

        $this->makeAnnouncement(['is_popup' => true]);

        $this->actingAs($teacher)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('mode', 'teacher')
                ->missing('popupAnnouncement'));
    }

    public function test_announcement_list_exposes_plain_excerpt_and_flags(): void
    {
        $this->makeAnnouncement([
            'title' => 'HTML 公告',
            'content' => '<p>第一段 <strong>重点</strong></p><p>第二段</p>',
            'is_popup' => true,
        ]);

        $this->actingAs($this->student)
            ->get('/announcements')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Announcements/Index')
                ->where('announcements.data.0.title', 'HTML 公告')
                ->where('announcements.data.0.excerpt', '第一段 重点 第二段')
                ->where('announcements.data.0.is_popup', true)
                ->where('announcements.data.0.author', '公告管理员')
                ->where('announcements.data.0.content', '<p>第一段 <strong>重点</strong></p><p>第二段</p>'));
    }

    private function makeAnnouncement(array $overrides = []): Announcement
    {
        return Announcement::create([
            'title' => '测试公告',
            'content' => '<p>测试内容</p>',
            'is_important' => false,
            'is_popup' => false,
            'author_id' => $this->admin->id,
            'published_at' => now(),
            ...$overrides,
        ]);
    }
}
