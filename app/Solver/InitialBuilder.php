<?php

namespace App\Solver;

/**
 * Başlangıç çözümü — açgözlü yerleştirme.
 *
 * Amaç iyi bir çizelge üretmek değil, *geçerli* bir çizelge üretmektir.
 * İyileştirme işini tavlama benzetimi yapar. Bu aşama saniyeler sürer.
 *
 * Sıralama önemli: en çok çakışan sınav en kısıtlı olandır, en son kalırsa
 * yerleşecek yer bulamaz. Bu yüzden çakışma derecesi azalan sırada gidilir.
 *
 * Derslik seçiminde "yeterli olan en küçük derslik" alınır: büyük derslikler
 * büyük sınavlar için saklanır, ayrıca E4 (kapasite israfı) doğrudan düşer.
 */
final class InitialBuilder
{
    public function __construct(private readonly ProblemData $data) {}

    /**
     * @return array{schedule:Schedule,unplaced:int[]}
     */
    public function build(): array
    {
        $schedule = new Schedule;
        $hard = new HardConstraints($this->data);
        $unplaced = [];

        $roomsBySize = $this->data->roomIdsByCapacity();
        $order = ConflictGraph::orderByDegree(
            array_intersect_key($this->data->conflicts, array_flip($this->data->examIds))
        );

        foreach ($order as $examId) {
            $placed = false;

            foreach ($this->data->slotIds as $slotId) {
                // Önce saat dilimi elenebiliyor mu bakılır: çakışma varsa
                // o saatteki 30 dersliği tek tek denemenin anlamı yok.
                if (! $hard->slotIsFree($schedule, $examId, $slotId)) {
                    continue;
                }

                foreach ($roomsBySize as $roomId) {
                    if ($this->data->roomCapacity[$roomId] < $this->data->examSize[$examId]) {
                        continue;
                    }

                    if ($schedule->occupantOf($slotId, $roomId) !== null) {
                        continue;
                    }

                    $schedule->assign($examId, $slotId, $roomId);
                    $placed = true;
                    break 2;
                }
            }

            if (! $placed) {
                // Başarısızlık değil: kullanıcıya "daha fazla saat dilimi veya
                // derslik gerekiyor" diye raporlanacak bir sonuçtur.
                $unplaced[] = $examId;
            }
        }

        return ['schedule' => $schedule, 'unplaced' => $unplaced];
    }
}
