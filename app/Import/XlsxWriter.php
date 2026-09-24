<?php

namespace App\Import;

use RuntimeException;
use ZipArchive;

/**
 * Asgari XLSX yazıcısı.
 *
 * İki işi var: kullanıcıya indirilebilir bir boş şablon üretmek ve
 * testlerde gerçek .xlsx dosyaları oluşturmak. XlsxReader gibi bunun da
 * üçüncü parti bir bağımlılığı yoktur — .xlsx sonuçta içinde birkaç XML
 * dosyası olan bir zip arşividir.
 *
 * Bilinçli olarak desteklenmeyenler: biçimlendirme, formül, grafik,
 * birleştirilmiş hücre. Şablon ve test için gereken tek şey metin ve
 * sayı yazabilmek.
 */
final class XlsxWriter
{
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const NS_PKG_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const NS_CONTENT_TYPES = 'http://schemas.openxmlformats.org/package/2006/content-types';

    /** @var array<string,array<int,array<int,string|int|float>>> sayfa adı => satırlar */
    private array $sheets = [];

    /** @var string[] */
    private array $sharedStrings = [];

    /** @var array<string,int> */
    private array $sharedIndex = [];

    public function __construct(private readonly bool $useSharedStrings = true) {}

    /** @param array<int,array<int,string|int|float>> $rows */
    public function addSheet(string $name, array $rows): self
    {
        $this->sheets[$name] = $rows;

        return $this;
    }

    public function save(string $path): void
    {
        if ($this->sheets === []) {
            throw new RuntimeException('En az bir sayfa gerekli.');
        }

        // Sayfa XML'leri önce üretilir: paylaşılan metin tablosu bu
        // sırada dolar ve ancak ondan sonra yazılabilir.
        $sheetXml = [];
        $index = 1;

        foreach ($this->sheets as $rows) {
            $sheetXml[$index++] = $this->sheetXml($rows);
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Dosya yazılamadı: {$path}");
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());

        foreach ($sheetXml as $number => $xml) {
            $zip->addFromString("xl/worksheets/sheet{$number}.xml", $xml);
        }

        if ($this->useSharedStrings) {
            $zip->addFromString('xl/sharedStrings.xml', $this->sharedStringsXml());
        }

        $zip->close();
    }

    private function sheetXml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="'.self::NS_MAIN.'"><sheetData>';

        $rowNumber = 0;

        foreach ($rows as $row) {
            $rowNumber++;
            $xml .= '<row r="'.$rowNumber.'">';
            $column = 0;

            foreach ($row as $value) {
                $reference = self::columnName($column++).$rowNumber;
                $xml .= $this->cellXml($reference, $value);
            }

            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private function cellXml(string $reference, string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_int($value) || is_float($value)) {
            return '<c r="'.$reference.'"><v>'.$value.'</v></c>';
        }

        if ($this->useSharedStrings) {
            $index = $this->sharedIndex[$value] ?? null;

            if ($index === null) {
                $index = count($this->sharedStrings);
                $this->sharedStrings[] = $value;
                $this->sharedIndex[$value] = $index;
            }

            return '<c r="'.$reference.'" t="s"><v>'.$index.'</v></c>';
        }

        return '<c r="'.$reference.'" t="inlineStr"><is><t>'.self::escape($value).'</t></is></c>';
    }

    private function sharedStringsXml(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<sst xmlns="'.self::NS_MAIN.'" count="'.count($this->sharedStrings).'"'
            .' uniqueCount="'.count($this->sharedStrings).'">';

        foreach ($this->sharedStrings as $string) {
            $xml .= '<si><t xml:space="preserve">'.self::escape($string).'</t></si>';
        }

        return $xml.'</sst>';
    }

    private function workbookXml(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="'.self::NS_MAIN.'" xmlns:r="'.self::NS_REL.'"><sheets>';

        $index = 1;

        foreach (array_keys($this->sheets) as $name) {
            $xml .= '<sheet name="'.self::escape($name).'" sheetId="'.$index.'" r:id="rId'.$index.'"/>';
            $index++;
        }

        return $xml.'</sheets></workbook>';
    }

    private function workbookRelsXml(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="'.self::NS_PKG_REL.'">';

        $index = 1;

        foreach (array_keys($this->sheets) as $ignored) {
            $xml .= '<Relationship Id="rId'.$index.'" Type="'.self::NS_REL.'/worksheet"'
                .' Target="worksheets/sheet'.$index.'.xml"/>';
            $index++;
        }

        if ($this->useSharedStrings) {
            $xml .= '<Relationship Id="rId'.$index.'" Type="'.self::NS_REL.'/sharedStrings"'
                .' Target="sharedStrings.xml"/>';
        }

        return $xml.'</Relationships>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="'.self::NS_PKG_REL.'">'
            .'<Relationship Id="rId1" Type="'.self::NS_REL.'/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function contentTypesXml(): string
    {
        $base = 'application/vnd.openxmlformats-officedocument.spreadsheetml';

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="'.self::NS_CONTENT_TYPES.'">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="'.$base.'.sheet.main+xml"/>';

        for ($i = 1; $i <= count($this->sheets); $i++) {
            $xml .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="'.$base.'.worksheet+xml"/>';
        }

        if ($this->useSharedStrings) {
            $xml .= '<Override PartName="/xl/sharedStrings.xml" ContentType="'.$base.'.sharedStrings+xml"/>';
        }

        return $xml.'</Types>';
    }

    /** 0 → "A", 26 → "AA" */
    public static function columnName(int $index): string
    {
        $name = '';

        for ($i = $index + 1; $i > 0; $i = intdiv($i - 1, 26)) {
            $name = chr(65 + ($i - 1) % 26).$name;
        }

        return $name;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
