<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScoreCategory extends Model
{
    protected $fillable = [
        'name',
        'code',
        'type',
        'description',
        'default_score',
        'max_score',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'default_score' => 'float',
            'max_score' => 'float',
        ];
    }

    public function applications()
    {
        return $this->hasMany(ScoreApplication::class, 'category_id');
    }

    public function records()
    {
        return $this->hasMany(ScoreRecord::class, 'category_id');
    }

    public function isDeduct(): bool
    {
        return $this->type === 'deduct';
    }

    public function isVolunteer(): bool
    {
        return $this->code === 'volunteer';
    }

    public function typeLabel(): string
    {
        return $this->isDeduct() ? '扣分' : '加分';
    }
}
