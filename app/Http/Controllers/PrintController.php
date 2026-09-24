<?php

namespace App\Http\Controllers;

use App\Models\Solution;
use Illuminate\Support\Facades\DB;

/**
 * Yazdırılabilir çıktılar.
 *
 * PDF kütüphanesi yerine tarayıcıdan yazdırma tercih edildi. Sebep:
 * bu üç çıktı da düz tablodur; bir PDF motoru eklemek, sayfa kırılmasını
 * ve Türkçe karakterli yazı tipini kendimiz çözmek anlamına gelir.
 * Tarayıcı ikisini de zaten doğru yapıyor ve "PDF olarak kaydet"
 * seçeneği her işletim sisteminde var.
 *
 * Sayfa kırılmaları CSS ile ayarlandı: kapı listesinde her derslik yeni
 * sayfada, gözetmen çizelgesinde her hoca yeni sayfada başlar.
 */
class PrintController extends Controller
{
    /**
     * Kapı listesi — derslik başına: ders adı, saat, öğrenci numaraları.
     * Sınav günü kapıya asılan liste budur.
     */
    public function doorList(Solution $solution)
    {
        $this->authorize('view', $solution);

        $entries = $this->entries($solution)
            ->orderBy('slots.day')
            ->orderBy('slots.index_in_day')
            ->orderBy('rooms.name')
            ->get();

        // Öğrenci numaraları tek sorguda çekilir; sınav başına ayrı sorgu
        // atmak 120 sınavda 120 sorgu demektir.
        $students = DB::table('enrollments')
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->join('exams', 'exams.course_id', '=', 'enrollments.course_id')
            ->whereIn('exams.id', $entries->pluck('exam_id'))
            ->orderBy('students.number')
            ->get(['exams.id as exam_id', 'students.number'])
            ->groupBy('exam_id');

        return view('print.door-list', [
            'solution' => $solution,
            'entries' => $entries,
            'students' => $students,
        ]);
    }

    /** Gözetmen çizelgesi — hoca başına: tarih, saat, derslik. */
    public function invigilators(Solution $solution)
    {
        $this->authorize('viewInvigilation', $solution);

        $duties = DB::table('invigilations')
            ->join('lecturers', 'lecturers.id', '=', 'invigilations.lecturer_id')
            ->join('exams', 'exams.id', '=', 'invigilations.exam_id')
            ->join('courses', 'courses.id', '=', 'exams.course_id')
            ->join('schedule_entries', function ($join) {
                $join->on('schedule_entries.exam_id', '=', 'invigilations.exam_id')
                    ->on('schedule_entries.solution_id', '=', 'invigilations.solution_id');
            })
            ->join('slots', 'slots.id', '=', 'schedule_entries.slot_id')
            ->join('rooms', 'rooms.id', '=', 'schedule_entries.room_id')
            ->where('invigilations.solution_id', $solution->id)
            ->when(
                auth()->user()->isLecturer(),
                fn ($q) => $q->where('invigilations.lecturer_id', auth()->user()->lecturer_id),
            )
            ->orderBy('lecturers.name')
            ->orderBy('slots.day')
            ->orderBy('slots.index_in_day')
            ->get([
                'lecturers.name as lecturer_name',
                'lecturers.title',
                'lecturers.department',
                'slots.day',
                'slots.starts_at',
                'rooms.name as room_name',
                'courses.code',
                'courses.name as course_name',
                'exams.duration_min',
            ])
            ->groupBy('lecturer_name');

        return view('print.invigilators', [
            'solution' => $solution,
            'duties' => $duties,
        ]);
    }

    /** Derslik planı — gün bazında ızgara. */
    public function roomPlan(Solution $solution)
    {
        $this->authorize('view', $solution);

        $entries = $this->entries($solution)
            ->orderBy('slots.day')
            ->orderBy('rooms.name')
            ->orderBy('slots.index_in_day')
            ->get();

        $slots = DB::table('slots')
            ->where('tenant_id', $solution->tenant_id)
            ->orderBy('day')
            ->orderBy('index_in_day')
            ->get(['id', 'day', 'index_in_day', 'starts_at'])
            ->groupBy(fn ($slot) => substr((string) $slot->day, 0, 10));

        $grid = [];

        foreach ($entries as $entry) {
            $grid[substr((string) $entry->day, 0, 10)][$entry->room_name][$entry->slot_id] = $entry;
        }

        return view('print.room-plan', [
            'solution' => $solution,
            'slotsByDay' => $slots,
            'grid' => $grid,
        ]);
    }

    /** Üç çıktının da ortak sorgusu. */
    private function entries(Solution $solution)
    {
        return DB::table('schedule_entries')
            ->join('exams', 'exams.id', '=', 'schedule_entries.exam_id')
            ->join('courses', 'courses.id', '=', 'exams.course_id')
            ->join('rooms', 'rooms.id', '=', 'schedule_entries.room_id')
            ->join('buildings', 'buildings.id', '=', 'rooms.building_id')
            ->join('slots', 'slots.id', '=', 'schedule_entries.slot_id')
            ->leftJoin('lecturers', 'lecturers.id', '=', 'courses.lecturer_id')
            ->where('schedule_entries.solution_id', $solution->id)
            ->select([
                'schedule_entries.exam_id',
                'schedule_entries.slot_id',
                'slots.day',
                'slots.index_in_day',
                'slots.starts_at',
                'rooms.name as room_name',
                'rooms.capacity',
                'buildings.name as building_name',
                'courses.code',
                'courses.name as course_name',
                'courses.department',
                'exams.student_count',
                'exams.duration_min',
                'lecturers.name as lecturer_name',
            ]);
    }
}
