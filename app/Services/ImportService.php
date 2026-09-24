<?php

namespace App\Services;

use App\Import\ImportPlan;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Doğrulanmış içe aktarma planını veritabanına yazar.
 *
 * Doğrulama burada yapılmaz — o iş ImportParser'a aittir ve veritabanı
 * olmadan test edilir. Buradaki tek sorumluluk, plandaki veriyi doğru
 * sırayla ve tek bir işlem (transaction) içinde yazmaktır: yarım
 * aktarılmış bir kurum, hiç aktarılmamış olandan çok daha kötüdür.
 */
final class ImportService
{
    /**
     * @param  bool  $replace  true ise kurumun mevcut verisi silinir
     * @return array<string,int> yazılan kayıt sayıları
     */
    public function apply(Tenant $tenant, ImportPlan $plan, bool $replace = true): array
    {
        if ($plan->hasErrors()) {
            throw new \RuntimeException(
                'Hatalı satır içeren bir plan yazılamaz. Önce hataları düzeltin.'
            );
        }

        return DB::transaction(function () use ($tenant, $plan, $replace): array {
            if ($replace) {
                $this->wipe($tenant->id);
            }

            $buildingIds = $this->insertBuildings($tenant->id, array_keys($plan->buildings));
            $roomCount = $this->insertRooms($tenant->id, $plan, $buildingIds);
            $slotIds = $this->insertSlots($tenant->id, $plan);
            $lecturerIds = $this->insertLecturers($tenant->id, $plan);
            $courseIds = $this->insertCourses($tenant->id, $plan, $lecturerIds);
            $studentIds = $this->insertStudents($tenant->id, $plan);
            $enrollmentCount = $this->insertEnrollments($tenant->id, $plan, $studentIds, $courseIds);
            $examCount = $this->insertExams($tenant->id, $plan, $courseIds);
            $unavailabilityCount = $this->insertUnavailability($plan, $lecturerIds, $slotIds);

            return [
                'binalar' => count($buildingIds),
                'derslikler' => $roomCount,
                'saat_dilimleri' => count($slotIds),
                'ogretim_uyeleri' => count($lecturerIds),
                'musaitsizlik' => $unavailabilityCount,
                'dersler' => count($courseIds),
                'ogrenciler' => count($studentIds),
                'kayitlar' => $enrollmentCount,
                'sinavlar' => $examCount,
            ];
        });
    }

    /**
     * Kurumun mevcut verisini siler.
     *
     * Sıra yabancı anahtarlara göre belirlenir: önce başkalarına bağımlı
     * olanlar. Çözümler de silinir — eski bir çizelge, artık var olmayan
     * sınavlara işaret eder ve hiçbir işe yaramaz.
     */
    private function wipe(int $tenantId): void
    {
        $examIds = DB::table('exams')->where('tenant_id', $tenantId)->pluck('id');
        $lecturerIds = DB::table('lecturers')->where('tenant_id', $tenantId)->pluck('id');

        DB::table('solutions')->where('tenant_id', $tenantId)->delete(); // entries + invigilations cascade
        DB::table('exams')->whereIn('id', $examIds)->delete();
        DB::table('enrollments')->where('tenant_id', $tenantId)->delete();
        DB::table('lecturer_unavailability')->whereIn('lecturer_id', $lecturerIds)->delete();
        DB::table('courses')->where('tenant_id', $tenantId)->delete();
        DB::table('students')->where('tenant_id', $tenantId)->delete();
        DB::table('lecturers')->where('tenant_id', $tenantId)->delete();
        DB::table('slots')->where('tenant_id', $tenantId)->delete();
        DB::table('rooms')->where('tenant_id', $tenantId)->delete();
        DB::table('buildings')->where('tenant_id', $tenantId)->delete();
    }

