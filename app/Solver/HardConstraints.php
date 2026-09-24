<?php

namespace App\Solver;

use App\Solver\Moves\Move;

/**
 * Bozulamaz kuralların hamle öncesi kontrolü.
 *
 *   K1  Aynı öğrencinin iki sınavı aynı saat diliminde olamaz
 *   K2  Bir dersliğe aynı saat diliminde tek sınav atanır
 *   K3  Sınavın öğrenci sayısı, dersliğin kapasitesini aşamaz
 *
 * Bu sınıf hamleyi *uygulamadan* değerlendirir. Geçersiz hamle hiç
 * denenmez; dolayısıyla çizelge hiçbir an geçersiz hâle gelmez.
 *
 * K4 (öğretim üyesinin müsait olmadığı saat) gözetmen atamasına aittir,
 * çizelgeleme aşamasında devreye girmez.
 * K5 (her sınav tam olarak bir yerde) Schedule veri yapısının doğası
 * gereği zaten sağlanır.
 */
final class HardConstraints
{
    public function __construct(private readonly ProblemData $data) {}

    public function allows(Schedule $schedule, Move $move): bool
    {
        $targets = $move->targets();
        $moved = array_keys($targets);
        $movedSet = array_flip($moved);

        // Hamlenin kendi içinde iki sınavı aynı yere koymaması gerekir.
        $claimed = [];

        foreach ($targets as $target) {
            $key = $target['slot'].':'.$target['room'];

            if (isset($claimed[$key])) {
                return false;
            }

            $claimed[$key] = true;
        }

        foreach ($targets as $examId => $target) {
            // K3 — kapasite
            if ($this->data->examSize[$examId] > $this->data->roomCapacity[$target['room']]) {
                return false;
            }

            // K2 — derslik doluluğu. Yerinden kalkacak olan sınavlar boş sayılır,
            // yoksa takas hamlesi hiçbir zaman geçerli olmazdı.
            $occupant = $schedule->occupantOf($target['slot'], $target['room']);

            if ($occupant !== null && ! isset($movedSet[$occupant])) {
                return false;
            }

            // K1 — ortak öğrencili bir sınav aynı saatte olmamalı.
            foreach ($this->data->conflicts[$examId] as $neighbour) {
                $neighbourSlot = isset($targets[$neighbour])
                    ? $targets[$neighbour]['slot']
                    : $schedule->slotOf($neighbour);

                if ($neighbourSlot === $target['slot']) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Tek bir yerleştirmenin geçerliliği — başlangıç çözümü bunu kullanır. */
    public function canPlace(Schedule $schedule, int $examId, int $slotId, int $roomId): bool
    {
        if ($this->data->examSize[$examId] > $this->data->roomCapacity[$roomId]) {
            return false;
        }

        if ($schedule->occupantOf($slotId, $roomId) !== null) {
            return false;
        }

        foreach ($this->data->conflicts[$examId] as $neighbour) {
            if ($schedule->slotOf($neighbour) === $slotId) {
                return false;
            }
        }

        return true;
    }

    /** Sınavın bu saat diliminde hiç çakışması var mı (derslikten bağımsız). */
    public function slotIsFree(Schedule $schedule, int $examId, int $slotId): bool
    {
        foreach ($this->data->conflicts[$examId] as $neighbour) {
            if ($schedule->slotOf($neighbour) === $slotId) {
                return false;
            }
        }

        return true;
    }
}
