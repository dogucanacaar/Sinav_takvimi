<?php

namespace Tests\Unit\Import;

use App\Import\Value;
use PHPUnit\Framework\TestCase;

/**
 * Gerçek dosyalar tek bir biçimde gelmez. Bu testler, kullanıcıyı tek
 * biçime zorlamadan hangi yazımların kabul edildiğini sabitler.
 */
final class ValueTest extends TestCase
{
    public function test_baslik_sadelestirme_turkce_harfleri_cozer(): void
    {
        $this->assertSame('ogretimuyesi', Value::slug('Öğretim Üyesi'));
        $this->assertSame('derskodu', Value::slug('DERS KODU'));
        $this->assertSame('derskodu', Value::slug('ders_kodu'));
        $this->assertSame('sira', Value::slug('Sıra'));
        $this->assertSame('gecmisgorev', Value::slug('Geçmiş Görev'));
    }

    public function test_tarih_yaygin_yazimlari_kabul_eder(): void
    {
        $this->assertSame('2026-06-12', Value::date('12.06.2026'));
        $this->assertSame('2026-06-12', Value::date('12/06/2026'));
        $this->assertSame('2026-06-12', Value::date('2026-06-12'));
    }

    public function test_excel_seri_numarasi_tarihe_cevrilir(): void
    {
        // Excel 12.06.2026'yı 46185 olarak saklar.
        $this->assertSame('2026-06-12', Value::date('46185'));
    }

    public function test_anlasilmayan_tarih_null_doner(): void
    {
        $this->assertNull(Value::date('haftaya salı'));
        $this->assertNull(Value::date('32.13.2026'));
        $this->assertNull(Value::date(''));
    }

    public function test_saat_yaygin_yazimlari_kabul_eder(): void
    {
        $this->assertSame('09:00:00', Value::time('09:00'));
        $this->assertSame('09:00:00', Value::time('9:00'));
        $this->assertSame('14:30:15', Value::time('14:30:15'));
        $this->assertSame('09:00:00', Value::time('0.375')); // Excel: günün kesri
    }

    public function test_gecersiz_saat_null_doner(): void
    {
        $this->assertNull(Value::time('25:00'));
        $this->assertNull(Value::time('sabah'));
    }

    public function test_tam_sayi_excel_ondaliklarini_temizler(): void
    {
        $this->assertSame(120, Value::integer('120'));
        $this->assertSame(120, Value::integer('120.0'));
        $this->assertSame(120, Value::integer('120,00'));
        $this->assertNull(Value::integer('yüz yirmi'));
        $this->assertNull(Value::integer(''));
    }

    public function test_metin_fazla_bosluklari_temizler(): void
    {
        $this->assertSame('Ahmet Yılmaz', Value::text("  Ahmet   Yılmaz \n"));
    }
}
