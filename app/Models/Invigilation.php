<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invigilation extends Model
{
    protected $table = 'invigilations';

    public $timestamps = false;

    public $incrementing = false;

    protected $fillable = ['solution_id', 'exam_id', 'lecturer_id'];

    public function lecturer(): BelongsTo
    {
        return $this->belongsTo(Lecturer::class);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }
}
