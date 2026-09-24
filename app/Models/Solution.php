<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Solution extends Model
{
    use BelongsToTenant;

    protected $table = 'solutions';

    protected $fillable = ['tenant_id', 'label', 'status', 'params', 'penalty', 'hard_violations', 'stats', 'failure_reason', 'finished_at'];

    protected $casts = [
        'params' => 'array',
        'stats' => 'array',
        'penalty' => 'integer',
        'hard_violations' => 'integer',
        'finished_at' => 'datetime',
    ];

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public function entries(): HasMany
    {
        return $this->hasMany(ScheduleEntry::class);
    }

    public function invigilations(): HasMany
    {
        return $this->hasMany(Invigilation::class);
    }

    /** Ilerleme bilgisinin Redis'te tutuldugu anahtar. */
    public function progressKey(): string
    {
        return "solve:{$this->id}";
    }
}
