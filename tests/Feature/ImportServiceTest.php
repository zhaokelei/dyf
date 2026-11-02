<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\Major;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_import_builds_college_major_grade_and_class_chain(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('学生端');
        $sheet->fromArray([
            ['学号', '姓名', '学院', '专业', '班级'],
            ['2026001', '张三', '信息工学院', '计算机科学与技术', '计算机科学与技术2601班'],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'dyf-import-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $file = new UploadedFile(
            $path,
            'students.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $counts = app(ImportService::class)->importUsers($file);

        $this->assertSame(1, $counts['students']);

        $college = College::where('name', '信息工学院')->firstOrFail();
        $major = Major::where('college_id', $college->id)->where('name', '计算机科学与技术')->firstOrFail();
        $class = SchoolClass::where('name', '计算机科学与技术2601班')->firstOrFail();
        $student = User::where('student_id', '2026001')->firstOrFail();

        $this->assertSame($major->id, $class->major_id);
        $this->assertSame('2026级', $class->grade);
        $this->assertSame($college->id, $student->college_id);
        $this->assertSame($major->id, $student->major_id);
        $this->assertSame($class->id, $student->class_id);
        $this->assertSame('2026级', $student->grade);

        @unlink($path);
    }

    public function test_student_import_matches_class_by_major_and_grade_when_blank(): void
    {
        $college = College::create(['name' => '信息工学院']);
        $major = Major::create(['college_id' => $college->id, 'name' => '计算机科学与技术']);
        $class = SchoolClass::create([
            'college_id' => $college->id,
            'major_id' => $major->id,
            'name' => '计算机科学与技术2601班',
            'grade' => '2026级',
        ]);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('学生');
        $sheet->fromArray([
            ['学号', '姓名', '学院', '专业', '年级'],
            ['2026010', '匹配学生', '信息工学院', '计算机科学与技术', '2026级'],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'dyf-import-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $file = new UploadedFile($path, 'students.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $counts = app(ImportService::class)->importUsers($file);
        $student = User::where('student_id', '2026010')->firstOrFail();

        $this->assertSame(1, $counts['students']);
        $this->assertSame($class->id, $student->class_id);
        $this->assertSame('计算机科学与技术2601班', $student->class_name);

        @unlink($path);
    }

    public function test_student_import_does_not_guess_when_multiple_classes_match(): void
    {
        $college = College::create(['name' => '信息工学院']);
        $major = Major::create(['college_id' => $college->id, 'name' => '计算机科学与技术']);

        foreach (['计算机科学与技术2601班', '计算机科学与技术2602班'] as $name) {
            SchoolClass::create([
                'college_id' => $college->id,
                'major_id' => $major->id,
                'name' => $name,
                'grade' => '2026级',
            ]);
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('学生');
        $sheet->fromArray([
            ['学号', '姓名', '学院', '专业', '年级'],
            ['2026011', '歧义学生', '信息工学院', '计算机科学与技术', '2026级'],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'dyf-import-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $file = new UploadedFile($path, 'students.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        app(ImportService::class)->importUsers($file);
        $student = User::where('student_id', '2026011')->firstOrFail();

        $this->assertNull($student->class_id);
        $this->assertNull($student->class_name);

        @unlink($path);
    }

    public function test_csv_import_uses_filename_to_detect_role(): void
    {
        $counts = app(ImportService::class)->importUsers($this->csvFile(
            '学生名单.csv',
            "学号,姓名,学院,专业,班级\n2026002,李四,信息工学院,计算机科学与技术,计算机科学与技术2601班\n"
        ));

        $this->assertSame(1, $counts['students']);
        $this->assertSame(0, $counts['unrecognized']);

        $student = User::where('student_id', '2026002')->firstOrFail();
        $this->assertSame('student', $student->role);
    }

    public function test_csv_import_supports_role_column_for_mixed_rows(): void
    {
        $counts = app(ImportService::class)->importUsers($this->csvFile(
            '混合名单.csv',
            "账号,姓名,角色,学院\nT1001,王老师,教师,信息工学院\n2026100,李同学,学生,信息工学院\n"
        ));

        $this->assertSame(1, $counts['students']);
        $this->assertSame(1, $counts['teachers']);
        $this->assertSame('teacher', User::where('username', 'T1001')->firstOrFail()->role);
        $this->assertSame('student', User::where('username', '2026100')->firstOrFail()->role);
    }

    public function test_csv_import_without_role_information_is_reported(): void
    {
        $counts = app(ImportService::class)->importUsers($this->csvFile(
            '数据.csv',
            "学号,姓名\n2026003,王五\n"
        ));

        $this->assertSame(1, $counts['unrecognized']);
        $this->assertSame(0, $counts['students']);
        $this->assertSame(0, User::where('student_id', '2026003')->count());
    }

    public function test_import_endpoint_accepts_csv_with_filename_role(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->post('/admin/users/import', [
            'file' => $this->csvFile('学生名单.csv', "学号,姓名\n2026004,赵六\n"),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('users', ['student_id' => '2026004', 'role' => 'student']);
    }

    public function test_import_endpoint_reports_unrecognized_file(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->post('/admin/users/import', [
            'file' => $this->csvFile('数据.csv', "学号,姓名\n2026005,孙七\n"),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('users', ['student_id' => '2026005']);
    }

    public function test_user_import_template_has_expected_sheets(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get('/admin/users/import/template');
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'dyf-template-').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        try {
            $spreadsheet = IOFactory::load($path);

            $this->assertSame(['学生', '教师', '审核端', '使用说明'], $spreadsheet->getSheetNames());
            $this->assertSame('学号', $spreadsheet->getSheetByName('学生')->getCell('A1')->getValue());
            $this->assertSame('2026000001', $spreadsheet->getSheetByName('学生')->getCell('A2')->getValue());
            $this->assertSame('工号', $spreadsheet->getSheetByName('教师')->getCell('A1')->getValue());
            $this->assertSame('工号', $spreadsheet->getSheetByName('审核端')->getCell('A1')->getValue());
        } finally {
            @unlink($path);
        }
    }

    private function makeAdmin(): User
    {
        return User::create([
            'username' => 'template-admin',
            'name' => '模板管理员',
            'password' => 'secret123',
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    private function csvFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'dyf-csv-');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }
}
