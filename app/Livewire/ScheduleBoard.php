<?php

namespace App\Livewire;

use App\Models\Solution;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Program ekranı: gün × derslik ızgarası.
 *
 * Tek bir sorguda tüm gün çekilir. Hücre başına sorgu atmak (30 derslik
 * × 4 oturum = 120 sorgu) ekranı tek başına yavaşlatmaya yeter.
 *
 * Gün seçicisi var çünkü 5 gün × 4 oturum × 30 derslik tek ekrana
 * sığmaz; sığsa bile okunmaz.
 */
#[Layout('components.layouts.app')]
class ScheduleBoard extends Component
{
    public ?Solution $solution = null;

    #[Url(as: 'gun')]
    public ?string $day = null;

    #[Url(as: 'bolum')]
    public string $department = '';

    #[Url(as: 'ara')]
    public string $search = '';

    /**
     * Boş derslikler de görünsün mü?
     *
     * Varsayılan hayır. Otuz derslikli bir fakültede bir günde
     * çoğunlukla on kadarı kullanılır; kalan yirmi boş satır ızgarayı
     * üç ekran boyu uzatıp okunmaz hâle getiriyordu. Yazdırma çıktısı
     * (derslik planı) zaten yalnızca kullanılan derslikleri basıyor —
     * ekran da aynı şeyi yapmalı. Yine de yer arayan biri için
     * kapatılabilir bir seçenek olarak duruyor.
     */
    #[Url(as: 'bos')]
    public bool $showEmptyRooms = false;

    public function mount(?Solution $solution = null): void
    {
        // Öğretim üyesi buraya hiç gelmez: RedirectLecturers ara katmanı
        // onu kendi görev listesine yollar. Buradaki kontrol, rota
        // tanımında ara katman unutulursa diye duran ikinci kapıdır.
        abort_unless(auth()->user()->canSeeWholeSchedule(), 403);

        $this->solution = $solution ?? Solution::where('status', Solution::COMPLETED)
            ->orderByDesc('id')
            ->first();

        if ($this->solution !== null) {
            $this->authorize('view', $this->solution);
        }

        $this->day ??= $this->days()->first();
    }

    /** @return Collection<int,string> */
    public function days()
    {
        if ($this->solution === null) {
            return collect();
        }

        return DB::table('schedule_entries')
            ->join('slots', 'slots.id', '=', 'schedule_entries.slot_id')
            ->where('schedule_entries.solution_id', $this->solution->id)
            ->distinct()
            ->orderBy('slots.day')
            ->pluck('slots.day')
            ->map(fn ($day) => substr((string) $day, 0, 10));
    }

    public function render()
    {
        if ($this->solution === null) {
            return view('livewire.schedule-board', [
                'slots' => collect(),
                'rooms' => collect(),
                'grid' => [],
                'days' => collect(),
                'departments' => collect(),
            ]);
        }

        $slots = DB::table('slots')
            ->where('tenant_id', $this->solution->tenant_id)
            ->whereDate('day', $this->day)
            ->orderBy('index_in_day')
            ->get(['id', 'index_in_day', 'starts_at']);

        $entries = DB::table('schedule_entries')
            ->join('exams', 'exams.id', '=', 'schedule_entries.exam_id')
            ->join('courses', 'courses.id', '=', 'exams.course_id')
            ->join('rooms', 'rooms.id', '=', 'schedule_entries.room_id')
            ->join('buildings', 'buildings.id', '=', 'rooms.building_id')
            ->leftJoin('lecturers', 'lecturers.id', '=', 'courses.lecturer_id')
            ->where('schedule_entries.solution_id', $this->solution->id)
            ->whereIn('schedule_entries.slot_id', $slots->pluck('id'))
            ->when($this->department !== '', fn ($q) => $q->where('courses.department', $this->department))
            ->when($this->search !== '', fn ($q) => $q->where(function ($q) {
                // LIKE, PostgreSQL'de büyük/küçük harfe duyarlıdır (SQLite'ta
                // değildir). Aramanın iki veritabanında da aynı davranması
                // için karşılaştırma iki tarafta da küçük harfe indiriliyor;
                // ILIKE kullanmak sorguyu PostgreSQL'e bağlardı.
                $like = '%'.mb_strtolower($this->search, 'UTF-8').'%';
                $q->whereRaw('lower(courses.code) like ?', [$like])
                    ->orWhereRaw('lower(courses.name) like ?', [$like])
                    ->orWhereRaw('lower(lecturers.name) like ?', [$like]);
            }))
            ->orderBy('rooms.name')
            ->get([
                'schedule_entries.slot_id',
                'schedule_entries.room_id',
                'rooms.name as room_name',
                'buildings.name as building_name',
                'courses.code',
                'courses.name as course_name',
                'courses.department',
                'exams.student_count',
                'lecturers.name as lecturer_name',
            ]);

        // Izgara: derslik satırı × oturum sütunu
        $grid = [];

        foreach ($entries as $entry) {
            $grid[$entry->room_id][$entry->slot_id] = $entry;
        }

        $rooms = DB::table('rooms')
            ->where('tenant_id', $this->solution->tenant_id)
            ->orderBy('name')
            ->get(['id', 'name', 'capacity', 'building_id'])
            ->filter(fn ($room) => isset($grid[$room->id])
                || ($this->showEmptyRooms && $this->department === '' && $this->search === ''));

        $departments = DB::table('courses')
            ->where('tenant_id', $this->solution->tenant_id)
            ->whereNotNull('department')
            ->distinct()
            ->orderBy('department')
            ->pluck('department');

        return view('livewire.schedule-board', [
            'slots' => $slots,
            'rooms' => $rooms,
            'grid' => $grid,
            'days' => $this->days(),
            'departments' => $departments,
        ]);
    }
}
