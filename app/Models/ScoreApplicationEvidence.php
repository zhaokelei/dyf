<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScoreApplicationEvidence extends Model
{
    protected $table = 'score_application_evidences';

    protected $fillable = [
        'score_application_id',
        'path',
        'name',
        'sort_order',
    ];

    public function application()
    {
        return $this->belongsTo(ScoreApplication::class, 'score_application_id');
    }
}
