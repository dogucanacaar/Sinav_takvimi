<?php

namespace Tests\Feature;

use App\Import\ImportParser;
use App\Import\Reader\XlsxReader;
use App\Import\XlsxWriter;
use App\Models\Tenant;
use App\Services\ImportService;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * İçe aktarmanın veritabanına kadar olan yolu.
 *
 * Doğrulamanın kendisi ImportParserTest'te, veritabanı olmadan test
 * ediliyor. Burada sınanan şey ayrı: doğrulanmış bir planın tablolara
 * doğru yazılması — özellikle sınavların kayıtlardan türetilmesi ve
 * yeniden aktarımda eski verinin temiz silinmesi.
 */
class ImportTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = storage_path('app/test-import-'.bin2hex(random_bytes(4)).'.xlsx');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    /** @param array<string,array> $overrides */
    private function writeWorkbook(array $overrides = []): void
    {
        $sheets = array_merge([
            'Derslikler' => [
                ['Bina', 'Derslik', 'Kapasite'],
                ['A Blok', 'D-101', 50],
                ['B Blok', 'Amfi 1', 200],
            ],
            'SaatDilimleri' => [
                ['Tarih', 'Sıra', 'Başlangıç'],
                ['08.06.2026', 1, '09:00'],
                ['08.06.2026', 2, '11:00'],
                ['09.06.2026', 1, '09:00'],
            ],
            'OgretimUyeleri' => [
                ['Ad Soyad', 'Unvan', 'Bölüm', 'Geçmiş Görev'],
                ['Ayşe Yılmaz', 'Prof. Dr.', 'Bilgisayar', 12],
                ['Mehmet Kaya', 'Dr. Öğr. Üyesi', 'Bilgisayar', 4],
            ],
            'Musaitsizlik' => [
                ['Ad Soyad', 'Tarih', 'Sıra'],
                ['Ayşe Yılmaz', '08.06.2026', 1],
            ],
            'Dersler' => [
                ['Ders Kodu', 'Ders Adı', 'Bölüm', 'Öğretim Üyesi', 'Süre'],
                ['BLM101', 'Programlamaya Giriş', 'Bilgisayar', 'Ayşe Yılmaz', 90],
                ['BLM201', 'Veri Yapıları', 'Bilgisayar', 'Mehmet Kaya', 60],
            ],
            'Kayitlar' => [
                ['Öğrenci No', 'Ders Kodu'],
                ['2026001', 'BLM101'],
                ['2026001', 'BLM201'],
                ['2026002', 'BLM101'],
            ],
        ], $overrides);

        $writer = new XlsxWriter;

        foreach ($sheets as $name => $rows) {
            $writer->addSheet($name, $rows);
        }

        $writer->save($this->path);
    }

    private function import(bool $replace = true): array
    {
        $tenant = Tenant::firstOrCreate(['name' => 'Test Fakültesi']);
        Tenancy::use($tenant->id);

        $plan = (new ImportParser)->parse(new XlsxReader($this->path));

        return app(ImportService::class)->apply($tenant, $plan, $replace);
    }

    public function test_dosya_tablolara_yazilir(): void
    {
        $this->writeWorkbook();
        $written = $this->import();

        $this->assertSame([
            'binalar' => 2,
            'derslikler' => 2,
            'saat_dilimleri' => 3,
            'ogretim_uyeleri' => 2,
            'musaitsizlik' => 1,
            'dersler' => 2,
            'ogrenciler' => 2,
            'kayitlar' => 3,
            'sinavlar' => 2,
        ], $written);

        $this->assertDatabaseCount('rooms', 2);
        $this->assertDatabaseCount('enrollments', 3);
        $this->assertDatabaseHas('rooms', ['name' => 'Amfi 1', 'capacity' => 200]);
        $this->assertDatabaseHas('slots', ['day' => '2026-06-08', 'index_in_day' => 2]);
        $this->assertDatabaseHas('lecturers', ['name' => 'Ayşe Yılmaz', 'past_duty_count' => 12]);
    }

    public function test_sinavlar_kayitlardan_turetilir(): void
    {
        $this->writeWorkbook();
        $this->import();

        // BLM101'e iki, BLM201'e bir öğrenci kayıtlı.
        $counts = DB::table('exams')
            ->join('courses', 'courses.id', '=', 'exams.course_id')
            ->pluck('exams.student_count', 'courses.code')
            ->all();

        $this->assertSame(2, $counts['BLM101']);
        $this->assertSame(1, $counts['BLM201']);

        // Sınav süresi Dersler sayfasından gelir.
        $durations = DB::table('exams')
            ->join('courses', 'courses.id', '=', 'exams.course_id')
            ->pluck('exams.duration_min', 'courses.code')
            ->all();

        $this->assertSame(90, $durations['BLM101']);
    }

    public function test_ders_sorumlusu_ve_musaitsizlik_baglanir(): void
    {
        $this->writeWorkbook();
        $this->import();

        $lecturer = DB::table('lecturers')->where('name', 'Ayşe Yılmaz')->first();
        $course = DB::table('courses')->where('code', 'BLM101')->first();

        $this->assertSame((int) $lecturer->id, (int) $course->lecturer_id);

        $slot = DB::table('slots')->where('index_in_day', 1)->where('day', 'like', '2026-06-08%')->first();

        $this->assertDatabaseHas('lecturer_unavailability', [
            'lecturer_id' => $lecturer->id,
            'slot_id' => $slot->id,
        ]);
    }

    public function test_kayitsiz_ders_icin_sinav_olusmaz(): void
    {
        $this->writeWorkbook([
            'Dersler' => [
                ['Ders Kodu', 'Ders Adı', 'Bölüm', 'Öğretim Üyesi', 'Süre'],
                ['BLM101', 'Programlamaya Giriş', 'Bilgisayar', 'Ayşe Yılmaz', 90],
                ['BLM201', 'Veri Yapıları', 'Bilgisayar', 'Mehmet Kaya', 60],
                ['BLM401', 'Bitirme Projesi', 'Bilgisayar', null, 60],
            ],
        ]);

        $written = $this->import();

        $this->assertSame(3, $written['dersler']);
        $this->assertSame(2, $written['sinavlar'], 'Kimsenin almadığı dersin sınavı olmamalı');
    }

    public function test_yeniden_aktarim_eski_veriyi_temizler(): void
    {
        $this->writeWorkbook();
        $this->import();

        // İkinci aktarımda bir derslik eksik.
        $this->writeWorkbook([
            'Derslikler' => [
                ['Bina', 'Derslik', 'Kapasite'],
                ['A Blok', 'D-101', 50],
            ],
        ]);

        $this->import(replace: true);

        $this->assertDatabaseCount('rooms', 1);
        // assertDatabaseCount'un üçüncü parametresi bağlantı adıdır, mesaj değil.
        $this->assertSame(
            3,
            DB::table('enrollments')->count(),
            'Kayıtlar yeniden yazılmalı, çoğalmamalı',
        );
        $this->assertDatabaseMissing('rooms', ['name' => 'Amfi 1']);
    }

    public function test_hatali_plan_yazilmaz(): void
    {
        // Kapasite sayı değil → doğrulama hatası.
        $this->writeWorkbook([
            'Derslikler' => [
                ['Bina', 'Derslik', 'Kapasite'],
                ['A Blok', 'D-101', 'çok'],
            ],
        ]);

        $this->expectException(\RuntimeException::class);

        try {
            $this->import();
        } finally {
            // Hiçbir şey yazılmamış olmalı.
            $this->assertDatabaseCount('rooms', 0);
        }
    }
}
