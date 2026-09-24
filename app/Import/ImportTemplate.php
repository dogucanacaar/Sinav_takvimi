<?php

namespace App\Import;

/**
 * İçe aktarma şablonu.
 *
 * Kullanıcıya boş bir dosya vermek yerine, her sayfada birkaç örnek satır
 * bulunan bir dosya verilir. Beklenen biçimi anlatmanın en kısa yolu,
 * doğru doldurulmuş bir örnek göstermektir.
 */
final class ImportTemplate
{
    public static function write(string $path): void
    {
        (new XlsxWriter)
            ->addSheet('Derslikler', [
                ['Bina', 'Derslik', 'Kapasite'],
                ['A Blok', 'D-101', 60],
                ['A Blok', 'D-102', 45],
                ['B Blok', 'Amfi 1', 220],
            ])
            ->addSheet('SaatDilimleri', [
                ['Tarih', 'Sıra', 'Başlangıç'],
                ['08.06.2026', 1, '09:00'],
                ['08.06.2026', 2, '11:00'],
                ['08.06.2026', 3, '14:00'],
                ['09.06.2026', 1, '09:00'],
            ])
            ->addSheet('OgretimUyeleri', [
                ['Ad Soyad', 'Unvan', 'Bölüm', 'Geçmiş Görev'],
                ['Ayşe Yılmaz', 'Prof. Dr.', 'Bilgisayar Mühendisliği', 12],
                ['Mehmet Kaya', 'Dr. Öğr. Üyesi', 'Bilgisayar Mühendisliği', 4],
            ])
            ->addSheet('Musaitsizlik', [
                ['Ad Soyad', 'Tarih', 'Sıra'],
                ['Ayşe Yılmaz', '08.06.2026', 1],
            ])
            ->addSheet('Dersler', [
                ['Ders Kodu', 'Ders Adı', 'Bölüm', 'Öğretim Üyesi', 'Süre'],
                ['BLM101', 'Programlamaya Giriş', 'Bilgisayar Mühendisliği', 'Ayşe Yılmaz', 90],
                ['BLM201', 'Veri Yapıları', 'Bilgisayar Mühendisliği', 'Mehmet Kaya', 60],
            ])
            ->addSheet('Kayitlar', [
                ['Öğrenci No', 'Ders Kodu'],
                ['2026001', 'BLM101'],
                ['2026001', 'BLM201'],
                ['2026002', 'BLM101'],
            ])
            ->save($path);
    }

    /** Sayfa ve sütun beklentilerinin insan okunur özeti (arayüzde gösterilir). */
    public static function describe(): array
    {
        return [
            'Derslikler' => ['Bina', 'Derslik', 'Kapasite'],
            'SaatDilimleri' => ['Tarih', 'Sıra (gün içi oturum no)', 'Başlangıç'],
            'OgretimUyeleri' => ['Ad Soyad', 'Unvan', 'Bölüm', 'Geçmiş Görev'],
            'Musaitsizlik' => ['Ad Soyad', 'Tarih', 'Sıra'],
            'Dersler' => ['Ders Kodu', 'Ders Adı', 'Bölüm', 'Öğretim Üyesi', 'Süre'],
            'Kayitlar' => ['Öğrenci No', 'Ders Kodu'],
        ];
    }
}
