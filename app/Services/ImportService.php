<?php

namespace App\Services;

use App\Models\College;
use App\Models\Major;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\ClassNaming;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

class ImportService
{
    /**
     * @return array{students:int,teachers:int,admins:int,skipped:int,unrecognized:int}
     */
    public function importUsers(UploadedFile $file): array
    {
        $sheets = $this->loadSheets($file);
        $counts = ['students' => 0, 'teachers' => 0, 'admins' => 0, 'skipped' => 0, 'unrecognized' => 0];

        DB::transaction(function () use ($sheets, &$counts) {
            foreach ($sheets as $sheetName => $rows) {
                $sheetRole = $this->detectRole($sheetName, $rows);

                if ($rows === []) {
                    continue;
                }

                $header = array_shift($rows);
                $columns = $this->mapHeader($header);

                if (! $sheetRole && $columns['role'] === null) {
                    $counts['unrecognized']++;
                    continue;
                }

                if ($columns['account'] === null || $columns['name'] === null) {
                    $counts['unrecognized']++;
                    continue;
                }

                foreach ($rows as $row) {
                    $role = $columns['role'] !== null
                        ? $this->detectRowRole($row[$columns['role']] ?? null)
                        : $sheetRole;

                    if (! $role) {
                        continue;
                    }

                    $name = trim((string) ($row[$columns['name']] ?? ''));
                    $account = $this->normalizeId($row[$columns['account']] ?? '');

                    if ($account === '' || $name === '') {
                        continue;
                    }

                    $payload = [
                        'username' => $account,
                        'name' => $name,
                        'role' => $role,
                        'is_active' => true,
                    ];

                    if ($role === 'student') {
                        $payload['student_id'] = $account;
                        $payload['class_name'] = $this->clean($row[$columns['class_name']] ?? null);
                        $payload['college'] = $this->clean($row[$columns['college']] ?? null);
                        $payload['grade'] = $this->clean($row[$columns['grade']] ?? null);
                        $majorName = $this->clean($row[$columns['major']] ?? null);
                    } elseif ($role === 'teacher') {
                        $payload['employee_id'] = $account;
                        $payload['teacher_college'] = $this->clean($row[$columns['college']] ?? null);
                    } else {
                        $payload['employee_id'] = $account;
                    }

                    if ($role === 'student') {
                        $college = $this->resolveCollege($payload['college'] ?? null);
                        $identity = ClassNaming::parse($payload['class_name'] ?? null);
                        $payload['grade'] = ClassNaming::normalizeGrade($payload['grade'] ?? null) ?? $identity['grade'];
                        $major = $this->resolveMajor($college?->id, $majorName, $payload['class_name'] ?? null);
                        $payload['college_id'] = $college?->id;
                        $payload['major_id'] = $major?->id;
                        $payload['class_id'] = $this->resolveClass(
                            $college?->id,
                            $major?->id,
                            $payload['class_name'] ?? null,
                            $payload['grade'] ?? null
                        );

                        if (! $payload['class_id']) {
                            $matched = $this->matchClassByMajorAndGrade(
                                $college?->id,
                                $major?->id,
                                $payload['grade'] ?? null
                            );

                            if ($matched) {
                                $payload['class_id'] = $matched->id;
                                $payload['class_name'] = $matched->name;
                            }
                        }
                    } elseif ($role === 'teacher') {
                        $college = $this->resolveCollege($payload['teacher_college'] ?? null);
                        $payload['college_id'] = $college?->id;
                        $payload['teacher_college_id'] = $college?->id;
                    }

                    $user = User::where('username', $account)->first();

                    if ($user) {
                        unset($payload['is_active']);

                        if (in_array($user->role, ['admin', 'college_admin', 'class_admin'], true)) {
                            unset($payload['role']);
                        }

                        $user->update($payload);
                        $counts['skipped']++;
                        continue;
                    }

                    $payload['password'] = Hash::make($account);
                    User::create($payload);

                    match ($role) {
                        'student' => $counts['students']++,
                        'teacher' => $counts['teachers']++,
                        'admin' => $counts['admins']++,
                    };
                }
            }
        });

        return $counts;
    }

    /**
     * @return array{members: array<int, array{student_id:int, username:string, name:string, class_name:?string, score:int}>, errors: array<int, string>}
     */
    public function parseGroupMembers(UploadedFile $file): array
    {
        $sheetRows = $this->loadSheets($file);
        $rows = reset($sheetRows);

        if (! $rows) {
            return ['members' => [], 'errors' => ['文件为空或无法读取。']];
        }

        $header = array_shift($rows);
        $columns = $this->mapHeader($header);

        if ($columns['account'] === null || $columns['score'] === null) {
            return ['members' => [], 'errors' => ['表头必须包含：学号、姓名、分值。']];
        }

        $errors = [];
        $members = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $account = $this->normalizeId($row[$columns['account']] ?? '');
            $name = trim((string) ($row[$columns['name']] ?? ''));
            $scoreRaw = $row[$columns['score']] ?? null;

            if ($account === '' && $name === '') {
                continue;
            }

            if ($account === '') {
                $errors[] = "第 {$line} 行：学号为空。";
                continue;
            }

            if (isset($seen[$account])) {
                $errors[] = "第 {$line} 行：学号 {$account} 在文件中重复。";
                continue;
            }

            $scoreValue = is_numeric($scoreRaw) ? (float) $scoreRaw : null;

            if ($scoreValue === null || $scoreValue <= 0 || abs(fmod($scoreValue, 0.5)) > 0.001) {
                $errors[] = "第 {$line} 行：分值必须大于 0，且为 0.5 的倍数（如 0.5、1、1.5）。";
                continue;
            }

            $seen[$account] = true;
            $student = User::where('student_id', $account)
                ->where('role', 'student')
                ->first();

            if (! $student) {
                $errors[] = "第 {$line} 行：未找到学号为 {$account} 的学生。";
                continue;
            }

            if (! $student->is_active) {
                $errors[] = "第 {$line} 行：学生 {$student->name} 已被禁用。";
                continue;
            }

            $members[] = [
                'student_id' => $student->id,
                'username' => (string) $student->student_id,
                'name' => $student->name,
                'class_name' => $student->class_name,
                'score' => $scoreValue,
            ];
        }

