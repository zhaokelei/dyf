<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    protected $fillable = [
        'title',
        'content',
        'is_important',
        'is_popup',
        'author_id',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'is_important' => 'boolean',
            'is_popup' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
