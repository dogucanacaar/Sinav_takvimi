<?php

namespace App\Import\Reader;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Bağımsız XLSX okuyucusu.
 *
 * Neden hazır bir kütüphane değil: .xlsx zaten XML dosyalarından oluşan
 * bir zip arşividir ve PHP'nin ZipArchive + SimpleXML eklentileri
 * kutudan çıkar. Bize gereken tek şey hücrelerin metin karşılığı —
 * biçim, formül, grafik, stil değil. Bu kadarı için üçüncü parti bir
 * bağımlılık taşımak, güncellemesinden güvenlik yamasına kadar her şeyi
 * de beraberinde taşımak demek.
 *
 * Desteklenenler: paylaşılan metinler, satır içi metinler, sayılar,
 * tarihler (hem metin hem Excel seri numarası), boolean.
 * Desteklenmeyen: formüllerin yeniden hesaplanması (Excel'in sakladığı
 * son değer okunur — pratikte istediğimiz de budur).
 */
final class XlsxReader implements SheetReader
{
    private const NS_RELATIONSHIPS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** @var array<string,string> sayfa adı => zip içindeki yol */
    private array $sheetPaths = [];

    /** @var string[] */
    private array $sharedStrings = [];

    private ZipArchive $zip;

    public function __construct(string $path)
    {
        if (! is_file($path)) {
            throw new RuntimeException("Dosya bulunamadı: {$path}");
        }

        $this->zip = new ZipArchive;

        if ($this->zip->open($path) !== true) {
            throw new RuntimeException('Dosya açılamadı. Geçerli bir .xlsx dosyası mı?');
        }

        $this->readWorkbook();
        $this->readSharedStrings();
    }

    public function __destruct()
    {
        @$this->zip->close();
    }

    public function sheets(): array
    {
        return array_keys($this->sheetPaths);
    }

    public function hasSheet(string $sheet): bool
    {
        return isset($this->sheetPaths[$sheet]);
    }

    public function rows(string $sheet): iterable
    {
        if (! isset($this->sheetPaths[$sheet])) {
            throw new RuntimeException("Sayfa bulunamadı: {$sheet}");
        }

        $xml = $this->load($this->sheetPaths[$sheet]);

        foreach ($xml->sheetData->row as $row) {
            $rowNumber = (int) $row['r'];
            $cells = [];
            $maxColumn = -1;

            foreach ($row->c as $cell) {
                $column = self::columnIndex((string) $cell['r']);
                $cells[$column] = $this->cellValue($cell);
                $maxColumn = max($maxColumn, $column);
            }

            if ($maxColumn < 0) {
                continue;
            }

            // Boş hücreler dosyada hiç yer almaz. Sütun hizası bozulmasın
            // diye aradaki boşluklar doldurulur.
            $values = [];

            for ($i = 0; $i <= $maxColumn; $i++) {
                $values[$i] = $cells[$i] ?? '';
            }

            yield $rowNumber => $values;
        }
    }

    private function readWorkbook(): void
    {
        $workbook = $this->load('xl/workbook.xml');
        $rels = $this->load('xl/_rels/workbook.xml.rels');

        $targets = [];

        foreach ($rels->Relationship as $relationship) {
            $targets[(string) $relationship['Id']] = (string) $relationship['Target'];
        }

        foreach ($workbook->sheets->sheet as $sheet) {
            $id = (string) $sheet->attributes(self::NS_RELATIONSHIPS)['id'];
            $target = $targets[$id] ?? null;

            if ($target === null) {
                continue;
            }

            // Hedef yol xl/ klasörüne görelidir.
            $target = ltrim($target, '/');
            $path = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;

            $this->sheetPaths[(string) $sheet['name']] = $path;
        }

        if ($this->sheetPaths === []) {
            throw new RuntimeException('Dosyada hiç sayfa bulunamadı.');
        }
    }

    private function readSharedStrings(): void
    {
        $raw = $this->zip->getFromName('xl/sharedStrings.xml');

        if ($raw === false) {
            return; // Tüm metinler satır içiyse bu dosya hiç oluşmaz.
        }

        $xml = new SimpleXMLElement($raw);

        foreach ($xml->si as $item) {
            // Biçimlendirilmiş metin <r> parçalarına bölünür; hepsi birleştirilir.
            $text = '';

            foreach ($item->xpath('.//*[local-name()="t"]') as $part) {
                $text .= (string) $part;
            }

            $this->sharedStrings[] = $text;
        }
    }

    private function cellValue(SimpleXMLElement $cell): string
    {
        $type = (string) $cell['t'];

        return match ($type) {
            's' => $this->sharedStrings[(int) $cell->v] ?? '',
            'inlineStr' => trim(implode('', array_map(strval(...), $cell->xpath('.//*[local-name()="t"]') ?: []))),
            'b' => ((string) $cell->v) === '1' ? '1' : '0',
            default => trim((string) $cell->v),
        };
    }

    private function load(string $path): SimpleXMLElement
    {
        $raw = $this->zip->getFromName($path);

        if ($raw === false) {
            throw new RuntimeException("Arşivde beklenen dosya yok: {$path}");
        }

        return new SimpleXMLElement($raw);
    }

    /** "BC12" → 54 (0'dan indeksli sütun numarası) */
    public static function columnIndex(string $reference): int
    {
        $letters = rtrim($reference, '0123456789');
        $index = 0;

        for ($i = 0, $length = strlen($letters); $i < $length; $i++) {
            $index = $index * 26 + (ord(strtoupper($letters[$i])) - 64);
        }

        return $index - 1;
    }
}
