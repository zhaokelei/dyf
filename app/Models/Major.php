<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Major extends Model
{
    protected $fillable = [
        'college_id',
        'name',
        'code',
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

    public function classes()
    {
        return $this->hasMany(SchoolClass::class)->orderBy('grade')->orderBy('name');
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
