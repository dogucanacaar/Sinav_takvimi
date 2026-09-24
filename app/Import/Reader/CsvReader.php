<?php

namespace App\Import\Reader;

use RuntimeException;

/**
 * CSV okuyucusu.
 *
 * Bir CSV dosyası tek sayfadır; sayfa adı dosya adından gelir. Birden
 * çok sayfa gerektiğinde her sayfa ayrı bir CSV olur ve
 * CsvReader::directory() ile bir klasör tek bir okuyucu gibi kullanılır.
 *
 * Ayırıcı otomatik bulunur: Türkçe Excel ondalık ayırıcı olarak virgül
 * kullandığı için CSV'leri noktalı virgülle kaydeder; bunu kullanıcıya
 * sormak yerine ilk satırdan anlamak daha az sürtünme yaratır.
 */
final class CsvReader implements SheetReader
{
    /** @var array<string,string> sayfa adı => dosya yolu */
    private array $files;

    private function __construct(array $files)
    {
        $this->files = $files;
    }

    public static function file(string $path, ?string $sheetName = null): self
    {
        if (! is_file($path)) {
            throw new RuntimeException("Dosya bulunamadı: {$path}");
        }

        return new self([$sheetName ?? pathinfo($path, PATHINFO_FILENAME) => $path]);
    }

    /** Klasördeki her .csv dosyasını bir sayfa olarak ele alır. */
    public static function directory(string $path): self
    {
        if (! is_dir($path)) {
            throw new RuntimeException("Klasör bulunamadı: {$path}");
        }

        $files = [];

        foreach (glob(rtrim($path, '/').'/*.csv') ?: [] as $file) {
            $files[pathinfo($file, PATHINFO_FILENAME)] = $file;
        }

        if ($files === []) {
            throw new RuntimeException("Klasörde .csv dosyası yok: {$path}");
        }

        return new self($files);
    }

    public function sheets(): array
    {
        return array_keys($this->files);
    }

    public function hasSheet(string $sheet): bool
    {
        return isset($this->files[$sheet]);
    }

    public function rows(string $sheet): iterable
    {
        if (! isset($this->files[$sheet])) {
            throw new RuntimeException("Sayfa bulunamadı: {$sheet}");
        }

        $path = $this->files[$sheet];
        $delimiter = $this->detectDelimiter($path);

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Dosya okunamadı: {$path}");
        }

        try {
            $rowNumber = 0;

            while (($values = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                $rowNumber++;

                if ($values === [null]) {
                    continue; // tamamen boş satır
                }

                // Excel'in eklediği BOM ilk sütun başlığını bozar.
                if ($rowNumber === 1 && isset($values[0])) {
                    $values[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $values[0]);
                }

                yield $rowNumber => array_map(fn ($v) => trim((string) $v), $values);
            }
        } finally {
            fclose($handle);
        }
    }

    private function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return ',';
        }

        $line = (string) fgets($handle);
        fclose($handle);

        $counts = [
            ';' => substr_count($line, ';'),
            ',' => substr_count($line, ','),
            "\t" => substr_count($line, "\t"),
        ];

        arsort($counts);

        return array_key_first($counts) ?: ',';
    }
}
