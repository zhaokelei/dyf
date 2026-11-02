<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class College extends Model
{
    protected $fillable = [
        'name',
        'code',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function majors()
    {
        return $this->hasMany(Major::class)->orderBy('name');
    }

    public function classes()
    {
        return $this->hasMany(SchoolClass::class)->orderBy('grade')->orderBy('name');
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function admins()
    {
        return $this->belongsToMany(User::class, 'college_admin_user')
            ->withPivot('granted_by')
            ->withTimestamps();
    }
}
