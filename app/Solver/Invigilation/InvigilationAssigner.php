<?php

namespace App\Solver\Invigilation;

/**
 * Gözetmen ataması.
 *
 * İki aşamalı:
 *
 *   1. Açgözlü dağıtım — sınavlar öğrenci sayısı azalan sırada gezilir,
 *      her biri için o saatte uygun olan en az yüklü hocalar seçilir.
 *      Büyük sınavların önce gelmesi önemli: en çok gözetmen isteyen
 *      sınav en son kalırsa havuz tükenmiş olur.
 *
 *   2. Dengeleme — en yüklü hocadan en az yüklüye, kural bozmayan bir
 *      görev aktarılır. Açgözlü aşama yerel olarak doğru kararlar verir
 *      ama bütünde eşitsizlik bırakır: bir hoca erken saatlerde müsait
 *      olduğu için üst üste görev alabilir.
 *
 * Yük = geçmiş dönem görev sayısı + bu dönem atanan görevlerin ağırlığı.
 * Geçmişin sayılması, "geçen dönem çok görev aldım" itirazını çözer.
 */
final class InvigilationAssigner
{
    public function __construct(
        private readonly InvigilationData $data,
        private readonly int $studentsPerInvigilator = 40,
        private readonly int $minimumPerExam = 1,
        private readonly int $balanceIterations = 200,
        private readonly bool $preferOwnLecturer = true,
    ) {}

    public function assign(): InvigilationResult
    {
        $startedAt = microtime(true);

        // Yükler geçmiş dönemden devralınır.
        $loads = [];
        $assignedWeight = [];

        foreach ($this->data->lecturerIds as $lecturerId) {
            $loads[$lecturerId] = $this->data->pastDuty[$lecturerId] ?? 0;
            $assignedWeight[$lecturerId] = 0;
        }

        $assignments = [];
        $shortages = [];

        /** @var array<int,array<int,true>> slotId => lecturerId => true */
        $busy = [];

        foreach ($this->data->examsBySizeDesc() as $examId) {
            $slotId = $this->data->examSlot[$examId];
            $needed = $this->data->requiredInvigilators($examId, $this->studentsPerInvigilator, $this->minimumPerExam);
            $weight = $this->data->dutyWeight($examId);

            $chosen = [];

            // Dersin kendi sorumlusu, müsaitse, kendi sınavında bulunur.
            // Üniversitelerde fiilen böyle işler; ayrıca soru gelirse
            // cevap verebilecek kişi salonda olur.
            if ($this->preferOwnLecturer) {
                $own = $this->data->examLecturer[$examId] ?? null;

                if ($own !== null && $this->canTake($own, $slotId, $busy, $loads)) {
                    $chosen[] = $own;
                    $busy[$slotId][$own] = true;
                    $loads[$own] += $weight;
                    $assignedWeight[$own] += $weight;
                }
            }

            $candidates = $this->candidates($slotId, $busy, $chosen);

            // Yükü en düşük olandan başla; eşitlikte id'ye göre — sonuç
            // tekrar üretilebilir olsun.
            usort($candidates, fn (int $a, int $b): int => [$loads[$a], $a] <=> [$loads[$b], $b]);

            foreach ($candidates as $lecturerId) {
                if (count($chosen) >= $needed) {
                    break;
                }

                $chosen[] = $lecturerId;
                $busy[$slotId][$lecturerId] = true;
                $loads[$lecturerId] += $weight;
                $assignedWeight[$lecturerId] += $weight;
            }

            if (count($chosen) < $needed) {
                // Havuz yetmedi. Hata değil, raporlanacak bir eksiklik:
                // "bu saatte yeterli müsait öğretim üyesi yok".
                $shortages[$examId] = $needed - count($chosen);
            }

            $assignments[$examId] = $chosen;
        }

        $deviationBefore = InvigilationResult::deviation(array_values($loads));

        $moves = $this->balance($assignments, $loads, $assignedWeight, $busy);

        return new InvigilationResult(
            assignments: $assignments,
            loads: $loads,
            assignedWeight: $assignedWeight,
            shortages: $shortages,
            deviationBefore: $deviationBefore,
            deviationAfter: InvigilationResult::deviation(array_values($loads)),
            balanceMoves: $moves,
            elapsedSeconds: microtime(true) - $startedAt,
        );
    }

