<?php

namespace App\Solver\Invigilation;

use App\Solver\Violation;

/**
 * Gözetmen atamasının bağımsız denetleyicisi.
 *
 * Çizelgedeki HardConstraintChecker ile aynı mantık: atamayı yapan kodun
 * yardımcı yapılarına hiç bakmadan, sonucu sıfırdan kontrol eder.
 * Atamada bir hata varsa, aynı hatayı paylaşan bir kontrol onu göremez.
 */
final class InvigilationChecker
{
    /**
     * @param  array<int,int[]>  $assignments  examId => öğretim üyesi id'leri
     * @return Violation[] boş dizi = geçerli atama
     */
    public function check(array $assignments, InvigilationData $data, int $studentsPerInvigilator = 40, int $minimum = 1): array
    {
        $violations = [];

        /** @var array<int,array<int,int>> slotId => lecturerId => examId */
        $seen = [];

        foreach ($assignments as $examId => $lecturers) {
            $slotId = $data->examSlot[$examId] ?? null;

            if ($slotId === null) {
                $violations[] = new Violation(
                    'G0',
                    "Sınav #{$examId} çizelgede yok ama gözetmen atanmış.",
                    ['exam_id' => $examId],
                );

                continue;
            }

            // Aynı öğretim üyesi bir sınava iki kez yazılmamalı.
            if (count($lecturers) !== count(array_unique($lecturers))) {
                $violations[] = new Violation(
                    'G3',
                    "Sınav #{$examId} için aynı öğretim üyesi birden fazla kez atanmış.",
                    ['exam_id' => $examId],
                );
            }

            foreach ($lecturers as $lecturerId) {
                // K4 — müsait olmadığı saat
                if (! $data->isAvailable($lecturerId, $slotId)) {
                    $violations[] = new Violation(
                        'K4',
                        "Öğretim üyesi #{$lecturerId}, müsait olmadığı saat dilimi #{$slotId} için "
                            ."sınav #{$examId}'e gözetmen yazılmış.",
                        ['lecturer_id' => $lecturerId, 'slot_id' => $slotId, 'exam_id' => $examId],
                    );
                }

                // Aynı saatte iki sınavda birden olamaz.
                if (isset($seen[$slotId][$lecturerId])) {
                    $violations[] = new Violation(
                        'G1',
                        "Öğretim üyesi #{$lecturerId}, saat dilimi #{$slotId} içinde iki sınavda "
                            ."(#{$seen[$slotId][$lecturerId]} ve #{$examId}).",
                        ['lecturer_id' => $lecturerId, 'slot_id' => $slotId],
                    );

                    continue;
                }

                $seen[$slotId][$lecturerId] = $examId;
            }
        }

        // Çizelgedeki her sınavın gözetmeni olmalı.
        foreach ($data->examSlot as $examId => $slotId) {
            $assigned = count($assignments[$examId] ?? []);
            $needed = $data->requiredInvigilators($examId, $studentsPerInvigilator, $minimum);

            if ($assigned === 0) {
                $violations[] = new Violation(
                    'G2',
                    "Sınav #{$examId} için hiç gözetmen atanmamış.",
                    ['exam_id' => $examId, 'needed' => $needed],
                );
            }
        }

        return $violations;
    }

    /**
     * @param  Violation[]  $violations
     * @return array<string,int>
     */
    public static function summarize(array $violations): array
    {
        $counts = [];

        foreach ($violations as $violation) {
            $counts[$violation->code] = ($counts[$violation->code] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }
}
