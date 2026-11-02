<?php

namespace Tests\Unit;

use App\Support\ClassNaming;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ClassNamingTest extends TestCase
{
    #[DataProvider('classNames')]
    public function test_it_parses_real_class_names(string $className, ?string $major, ?string $grade): void
    {
        $this->assertSame(['major' => $major, 'grade' => $grade], ClassNaming::parse($className));
    }

    public static function classNames(): array
    {
        return [
            'grade and section suffix' => ['计算机科学与技术2601班', '计算机科学与技术', '2026级'],
            'explicit grade suffix' => ['计算机科学与技术2026级1班', '计算机科学与技术', '2026级'],
            'three digit section' => ['计算机科学与技术2026级100班', '计算机科学与技术', '2026级'],
            'full year and section suffix' => ['计算机科学与技术202601班', '计算机科学与技术', '2026级'],
            'grade prefix' => ['2026级计算机科学与技术1班', '计算机科学与技术', '2026级'],
            'section only' => ['计算机科学与技术1班', '计算机科学与技术', null],
            'legacy short class' => ['公安71', '公安', null],
            'major only' => ['软件工程', '软件工程', null],
            'long major with digits' => ['数据科学与大数据技术2602班', '数据科学与大数据技术', '2026级'],
        ];
    }

    public function test_it_normalizes_grades(): void
    {
        $this->assertSame('2026级', ClassNaming::normalizeGrade('2026'));
        $this->assertSame('2026级', ClassNaming::normalizeGrade('26级'));
        $this->assertSame('2026级', ClassNaming::normalizeGrade('2026级'));
        $this->assertSame('大一', ClassNaming::normalizeGrade('大一'));
        $this->assertNull(ClassNaming::normalizeGrade(''));
    }
}
