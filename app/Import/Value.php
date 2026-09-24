<?php

namespace App\Import;

/**
 * Hücre değerlerinin normalleştirilmesi.
 *
 * Gerçek dosyalar temiz gelmez: tarih bir yerde 12.06.2026, bir yerde
 * 2026-06-12, bir yerde de Excel'in sakladığı 46185 sayısıdır. Aynı
 * şekilde başlıklar "Ders Kodu", "ders_kodu", "DERSKODU" olabilir.
 * Kullanıcıyı tek bir biçime zorlamak yerine yaygın olanları kabul
 * etmek, içe aktarmada en çok zaman kazandıran şeydir.
 */
final class Value
{
    /** Excel tarihleri 30.12.1899'dan itibaren geçen gün sayısıdır. */
    private const EXCEL_EPOCH = '1899-12-30';

    /**
     * Başlık ve sayfa adlarını karşılaştırılabilir hâle getirir:
     * Türkçe harfler sadeleşir, boşluk ve noktalama düşer.
     *
     * "Öğretim Üyesi" → "ogretimuyesi"
     */
    public static function slug(string $text): string
    {
        $map = [
            'ı' => 'i', 'İ' => 'i', 'I' => 'i', 'i' => 'i',
            'ş' => 's', 'Ş' => 's',
            'ğ' => 'g', 'Ğ' => 'g',
            'ü' => 'u', 'Ü' => 'u',
            'ö' => 'o', 'Ö' => 'o',
            'ç' => 'c', 'Ç' => 'c',
        ];

        $text = strtr($text, $map);
        $text = mb_strtolower($text, 'UTF-8');

        return preg_replace('/[^a-z0-9]/u', '', $text) ?? '';
    }

    /** Tarihi 'Y-m-d' biçimine çevirir; anlaşılmazsa null. */
    public static function date(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        // Excel'in seri numarası (yaklaşık 1970–2070 aralığı)
        if (preg_match('/^\d+(\.\d+)?$/', $raw) && (float) $raw > 25000 && (float) $raw < 80000) {
            $date = date_create(self::EXCEL_EPOCH);

            return $date ? $date->modify('+'.(int) $raw.' days')->format('Y-m-d') : null;
        }

        foreach (['d.m.Y', 'd/m/Y', 'd-m-Y', 'Y-m-d', 'Y.m.d', 'd.m.y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $raw);

            if ($date !== false && $date->format($format) === $raw) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    /** Saati 'H:i:s' biçimine çevirir; anlaşılmazsa null. */
    public static function time(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        // Excel saatleri günün kesri olarak tutar: 0.375 = 09:00
        if (preg_match('/^0?\.\d+$/', $raw)) {
            $seconds = (int) round((float) $raw * 86400);

            return sprintf('%02d:%02d:00', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
        }

        if (preg_match('/^(\d{1,2})[:.](\d{2})(?:[:.](\d{2}))?$/', $raw, $m)) {
            $hour = (int) $m[1];
            $minute = (int) $m[2];

            if ($hour > 23 || $minute > 59) {
                return null;
            }

            return sprintf('%02d:%02d:%02d', $hour, $minute, (int) ($m[3] ?? 0));
        }

        return null;
    }

    /** Tam sayıya çevirir; sayı değilse null. */
    public static function integer(string $raw): ?int
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        // "120,0" ya da "120.0" gibi değerler Excel'den sık gelir.
        $raw = preg_replace('/[.,]0+$/', '', $raw) ?? $raw;

        return preg_match('/^-?\d+$/', $raw) === 1 ? (int) $raw : null;
    }

    /** Fazla boşlukları temizler: "  Ahmet   Yılmaz " → "Ahmet Yılmaz" */
    public static function text(string $raw): string
    {
        return trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
    }
}
