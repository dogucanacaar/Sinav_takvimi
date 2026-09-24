<?php

namespace Tests\Feature;

use App\Import\XlsxWriter;
use App\Jobs\SolveJob;
use App\Livewire\ImportWizard;
use App\Livewire\InvigilationTable;
use App\Livewire\ScheduleBoard;
use App\Livewire\SolutionCompare;
use App\Livewire\SolutionLauncher;
use App\Models\Solution;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SolveService;
use App\Support\Tenancy;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ekranlara tıklandığında ne oluyor?
 *
 * Sayfanın açılması ile çalışması ayrı şeyler: SmokeTest sayfaların
 * açıldığını gösteriyor, burada düğmelere basılıyor. Livewire'da asıl
 * mantık mount()'ta değil eylem metotlarında olduğu için bu testler
 * olmadan arayüzün yarısı denenmemiş sayılır.
 */
class LivewireInteractionTest extends TestCase
{
    use RefreshDatabase;

    /** @var string[] */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function admin(): User
    {
        $tenant = Tenant::firstOrCreate(['name' => 'Test Fakültesi']);
        Tenancy::use($tenant->id);

        return User::firstOrCreate(
            ['email' => 'yonetici@test.local'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Yönetici',
                'password' => 'parola-123',
                'role' => User::ADMIN,
            ],
        );
    }

    /** Geçerli bir içe aktarma dosyası üretir ve yüklenebilir hâle getirir. */
    private function workbook(array $overrides = []): UploadedFile
    {
        $path = storage_path('app/test-'.bin2hex(random_bytes(4)).'.xlsx');
        $this->temporary[] = $path;

        $sheets = array_merge([
            'Derslikler' => [['Bina', 'Derslik', 'Kapasite'], ['A Blok', 'D-101', 50]],
            'SaatDilimleri' => [['Tarih', 'Sıra', 'Başlangıç'], ['08.06.2026', 1, '09:00']],
            'OgretimUyeleri' => [['Ad Soyad', 'Unvan'], ['Ayşe Yılmaz', 'Prof. Dr.']],
            'Dersler' => [['Ders Kodu', 'Ders Adı', 'Öğretim Üyesi'], ['BLM101', 'Giriş', 'Ayşe Yılmaz']],
            'Kayitlar' => [['Öğrenci No', 'Ders Kodu'], ['2026001', 'BLM101']],
        ], $overrides);

        $writer = new XlsxWriter;

        foreach ($sheets as $name => $rows) {
            $writer->addSheet($name, $rows);
        }

        $writer->save($path);

        // Livewire'ın dosya yükleme desteği geçici yüklenmiş dosya bekler;
        // gerçek bir UploadedFile örneği değil, sahtesi verilmeli.
        return UploadedFile::fake()->createWithContent('veriler.xlsx', file_get_contents($path));
    }

    // --- Veri aktarma sihirbazı --------------------------------------------

    public function test_dosya_dogrulanir_ve_hicbir_sey_yazilmaz(): void
    {
        $component = Livewire::actingAs($this->admin())
            ->test(ImportWizard::class)
            ->set('file', $this->workbook())
            ->call('validateFile');

        $component->assertSet('validated', true);
        $component->assertSet('rowErrors', []);

        $summary = $component->get('summary');
        $this->assertSame(1, $summary['derslikler']);
        $this->assertSame(1, $summary['dersler']);

        // Doğrulama adımı veritabanına dokunmamalı.
        $this->assertDatabaseCount('rooms', 0);
        $this->assertDatabaseCount('courses', 0);
    }

    public function test_onaylaninca_veri_yazilir(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ImportWizard::class)
            ->set('file', $this->workbook())
            ->call('validateFile')
            ->call('apply')
            ->assertSet('failure', null);

