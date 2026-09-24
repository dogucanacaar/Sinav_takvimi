<?php

namespace App\Solver\Moves;

use App\Solver\Schedule;

/**
 * Bir sınavı başka bir (saat dilimi, derslik) ikilisine taşır.
 */
final class MoveExam implements Move
{
    public function __construct(
        public readonly int $examId,
        public readonly int $fromSlot,
        public readonly int $fromRoom,
        public readonly int $toSlot,
        public readonly int $toRoom,
    ) {}

    public static function from(Schedule $schedule, int $examId, int $toSlot, int $toRoom): self
    {
        return new self(
            examId: $examId,
            fromSlot: (int) $schedule->slotOf($examId),
            fromRoom: (int) $schedule->roomOf($examId),
            toSlot: $toSlot,
            toRoom: $toRoom,
        );
    }

    public function affectedExams(): array
    {
        return [$this->examId];
    }

    public function targets(): array
    {
        return [$this->examId => ['slot' => $this->toSlot, 'room' => $this->toRoom]];
    }

    public function isNoop(): bool
    {
        return $this->toSlot === $this->fromSlot && $this->toRoom === $this->fromRoom;
    }

    public function applyTo(Schedule $schedule): void
    {
        $schedule->assign($this->examId, $this->toSlot, $this->toRoom);
    }

    public function revert(Schedule $schedule): void
    {
        $schedule->assign($this->examId, $this->fromSlot, $this->fromRoom);
    }
}
