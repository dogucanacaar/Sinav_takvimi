<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Student extends Model
{
    use BelongsToTenant;

    protected $table = 'students';

    protected $fillable = ['tenant_id', 'number'];

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'enrollments');
    }
}
