<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchoolClass extends Model
{
    protected $table = 'classes';

    protected $fillable = [
        'college_id',
        'major_id',
        'name',
        'grade',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function college()
    {
        return $this->belongsTo(College::class);
    }

    public function major()
    {
        return $this->belongsTo(Major::class);
    }

    public function users()
    {
        return $this->hasMany(User::class, 'class_id');
    }

    public function admins()
    {
        return $this->belongsToMany(User::class, 'class_admin_user', 'class_id', 'user_id')
            ->withPivot('granted_by')
            ->withTimestamps();
    }

    public function officers()
    {
        return $this->belongsToMany(User::class, 'class_officer_user', 'class_id', 'user_id')
            ->withPivot(['title', 'granted_by'])
            ->withTimestamps();
    }

    public function displayName(): string
    {
        return $this->grade ? "{$this->name}（{$this->grade}）" : $this->name;
    }
}
