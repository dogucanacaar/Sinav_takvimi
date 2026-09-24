<?php

namespace App\Import\Reader;

use RuntimeException;

/**
 * Dosya yoluna bakarak doğru okuyucuyu seçer.
 */
final class ReaderFactory
{
    public static function open(string $path): SheetReader
    {
        if (is_dir($path)) {
            return CsvReader::directory($path);
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'xlsx', 'xlsm' => new XlsxReader($path),
            'csv', 'txt', 'tsv' => CsvReader::file($path),
            default => throw new RuntimeException(
                "Desteklenmeyen dosya türü: .{$extension}. .xlsx veya .csv bekleniyor."
            ),
        };
    }
}
