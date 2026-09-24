<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Slot extends Model
{
    use BelongsToTenant;

    protected $table = 'slots';

    protected $fillable = ['tenant_id', 'day', 'index_in_day', 'starts_at'];

    protected $casts = [
        'day' => 'date',
        'index_in_day' => 'integer',
    ];

    /** Ekranlarda gosterilen etiket: 12.06.2026 09:00 */
    public function label(): string
    {
        return $this->day->format('d.m.Y').' '.substr((string) $this->starts_at, 0, 5);
    }
}
