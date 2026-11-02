<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScoreRecord extends Model
{
    protected $fillable = [
        'user_id',
        'category_id',
        'semester_id',
        'score',
        'applied_on',
        'source_type',
        'source_id',
        'description',
        'created_by',
        'revoked_at',
        'revoked_by',
        'revoke_reason',
    ];

    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
            'applied_on' => 'date',
            'score' => 'float',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function category()
    {
        return $this->belongsTo(ScoreCategory::class, 'category_id');
    }

    public function semester()
    {
        return $this->belongsTo(Semester::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function personalApplication()
    {
        return $this->belongsTo(ScoreApplication::class, 'source_id');
    }

    public function groupApplication()
    {
        return $this->belongsTo(GroupApplication::class, 'source_id');
    }

    public function revoker()
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function appeals()
    {
        return $this->hasMany(Appeal::class);
    }

    public function latestAppeal()
    {
        return $this->hasOne(Appeal::class)->latestOfMany();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
