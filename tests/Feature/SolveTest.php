<?php

namespace Tests\Feature;

use App\Jobs\SolveJob;
use App\Models\Solution;
use App\Models\Tenant;
use App\Services\ProblemDataLoader;
use App\Services\SolveService;
use App\Solver\HardConstraintChecker;
use App\Solver\Schedule;
use App\Support\ProgressStore;
use App\Support\Tenancy;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Uçtan uca çözüm üretimi.
 *
 * Motorun kendisi veritabanı olmadan test ediliyor (tests/Unit/Solver).
 * Burada sınanan şey aradaki bağlantı: verinin doğru okunması, sonucun
 * doğru yazılması ve bağımsız denetleyicinin gerçekten devreye girmesi.
 *
 * Veri kasıtlı olarak küçük — amaç motorun ne kadar iyi optimize ettiği
 * değil, boru hattının uçtan uca çalışması.
 */
class SolveTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake(); // SolveJob kuyruğa gitsin, testte biz çalıştıralım
        $this->tenant = $this->seedSmallFaculty();
        Tenancy::use($this->tenant->id);
    }

    /**
     * 6 ders, 30 öğrenci, 3 derslik, 2 gün × 2 oturum, 4 öğretim üyesi.
     * Öğrenciler ikişer gruba ayrılır: grup başına üç ders, gruplar
     * arasında ortak öğrenci yok — yani iki ayrı çakışma kümesi.
     */
    private function seedSmallFaculty(): Tenant
    {
        $tenant = Tenant::create(['name' => 'Test Fakültesi']);

        $buildingA = DB::table('buildings')->insertGetId([
            'tenant_id' => $tenant->id, 'name' => 'A Blok', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $buildingB = DB::table('buildings')->insertGetId([
            'tenant_id' => $tenant->id, 'name' => 'B Blok', 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([['D-101', 20, $buildingA], ['D-102', 20, $buildingA], ['Amfi', 40, $buildingB]] as [$name, $capacity, $building]) {
            DB::table('rooms')->insert([
                'tenant_id' => $tenant->id, 'building_id' => $building, 'name' => $name,
                'capacity' => $capacity, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach (['2026-06-08', '2026-06-09'] as $day) {
            foreach ([1 => '09:00:00', 2 => '11:00:00'] as $index => $startsAt) {
                DB::table('slots')->insert([
                    'tenant_id' => $tenant->id, 'day' => $day, 'index_in_day' => $index,
                    'starts_at' => $startsAt, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        $lecturerIds = [];

        for ($i = 1; $i <= 4; $i++) {
            $lecturerIds[] = DB::table('lecturers')->insertGetId([
                'tenant_id' => $tenant->id, 'name' => "Hoca {$i}", 'title' => 'Dr. Öğr. Üyesi',
                'department' => 'Bilgisayar', 'past_duty_count' => $i,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $courseIds = [];

        for ($i = 1; $i <= 6; $i++) {
            $courseIds[$i] = DB::table('courses')->insertGetId([
                'tenant_id' => $tenant->id, 'code' => "DRS{$i}0{$i}", 'name' => "Ders {$i}",
                'department' => 'Bilgisayar', 'lecturer_id' => $lecturerIds[($i - 1) % 4],
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $sizes = array_fill(1, 6, 0);

        for ($s = 1; $s <= 30; $s++) {
            $studentId = DB::table('students')->insertGetId([
                'tenant_id' => $tenant->id, 'number' => (string) (2026000 + $s),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            // İlk 15 öğrenci 1-2-3, kalan 15 öğrenci 4-5-6 derslerini alır.
            $courses = $s <= 15 ? [1, 2, 3] : [4, 5, 6];

            foreach ($courses as $course) {
                DB::table('enrollments')->insert([
                    'tenant_id' => $tenant->id, 'student_id' => $studentId, 'course_id' => $courseIds[$course],
                ]);
                $sizes[$course]++;
            }
        }

        foreach ($courseIds as $i => $courseId) {
            DB::table('exams')->insert([
                'tenant_id' => $tenant->id, 'course_id' => $courseId, 'student_count' => $sizes[$i],
                'duration_min' => 60, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $tenant;
    }

    private function solve(array $params = []): Solution
    {
        $service = app(SolveService::class);

        $solution = $service->start($this->tenant->id, array_merge([
            'seed' => 2026,
            'max_iter' => 3000,
            'cooling' => 0.99,
        ], $params), 'Test çözümü');

        return $service->execute($solution);
    }

    public function test_cozum_uretilir_ve_kaydedilir(): void
    {
        $solution = $this->solve();

        $this->assertSame(Solution::COMPLETED, $solution->status);
        $this->assertSame(0, $solution->hard_violations);
        $this->assertNotNull($solution->penalty);
        $this->assertNotNull($solution->finished_at);

        // Altı sınavın altısı da yerleşmeli: 4 oturum × 3 derslik = 12 yer,
        // iki ayrı çakışma kümesi var.
        $this->assertDatabaseCount('schedule_entries', 6);
        $this->assertSame(0, $solution->stats['unplaced_count']);
    }

    public function test_sonuc_cizelgesi_kati_kural_bozmaz(): void
    {
        $solution = $this->solve();

        $data = app(ProblemDataLoader::class)->load($this->tenant->id);
        $schedule = new Schedule;

        foreach (DB::table('schedule_entries')->where('solution_id', $solution->id)->get() as $entry) {
            $schedule->assign((int) $entry->exam_id, (int) $entry->slot_id, (int) $entry->room_id);
        }

        $violations = (new HardConstraintChecker)->check($schedule, $data);

        $this->assertSame([], array_map(strval(...), $violations));
    }

    public function test_gozetmenler_atanir_ve_k4_bozulmaz(): void
    {
        $solution = $this->solve();

        $this->assertSame('tamam', $solution->stats['invigilation']['durum']);
        $this->assertGreaterThan(0, DB::table('invigilations')->where('solution_id', $solution->id)->count());

        // Her sınavın en az bir gözetmeni olmalı.
        $withInvigilator = DB::table('invigilations')
            ->where('solution_id', $solution->id)
            ->distinct()
            ->count('exam_id');

        $this->assertSame(6, $withInvigilator);
    }

    public function test_ayni_tohum_ayni_sonucu_verir(): void
    {
        $first = $this->solve(['seed' => 7]);
        $second = $this->solve(['seed' => 7]);

        $this->assertSame($first->penalty, $second->penalty);

        $entries = fn (Solution $s) => DB::table('schedule_entries')
            ->where('solution_id', $s->id)
            ->orderBy('exam_id')
            ->get(['exam_id', 'slot_id', 'room_id'])
            ->map(fn ($e) => (array) $e)
            ->all();

        $this->assertSame($entries($first), $entries($second));
    }

    public function test_sinavi_olmayan_kurumda_anlamli_hata_verir(): void
    {
        DB::table('exams')->delete();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sınavı olan ders yok');

        $this->solve();
    }

    public function test_cozum_kuyruga_birakilir(): void
    {
        app(SolveService::class)->start($this->tenant->id, ['max_iter' => 1000]);

        Queue::assertPushedOn('solve', SolveJob::class);
    }

    /**
     * Arayüzdeki ilerleme çubuğu wire:poll ile bu önbellek anahtarını
     * okur. Motor içinde geri çağrının çalışması yetmez; değerin gerçekten
     * önbelleğe yazılması ve iş bitince temizlenmesi gerekir.
     */
    public function test_ilerleme_onbellege_yazilir_ve_sonunda_temizlenir(): void
    {
        $service = app(SolveService::class);

        $solution = $service->create($this->tenant->id, [
            'seed' => 3,
            'max_iter' => 3000,
            'cooling' => 0.99,
            'progress_every' => 100,
        ], 'İlerleme testi');

        $seen = [];

        $service->execute($solution, function () use ($solution, &$seen): void {
            $seen[] = Cache::get($solution->progressKey());
        });

        $this->assertNotEmpty($seen, 'İlerleme geri çağrısı hiç çalışmadı');
        $this->assertNotNull($seen[0], 'Arayüzün okuduğu önbellek anahtarı yazılmamış');
        $this->assertArrayHasKey('best', $seen[0]);
        $this->assertArrayHasKey('iter', $seen[0]);

        $this->assertNull(
            Cache::get($solution->progressKey()),
            'İş bitince ilerleme anahtarı temizlenmeliydi',
        );
    }

    /**
     * Önbellek sürücüsü bozuksa (örneğin CACHE_STORE=redis ama phpredis
     * kurulu değil) ilerleme çubuğu kaybolur — çözüm kaybolmaz.
     *
     * Bu tam olarak sahada yaşandı: Cache::put() motorun ilerleme geri
     * çağrısının içinden "Class Redis not found" fırlatıyor, istisna
     * yukarı çıkıyor ve dakikalar süren arama ortasından kesiliyordu.
     */
    public function test_bozuk_onbellek_cozumu_durdurmaz(): void
    {
        ProgressStore::resetWarning();

        $broken = \Mockery::mock(Repository::class);
        $broken->shouldReceive('put', 'get', 'forget')
            ->andThrow(new \Error('Class "Redis" not found'));
        $broken->shouldIgnoreMissing();

        Cache::swap($broken);

        $solution = $this->solve(['progress_every' => 100]);

        $this->assertSame(Solution::COMPLETED, $solution->status);
        $this->assertSame(0, $solution->hard_violations);
        $this->assertDatabaseCount('schedule_entries', 6);
    }

    public function test_istatistikler_kural_bazinda_dokum_icerir(): void
    {
        $solution = $this->solve();

        $breakdown = $solution->stats['breakdown'];

        $this->assertArrayHasKey('E1', $breakdown);
        $this->assertArrayHasKey('counts', $breakdown);
        $this->assertSame(
            $breakdown['E1'] + $breakdown['E2'] + $breakdown['E3'] + $breakdown['E4'],
            $breakdown['total'],
        );
    }
}
