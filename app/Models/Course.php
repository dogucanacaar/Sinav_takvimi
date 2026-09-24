<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Course extends Model
{
    use BelongsToTenant;

    protected $table = 'courses';

    protected $fillable = ['tenant_id', 'code', 'name', 'department', 'lecturer_id'];

    public function lecturer(): BelongsTo
    {
        return $this->belongsTo(Lecturer::class);
    }

    public function exam(): HasOne
    {
        return $this->hasOne(Exam::class);
    }
}
