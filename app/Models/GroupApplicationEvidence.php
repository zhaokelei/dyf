<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroupApplicationEvidence extends Model
{
    protected $table = 'group_application_evidences';

    protected $fillable = [
        'group_application_id',
        'path',
        'name',
        'sort_order',
    ];

    public function groupApplication()
    {
        return $this->belongsTo(GroupApplication::class);
    }
}