        return ['members' => $members, 'errors' => $errors];
    }

    /**
     * @return array<string, array<int, array<int, mixed>>>
     */
    private function loadSheets(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'csv' || $extension === 'txt') {
            $content = file_get_contents($path);
            $encoding = str_starts_with($content, "\xEF\xBB\xBF")
                ? 'UTF-8'
                : (mb_check_encoding($content, 'UTF-8') ? 'UTF-8' : 'GBK');

            $reader = new Csv();
            $reader->setInputEncoding($encoding);
            $reader->setDelimiter(',');
            $spreadsheet = $reader->load($path);

            $name = trim(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));

            return [($name !== '' ? $name : 'CSV') => $spreadsheet->getActiveSheet()->toArray(null, true, true, false)];
        }

        $spreadsheet = IOFactory::load($path);
        $sheets = [];

        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $sheets[$worksheet->getTitle()] = $worksheet->toArray(null, true, true, false);
        }

        return $sheets;
    }

    private function detectRole(string $sheetName, array $rows): ?string
    {
        if (str_contains($sheetName, '学生')) {
            return 'student';
        }

        if (str_contains($sheetName, '教师')) {
            return 'teacher';
        }

        if (str_contains($sheetName, '审核') || str_contains($sheetName, '管理')) {
            return 'admin';
        }

        return null;
    }

    private function detectRowRole(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        if (str_contains($value, '学生') || strcasecmp($value, 'student') === 0) {
            return 'student';
        }

        if (str_contains($value, '教师') || str_contains($value, '老师') || strcasecmp($value, 'teacher') === 0) {
            return 'teacher';
        }

        if (str_contains($value, '审核') || str_contains($value, '管理') || strcasecmp($value, 'admin') === 0) {
            return 'admin';
        }

        return null;
    }

    /**
     * @return array{account:?int,name:?int,role:?int,score:?int,class_name:?int,college:?int,grade:?int,major:?int}
     */
    private function mapHeader(array $header): array
    {
        $map = [
            'account' => null,
            'name' => null,
            'role' => null,
            'score' => null,
            'class_name' => null,
            'college' => null,
            'grade' => null,
            'major' => null,
        ];

        foreach ($header as $index => $title) {
            $title = trim((string) $title);

            if (in_array($title, ['学号', '工号', '账号'], true)) {
                $map['account'] ??= $index;
            } elseif ($title === '姓名') {
                $map['name'] = $index;
            } elseif (in_array($title, ['角色', '身份', '人员类型'], true)) {
                $map['role'] = $index;
            } elseif ($title === '分值' || $title === '分数') {
                $map['score'] = $index;
            } elseif ($title === '班级') {
                $map['class_name'] = $index;
            } elseif (in_array($title, ['书院', '学院', '系部'], true)) {
                $map['college'] = $index;
            } elseif (in_array($title, ['专业', '专业名称'], true)) {
                $map['major'] = $index;
            } elseif ($title === '年级') {
                $map['grade'] = $index;
            }
        }

        return $map;
    }

    private function resolveCollege(?string $name): ?College
    {
        $name = trim((string) $name);

        return $name === '' ? null : College::firstOrCreate(['name' => $name]);
    }

    private function resolveMajor(?int $collegeId, ?string $majorName, ?string $className): ?Major
    {
        if (! $collegeId) {
            return null;
        }

        $name = trim((string) $majorName) ?: ClassNaming::parse($className)['major'] ?: '综合';

        return Major::firstOrCreate([
            'college_id' => $collegeId,
            'name' => $name,
        ]);
    }

    private function resolveClass(?int $collegeId, ?int $majorId, ?string $name, ?string $grade): ?int
    {
        $name = trim((string) $name);

        if (! $collegeId || $name === '') {
            return null;
        }

        $class = SchoolClass::firstOrCreate(
            [
                'college_id' => $collegeId,
                'name' => $name,
                'grade' => $grade ?: null,
            ],
            ['major_id' => $majorId]
        );

        if (! $class->major_id && $majorId) {
            $class->update(['major_id' => $majorId]);
        }

        return $class->id;
    }

    private function matchClassByMajorAndGrade(?int $collegeId, ?int $majorId, ?string $grade): ?SchoolClass
    {
        if (! $majorId || ! $grade) {
            return null;
        }

        $classes = SchoolClass::query()
            ->where('major_id', $majorId)
            ->when($collegeId, fn ($q) => $q->where('college_id', $collegeId))
            ->where('grade', $grade)
            ->limit(2)
            ->get();

        return $classes->count() === 1 ? $classes->first() : null;
    }

    private function normalizeId(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_float($value) && floor($value) === $value) {
            return number_format($value, 0, '', '');
        }

        return trim((string) $value);
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
