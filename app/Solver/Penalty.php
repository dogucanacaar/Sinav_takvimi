<?php

namespace App\Solver;

use App\Solver\Moves\Move;

/**
 * Esnek kuralların ceza puanı.
 *
 *   E1  Bir öğrenciye aynı gün birden fazla sınav düşmesi
 *   E2  Bir öğrenciye art arda iki saat diliminde sınav düşmesi
 *   E3  Öğrencinin aynı gün farklı binalarda sınava girmesi
 *   E4  Dersliğin boş kalan kapasitesinin çok olması (israf)
 *
 * Projedeki en kritik optimizasyon burada: total() bir kez çalışır,
 * sonra her hamlede sadece delta() çalışır. delta(), hamleden etkilenen
 * öğrencilerin katkısını eski ve yeni hâliyle yeniden puanlar; çizelgenin
 * geri kalanına hiç dokunmaz.
 *
 * Bir öğrencinin 5–7 sınavı olduğu için "o öğrencinin tüm katkısı" zaten
 * çok küçük bir hesaptır. Sadece etkilenen günleri ayıklamak yerine
 * öğrencinin tamamını yeniden puanlamak hem daha basit hem de pratikte
 * aynı hızdadır — ve total() ile birebir aynı kodu kullandığı için
 * ikisinin ayrışma riski ortadan kalkar.
 */
final class Penalty
{
    public function __construct(
        private readonly ProblemData $data,
        private readonly array $weights = ['E1' => 10, 'E2' => 25, 'E3' => 15, 'E4' => 1],
    ) {}

    /** Çizelgenin toplam ceza puanı — sıfırdan hesaplanır. */
    public function total(Schedule $schedule): int
    {
        $sum = 0;

        foreach ($this->data->studentExams as $examIds) {
            $sum += $this->studentPenalty($examIds, $schedule);
        }

        foreach ($schedule->assignedExams() as $examId) {
            $sum += $this->wastePenalty($examId, $schedule);
        }

        return $sum;
    }

    /**
     * Hamlenin toplam puana etkisi. Pozitif = kötüleşme.
     *
     * Hamle geçici olarak uygulanır, ölçülür ve geri alınır; çizelge
     * çağrıdan önceki hâliyle döner.
     */
    public function delta(Schedule $schedule, Move $move): int
    {
        $affected = $move->affectedExams();
        $students = $this->studentsOf($affected);

        $before = 0;

        foreach ($students as $studentId) {
            $before += $this->studentPenalty($this->data->studentExams[$studentId], $schedule);
        }

        foreach ($affected as $examId) {
            $before += $this->wastePenalty($examId, $schedule);
        }

        $move->applyTo($schedule);

        $after = 0;

        foreach ($students as $studentId) {
            $after += $this->studentPenalty($this->data->studentExams[$studentId], $schedule);
        }

        foreach ($affected as $examId) {
            $after += $this->wastePenalty($examId, $schedule);
        }

        $move->revert($schedule);

        return $after - $before;
    }

    /**
     * Hangi kuraldan ne kadar ceza geldiğinin dökümü.
     * İki çözümü karşılaştırırken tek bir sayı yetmez; hangi kuralın
     * iyileştiği görülmelidir.
     *
     * @return array{E1:int,E2:int,E3:int,E4:int,total:int,counts:array{E1:int,E2:int,E3:int}}
     */
    public function breakdown(Schedule $schedule): array
    {
        $e1 = $e2 = $e3 = 0;
        $c1 = $c2 = $c3 = 0;

        foreach ($this->data->studentExams as $examIds) {
            foreach ($this->groupByDay($examIds, $schedule) as $day) {
                $count = count($day);

                if ($count > 1) {
                    $c1 += $count - 1;
                    $e1 += ($count - 1) * $this->weights['E1'];
                }

                $indices = array_column($day, 'index');
                sort($indices);

                for ($i = 1; $i < $count; $i++) {
                    if ($indices[$i] - $indices[$i - 1] === 1) {
                        $c2++;
                        $e2 += $this->weights['E2'];
                    }
                }

                $buildings = count(array_unique(array_column($day, 'building')));

                if ($buildings > 1) {
                    $c3 += $buildings - 1;
                    $e3 += ($buildings - 1) * $this->weights['E3'];
                }
            }
        }

        $e4 = 0;

        foreach ($schedule->assignedExams() as $examId) {
            $e4 += $this->wastePenalty($examId, $schedule);
        }

        return [
            'E1' => $e1,
            'E2' => $e2,
            'E3' => $e3,
            'E4' => $e4,
            'total' => $e1 + $e2 + $e3 + $e4,
            'counts' => ['E1' => $c1, 'E2' => $c2, 'E3' => $c3],
        ];
    }

    /** Tek bir öğrencinin E1 + E2 + E3 katkısı. */
    private function studentPenalty(array $examIds, Schedule $schedule): int
    {
        $penalty = 0;

        foreach ($this->groupByDay($examIds, $schedule) as $day) {
            $count = count($day);

            if ($count < 2) {
                continue;
            }

            // E1 — aynı gün birden fazla sınav
            $penalty += ($count - 1) * $this->weights['E1'];

            // E2 — art arda saat dilimleri
            $indices = array_column($day, 'index');
            sort($indices);

            for ($i = 1; $i < $count; $i++) {
                if ($indices[$i] - $indices[$i - 1] === 1) {
                    $penalty += $this->weights['E2'];
                }
            }

            // E3 — aynı gün farklı binalar
            $buildings = count(array_unique(array_column($day, 'building')));

            if ($buildings > 1) {
                $penalty += ($buildings - 1) * $this->weights['E3'];
            }
        }

        return $penalty;
    }

    /**
     * Öğrencinin sınavlarını güne göre gruplar.
     *
     * @return array<string,array<int,array{index:int,building:int}>>
     */
    private function groupByDay(array $examIds, Schedule $schedule): array
    {
        $days = [];

        foreach ($examIds as $examId) {
            $slotId = $schedule->slotOf($examId);

            // Yerleşmemiş sınav ceza üretmez; kullanıcıya ayrıca raporlanır.
            if ($slotId === null) {
                continue;
            }

            $meta = $this->data->slotMeta[$slotId];

            $days[$meta['day']][] = [
                'index' => $meta['index'],
                'building' => $this->data->roomBuilding[$schedule->roomOf($examId)],
            ];
        }

        return $days;
    }

    /** E4 — dersliğin boşa giden kapasitesi. */
    private function wastePenalty(int $examId, Schedule $schedule): int
    {
        $roomId = $schedule->roomOf($examId);

        if ($roomId === null) {
            return 0;
        }

        $waste = $this->data->roomCapacity[$roomId] - $this->data->examSize[$examId];

        return max(0, $waste) * $this->weights['E4'];
    }

    /**
     * Etkilenen sınavlara giren öğrencilerin birleşimi.
     *
     * @param  int[]  $examIds
     * @return int[]
     */
    private function studentsOf(array $examIds): array
    {
        $students = [];

        foreach ($examIds as $examId) {
            foreach ($this->data->examStudents[$examId] ?? [] as $studentId) {
                $students[$studentId] = true;
            }
        }

        return array_keys($students);
    }

    public function weights(): array
    {
        return $this->weights;
    }
}
