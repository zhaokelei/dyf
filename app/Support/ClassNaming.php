<?php

namespace App\Support;

class ClassNaming
{
    /**
     * @return array{major:?string, grade:?string}
     */
    public static function parse(?string $className): array
    {
        $name = trim((string) $className);

        if ($name === '') {
            return ['major' => null, 'grade' => null];
        }

        $name = preg_replace('/\s+/u', '', $name);
        $name = preg_replace('/班$/u', '', $name);
        $name = trim($name, "-－—_,，.。");

        if (preg_match('/^(20\d{2})级(.+?)(\d{1,3})$/u', $name, $matches)) {
            return ['major' => $matches[2], 'grade' => $matches[1].'级'];
        }

        if (preg_match('/^(.+?)(20\d{2})级(\d{1,3})$/u', $name, $matches)) {
            return ['major' => $matches[1], 'grade' => $matches[2].'级'];
        }

        if (preg_match('/^(.+?)(20\d{2})(\d{2})$/u', $name, $matches)) {
            return ['major' => $matches[1], 'grade' => $matches[2].'级'];
        }

        if (preg_match('/^(.+?)(\d{2})(\d{2})$/u', $name, $matches) && mb_strlen($matches[1]) >= 2) {
            return ['major' => $matches[1], 'grade' => '20'.$matches[2].'级'];
        }

        if (preg_match('/^(.+?)\d+$/u', $name, $matches) && mb_strlen($matches[1]) >= 2) {
            return ['major' => $matches[1], 'grade' => null];
        }

        return ['major' => $name ?: null, 'grade' => null];
    }

    public static function normalizeGrade(?string $grade): ?string
    {
        $grade = trim((string) $grade);

        if ($grade === '') {
            return null;
        }

        if (preg_match('/(20\d{2})/', $grade, $matches)) {
            return $matches[1].'级';
        }

        if (preg_match('/^(\d{2})级?$/', $grade, $matches)) {
            return '20'.$matches[1].'级';
        }

        return $grade;
    }
}
