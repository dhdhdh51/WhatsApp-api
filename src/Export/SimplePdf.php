<?php
declare(strict_types=1);

namespace App\Export;

/**
 * Tiny dependency-free PDF writer for tabular reports (A4 landscape, Helvetica).
 */
final class SimplePdf
{
    private const W = 842.0;
    private const H = 595.0;
    private const M = 28.0;

    /** @var list<string> page content streams */
    private array $pages = [];
    private string $cur = '';
    private float $y = 0;

    public function table(string $title, array $head, array $rows): void
    {
        $fs = count($head) > 10 ? 6.5 : 8.0;
        $rowH = $fs + 6;
        $usable = self::W - 2 * self::M;

        // column widths proportional to the longest content (capped)
        $len = array_map(static fn ($h) => max(4, mb_strlen((string) $h)), $head);
        foreach (array_slice($rows, 0, 300) as $r) {
            foreach (array_values($r) as $i => $v) {
                $len[$i] = max($len[$i] ?? 4, min(28, mb_strlen((string) $v)));
            }
        }
        $sum = array_sum($len);
        $widths = array_map(static fn ($l) => $usable * $l / $sum, $len);

        $newPage = function () use ($title, $head, $widths, $fs, $rowH): void {
            if ($this->cur !== '') {
                $this->pages[] = $this->cur;
            }
            $this->cur = '';
            $this->y = self::H - self::M;
            $this->text(self::M, $this->y - 12, 13, self::latin($title), true);
            $this->text(self::W - self::M - 150, $this->y - 12, 8, 'Generated ' . date('d-m-Y H:i'), false);
            $this->y -= 26;
            $this->rowOut($head, $widths, $fs, $rowH, true);
        };
        $newPage();

        foreach ($rows as $i => $r) {
            if ($this->y - $rowH < self::M + 14) {
                $newPage();
            }
            if ($i % 2 === 1) {
                $this->cur .= sprintf("0.95 g %.2F %.2F %.2F %.2F re f 0 g\n", self::M, $this->y - $rowH, $usable, $rowH);
            }
            $this->rowOut(array_values($r), $widths, $fs, $rowH, false);
        }
        $this->pages[] = $this->cur;
        $this->cur = '';
    }

    private function rowOut(array $cells, array $widths, float $fs, float $rowH, bool $bold): void
    {
        $x = self::M;
        if ($bold) {
            $this->cur .= sprintf("0.85 g %.2F %.2F %.2F %.2F re f 0 g\n", self::M, $this->y - $rowH, array_sum($widths), $rowH);
        }
        foreach ($widths as $i => $w) {
            $txt = self::fit(self::latin((string) ($cells[$i] ?? '')), $w - 4, $fs);
            $this->text($x + 2, $this->y - $rowH + 4, $fs, $txt, $bold);
            $x += $w;
        }
        $this->cur .= sprintf("0.7 G 0.3 w %.2F %.2F m %.2F %.2F l S 0 G\n", self::M, $this->y - $rowH, self::M + array_sum($widths), $this->y - $rowH);
        $this->y -= $rowH;
    }

    private function text(float $x, float $y, float $size, string $s, bool $bold): void
    {
        $s = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $s);
        $this->cur .= sprintf("BT /%s %.1F Tf %.2F %.2F Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $x, $y, $s);
    }

    private static function fit(string $s, float $width, float $fs): string
    {
        $max = (int) floor($width / ($fs * 0.52));
        return strlen($s) > $max ? substr($s, 0, max(1, $max - 1)) . '.' : $s;
    }

    private static function latin(string $s): string
    {
        $t = function_exists('transliterator_transliterate')
            ? (string) transliterator_transliterate('Any-Latin; Latin-ASCII', $s)
            : $s;
        $r = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $t);
        return $r === false ? (string) preg_replace('/[^\x20-\x7E]/', '?', $t) : $r;
    }

    public function output(): string
    {
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $n = 5;
        $total = count($this->pages);
        foreach ($this->pages as $pi => $content) {
            $content .= sprintf("BT /F1 7 Tf %.2F %.2F Td (Page %d / %d) Tj ET\n", self::W / 2 - 20, 14.0, $pi + 1, $total);
            $pageId = $n++;
            $contId = $n++;
            $objs[$contId] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";
            $objs[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.0F %.0F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                self::W, self::H, $contId);
            $kids[] = "{$pageId} 0 R";
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($out);
        $max = max(array_keys($objs));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $out .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n";
        }
        $out .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        return $out;
    }
}
