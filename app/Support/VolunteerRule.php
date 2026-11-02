<?php

namespace App\Support;

use App\Models\ScoreApplication;
use App\Models\ScoreCategory;
use App\Models\ScoreRecord;

class VolunteerRule
{
    public const REQUIRED_COUNT = 4;

    public const BONUS_SCORE = 6.0;

    public const BONUS_SOURCE = 'volunteer_bonus';

    public static function category(): ?ScoreCategory
    {
        return ScoreCategory::query()->where('code', 'volunteer')->first();
    }

    public static function approvedCount(int $userId, int $semesterId, ?int $excludeApplicationId = null): int
    {
        $category = static::category();

        if (! $category) {
            return 0;
        }

        return ScoreApplication::query()
            ->where('user_id', $userId)
            ->where('category_id', $category->id)
            ->where('semester_id', $semesterId)
            ->where('status', 'approved')
            ->when($excludeApplicationId, fn ($query) => $query->whereKeyNot($excludeApplicationId))
            ->count();
    }

    public static function limitReached(int $userId, int $semesterId): bool
    {
        return static::approvedCount($userId, $semesterId) >= self::REQUIRED_COUNT;
    }

    public static function bonusAwarded(int $userId, int $semesterId): bool
    {
        return ScoreRecord::query()
            ->where('user_id', $userId)
            ->where('semester_id', $semesterId)
            ->where('source_type', self::BONUS_SOURCE)
            ->whereNull('revoked_at')
            ->exists();
    }

    /**
     * @return array{category_id: int|null, required_count: int, approved_count: int, bonus_score: float, limit_reached: bool, bonus_awarded: bool}
     */
    public static function progress(int $userId, int $semesterId): array
    {
        $category = static::category();
        $approved = $category ? static::approvedCount($userId, $semesterId) : 0;

        return [
            'category_id' => $category?->id,
            'required_count' => self::REQUIRED_COUNT,
            'approved_count' => $approved,
            'bonus_score' => self::BONUS_SCORE,
            'limit_reached' => $approved >= self::REQUIRED_COUNT,
            'bonus_awarded' => $category ? static::bonusAwarded($userId, $semesterId) : false,
        ];
    }
}
