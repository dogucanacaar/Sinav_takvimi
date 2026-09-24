<?php

namespace App\Solver;

/**
 * Çizelgenin kendisi: hangi sınav hangi (saat dilimi, derslik) ikilisinde.
 *
 * Üç indeks birlikte tutulur. Üçü de aynı bilgiyi farklı yönden gösterir;
 * amaç her sorgunun O(1) olmasıdır:
 *   examSlot / examRoom : sınavdan yerine
 *   occupied            : yerden sınava (K2 kontrolü için)
 */
final class Schedule
{
    /** @var array<int,int> examId => slotId */
    private array $examSlot = [];

    /** @var array<int,int> examId => roomId */
    private array $examRoom = [];

    /** @var array<int,array<int,int>> slotId => roomId => examId */
    private array $occupied = [];

    /**
     * Sınavı verilen yere yerleştirir.
     *
     * Orada başka bir sınav varsa o sınav yerinden kaldırılır. Bu, K2'yi
     * veri yapısı düzeyinde imkânsız kılar: bir (saat, derslik) ikilisi
     * her zaman en fazla bir sınav tutar. Motor zaten dolu bir yere atama
     * yapmaz (HardConstraints engeller); buradaki davranış, bir hata
     * durumunda indekslerin sessizce tutarsızlaşmasını önlemek içindir.
     */
    public function assign(int $examId, int $slotId, int $roomId): void
    {
        if (isset($this->examSlot[$examId])) {
            $this->unassign($examId);
        }

        $occupant = $this->occupied[$slotId][$roomId] ?? null;

        if ($occupant !== null && $occupant !== $examId) {
            $this->unassign($occupant);
        }

        $this->examSlot[$examId] = $slotId;
        $this->examRoom[$examId] = $roomId;
        $this->occupied[$slotId][$roomId] = $examId;
    }

    public function unassign(int $examId): void
    {
        if (! isset($this->examSlot[$examId])) {
            return;
        }

        $slotId = $this->examSlot[$examId];
        $roomId = $this->examRoom[$examId];

        unset($this->occupied[$slotId][$roomId], $this->examSlot[$examId], $this->examRoom[$examId]);
    }

    public function isAssigned(int $examId): bool
    {
        return isset($this->examSlot[$examId]);
    }

    public function slotOf(int $examId): ?int
    {
        return $this->examSlot[$examId] ?? null;
    }

    public function roomOf(int $examId): ?int
    {
        return $this->examRoom[$examId] ?? null;
    }

    /** Verilen yerde oturan sınav; boşsa null. */
    public function occupantOf(int $slotId, int $roomId): ?int
    {
        return $this->occupied[$slotId][$roomId] ?? null;
    }

    /** @return int[] yerleşmiş sınav id'leri */
    public function assignedExams(): array
    {
        return array_keys($this->examSlot);
    }

    public function assignedCount(): int
    {
        return count($this->examSlot);
    }

    /** @return array<int,array{0:int,1:int}> examId => [slotId, roomId] */
    public function entries(): array
    {
        $out = [];

        foreach ($this->examSlot as $examId => $slotId) {
            $out[$examId] = [$slotId, $this->examRoom[$examId]];
        }

        return $out;
    }

    /**
     * En iyi çizelgeyi saklamak için ucuz bir kopya.
     * Sadece iki dizi kopyalanır; occupied yeniden kurulabildiği için tutulmaz.
     */
    public function snapshot(): array
    {
        return [$this->examSlot, $this->examRoom];
    }

    public static function fromSnapshot(array $snapshot): self
    {
        $schedule = new self;
        [$examSlot, $examRoom] = $snapshot;

        foreach ($examSlot as $examId => $slotId) {
            $schedule->assign($examId, $slotId, $examRoom[$examId]);
        }

        return $schedule;
    }

    public function restore(array $snapshot): void
    {
        $this->examSlot = [];
        $this->examRoom = [];
        $this->occupied = [];

        [$examSlot, $examRoom] = $snapshot;

        foreach ($examSlot as $examId => $slotId) {
            $this->assign($examId, $slotId, $examRoom[$examId]);
        }
    }
}
