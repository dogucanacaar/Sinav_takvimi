<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Lecturer extends Model
{
    use BelongsToTenant;

    protected $table = 'lecturers';

    protected $fillable = ['tenant_id', 'name', 'title', 'department', 'past_duty_count'];

    protected $casts = ['past_duty_count' => 'integer'];

    public function unavailableSlots(): BelongsToMany
    {
        return $this->belongsToMany(Slot::class, 'lecturer_unavailability');
    }
}
