<?php

namespace Tests\Unit\Import;

use App\Import\Reader\CsvReader;
use App\Import\Reader\XlsxReader;
use App\Import\XlsxWriter;
use PHPUnit\Framework\TestCase;

/**
 * Okuyucu ile yazıcı aynı projede olduğu için birbirini doğrulayabilir:
 * yazılan dosya gerçek bir .xlsx arşividir ve geri okunduğunda aynı
 * değerleri vermelidir.
 */
final class XlsxRoundTripTest extends TestCase
{
    /** @var string[] */
    private array $temporary = [];

    private function tempPath(string $extension = 'xlsx'): string
    {
        $path = sys_get_temp_dir().'/sinav-test-'.bin2hex(random_bytes(6)).'.'.$extension;
        $this->temporary[] = $path;

        return $path;
    }

    public function __destruct()
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }
    }

    public function test_paylasilan_metinlerle_yazilan_dosya_geri_okunur(): void
    {
        $path = $this->tempPath();

        (new XlsxWriter(useSharedStrings: true))
            ->addSheet('Derslikler', [
                ['Bina', 'Derslik', 'Kapasite'],
                ['A Blok', 'D-101', 60],
                ['A Blok', 'D-102', 45],
            ])
            ->save($path);

        $reader = new XlsxReader($path);

        $this->assertSame(['Derslikler'], $reader->sheets());

        $rows = iterator_to_array($reader->rows('Derslikler'));

        $this->assertSame(['Bina', 'Derslik', 'Kapasite'], $rows[1]);
        $this->assertSame(['A Blok', 'D-101', '60'], $rows[2]);
        $this->assertSame(['A Blok', 'D-102', '45'], $rows[3]);
    }

    public function test_satir_ici_metinlerle_yazilan_dosya_geri_okunur(): void
    {
        $path = $this->tempPath();

        (new XlsxWriter(useSharedStrings: false))
            ->addSheet('Dersler', [
                ['Ders Kodu', 'Ders Adı'],
                ['BLM101', 'Programlamaya Giriş & Algoritma'],
            ])
            ->save($path);

        $rows = iterator_to_array((new XlsxReader($path))->rows('Dersler'));

        // & işareti XML'de kaçışlanır; geri okunduğunda aslına dönmeli.
        $this->assertSame(['BLM101', 'Programlamaya Giriş & Algoritma'], $rows[2]);
    }

    public function test_birden_cok_sayfa_korunur(): void
    {
        $path = $this->tempPath();

        (new XlsxWriter)
            ->addSheet('Derslikler', [['Bina']])
            ->addSheet('Dersler', [['Ders Kodu']])
            ->addSheet('Kayitlar', [['Öğrenci No']])
            ->save($path);

        $reader = new XlsxReader($path);

        $this->assertSame(['Derslikler', 'Dersler', 'Kayitlar'], $reader->sheets());
        $this->assertTrue($reader->hasSheet('Kayitlar'));
        $this->assertFalse($reader->hasSheet('Yok'));
    }

    public function test_bos_hucreler_sutun_hizasini_bozmaz(): void
    {
        $path = $this->tempPath();

        (new XlsxWriter)
            ->addSheet('Dersler', [
                ['Ders Kodu', 'Ders Adı', 'Bölüm', 'Öğretim Üyesi'],
                ['BLM101', 'Giriş', '', 'Ayşe Yılmaz'],
            ])
            ->save($path);

        $rows = iterator_to_array((new XlsxReader($path))->rows('Dersler'));

        // Boş hücre dosyada hiç yer almaz; okuyucu aradaki boşluğu
        // doldurmazsa "Ayşe Yılmaz" bölüm sütununa kayar.
        $this->assertSame('', $rows[2][2]);
        $this->assertSame('Ayşe Yılmaz', $rows[2][3]);
    }

    public function test_sutun_harfi_numaraya_cevrilir(): void
    {
        $this->assertSame(0, XlsxReader::columnIndex('A1'));
        $this->assertSame(25, XlsxReader::columnIndex('Z9'));
        $this->assertSame(26, XlsxReader::columnIndex('AA100'));
        $this->assertSame(54, XlsxReader::columnIndex('BC12'));

        $this->assertSame('A', XlsxWriter::columnName(0));
        $this->assertSame('Z', XlsxWriter::columnName(25));
        $this->assertSame('AA', XlsxWriter::columnName(26));
        $this->assertSame('BC', XlsxWriter::columnName(54));
    }

    public function test_csv_noktali_virgulu_kendisi_bulur(): void
    {
        $path = $this->tempPath('csv');
        file_put_contents($path, "Bina;Derslik;Kapasite\nA Blok;D-101;60\n");

        $rows = iterator_to_array(CsvReader::file($path, 'Derslikler')->rows('Derslikler'));

        $this->assertSame(['Bina', 'Derslik', 'Kapasite'], $rows[1]);
        $this->assertSame(['A Blok', 'D-101', '60'], $rows[2]);
    }

    public function test_csv_bom_baslik_sutununu_bozmaz(): void
    {
        $path = $this->tempPath('csv');
        file_put_contents($path, "\xEF\xBB\xBFBina,Derslik\nA Blok,D-101\n");

        $rows = iterator_to_array(CsvReader::file($path, 'Derslikler')->rows('Derslikler'));

        $this->assertSame('Bina', $rows[1][0]);
    }
}
