<?php

namespace Tests\Support;

use App\Solver\Invigilation\InvigilationData;
use App\Solver\ProblemData;
use App\Solver\Schedule;

/**
 * Gözetmen atama testleri için elle kurulmuş veri.
 */
final class InvigilationFactory
{
    /**
     * @param  int  $lecturers  öğretim üyesi sayısı (id'ler 1..N)
     * @param  array<int,array{slot:int,size:int,duration?:int,lecturer?:int|null}>  $exams
     * @param  array<int,int>  $pastDuty
     * @param  array<int,int[]>  $unavailable  lecturerId => slot id listesi
     */
    public static function make(
        int $lecturers,
        array $exams,
        array $pastDuty = [],
        array $unavailable = [],
    ): InvigilationData {
        $unavailableMap = [];

        foreach ($unavailable as $lecturerId => $slots) {
            foreach ($slots as $slotId) {
                $unavailableMap[$lecturerId][$slotId] = true;
            }
        }

        $examSlot = [];
        $examSize = [];
        $examDuration = [];
        $examLecturer = [];

        foreach ($exams as $examId => $exam) {
            $examSlot[$examId] = $exam['slot'];
            $examSize[$examId] = $exam['size'];
            $examDuration[$examId] = $exam['duration'] ?? 60;
            $examLecturer[$examId] = $exam['lecturer'] ?? null;
        }

        return new InvigilationData(
            lecturerIds: range(1, $lecturers),
            pastDuty: $pastDuty,
            unavailable: $unavailableMap,
            examSlot: $examSlot,
            examSize: $examSize,
            examDuration: $examDuration,
            examLecturer: $examLecturer,
        );
    }

    /**
     * Gerçekçi ölçek: 20 saat dilimi, 60 sınav, 40 öğretim üyesi,
     * eşitsiz geçmiş yük ve dağınık müsaitsizlik.
     */
    public static function facultyScale(int $seed = 7): InvigilationData
    {
        mt_srand($seed);

        $exams = [];

        for ($examId = 1; $examId <= 60; $examId++) {
            $exams[$examId] = [
                'slot' => 1 + ($examId % 20),
                'size' => mt_rand(40, 200),
                'duration' => [60, 90, 120][mt_rand(0, 2)],
                'lecturer' => null,
            ];
        }

        $pastDuty = [];
        $unavailable = [];

        for ($lecturerId = 1; $lecturerId <= 40; $lecturerId++) {
            // Geçmiş yük bilerek eşitsiz: dengelemenin işe yaradığı
            // ancak böyle görülebilir.
            $pastDuty[$lecturerId] = mt_rand(0, 10);

            $busy = [];
            $count = mt_rand(0, 4);

            while (count($busy) < $count) {
                $busy[mt_rand(1, 20)] = true;
            }

            $unavailable[$lecturerId] = array_keys($busy);
        }

        return self::make(40, $exams, $pastDuty, $unavailable);
    }

    /**
     * Üretilmiş bir çizelgenin üstüne, DemoSeeder ile aynı profilde bir
     * öğretim üyesi kadrosu kurar: 80 hoca, eşitsiz geçmiş yük, dağınık
     * müsaitsizlik. Derslerin sorumluları sırayla dağıtılır.
     */
    public static function forSchedule(
        ProblemData $data,
        Schedule $schedule,
        int $lecturerCount = 80,
        int $seed = 4242,
    ): InvigilationData {
        mt_srand($seed);

        $lecturerIds = range(1, $lecturerCount);
        $pastDuty = [];
        $unavailable = [];

        foreach ($lecturerIds as $lecturerId) {
            $pastDuty[$lecturerId] = mt_rand(0, 8);

            $busy = [];
            $count = mt_rand(0, 4);

            while (count($busy) < $count) {
                $busy[$data->slotIds[mt_rand(0, count($data->slotIds) - 1)]] = true;
            }

            $unavailable[$lecturerId] = array_keys($busy);
        }

        $unavailableMap = [];

        foreach ($unavailable as $lecturerId => $slots) {
            foreach ($slots as $slotId) {
                $unavailableMap[$lecturerId][$slotId] = true;
            }
        }

        $examSlot = [];
        $examDuration = [];
        $examLecturer = [];
        $index = 0;

        foreach ($schedule->entries() as $examId => [$slotId, $roomId]) {
            $examSlot[$examId] = $slotId;
            $examDuration[$examId] = [60, 90, 120][$examId % 3];
            $examLecturer[$examId] = $lecturerIds[$index++ % $lecturerCount];
        }

        return new InvigilationData(
            lecturerIds: $lecturerIds,
            pastDuty: $pastDuty,
            unavailable: $unavailableMap,
            examSlot: $examSlot,
            examSize: $data->examSize,
            examDuration: $examDuration,
            examLecturer: $examLecturer,
        );
    }
}
