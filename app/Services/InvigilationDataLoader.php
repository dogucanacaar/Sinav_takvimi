<?php

namespace App\Services;

use App\Solver\Invigilation\InvigilationData;
use App\Solver\Schedule;
use Illuminate\Support\Facades\DB;

/**
 * Gözetmen atamasının ihtiyaç duyduğu veriyi bir kez okur.
 *
 * Çizelge zaten üretilmiştir; buradaki tek ek bilgi öğretim üyeleri,
 * müsait olmadıkları saatler ve derslerin sorumlularıdır.
 */
final class InvigilationDataLoader
{
    public function load(int $tenantId, Schedule $schedule): InvigilationData
    {
        $lecturers = DB::table('lecturers')
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->get(['id', 'past_duty_count']);

        $lecturerIds = [];
        $pastDuty = [];

        foreach ($lecturers as $lecturer) {
            $lecturerIds[] = (int) $lecturer->id;
            $pastDuty[(int) $lecturer->id] = (int) $lecturer->past_duty_count;
        }

        $unavailable = [];

        $rows = DB::table('lecturer_unavailability')
            ->whereIn('lecturer_id', $lecturerIds)
            ->get(['lecturer_id', 'slot_id']);

        foreach ($rows as $row) {
            $unavailable[(int) $row->lecturer_id][(int) $row->slot_id] = true;
        }

        // Sınav → süre ve dersin sorumlusu
        $exams = DB::table('exams')
            ->join('courses', 'courses.id', '=', 'exams.course_id')
            ->where('exams.tenant_id', $tenantId)
            ->get(['exams.id', 'exams.student_count', 'exams.duration_min', 'courses.lecturer_id']);

        $examSize = [];
        $examDuration = [];
        $examLecturer = [];

        foreach ($exams as $exam) {
            $examSize[(int) $exam->id] = (int) $exam->student_count;
            $examDuration[(int) $exam->id] = (int) $exam->duration_min;
            $examLecturer[(int) $exam->id] = $exam->lecturer_id !== null ? (int) $exam->lecturer_id : null;
        }

        // Sadece çizelgeye giren sınavlara gözetmen atanır.
        $examSlot = [];

        foreach ($schedule->entries() as $examId => [$slotId, $roomId]) {
            $examSlot[$examId] = $slotId;
        }

        return new InvigilationData(
            lecturerIds: $lecturerIds,
            pastDuty: $pastDuty,
            unavailable: $unavailable,
            examSlot: $examSlot,
            examSize: $examSize,
            examDuration: $examDuration,
            examLecturer: $examLecturer,
        );
    }
}