    /** @return array<string,int> bina adı => id */
    private function insertBuildings(int $tenantId, array $names): array
    {
        $ids = [];

        foreach ($names as $name) {
            $ids[$name] = DB::table('buildings')->insertGetId([
                'tenant_id' => $tenantId,
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    private function insertRooms(int $tenantId, ImportPlan $plan, array $buildingIds): int
    {
        $rows = [];

        foreach ($plan->rooms as $room) {
            $rows[] = [
                'tenant_id' => $tenantId,
                'building_id' => $buildingIds[$room['building']],
                'name' => $room['name'],
                'capacity' => $room['capacity'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $this->insertChunked('rooms', $rows);

        return count($rows);
    }

    /** @return array<string,int> "gün#sıra" => id */
    private function insertSlots(int $tenantId, ImportPlan $plan): array
    {
        $rows = [];

        foreach ($plan->slots as $slot) {
            $rows[] = [
                'tenant_id' => $tenantId,
                'day' => $slot['day'],
                'index_in_day' => $slot['index'],
                'starts_at' => $slot['starts_at'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $this->insertChunked('slots', $rows);

        $ids = [];

        foreach (DB::table('slots')->where('tenant_id', $tenantId)->get(['id', 'day', 'index_in_day']) as $row) {
            $ids[substr((string) $row->day, 0, 10).'#'.$row->index_in_day] = (int) $row->id;
        }

        return $ids;
    }

    /** @return array<string,int> ad => id */
    private function insertLecturers(int $tenantId, ImportPlan $plan): array
    {
        $rows = [];

        foreach ($plan->lecturers as $lecturer) {
            $rows[] = [
                'tenant_id' => $tenantId,
                'name' => $lecturer['name'],
                'title' => $lecturer['title'],
                'department' => $lecturer['department'],
                'past_duty_count' => $lecturer['past_duty_count'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $this->insertChunked('lecturers', $rows);

        return DB::table('lecturers')->where('tenant_id', $tenantId)
            ->pluck('id', 'name')->map(fn ($value) => (int) $value)->all();
    }

    /** @return array<string,int> ders kodu => id */
    private function insertCourses(int $tenantId, ImportPlan $plan, array $lecturerIds): array
    {
        $rows = [];

        foreach ($plan->courses as $course) {
            $rows[] = [
                'tenant_id' => $tenantId,
                'code' => $course['code'],
                'name' => $course['name'],
                'department' => $course['department'],
                'lecturer_id' => $course['lecturer'] !== null ? ($lecturerIds[$course['lecturer']] ?? null) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $this->insertChunked('courses', $rows);

        return DB::table('courses')->where('tenant_id', $tenantId)
            ->pluck('id', 'code')->map(fn ($value) => (int) $value)->all();
    }

    /** @return array<string,int> öğrenci numarası => id */
    private function insertStudents(int $tenantId, ImportPlan $plan): array
    {
        $rows = [];

        foreach ($plan->studentNumbers() as $number) {
            $rows[] = [
                'tenant_id' => $tenantId,
                'number' => $number,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $this->insertChunked('students', $rows);

        return DB::table('students')->where('tenant_id', $tenantId)
            ->pluck('id', 'number')->map(fn ($value) => (int) $value)->all();
    }

    private function insertEnrollments(int $tenantId, ImportPlan $plan, array $studentIds, array $courseIds): int
    {
        $rows = [];

        foreach ($plan->enrollments as $enrollment) {
            $rows[] = [
                'tenant_id' => $tenantId,
                'student_id' => $studentIds[$enrollment['student']],
                'course_id' => $courseIds[$enrollment['course']],
            ];
        }

        $this->insertChunked('enrollments', $rows, 2000);

        return count($rows);
    }

    /**
     * Sınavlar kayıtlardan türetilir: öğrencisi olan her dersin bir sınavı
     * olur ve öğrenci sayısı burada bir kez hesaplanıp saklanır. Motor
     * her hamlede COUNT(*) atmamalıdır.
     */
    private function insertExams(int $tenantId, ImportPlan $plan, array $courseIds): int
    {
        $sizes = [];

        foreach ($plan->enrollments as $enrollment) {
            $sizes[$enrollment['course']] = ($sizes[$enrollment['course']] ?? 0) + 1;
        }

        $durations = array_column($plan->courses, 'duration_min', 'code');
        $rows = [];

        foreach ($sizes as $code => $size) {
            $rows[] = [
                'tenant_id' => $tenantId,
                'course_id' => $courseIds[$code],
                'student_count' => $size,
                'duration_min' => $durations[$code] ?? 60,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $this->insertChunked('exams', $rows);

        return count($rows);
    }

    private function insertUnavailability(ImportPlan $plan, array $lecturerIds, array $slotIds): int
    {
        $rows = [];

        foreach ($plan->unavailability as $entry) {
            $slotId = $slotIds[$entry['day'].'#'.$entry['index']] ?? null;
            $lecturerId = $lecturerIds[$entry['lecturer']] ?? null;

            if ($slotId === null || $lecturerId === null) {
                continue;
            }

            $rows[] = ['lecturer_id' => $lecturerId, 'slot_id' => $slotId];
        }

        $this->insertChunked('lecturer_unavailability', $rows);

        return count($rows);
    }

    private function insertChunked(string $table, array $rows, int $size = 1000): void
    {
        foreach (array_chunk($rows, $size) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
}
