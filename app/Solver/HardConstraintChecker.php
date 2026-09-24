<?php

namespace App\Solver;

/**
 * Bağımsız denetleyici.
 *
 * Bu sınıf motordan ayrı yazılmıştır ve motorun hiçbir yardımcı yapısını
 * kullanmaz — çakışma grafiğine bile bakmaz, K1'i doğrudan öğrenci
 * listelerinden yeniden kurar. Amaç budur: motorda bir hata varsa,
 * aynı hatayı paylaşan bir kontrol onu göremez.
 *
 * Çözüm veritabanına yazılmadan önce çalıştırılır. İhlal bulunursa
 * çözüm 'failed' işaretlenir; geçersiz bir program asla geçerli gibi
 * sunulmaz.
 */
final class HardConstraintChecker
{
    /**
     * @param  bool  $requireAll  true ise yerleşmemiş sınavlar K5 ihlali sayılır
     * @return Violation[] boş dizi = geçerli çizelge
     */
    public function check(Schedule $schedule, ProblemData $data, bool $requireAll = true): array
    {
        $violations = [];
        $entries = $schedule->entries();

        // K5 — her sınav tam olarak bir yerde
        if ($requireAll) {
            foreach ($data->examIds as $examId) {
                if (! isset($entries[$examId])) {
                    $violations[] = new Violation(
                        'K5',
                        "Sınav #{$examId} hiçbir saat dilimine yerleştirilmemiş.",
                        ['exam_id' => $examId],
                    );
                }
            }
        }

        // K2 — bir dersliğe aynı saatte tek sınav
        $seats = [];

        foreach ($entries as $examId => [$slotId, $roomId]) {
            $key = "$slotId:$roomId";

            if (isset($seats[$key])) {
                $violations[] = new Violation(
                    'K2',
                    "Saat dilimi #{$slotId}, derslik #{$roomId}: #{$seats[$key]} ve #{$examId} sınavları aynı yerde.",
                    ['slot_id' => $slotId, 'room_id' => $roomId, 'exam_ids' => [$seats[$key], $examId]],
                );

                continue;
            }

            $seats[$key] = $examId;
        }

        // K3 — kapasite
        foreach ($entries as $examId => [$slotId, $roomId]) {
            $size = $data->examSize[$examId] ?? 0;
            $capacity = $data->roomCapacity[$roomId] ?? 0;

            if ($size > $capacity) {
                $violations[] = new Violation(
                    'K3',
                    "Sınav #{$examId} ({$size} öğrenci), derslik #{$roomId} kapasitesini ({$capacity}) aşıyor.",
                    ['exam_id' => $examId, 'room_id' => $roomId, 'student_count' => $size, 'capacity' => $capacity],
                );
            }
        }

        // K1 — aynı öğrenci aynı saatte iki sınavda olamaz.
        // Bilerek çakışma grafiğinden değil, ham öğrenci listelerinden kurulur.
        $slotStudents = [];

        foreach ($entries as $examId => [$slotId, $roomId]) {
            foreach ($data->examStudents[$examId] ?? [] as $studentId) {
                if (isset($slotStudents[$slotId][$studentId])) {
                    $violations[] = new Violation(
                        'K1',
                        "Öğrenci #{$studentId}, saat dilimi #{$slotId} içinde iki sınava giriyor "
                            ."(#{$slotStudents[$slotId][$studentId]} ve #{$examId}).",
                        [
                            'student_id' => $studentId,
                            'slot_id' => $slotId,
                            'exam_ids' => [$slotStudents[$slotId][$studentId], $examId],
                        ],
                    );

                    continue;
                }

                $slotStudents[$slotId][$studentId] = $examId;
            }
        }

        return $violations;
    }

    /**
     * @param  Violation[]  $violations
     * @return array<string,int> kural koduna göre sayım
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
