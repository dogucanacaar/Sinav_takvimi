<?php

namespace App\Solver;

use App\Solver\Moves\Move;
use App\Solver\Moves\MoveExam;
use App\Solver\Moves\SwapExams;

/**
 * Rastgele hamle üretir.
 *
 * Rastgelelik mt_rand üzerinden gelir; mt_srand ile tohum sabitlenirse
 * tüm çalıştırma tekrar üretilebilir olur. Karşılaştırma yapabilmek için
 * bu şarttır — iki parametre setini kıyaslarken aradaki farkın şanstan
 * değil parametreden geldiğini ancak böyle bilebiliriz.
 */
final class MoveGenerator
{
    /** @var int[] */
    private array $examIds;

    /** @var array<int,int[]> examId => kapasitesi yeten derslik id'leri */
    private array $feasibleRooms;

    public function __construct(
        private readonly ProblemData $data,
        private readonly int $moveShare = 70,
        private readonly int $swapShare = 30,
    ) {
        $this->examIds = $this->data->examIds;

        // Hangi sınavın hangi dersliğe sığdığı bir kez hesaplanır.
        // Kapasitesi yetmeyen bir dersliği rastgele seçip sonra kuralla
        // elemek, boşa üretilmiş hamle demektir; arama bütçesinin yarısı
        // buraya gidiyordu.
        $this->feasibleRooms = [];

        foreach ($this->data->examIds as $examId) {
            $size = $this->data->examSize[$examId];
            $rooms = [];

            foreach ($this->data->roomCapacity as $roomId => $capacity) {
                if ($capacity >= $size) {
                    $rooms[] = $roomId;
                }
            }

            $this->feasibleRooms[$examId] = $rooms;
        }
    }

    /** @return int[] bu sınavın sığabildiği derslikler */
    public function feasibleRoomsFor(int $examId): array
    {
        return $this->feasibleRooms[$examId] ?? [];
    }

    public function random(Schedule $schedule): ?Move
    {
        $assigned = $schedule->assignedExams();
        $count = count($assigned);

        if ($count === 0) {
            return null;
        }

        $total = $this->moveShare + $this->swapShare;
        $roll = mt_rand(1, max(1, $total));

        if ($roll <= $this->moveShare || $count < 2) {
            return $this->randomMove($schedule, $assigned, $count);
        }

        return $this->randomSwap($schedule, $assigned, $count);
    }

    private function randomMove(Schedule $schedule, array $assigned, int $count): ?Move
    {
        $examId = $assigned[mt_rand(0, $count - 1)];
        $rooms = $this->feasibleRooms[$examId];

        if ($rooms === []) {
            return null;
        }

        $slotId = $this->data->slotIds[mt_rand(0, count($this->data->slotIds) - 1)];
        $roomId = $rooms[mt_rand(0, count($rooms) - 1)];

        return MoveExam::from($schedule, $examId, $slotId, $roomId);
    }

    private function randomSwap(Schedule $schedule, array $assigned, int $count): ?Move
    {
        $a = $assigned[mt_rand(0, $count - 1)];

        do {
            $b = $assigned[mt_rand(0, $count - 1)];
        } while ($b === $a);

        return SwapExams::from($schedule, $a, $b);
    }
}
