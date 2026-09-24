<?php

namespace Tests\Unit\Import;

use App\Import\ImportParser;
use App\Import\ImportPlan;
use App\Import\Reader\XlsxReader;
use App\Import\RowError;
use App\Import\XlsxWriter;
use PHPUnit\Framework\TestCase;

/**
 * İçe aktarmanın sözleşmesi: bozuk satır aktarımı durdurmaz.
 *
 * Kullanıcı 400 satırlık bir dosyayı yükleyip "3. satırda hata" alıp
 * düzeltip tekrar yüklemek, sonra "17. satırda hata" almak istemez.
 * Bütün sorunlar tek geçişte toplanır; sağlam satırlar işlenmeye devam
 * eder ve rapor hepsini birlikte gösterir.
 */
final class ImportParserTest extends TestCase
{
    /** @var string[] */
    private array $temporary = [];

    public function __destruct()
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }
    }

    /** @param array<string,array> $sheets */
    private function parse(array $sheets): ImportPlan
    {
        $path = sys_get_temp_dir().'/sinav-import-'.bin2hex(random_bytes(6)).'.xlsx';
        $this->temporary[] = $path;

        $writer = new XlsxWriter;

        foreach ($sheets as $name => $rows) {
            $writer->addSheet($name, $rows);
        }

        $writer->save($path);

        return (new ImportParser)->parse(new XlsxReader($path));
    }

    /** Hatasız, tutarlı bir dosya. Diğer testler bunun üstüne bozukluk ekler. */
    private function validSheets(): array
    {
        return [
            'Derslikler' => [
                ['Bina', 'Derslik', 'Kapasite'],
                ['A Blok', 'D-101', 60],
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
        ];
    }

    /** @return string[] */
    private function messages(array $errors): array
    {
        return array_map(strval(...), $errors);
    }

    public function test_gecerli_dosya_hatasiz_okunur(): void
    {
        $plan = $this->parse($this->validSheets());

        $this->assertSame([], $this->messages($plan->errorsOnly()));
        $this->assertFalse($plan->hasErrors());

        $this->assertCount(2, $plan->rooms);
        $this->assertCount(3, $plan->slots);
        $this->assertCount(2, $plan->lecturers);
        $this->assertCount(2, $plan->courses);
        $this->assertCount(3, $plan->enrollments);
        $this->assertCount(1, $plan->unavailability);
        $this->assertSame(['2026001', '2026002'], $plan->studentNumbers());
        $this->assertSame(['A Blok', 'B Blok'], array_keys($plan->buildings));
    }

    public function test_degerler_normallesir(): void
    {
        $plan = $this->parse($this->validSheets());

        $this->assertSame(
            ['day' => '2026-06-08', 'index' => 1, 'starts_at' => '09:00:00'],
            $plan->slots[0],
        );

        $this->assertSame('BLM101', $plan->courses[0]['code']);
        $this->assertSame(90, $plan->courses[0]['duration_min']);
        $this->assertSame(12, $plan->lecturers[0]['past_duty_count']);
    }

    public function test_bozuk_satirlar_aktarimi_durdurmaz(): void
    {
        $sheets = $this->validSheets();

        // Üç ayrı sayfaya, üç ayrı türde bozukluk konuyor.
        $sheets['Derslikler'][] = ['C Blok', 'D-999', 'çok'];       // kapasite sayı değil
        $sheets['Derslikler'][] = ['C Blok', 'D-500', 50];          // sağlam, bozuğun ardından
        $sheets['SaatDilimleri'][] = ['haftaya', 3, '09:00'];       // tarih anlaşılmıyor
        $sheets['SaatDilimleri'][] = ['09.06.2026', 2, '11:00'];    // sağlam
        $sheets['Kayitlar'][] = ['2026003', 'YOK999'];              // tanımsız ders
        $sheets['Kayitlar'][] = ['2026003', 'BLM101'];              // sağlam

        $plan = $this->parse($sheets);

        // Üç hata bildirildi...
        $this->assertCount(3, $plan->errorsOnly(), implode("\n", $this->messages($plan->errorsOnly())));

        // ...ama bozuk satırlardan sonra gelen sağlam satırlar işlendi.
        $this->assertCount(3, $plan->rooms, 'Bozuk satırdan sonraki derslik de okunmalı');
        $this->assertCount(4, $plan->slots, 'Bozuk satırdan sonraki saat dilimi de okunmalı');
        $this->assertCount(4, $plan->enrollments, 'Bozuk satırdan sonraki kayıt da okunmalı');
    }

    public function test_hata_mesaji_sayfa_satir_ve_sutunu_soyler(): void
    {
        $sheets = $this->validSheets();
        $sheets['Derslikler'][] = ['C Blok', 'D-999', 'çok'];

        $plan = $this->parse($sheets);
        $error = $plan->errorsOnly()[0];

        $this->assertSame('Derslikler', $error->sheet);
        $this->assertSame(4, $error->row, 'Dosyadaki gerçek satır numarası verilmeli');
        $this->assertSame('kapasite', $error->column);
        $this->assertStringContainsStringCompat('çok', $error->message);
        $this->assertStringContainsStringCompat('pozitif bir tam sayı', $error->message);
    }

    public function test_tekrar_eden_derslik_ve_ders_kodu_yakalanir(): void
    {
        $sheets = $this->validSheets();
        $sheets['Derslikler'][] = ['A Blok', 'D-101', 60];
        $sheets['Dersler'][] = ['BLM101', 'Başka Ders', 'Bilgisayar', null, 60];

        $plan = $this->parse($sheets);
        $messages = implode("\n", $this->messages($plan->errorsOnly()));

        $this->assertCount(2, $plan->errorsOnly(), $messages);
        $this->assertCount(2, $plan->rooms, 'Tekrar eden derslik ikinci kez eklenmemeli');
        $this->assertCount(2, $plan->courses, 'Tekrar eden ders kodu ikinci kez eklenmemeli');
    }

    public function test_eksik_sayfa_bildirilir(): void
    {
        $sheets = $this->validSheets();
        unset($sheets['Kayitlar']);

        $plan = $this->parse($sheets);

        $this->assertTrue($plan->hasErrors());
        $this->assertContains('Kayitlar', array_map(fn (RowError $e) => $e->sheet, $plan->errorsOnly()));
    }

    public function test_musaitsizlik_isteğe_baglidir(): void
    {
        $sheets = $this->validSheets();
        unset($sheets['Musaitsizlik']);

        $plan = $this->parse($sheets);

        $this->assertFalse($plan->hasErrors());
        $this->assertSame([], $plan->unavailability);
    }

    public function test_sayfa_ve_sutun_adlari_esnek_yazilabilir(): void
    {
        $sheets = $this->validSheets();

        // Aynı veri, farklı yazımlarla
        $sheets['DERSLİKLER'] = [
            ['bina_adi', 'sinif', 'KONTENJAN'],
            ['A Blok', 'D-101', 60],
        ];
        unset($sheets['Derslikler']);

        $plan = $this->parse($sheets);

        $this->assertSame([], $this->messages($plan->errorsOnly()));
        $this->assertCount(1, $plan->rooms);
        $this->assertSame('D-101', $plan->rooms[0]['name']);
    }

    public function test_tanimsiz_ders_kodu_bir_kez_bildirilir(): void
    {
        $sheets = $this->validSheets();

        // Aynı hatalı kod 50 satırda geçiyor; rapor okunmaz olmamalı.
        for ($i = 0; $i < 50; $i++) {
            $sheets['Kayitlar'][] = ['20260'.$i, 'YOK999'];
        }

        $plan = $this->parse($sheets);

        $this->assertCount(1, $plan->errorsOnly());
    }

    public function test_bilinmeyen_hoca_uyari_uretir_ders_yine_de_alinir(): void
    {
        $sheets = $this->validSheets();
        $sheets['Dersler'][] = ['BLM301', 'İşletim Sistemleri', 'Bilgisayar', 'Olmayan Hoca', 60];
        $sheets['Kayitlar'][] = ['2026001', 'BLM301'];

        $plan = $this->parse($sheets);

        $this->assertFalse($plan->hasErrors());
        $this->assertCount(1, $plan->warningsOnly());
        $this->assertCount(3, $plan->courses);
        $this->assertNull($plan->courses[2]['lecturer'], 'Ders sorumlusuz bırakılmalı');
    }

    public function test_dersligine_sigmayan_sinav_onceden_bildirilir(): void
    {
        $sheets = $this->validSheets();
        $sheets['Derslikler'] = [
            ['Bina', 'Derslik', 'Kapasite'],
            ['A Blok', 'D-101', 2],
        ];

        // BLM101'e 3 öğrenci kayıtlı, en büyük derslik 2 kişilik.
        $sheets['Kayitlar'][] = ['2026003', 'BLM101'];

        $plan = $this->parse($sheets);
        $messages = implode("\n", $this->messages($plan->errorsOnly()));

        $this->assertTrue($plan->hasErrors());
        $this->assertStringContainsStringCompat('yerleştirilemez', $messages);
    }

    public function test_kayitsiz_ders_uyari_uretir(): void
    {
        $sheets = $this->validSheets();
        $sheets['Dersler'][] = ['BLM401', 'Bitirme Projesi', 'Bilgisayar', null, 60];

        $plan = $this->parse($sheets);

        $this->assertFalse($plan->hasErrors());
        $this->assertStringContainsStringCompat(
            'kayıtlı öğrenci yok',
            implode("\n", $this->messages($plan->warningsOnly())),
        );
    }

    public function test_saat_dilimi_sirasi_verilmezse_uretilir(): void
    {
        $sheets = $this->validSheets();
        $sheets['SaatDilimleri'] = [
            ['Tarih', 'Başlangıç'],
            ['08.06.2026', '09:00'],
            ['08.06.2026', '11:00'],
            ['09.06.2026', '09:00'],
        ];

        $plan = $this->parse($sheets);

        $this->assertFalse($plan->hasErrors());
        $this->assertSame([1, 2, 1], array_column($plan->slots, 'index'));
    }

    private function assertStringContainsStringCompat(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "\"{$needle}\" beklenen metinde yok:\n{$haystack}",
        );
    }
}