        $this->assertDatabaseCount('rooms', 1);
        $this->assertDatabaseHas('courses', ['code' => 'BLM101']);
        $this->assertDatabaseCount('exams', 1);
    }

    public function test_hatali_dosya_onaylanamaz(): void
    {
        $component = Livewire::actingAs($this->admin())
            ->test(ImportWizard::class)
            ->set('file', $this->workbook([
                'Derslikler' => [['Bina', 'Derslik', 'Kapasite'], ['A Blok', 'D-101', 'çok']],
            ]))
            ->call('validateFile');

        $this->assertNotEmpty($component->get('rowErrors'));

        $component->call('apply');

        $this->assertNotNull($component->get('failure'));
        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_dosya_secilmeden_dogrulama_hata_verir(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ImportWizard::class)
            ->call('validateFile')
            ->assertHasErrors('file');
    }

    // --- Program üretme ------------------------------------------------------

    public function test_uret_dugmesi_cozum_kaydi_acar_ve_kuyruga_birakir(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $this->seedTinyFaculty($admin->tenant_id);

        $component = Livewire::actingAs($admin)
            ->test(SolutionLauncher::class)
            ->set('label', 'Arayüzden üretim')
            ->set('seed', 42)
            ->set('maxIter', 2000)
            ->call('launch');

        $this->assertDatabaseHas('solutions', [
            'label' => 'Arayüzden üretim',
            'status' => Solution::QUEUED,
        ]);

        // Id'yi sabit yazmak kırılgan: PostgreSQL'de diziler (sequence)
        // testler arasında sıfırlanmadığı için ilk kayıt 1 olmayabilir.
        $component->assertSet('watching', Solution::first()->id);

        Queue::assertPushedOn('solve', SolveJob::class);

        // Parametreler kayda yazılmış olmalı.
        $params = Solution::first()->params;
        $this->assertSame(42, $params['seed']);
        $this->assertSame(2000, $params['max_iter']);
    }

    public function test_gecersiz_parametre_reddedilir(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin())
            ->test(SolutionLauncher::class)
            ->set('maxIter', 10)          // en az 1000
            ->set('cooling', 2)           // en fazla 0.99999
            ->call('launch')
            ->assertHasErrors(['maxIter', 'cooling']);

        $this->assertDatabaseCount('solutions', 0);
        Queue::assertNothingPushed();
    }

    public function test_kuyruk_takilirsa_uyari_ve_cikis_yolu_gosterilir(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $this->seedTinyFaculty($admin->tenant_id);

        $component = Livewire::actingAs($admin)
            ->test(SolutionLauncher::class)
            ->set('maxIter', 2000)
            ->call('launch');

        // Az önce kuyruğa bırakılan iş için uyarı çıkmamalı.
        $component->assertDontSee('İş kuyruktan alınmıyor');

        // İş bir süredir alınmamışsa işçi çalışmıyor demektir.
        Solution::query()->update(['created_at' => now()->subMinute()]);

        $component->call('$refresh')->assertSee('İş kuyruktan alınmıyor');
    }

    public function test_sayfa_yenilenince_bekleyen_is_izlenmeye_devam_eder(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $this->seedTinyFaculty($admin->tenant_id);

        // Önceki oturumda başlatılmış, hâlâ kuyrukta bekleyen bir iş.
        $solution = app(SolveService::class)->start($admin->tenant_id, ['max_iter' => 2000], 'Önceki iş');
        Solution::query()->update(['created_at' => now()->subMinute()]);

        // Sayfa sıfırdan açılıyor; bileşen o işi kendiliğinden bulmalı.
        Livewire::actingAs($admin)
            ->test(SolutionLauncher::class)
            ->assertSet('watching', $solution->id)
            ->assertSee('İş kuyruktan alınmıyor');
    }

    public function test_kuyruk_beklemeden_uretim_yapilabilir(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $this->seedTinyFaculty($admin->tenant_id);

        Livewire::actingAs($admin)
            ->test(SolutionLauncher::class)
            ->set('maxIter', 2000)
            ->set('cooling', 0.99)
            ->call('launch')
            ->call('runNow')
            ->assertSet('failure', null);

        $solution = Solution::firstOrFail();

        $this->assertSame(Solution::COMPLETED, $solution->status);
        $this->assertSame(0, $solution->hard_violations);
        $this->assertDatabaseCount('schedule_entries', 1);
    }

    /**
     * Düğme hiçbir koşulda sessiz kalmamalı. Sessiz kalınca kullanıcı
     * düğmeye basıp hiçbir şey olmadığını görüyor ve uygulamanın bozuk
     * olduğunu düşünüyor; oysa iş çoktan bitmiş olabilir.
     */
    public function test_uretilmis_cozumde_yedek_dugme_sebebini_soyler(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $this->seedTinyFaculty($admin->tenant_id);

        $component = Livewire::actingAs($admin)
            ->test(SolutionLauncher::class)
            ->set('maxIter', 2000)
            ->set('cooling', 0.99)
            ->call('launch')
            ->call('runNow');

        // İkinci basış: çözüm artık tamamlanmış durumda.
        $component->call('runNow')
            ->assertSee('kuyrukta beklemiyor');
    }

    /** Eski denemenin uyarısı yeni işin yanında asılı kalmamalı. */
    public function test_yeni_is_baslatilinca_eski_uyari_silinir(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $this->seedTinyFaculty($admin->tenant_id);

        $component = Livewire::actingAs($admin)
            ->test(SolutionLauncher::class)
            ->set('maxIter', 2000)
            ->set('cooling', 0.99)
            ->call('launch')
            ->call('runNow')
            ->call('runNow');

        $this->assertNotNull($component->get('failure'));

        $component->call('launch')->assertSet('failure', null);
    }

    // --- Program ızgarası ----------------------------------------------------

    public function test_bolum_filtresi_izgarayi_daraltir(): void
    {
        $admin = $this->prepareSolved();

        $departments = DB::table('courses')->distinct()->pluck('department');
        $this->assertGreaterThan(1, $departments->count());

        $component = Livewire::actingAs($admin)->test(ScheduleBoard::class);

        // Filtresiz hâlde birden fazla bölümün dersi görünür.
        $day = $component->get('day');
        $this->assertNotNull($day);

        $component->set('department', $departments->first());

        $otherDepartment = $departments->last();
        $otherCode = DB::table('courses')->where('department', $otherDepartment)->value('code');

        $component->assertDontSee($otherCode);
    }

    public function test_arama_ders_koduyla_calisir(): void
    {
        $admin = $this->prepareSolved();

        $code = DB::table('schedule_entries')
            ->join('slots', 'slots.id', '=', 'schedule_entries.slot_id')
            ->join('exams', 'exams.id', '=', 'schedule_entries.exam_id')
            ->join('courses', 'courses.id', '=', 'exams.course_id')
            ->orderBy('slots.day')
            ->value('courses.code');

        Livewire::actingAs($admin)
            ->test(ScheduleBoard::class)
            ->set('search', $code)
            ->assertSee($code);
    }

    public function test_gun_degistirmek_baska_sinavlari_gosterir(): void
    {
        $admin = $this->prepareSolved();

        $days = DB::table('slots')->distinct()->orderBy('day')->pluck('day')
            ->map(fn ($d) => substr((string) $d, 0, 10));

        $component = Livewire::actingAs($admin)->test(ScheduleBoard::class);
        $first = $component->get('day');

        $component->set('day', $days->last());

        $this->assertNotSame($first, $component->get('day'));
        $component->assertOk();
    }

    // --- Karşılaştırma ve gözetmenler ---------------------------------------

    public function test_iki_cozum_karsilastirilir(): void
    {
        $admin = $this->prepareSolved();
        $second = $this->solve($admin->tenant_id, 'İkinci çözüm', ['seed' => 99]);

        $first = Solution::orderBy('id')->first();

        Livewire::actingAs($admin)
            ->test(SolutionCompare::class)
            ->set('leftId', $first->id)
            ->set('rightId', $second->id)
            ->assertSee('Duman testi')
            ->assertSee('İkinci çözüm')
            ->assertSee('Ceza puanı');
    }

    public function test_gozetmen_aramasi_daraltir(): void
    {
        $admin = $this->prepareSolved();

        $names = DB::table('invigilations')
            ->join('lecturers', 'lecturers.id', '=', 'invigilations.lecturer_id')
            ->distinct()
            ->pluck('lecturers.name');

        $this->assertGreaterThan(1, $names->count());

        Livewire::actingAs($admin)
            ->test(InvigilationTable::class)
            ->set('search', $names->first())
            ->assertSee($names->first());
    }

    /**
     * Otuz derslikli bir günde çoğu derslik boştur; ızgara varsayılan
     * olarak yalnızca kullanılanları göstermeli, yoksa ekranın üçte
     * ikisi boş satır olur.
     */
    public function test_bos_derslikler_varsayilan_olarak_gizlenir(): void
    {
        $admin = $this->prepareSolved();

        $component = Livewire::actingAs($admin)->test(ScheduleBoard::class);

        $day = $component->get('day');

        $usedRoomIds = DB::table('schedule_entries')
            ->join('slots', 'slots.id', '=', 'schedule_entries.slot_id')
            ->whereDate('slots.day', $day)
            ->distinct()
            ->pluck('schedule_entries.room_id');

        $allRoomCount = DB::table('rooms')->count();
        $this->assertGreaterThan($usedRoomIds->count(), $allRoomCount, 'Test verisinde boş derslik yok');

        $emptyRoom = DB::table('rooms')
            ->whereNotIn('id', $usedRoomIds)
            ->orderBy('name')
            ->first();

        $usedRoom = DB::table('rooms')->whereIn('id', $usedRoomIds)->orderBy('name')->first();

        $component->assertSee($usedRoom->name)
            ->assertDontSee($emptyRoom->name);

        // İsteyen açabilir.
        $component->set('showEmptyRooms', true)->assertSee($emptyRoom->name);
    }

    /**
     * Öğretim üyesi kendi görev listesini kâğıda dökebilmeli. Çıktı
     * sorgusu zaten kendi görevleriyle sınırlı.
     */
    public function test_ogretim_uyesi_kendi_cizelgesini_yazdirabilir(): void
    {
        $this->prepareSolved();

        $lecturer = User::where('role', User::LECTURER)->firstOrFail();
        $solution = Solution::where('status', Solution::COMPLETED)->orderByDesc('id')->firstOrFail();

        Livewire::actingAs($lecturer)
            ->test(InvigilationTable::class)
            ->assertSee(route('print.invigilators', $solution), escape: false)
            // Arama kutusu yöneticiye ait; öğretim üyesinde görünmemeli.
            ->assertDontSee('Öğretim üyesi ara');
    }

    // --- Yardımcılar ---------------------------------------------------------

    private function prepareSolved(): User
    {
        $this->seed(DemoSeeder::class);

        $tenantId = DB::table('tenants')->value('id');
        Tenancy::use($tenantId);

        $this->solve($tenantId, 'Duman testi');

        return User::where('role', User::ADMIN)->firstOrFail();
    }

    private function solve(int $tenantId, string $label, array $params = []): Solution
    {
        $service = app(SolveService::class);

        $solution = $service->create($tenantId, array_merge([
            'seed' => 1,
            'max_iter' => 1500,
            'cooling' => 0.99,
        ], $params), $label);

        return $service->execute($solution);
    }

    /** Üretim ekranı testleri için en küçük geçerli veri. */
    private function seedTinyFaculty(int $tenantId): void
    {
        $buildingId = DB::table('buildings')->insertGetId([
            'tenant_id' => $tenantId, 'name' => 'A Blok', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('rooms')->insert([
            'tenant_id' => $tenantId, 'building_id' => $buildingId, 'name' => 'D-101',
            'capacity' => 30, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('slots')->insert([
            'tenant_id' => $tenantId, 'day' => '2026-06-08', 'index_in_day' => 1,
            'starts_at' => '09:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $courseId = DB::table('courses')->insertGetId([
            'tenant_id' => $tenantId, 'code' => 'BLM101', 'name' => 'Giriş',
            'department' => 'Bilgisayar', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('exams')->insert([
            'tenant_id' => $tenantId, 'course_id' => $courseId, 'student_count' => 10,
            'duration_min' => 60, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
