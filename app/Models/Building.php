<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Building extends Model
{
    use BelongsToTenant;

    protected $table = 'buildings';

    protected $fillable = ['tenant_id', 'name'];

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }
}
