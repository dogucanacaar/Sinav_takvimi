<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Exam extends Model
{
    use BelongsToTenant;

    protected $table = 'exams';

    protected $fillable = ['tenant_id', 'course_id', 'student_count', 'duration_min'];

    protected $casts = [
        'student_count' => 'integer',
        'duration_min' => 'integer',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
