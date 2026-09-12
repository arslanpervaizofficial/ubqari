<?php

namespace App\Support;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/** Hand-rolled, dependency-free XLSX reader/writer for simple flat tables —
 *  deliberately built without Composer packages (PhpSpreadsheet/Maatwebsite
 *  Excel etc.) so Products import/export works on any shared-hosting PHP
 *  that has the near-universal `zip` extension, with nothing new to
 *  `composer require`. It only handles what that feature needs: one
 *  worksheet, a header row, and plain string/number cells — not a
 *  general-purpose spreadsheet library. */
class SimpleXlsx
{
    /** @param array<int, array<int, string|int|float|null>> $rows */
    public static function write(array $rows, string $sheetName = 'Sheet1'): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', self::contentTypesXml());
        $zip->addFromString('_rels/.rels', self::relsXml());
        $zip->addFromString('xl/workbook.xml', self::workbookXml($sheetName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelsXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::sheetXml($rows));
        $zip->close();

        $content = file_get_contents($tmp);
        unlink($tmp);

        return $content;
    }

    /** Reads the FIRST worksheet of an .xlsx file into a plain array of
     *  rows (each a 0-indexed array of string values, gaps filled with
     *  ''). Handles both inline strings and the shared-strings table —
     *  files actually saved by Excel/openpyxl almost always use the
     *  latter for text columns. */
    public static function read(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open the uploaded file as an XLSX file — is it a real .xlsx?');
        }

        $sharedStrings = self::readSharedStrings($zip);
        $sheetPath = self::firstSheetPath($zip);
        $sheetXml = $zip->getFromName($sheetPath);
        $zip->close();

        if ($sheetXml === false) {
            throw new RuntimeException('Could not find a worksheet inside that XLSX file.');
        }

        $doc = new SimpleXMLElement($sheetXml);
        $rows = [];

        foreach ($doc->sheetData->row as $row) {
            $rowIndex = ((int) $row['r']) - 1;
            $cells = [];

            foreach ($row->c as $c) {
                preg_match('/^([A-Z]+)/', (string) $c['r'], $m);
                $colIndex = self::colIndex($m[1] ?? 'A');
                $type = (string) $c['t'];

                if ($type === 's') {
                    $value = $sharedStrings[(int) $c->v] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = (string) ($c->is->t ?? '');
                } else {
                    $value = isset($c->v) ? (string) $c->v : '';
                }

                $cells[$colIndex] = $value;
            }

            if ($cells) {
                $max = max(array_keys($cells));
                for ($i = 0; $i <= $max; $i++) {
                    $rows[$rowIndex][$i] = $cells[$i] ?? '';
                }
            } else {
                $rows[$rowIndex] = [];
            }
        }

        ksort($rows);
        return array_values($rows);
    }

    private static function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $doc = new SimpleXMLElement($xml);
        $strings = [];
        foreach ($doc->si as $si) {
            if (isset($si->t)) {
                $strings[] = (string) $si->t;
            } else {
                $text = '';
                foreach ($si->r as $run) {
                    $text .= (string) $run->t;
                }
                $strings[] = $text;
            }
        }

        return $strings;
    }

    private static function firstSheetPath(ZipArchive $zip): string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbookXml === false || $relsXml === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        $wb = new SimpleXMLElement($workbookXml);
        $firstSheet = $wb->sheets->sheet[0] ?? null;
        if (!$firstSheet) {
            return 'xl/worksheets/sheet1.xml';
        }

        $attrs = $firstSheet->attributes('r', true);
        $rid = (string) ($attrs['id'] ?? '');

        $rels = new SimpleXMLElement($relsXml);
        foreach ($rels->Relationship as $rel) {
            if ((string) $rel['Id'] === $rid) {
                return 'xl/' . (string) $rel['Target'];
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private static function colIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $ch) {
            $index = $index * 26 + (ord($ch) - 64);
        }
        return $index - 1;
    }

    private static function colLetter(int $index): string
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $index = intdiv($index - $mod, 26);
        }
        return $letter;
    }

    private static function sheetXml(array $rows): string
    {
        $xmlRows = '';
        foreach ($rows as $r => $row) {
            $rowNum = $r + 1;
            $cells = '';
            foreach (array_values($row) as $c => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $ref = self::colLetter($c) . $rowNum;
                if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value) && !preg_match('/^0[0-9]/', $value))) {
                    $cells .= '<c r="' . $ref . '"><v>' . self::esc((string) $value) . '</v></c>';
                } else {
                    $cells .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . self::esc((string) $value) . '</t></is></c>';
                }
            }
            $xmlRows .= '<row r="' . $rowNum . '">' . $cells . '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $xmlRows . '</sheetData>'
            . '</worksheet>';
    }

    private static function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';
    }

    private static function relsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbookXml(string $sheetName): string
    {
        $sheetName = htmlspecialchars($sheetName, ENT_XML1);
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $sheetName . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>';
    }
}
