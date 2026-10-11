<?php
declare(strict_types=1);

namespace App\Export;

/** Reads the first sheet of an .xlsx (no library) or a CSV file into rows of strings. */
final class SheetReader
{
    /** @return list<list<string>> */
    public static function read(string $path, string $originalName): array
    {
        $isXlsx = str_ends_with(strtolower($originalName), '.xlsx') || self::looksLikeZip($path);
        return $isXlsx ? self::xlsx($path) : self::csv($path);
    }

    private static function looksLikeZip(string $path): bool
    {
        $fh = fopen($path, 'rb');
        $sig = $fh ? (string) fread($fh, 4) : '';
        if ($fh) {
            fclose($fh);
        }
        return $sig === "PK\x03\x04";
    }

    private static function csv(string $path): array
    {
        $fh = fopen($path, 'r');
        $first = (string) fgets($fh);
        $first = (string) preg_replace('/^\xEF\xBB\xBF/', '', $first);
        $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
        $rows = [array_map('strval', str_getcsv($first, $delim, '"', '\\'))];
        while (($cols = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
            $rows[] = array_map(static fn ($c) => (string) $c, $cols);
        }
        fclose($fh);
        return $rows;
    }

    private static function xlsx(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Could not open Excel file');
        }
        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false) {
            $x = simplexml_load_string($ss);
            foreach ($x->si as $si) {
                if (isset($si->t)) {
                    $shared[] = (string) $si->t;
                } else {
                    $t = '';
                    foreach ($si->r as $r) {
                        $t .= (string) $r->t;
                    }
                    $shared[] = $t;
                }
            }
        }
        // first sheet in workbook order
        $sheetPath = 'xl/worksheets/sheet1.xml';
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb !== false && $rels !== false) {
            $w = simplexml_load_string($wb);
            $rid = (string) ($w->sheets->sheet[0]->attributes('r', true)['id'] ?? '');
            $rx = simplexml_load_string($rels);
            foreach ($rx->Relationship as $rel) {
                if ((string) $rel['Id'] === $rid) {
                    $target = ltrim((string) $rel['Target'], '/');
                    $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                }
            }
        }
        $xml = $zip->getFromName($sheetPath);
        $zip->close();
        if ($xml === false) {
            throw new \RuntimeException('Sheet not found in Excel file');
        }
        $sx = simplexml_load_string($xml);
        $rows = [];
        foreach ($sx->sheetData->row as $row) {
            $line = [];
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $col = self::colIndex(preg_replace('/\d+/', '', $ref) ?: 'A');
                $type = (string) $c['t'];
                if ($type === 's') {
                    $v = $shared[(int) $c->v] ?? '';
                } elseif ($type === 'inlineStr') {
                    $v = (string) $c->is->t;
                } else {
                    $v = (string) $c->v;
                    // 9.87654321E9 style numbers (mobile numbers stored as numbers)
                    if (preg_match('/^\d+(\.\d+)?E\+?\d+$/i', $v)) {
                        $v = number_format((float) $v, 0, '', '');
                    } elseif (preg_match('/^\d+\.0+$/', $v)) {
                        $v = (string) (int) $v;
                    }
                }
                $line[$col] = trim($v);
            }
            if ($line) {
                $max = max(array_keys($line));
                $full = [];
                for ($i = 0; $i <= $max; $i++) {
                    $full[] = $line[$i] ?? '';
                }
                $rows[] = $full;
            }
        }
        return $rows;
    }

    private static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }
}