    /**
     * Dengeleme: en yüklüden en az yüklüye görev aktar.
     *
     * Aktarım ancak fark görev ağırlığından *büyükse* yapılır. Fark tam
     * olarak ağırlığa eşitse aktarım iki hocanın yerini değiştirmekten
     * ibaret olur ve döngü sonsuza kadar gidip gelir.
     */
    private function balance(array &$assignments, array &$loads, array &$assignedWeight, array &$busy): int
    {
        $moves = 0;

        for ($iteration = 0; $iteration < $this->balanceIterations; $iteration++) {
            $order = array_keys($loads);
            usort($order, fn (int $a, int $b): int => [$loads[$b], $a] <=> [$loads[$a], $b]);

            $moved = false;

            // En yüklüden başlayarak, en az yüklüye doğru dene. Tek bir
            // çift tıkanmış olabilir; bir sonraki çift çözebilir.
            foreach ($order as $heavy) {
                foreach (array_reverse($order) as $light) {
                    if ($heavy === $light || $loads[$heavy] <= $loads[$light]) {
                        continue 2;
                    }

                    if ($this->transfer($heavy, $light, $assignments, $loads, $assignedWeight, $busy)) {
                        $moves++;
                        $moved = true;
                        break 2;
                    }
                }
            }

            if (! $moved) {
                break; // Daha iyileştirilemiyor.
            }
        }

        return $moves;
    }

    /** $heavy'nin bir görevini $light'a aktarmayı dener. */
    private function transfer(
        int $heavy,
        int $light,
        array &$assignments,
        array &$loads,
        array &$assignedWeight,
        array &$busy,
    ): bool {
        foreach ($assignments as $examId => $lecturers) {
            $position = array_search($heavy, $lecturers, true);

            if ($position === false) {
                continue;
            }

            $slotId = $this->data->examSlot[$examId];
            $weight = $this->data->dutyWeight($examId);

            // Aktarım gerçekten dengeyi iyileştirmeli.
            if ($weight >= $loads[$heavy] - $loads[$light]) {
                continue;
            }

            if (! $this->data->isAvailable($light, $slotId)) {
                continue; // K4
            }

            if (isset($busy[$slotId][$light])) {
                continue; // Aynı saatte başka sınavda
            }

            // Dersin kendi sorumlusu kendi sınavından alınmaz.
            if ($this->preferOwnLecturer && ($this->data->examLecturer[$examId] ?? null) === $heavy) {
                continue;
            }

            $assignments[$examId][$position] = $light;

            unset($busy[$slotId][$heavy]);
            $busy[$slotId][$light] = true;

            $loads[$heavy] -= $weight;
            $loads[$light] += $weight;
            $assignedWeight[$heavy] -= $weight;
            $assignedWeight[$light] += $weight;

            return true;
        }

        return false;
    }

    /**
     * O saatte görev alabilecek öğretim üyeleri.
     *
     * @return int[]
     */
    private function candidates(int $slotId, array $busy, array $exclude): array
    {
        $excludeSet = array_flip($exclude);
        $candidates = [];

        foreach ($this->data->lecturerIds as $lecturerId) {
            if (isset($excludeSet[$lecturerId]) || isset($busy[$slotId][$lecturerId])) {
                continue;
            }

            if (! $this->data->isAvailable($lecturerId, $slotId)) {
                continue; // K4
            }

            $candidates[] = $lecturerId;
        }

        return $candidates;
    }

    private function canTake(int $lecturerId, int $slotId, array $busy, array $loads): bool
    {
        return isset($loads[$lecturerId])
            && ! isset($busy[$slotId][$lecturerId])
            && $this->data->isAvailable($lecturerId, $slotId);
    }
}
