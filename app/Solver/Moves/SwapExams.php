<?php

namespace App\Solver\Moves;

use App\Solver\Schedule;

/**
 * İki sınavın yerlerini (saat dilimi ve derslik) değiştirir.
 *
 * Tek sınav taşımanın tıkandığı yerde işe yarar: çizelge dolduğunda boş
 * yer kalmaz, ama iki sınavın yer değiştirmesi hâlâ mümkündür.
 *
 * Önemli ayrıntı: uygulama sırasında önce iki sınav da yerinden kaldırılır,
 * sonra yerleştirilir. Sırayla yapılırsa ikinci atama birincinin üstüne
 * yazar ve doluluk indeksi bozulur.
 */
final class SwapExams implements Move
{
    public function __construct(
        public readonly int $examA,
        public readonly int $slotA,
        public readonly int $roomA,
        public readonly int $examB,
        public readonly int $slotB,
        public readonly int $roomB,
    ) {}

    public static function from(Schedule $schedule, int $examA, int $examB): self
    {
        return new self(
            examA: $examA,
            slotA: (int) $schedule->slotOf($examA),
            roomA: (int) $schedule->roomOf($examA),
            examB: $examB,
            slotB: (int) $schedule->slotOf($examB),
            roomB: (int) $schedule->roomOf($examB),
        );
    }

    public function affectedExams(): array
    {
        return [$this->examA, $this->examB];
    }

    public function targets(): array
    {
        return [
            $this->examA => ['slot' => $this->slotB, 'room' => $this->roomB],
            $this->examB => ['slot' => $this->slotA, 'room' => $this->roomA],
        ];
    }

    public function applyTo(Schedule $schedule): void
    {
        $schedule->unassign($this->examA);
        $schedule->unassign($this->examB);

        $schedule->assign($this->examA, $this->slotB, $this->roomB);
        $schedule->assign($this->examB, $this->slotA, $this->roomA);
    }

    public function revert(Schedule $schedule): void
    {
        $schedule->unassign($this->examA);
        $schedule->unassign($this->examB);

        $schedule->assign($this->examA, $this->slotA, $this->roomA);
        $schedule->assign($this->examB, $this->slotB, $this->roomB);
    }
}
