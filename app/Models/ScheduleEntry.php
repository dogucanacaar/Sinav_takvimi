<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleEntry extends Model
{
    protected $table = 'schedule_entries';

    public $timestamps = false;

    public $incrementing = false;

    protected $fillable = ['solution_id', 'exam_id', 'slot_id', 'room_id'];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(Slot::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
