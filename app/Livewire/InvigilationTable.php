<?php

namespace App\Livewire;

use App\Models\Solution;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Gözetmen görev listesi — hoca bazında.
 *
 * Öğretim üyesi rolündeki kullanıcı burada sadece kendi görevlerini
 * görür. Bu, ekranı gizlemekle değil sorguyu daraltmakla yapılır:
 * gizlenmiş bir tablo, adres çubuğuna id yazan birine açıktır.
 */
#[Layout('components.layouts.app')]
class InvigilationTable extends Component
{
    public ?Solution $solution = null;

    #[Url(as: 'ara')]
    public string $search = '';

    public function mount(?Solution $solution = null): void
    {
        $this->solution = $solution ?? Solution::where('status', Solution::COMPLETED)
            ->orderByDesc('id')
            ->first();

        if ($this->solution !== null) {
            $this->authorize('viewInvigilation', $this->solution);
        }
    }

    public function render()
    {
        if ($this->solution === null) {
            return view('livewire.invigilation-table', ['duties' => collect(), 'loads' => collect()]);
        }

        $user = auth()->user();

        $query = DB::table('invigilations')
            ->join('exams', 'exams.id', '=', 'invigilations.exam_id')
            ->join('courses', 'courses.id', '=', 'exams.course_id')
            ->join('lecturers', 'lecturers.id', '=', 'invigilations.lecturer_id')
            ->join('schedule_entries', function ($join) {
                $join->on('schedule_entries.exam_id', '=', 'invigilations.exam_id')
                    ->on('schedule_entries.solution_id', '=', 'invigilations.solution_id');
            })
            ->join('slots', 'slots.id', '=', 'schedule_entries.slot_id')
            ->join('rooms', 'rooms.id', '=', 'schedule_entries.room_id')
            ->where('invigilations.solution_id', $this->solution->id);

        // Öğretim üyesi sadece kendi görevlerini görür.
        if ($user->isLecturer()) {
            $query->where('invigilations.lecturer_id', $user->lecturer_id);
        } elseif ($this->search !== '') {
            // Bkz. ScheduleBoard: LIKE PostgreSQL'de harf duyarlıdır.
            $query->whereRaw('lower(lecturers.name) like ?', [
                '%'.mb_strtolower($this->search, 'UTF-8').'%',
            ]);
        }

        $duties = $query
            ->orderBy('lecturers.name')
            ->orderBy('slots.day')
            ->orderBy('slots.index_in_day')
            ->get([
                'lecturers.id as lecturer_id',
                'lecturers.name as lecturer_name',
                'lecturers.title',
                'slots.day',
                'slots.starts_at',
                'rooms.name as room_name',
                'courses.code',
                'courses.name as course_name',
                'exams.student_count',
                'exams.duration_min',
            ])
            ->groupBy('lecturer_name');

        $loads = $duties->map(fn ($rows) => $rows->count())->sortDesc();

        return view('livewire.invigilation-table', [
            'duties' => $duties,
            'loads' => $loads,
        ]);
    }
}
