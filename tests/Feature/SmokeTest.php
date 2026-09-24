<?php

namespace Tests\Feature;

use App\Models\Solution;
use App\Models\User;
use App\Services\SolveService;
use App\Support\Tenancy;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Gerçek demo veriyle uçtan uca duman testi.
 *
 * Diğer testler parçaları ayrı ayrı sınıyor. Buradaki soru şu: demo
 * fakülte yüklendikten ve program üretildikten sonra, her rol her
 * ekranı gerçekten açabiliyor mu ve ekranda gerçek veri görünüyor mu?
 *
 * Boş veriyle açılan bir sayfa çoğu hatayı gizler; asıl hatalar
 * (eksik sütun, yanlış birleştirme, görünümde tanımsız değişken)
 * ancak ızgara dolduğunda ortaya çıkar.
 */
class SmokeTest extends TestCase
{
    use RefreshDatabase;

    private Solution $solution;

    private function prepare(): void
    {
        $this->seed(DemoSeeder::class);

        $tenantId = DB::table('tenants')->value('id');
        Tenancy::use($tenantId);

        $service = app(SolveService::class);

        // Kısa bir arama yeter: burada optimizasyonun kalitesi değil,
        // boru hattının çalışması sınanıyor.
        $solution = $service->create($tenantId, [
            'seed' => 1,
            'max_iter' => 2000,
            'cooling' => 0.99,
        ], 'Duman testi');

        $this->solution = $service->execute($solution);
    }

    private function user(string $role): User
    {
        return User::where('role', $role)->firstOrFail();
    }

    public function test_demo_veri_ve_cozum_uretilir(): void
    {
        $this->prepare();

        $this->assertSame(Solution::COMPLETED, $this->solution->status);
        $this->assertSame(0, $this->solution->hard_violations);
        $this->assertSame(120, DB::table('exams')->count());
        $this->assertSame(120, DB::table('schedule_entries')->count());
        $this->assertGreaterThan(0, DB::table('invigilations')->count());
        $this->assertSame('tamam', $this->solution->stats['invigilation']['durum']);

        // Demo kullanıcılar da yüklenmiş olmalı.
        $this->assertSame(3, DB::table('users')->count());
    }

    public function test_yonetici_tum_ekranlari_gercek_veriyle_acar(): void
    {
        $this->prepare();

        $admin = $this->user(User::ADMIN);

        // Izgara varsayılan olarak ilk günü gösterir; o güne düşen bir
        // ders seçilmeli, yoksa doğru çalışan ekran yanlış görünür.
        $code = DB::table('schedule_entries')
            ->join('slots', 'slots.id', '=', 'schedule_entries.slot_id')
            ->join('exams', 'exams.id', '=', 'schedule_entries.exam_id')
            ->join('courses', 'courses.id', '=', 'exams.course_id')
            ->where('schedule_entries.solution_id', $this->solution->id)
            ->orderBy('slots.day')
            ->value('courses.code');

        // Program ızgarası: gerçek bir ders kodu görünmeli.
        $this->actingAs($admin)->get('/')->assertOk()->assertSee($code);
        $this->actingAs($admin)->get("/cozum/{$this->solution->id}")->assertOk();

        $this->actingAs($admin)->get('/karsilastir')->assertOk()->assertSee('Duman testi');
        $this->actingAs($admin)->get('/gozetmenler')->assertOk();
        $this->actingAs($admin)->get('/uret')->assertOk();
        $this->actingAs($admin)->get('/aktarim')->assertOk()->assertSee('Derslikler');
    }

    public function test_yazdirilabilir_ciktilar_uretilir(): void
    {
        $this->prepare();

        $admin = $this->user(User::ADMIN);
        $id = $this->solution->id;

        $doorList = $this->actingAs($admin)->get("/cikti/{$id}/kapi-listesi");
        $doorList->assertOk()->assertSee('Öğrenci numaraları');

        $invigilators = $this->actingAs($admin)->get("/cikti/{$id}/gozetmen-cizelgesi");
        $invigilators->assertOk()->assertSee('Tebellüğ eden');

        $roomPlan = $this->actingAs($admin)->get("/cikti/{$id}/derslik-plani");
        $roomPlan->assertOk()->assertSee('Derslik Planı');

        // Kapı listesinde gerçek öğrenci numaraları yer almalı.
        $number = DB::table('students')->value('number');
        $doorList->assertSee($number);
    }

    public function test_roller_gercek_veride_de_ayrisir(): void
    {
        $this->prepare();

        $head = $this->user(User::DEPARTMENT_HEAD);
        $lecturer = $this->user(User::LECTURER);

        $this->actingAs($head)->get('/')->assertOk();
        $this->actingAs($head)->get('/uret')->assertForbidden();

        $this->actingAs($lecturer)->get('/')->assertRedirect(route('invigilation'));

        // Öğretim üyesi yalnızca kendi görevlerini görmeli: başka bir
        // hocanın adı sayfada geçmemeli.
        $own = DB::table('lecturers')->where('id', $lecturer->lecturer_id)->value('name');
        $other = DB::table('lecturers')
            ->where('id', '!=', $lecturer->lecturer_id)
            ->whereIn('id', DB::table('invigilations')->distinct()->pluck('lecturer_id'))
            ->where('name', '!=', $own)
            ->value('name');

        $response = $this->actingAs($lecturer)->get('/gozetmenler')->assertOk();

        if ($other !== null) {
            $response->assertDontSee($other);
        }
    }
}
