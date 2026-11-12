<?php
namespace App\Services\Leads;

use SimpleXMLElement;
use ZipArchive;

/**
 * Minimal XLSX reader (first worksheet, shared + inline strings, numbers).
 * Covers the flat lead lists this product imports; avoids a heavy
 * spreadsheet dependency for one narrow job.
 */
class SimpleXlsxReader
{
    /** @return array<int, array<string, string>> rows keyed by the header row */
    public static function read(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Could not open the XLSX file.');
        }
        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $doc = simplexml_load_string($xml);
            foreach ($doc->si as $si) {
                $text = '';
                foreach ($si->xpath('.//*[local-name()="t"]') as $t) $text .= (string) $t;
                $shared[] = $text;
            }
        }
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheetXml === false) throw new \RuntimeException('No worksheet found in the XLSX file.');

        $doc = simplexml_load_string($sheetXml);
        $rows = [];
        foreach ($doc->xpath('//*[local-name()="row"]') as $row) {
            $cells = [];
            foreach ($row->xpath('./*[local-name()="c"]') as $cell) {
                $ref = (string) $cell['r'];
                $col = preg_replace('/\d+/', '', $ref);
                $type = (string) $cell['t'];
                $value = '';
                if ($type === 's') {
                    $idx = (int) ($cell->v ?? 0);
                    $value = $shared[$idx] ?? '';
                } elseif ($type === 'inlineStr') {
                    foreach ($cell->xpath('.//*[local-name()="t"]') as $t) $value .= (string) $t;
                } else {
                    $value = isset($cell->v) ? (string) $cell->v : '';
                }
                $cells[self::colIndex($col)] = $value;
            }
            if ($cells) {
                ksort($cells);
                $rows[] = array_values($cells);
            }
        }
        if (count($rows) < 2) return [];

        $headers = $rows[0];
        $out = [];
        foreach (array_slice($rows, 1) as $row) {
            $assoc = [];
            foreach ($headers as $i => $header) {
                $assoc[trim((string) $header)] = $row[$i] ?? '';
            }
            $out[] = $assoc;
        }
        return $out;
    }

    private static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) $n = $n * 26 + (ord($ch) - 64);
        return $n - 1;
    }
}
