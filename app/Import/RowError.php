<?php

namespace App\Import;

/**
 * İçe aktarmada bulunan tek bir sorun.
 *
 * Tasarım kararı: ilk hatada durulmaz. Kullanıcı 400 satırlık bir
 * dosyayı yükleyip "3. satırda hata" mesajı alıp düzeltip tekrar
 * yüklemek, sonra "17. satırda hata" almak istemez. Bütün sorunlar tek
 * seferde toplanır ve birlikte raporlanır.
 */
final class RowError
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    public function __construct(
        public readonly string $sheet,
        public readonly ?int $row,
        public readonly string $message,
        public readonly ?string $column = null,
        public readonly string $severity = self::ERROR,
    ) {}

    public static function warning(string $sheet, ?int $row, string $message, ?string $column = null): self
    {
        return new self($sheet, $row, $message, $column, self::WARNING);
    }

    public function isError(): bool
    {
        return $this->severity === self::ERROR;
    }

    /** Kullanıcıya gösterilen satır: "Dersler, 17. satır (ders_kodu): ..." */
    public function __toString(): string
    {
        $where = $this->sheet;

        if ($this->row !== null) {
            $where .= ", {$this->row}. satır";
        }

        if ($this->column !== null) {
            $where .= " ({$this->column})";
        }

        return "{$where}: {$this->message}";
    }

    public function toArray(): array
    {
        return [
            'sheet' => $this->sheet,
            'row' => $this->row,
            'column' => $this->column,
            'severity' => $this->severity,
            'message' => $this->message,
        ];
    }
}
