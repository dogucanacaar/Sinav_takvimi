<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Room extends Model
{
    use BelongsToTenant;

    protected $table = 'rooms';

    protected $fillable = ['tenant_id', 'building_id', 'name', 'capacity'];

    protected $casts = ['capacity' => 'integer'];

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }
}
