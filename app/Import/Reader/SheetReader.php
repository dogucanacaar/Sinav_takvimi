<?php

namespace App\Import\Reader;

/**
 * Elektronik tablo okuyucusu.
 *
 * İçe aktarma katmanı hangi dosya biçimiyle uğraştığını bilmez; sadece
 * "şu sayfadaki satırları ver" der. Böylece CSV ve XLSX aynı doğrulama
 * ve aynı hata raporlama kodundan geçer.
 */
interface SheetReader
{
    /** @return string[] dosyadaki sayfa adları */
    public function sheets(): array;

    /**
     * Sayfadaki satırlar. Anahtar, dosyadaki gerçek satır numarasıdır
     * (1'den başlar) — hata mesajında kullanıcıya bu numara gösterilir.
     *
     * @return iterable<int,string[]> satırNo => sütun değerleri (0'dan indeksli)
     */
    public function rows(string $sheet): iterable;

    public function hasSheet(string $sheet): bool;
}
