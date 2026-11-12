<?php
namespace App\Services\Exports;

/**
 * Dependency-free report writers (CSV / XLSX / PDF) so exports work in
 * any environment without spreadsheet/PDF libraries.
 * Dataset shape: [title, columns[], rows[][]].
 */
class ReportExportService
{
    public function toCsv(array $dataset): string
    {
        [$title, $columns, $rows] = $dataset;
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, [$title]);
        fputcsv($fh, $columns);
        foreach ($rows as $row) fputcsv($fh, array_map(fn ($v) => $v ?? '', $row));
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    /** Minimal XLSX: one sheet, inline strings/numbers. */
    public function toXlsx(array $dataset): string
    {
        [$title, $columns, $rows] = $dataset;
        $esc = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $cell = function ($value, $col, $rowNum) use ($esc) {
            $ref = $this->colName($col) . $rowNum;
            if (is_numeric($value) && $value !== '') {
                return "<c r=\"{$ref}\"><v>{$value}</v></c>";
            }
            return "<c r=\"{$ref}\" t=\"inlineStr\"><is><t>" . $esc($value) . '</t></is></c>';
        };
        $sheetRows = '';
        $allRows = array_merge([$columns], $rows);
        foreach ($allRows as $i => $row) {
            $cells = '';
            foreach (array_values($row) as $j => $value) $cells .= $cell($value, $j, $i + 1);
            $sheetRows .= "<row r=\"" . ($i + 1) . "\">{$cells}</row>";
        }
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $esc(mb_substr($title, 0, 28)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sheetRows . '</sheetData></worksheet>',
        ];
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) $zip->addFromString($name, $content);
        $zip->close();
        $bytes = file_get_contents($tmp);
        unlink($tmp);
        return $bytes;
    }

    /** Minimal multi-page text PDF (Helvetica) — a genuine, openable PDF. */
    public function toPdf(array $dataset): string
    {
        [$title, $columns, $rows] = $dataset;
        $lines = [$title, str_repeat('-', min(100, strlen($title))), implode(' | ', $columns), str_repeat('-', 100)];
        foreach ($rows as $row) {
            $lines[] = implode(' | ', array_map(fn ($v) => $v === null ? '' : (string) $v, $row));
        }
        $pages = array_chunk($lines, 48);
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $pageIds = [];
        $nextId = 3;
        $pageObjects = [];
        foreach ($pages as $pageLines) {
            $contentId = $nextId + 1;
            $pageIds[] = $nextId;
            $stream = "BT /F1 9 Tf 40 800 Td 12 TL";
            foreach ($pageLines as $line) {
                $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], mb_substr($line, 0, 150));
                $stream .= " ({$safe}) Tj T*";
            }
            $stream .= ' ET';
            $pageObjects[$nextId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> /Contents {$contentId} 0 R >>";
            $pageObjects[$contentId] = '<< /Length ' . strlen($stream) . " >>\nstream\n{$stream}\nendstream";
            $nextId += 2;
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', array_map(fn ($id) => "{$id} 0 R", $pageIds)) . '] /Count ' . count($pageIds) . ' >>';
        $objects = $objects + $pageObjects;
        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $maxId = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maxId + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $maxId; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";
        return $pdf;
    }

    private function colName(int $index): string
    {
        $name = '';
        $n = $index + 1;
        while ($n > 0) { $m = ($n - 1) % 26; $name = chr(65 + $m) . $name; $n = intdiv($n - 1, 26); }
        return $name;
    }
}
