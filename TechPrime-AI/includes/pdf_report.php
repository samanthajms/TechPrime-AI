<?php
/**
 * Minimal PDF report writer for staff exports (no external library).
 * Uses the PDF core fonts Helvetica / Helvetica-Bold (WinAnsi encoding), so nothing is embedded.
 * Supports a branded title block, summary boxes, section headings, word-wrapping tables that
 * repeat their header row across pages, simple vertical bar charts and "Page X of Y" footers.
 *
 * Usage:
 *   $pdf = new IasPdfReport('Sales Report', ['This Month · Oct 01, 2026 - Oct 13, 2026'], 'L');
 *   $pdf->summary([['Total Sales', 'PHP 1,234.00'], ...]);
 *   $pdf->heading('Transactions');
 *   $pdf->table(['Order #', 'Total'], $rows, ['widths' => [1, 1], 'align' => ['L', 'R']]);
 *   $pdf->output('sales_report.pdf');   // streams the file and exits
 */
class IasPdfReport
{
    private const MARGIN = 36.0;           // 0.5 in
    private const FOOTER_H = 24.0;
    private const GREEN = [0.380, 0.702, 0.216];        // #61b337
    private const GREEN_DARK = [0.239, 0.455, 0.133];   // #3d7422
    private const GREEN_LIGHT = [0.957, 0.976, 0.941];  // #f4f9f0
    private const TEXT = [0.09, 0.09, 0.09];
    private const MUTED = [0.42, 0.45, 0.50];
    private const BORDER = [0.85, 0.88, 0.85];

    private float $w;
    private float $h;
    private array $pages = [];
    private string $buf = '';
    private float $y = 0.0;
    private string $title;
    private array $subtitle;

    /** Helvetica / Helvetica-Bold advance widths (1/1000 em) for ASCII 32..126. */
    private const W_REG = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
    private const W_BOLD = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584];

    /** Widths of the cp1252 punctuation that report text uses (same in both weights): … – — · */
    private const W_HIGH = [0x85 => 1000, 0x96 => 556, 0x97 => 1000, 0xB7 => 278];

    /** @param string $orientation 'P' (portrait) or 'L' (landscape), A4 */
    public function __construct(string $title, array $subtitleLines = [], string $orientation = 'P')
    {
        [$this->w, $this->h] = $orientation === 'L' ? [841.89, 595.28] : [595.28, 841.89];
        $this->title = $title;
        $this->subtitle = $subtitleLines;
        $this->newPage();
        $this->titleBlock();
    }

    /* ---------------------------------------------------------------- public API */

    /** Row of boxed key figures: [[label, value], ...] */
    public function summary(array $items): void
    {
        if (!$items) {
            return;
        }
        $gap = 10.0;
        $boxH = 46.0;
        $this->ensureSpace($boxH + 14);
        $n = count($items);
        $boxW = ($this->contentWidth() - $gap * ($n - 1)) / $n;
        $x = self::MARGIN;
        foreach ($items as [$label, $value]) {
            $this->rect($x, $this->y, $boxW, $boxH, self::GREEN_LIGHT, self::BORDER);
            $this->rect($x, $this->y, 3, $boxH, self::GREEN);
            $this->text($x + 10, $this->y + 15, $this->fit((string)$label, $boxW - 16, 8, false), 8, false, self::MUTED);
            $this->text($x + 10, $this->y + 34, $this->fit((string)$value, $boxW - 16, 13, true), 13, true, self::TEXT);
            $x += $boxW + $gap;
        }
        $this->y += $boxH + 14;
    }

    /** $keep = space to reserve below the heading, so it is never stranded without its content. */
    public function heading(string $text, string $note = '', float $keep = 60.0): void
    {
        $this->ensureSpace(($note !== '' ? 32 : 20) + $keep);
        $this->text(self::MARGIN, $this->y + 12, $text, 12, true, self::GREEN_DARK);
        if ($note !== '') {
            $this->text(self::MARGIN, $this->y + 25, $this->fit($note, $this->contentWidth(), 8, false), 8, false, self::MUTED);
            $this->y += 32;
        } else {
            $this->y += 20;
        }
    }

    public function paragraph(string $text, float $size = 9.0): void
    {
        foreach ($this->wrap($text, $this->contentWidth(), $size, false) as $line) {
            $this->ensureSpace($size + 4);
            $this->text(self::MARGIN, $this->y + $size, $line, $size, false, self::MUTED);
            $this->y += $size + 4;
        }
        $this->y += 6;
    }

    /**
     * Table with word-wrapped cells; the header row repeats on every page.
     * Options: widths (relative weights), align ('L'|'R'|'C' per column), font_size, max_lines, total_row (bold last row).
     */
    public function table(array $headers, array $rows, array $opt = []): void
    {
        $n = count($headers);
        $size = (float)($opt['font_size'] ?? 8.5);
        $maxLines = (int)($opt['max_lines'] ?? 4);
        $align = $opt['align'] ?? array_fill(0, $n, 'L');
        $weights = $opt['widths'] ?? array_fill(0, $n, 1);
        $sum = array_sum($weights) ?: 1;
        $widths = array_map(fn($wt) => $this->contentWidth() * $wt / $sum, $weights);
        $pad = 4.0;
        $lineH = $size + 3;
        $totalRow = !empty($opt['total_row']);

        $drawHeader = function () use ($headers, $widths, $align, $size, $pad, $lineH): void {
            $lines = [];
            $maxN = 1;
            foreach ($headers as $i => $hd) {
                $lines[$i] = $this->wrap((string)$hd, $widths[$i] - 2 * $pad, $size, true);
                $maxN = max($maxN, count($lines[$i]));
            }
            $rowH = $maxN * $lineH + 2 * $pad;
            $this->rect(self::MARGIN, $this->y, $this->contentWidth(), $rowH, self::GREEN_DARK);
            $x = self::MARGIN;
            foreach ($headers as $i => $hd) {
                foreach ($lines[$i] as $k => $ln) {
                    $this->cellText($x, $widths[$i], $pad, $this->y + $pad + $size + $k * $lineH, $ln, $size, true, $align[$i] ?? 'L', [1, 1, 1]);
                }
                $x += $widths[$i];
            }
            $this->y += $rowH;
        };

        $this->ensureSpace($lineH * 3 + 4 * $pad);
        $drawHeader();
        if (!$rows) {
            $this->rect(self::MARGIN, $this->y, $this->contentWidth(), $lineH + 2 * $pad, null, self::BORDER);
            $this->text(self::MARGIN + $pad, $this->y + $pad + $size, 'No records for this selection.', $size, false, self::MUTED);
            $this->y += $lineH + 2 * $pad + 12;
            return;
        }
        $last = count($rows) - 1;
        $prepared = [];
        foreach (array_values($rows) as $r => $row) {
            $bold = $totalRow && $r === $last;
            $lines = [];
            $maxN = 1;
            foreach ($widths as $i => $cw) {
                $cell = $this->wrap((string)($row[$i] ?? ''), $cw - 2 * $pad, $size, $bold);
                if (count($cell) > $maxLines) {
                    $cell = array_slice($cell, 0, $maxLines);
                    $cell[$maxLines - 1] = $this->fit($cell[$maxLines - 1] . '...', $cw - 2 * $pad, $size, $bold);
                }
                $lines[$i] = $cell;
                $maxN = max($maxN, count($cell));
            }
            $prepared[$r] = [$lines, $maxN * $lineH + 2 * $pad, $bold];
        }
        foreach ($prepared as $r => [$lines, $rowH, $bold]) {
            // a total row never starts a page on its own: carry the row before it along
            $need = $rowH + ($totalRow && $r === $last - 1 ? $prepared[$last][1] : 0);
            if ($this->y + $need > $this->bottom()) {
                $this->newPage();
                $drawHeader();
            }
            $fill = $bold ? self::GREEN_LIGHT : ($r % 2 === 1 ? [0.98, 0.985, 0.98] : null);
            $this->rect(self::MARGIN, $this->y, $this->contentWidth(), $rowH, $fill);
            $this->line(self::MARGIN, $this->y + $rowH, self::MARGIN + $this->contentWidth(), $this->y + $rowH, self::BORDER);
            $x = self::MARGIN;
            foreach ($widths as $i => $cw) {
                foreach ($lines[$i] as $k => $ln) {
                    $this->cellText($x, $cw, $pad, $this->y + $pad + $size + $k * $lineH, $ln, $size, $bold, $align[$i] ?? 'L', self::TEXT);
                }
                $x += $cw;
            }
            $this->y += $rowH;
        }
        $this->y += 14;
    }

    /**
     * Vertical bar chart. $values are numbers; $fmt formats axis/value labels (defaults to number_format).
     */
    public function barChart(array $labels, array $values, float $height = 170.0, ?callable $fmt = null): void
    {
        $fmt = $fmt ?? fn($v) => number_format((float)$v);
        $this->ensureSpace($height + 10);
        $values = array_map('floatval', array_values($values));
        $labels = array_values($labels);
        $n = count($values);
        $max = $n ? max($values) : 0.0;
        $axisW = 62.0;
        $x0 = self::MARGIN + $axisW;
        $plotW = $this->contentWidth() - $axisW;
        $top = $this->y + 6;
        $plotH = $height - 34;
        $base = $top + $plotH;
        if ($n === 0 || $max <= 0) {
            $this->rect(self::MARGIN, $this->y, $this->contentWidth(), 30, null, self::BORDER);
            $this->text(self::MARGIN + 6, $this->y + 19, 'No data for this period.', 8.5, false, self::MUTED);
            $this->y += 44;
            return;
        }
        $niceMax = $this->niceCeil($max);
        $allInts = !array_filter($values, fn($v) => floor($v) != $v);
        if ($allInts && $niceMax < 4) {
            $niceMax = 4.0;   // whole-number series (units): keep tick labels whole
        }
        for ($t = 0; $t <= 4; $t++) {
            $gy = $base - $plotH * $t / 4;
            $this->line($x0, $gy, $x0 + $plotW, $gy, $t === 0 ? self::MUTED : [0.92, 0.93, 0.92]);
            $label = $fmt($niceMax * $t / 4);
            $this->text($x0 - 6 - $this->strWidth($label, 7, false), $gy + 2.5, $label, 7, false, self::MUTED);
        }
        $slot = $plotW / $n;
        $barW = min(36.0, $slot * 0.7);
        $every = (int)max(1, ceil($n / max(1, floor($plotW / 42))));   // thin out crowded x labels
        foreach ($values as $i => $v) {
            $bh = $plotH * $v / $niceMax;
            $bx = $x0 + $slot * $i + ($slot - $barW) / 2;
            if ($bh > 0) {
                $this->rect($bx, $base - $bh, $barW, $bh, self::GREEN);
            }
            if ($v > 0 && $n <= 24) {
                $vl = $fmt($v);
                $vs = $barW >= 24 ? 6.5 : 5.5;
                $this->text($bx + ($barW - $this->strWidth($vl, $vs, false)) / 2, $base - $bh - 3, $vl, $vs, false, self::TEXT);
            }
            if ($i % $every === 0) {
                $lb = $this->fit((string)($labels[$i] ?? ''), $slot * $every - 2, 7, false);
                $this->text($x0 + $slot * $i + ($slot - $this->strWidth($lb, 7, false)) / 2, $base + 11, $lb, 7, false, self::MUTED);
            }
        }
        $this->y += $height + 4;
    }

    /** Stream the PDF as a download and exit. */
    public function output(string $filename): void
    {
        $doc = $this->build();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($doc));
        header('Cache-Control: private, max-age=0, must-revalidate');
        echo $doc;
        exit;
    }

    /** The finished PDF document as a string. */
    public function build(): string
    {
        $this->flushPage();
        $count = count($this->pages);
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $next = 5;
        foreach ($this->pages as $i => $content) {
            $content .= $this->footer($i + 1, $count);
            $pageId = $next++;
            $contentId = $next++;
            $kids[] = "$pageId 0 R";
            $objs[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', $this->w, $this->h, $contentId);
            $z = function_exists('gzcompress') ? gzcompress($content) : false;
            $objs[$contentId] = $z !== false
                ? '<< /Length ' . strlen($z) . " /Filter /FlateDecode >>\nstream\n" . $z . "\nendstream"
                : '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $count . ' >>';
        $objs[$next] = '<< /Title ' . $this->pdfString($this->title) . ' /Producer (TechPrime AI) /CreationDate (D:' . self::now()->format('YmdHis') . ') >>';
        $infoId = $next;
        ksort($objs);

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($out);
        $size = max(array_keys($objs)) + 1;
        $out .= "xref\n0 $size\n0000000000 65535 f \n";
        for ($i = 1; $i < $size; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $out .= "trailer\n<< /Size $size /Root 1 0 R /Info $infoId 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $out;
    }

    /* ---------------------------------------------------------------- layout */

    private function titleBlock(): void
    {
        $m = self::MARGIN;
        $this->rect($m, $this->y, $this->contentWidth(), 4, self::GREEN);
        $this->y += 22;
        $this->text($m, $this->y, 'EasyPC', 16, true, self::GREEN_DARK);
        $brand = 'One Oasis Branch';
        $this->text($m + $this->strWidth('EasyPC', 16, true) + 6, $this->y, $brand, 9, false, self::MUTED);
        $title = $this->title;
        $this->text($m + $this->contentWidth() - $this->strWidth($title, 16, true), $this->y, $title, 16, true, self::TEXT);
        $this->y += 8;
        foreach ($this->subtitle as $line) {
            $this->y += 12;
            $line = $this->fit((string)$line, $this->contentWidth(), 9, false);
            $this->text($m + $this->contentWidth() - $this->strWidth($line, 9, false), $this->y, $line, 9, false, self::MUTED);
        }
        $this->y += 10;
        $this->line($m, $this->y, $m + $this->contentWidth(), $this->y, self::BORDER);
        $this->y += 14;
    }

    private function footer(int $page, int $count): string
    {
        $saved = $this->buf;
        $this->buf = '';
        $fy = $this->h - self::MARGIN + 12;
        $this->line(self::MARGIN, $fy - 12, $this->w - self::MARGIN, $fy - 12, self::BORDER);
        $left = 'TechPrime AI · ' . $this->title . ' · Generated ' . self::now()->format('M d, Y g:i A');
        $this->text(self::MARGIN, $fy, $left, 7.5, false, self::MUTED);
        $right = "Page $page of $count";
        $this->text($this->w - self::MARGIN - $this->strWidth($right, 7.5, false), $fy, $right, 7.5, false, self::MUTED);
        $out = $this->buf;
        $this->buf = $saved;
        return $out;
    }

    /** Reports are read in the branch's local time. */
    private static function now(): DateTime
    {
        return new DateTime('now', new DateTimeZone('Asia/Manila'));
    }

    private function contentWidth(): float
    {
        return $this->w - 2 * self::MARGIN;
    }

    private function bottom(): float
    {
        return $this->h - self::MARGIN - self::FOOTER_H;
    }

    private function ensureSpace(float $needed): void
    {
        if ($this->y + $needed > $this->bottom()) {
            $this->newPage();
        }
    }

    private function newPage(): void
    {
        $this->flushPage();
        $this->buf = '';
        $this->y = self::MARGIN;
    }

    private function flushPage(): void
    {
        if ($this->buf !== '' || $this->y > 0) {
            $this->pages[] = $this->buf;
        }
        $this->buf = '';
        $this->y = 0.0;
    }

    /* ---------------------------------------------------------------- drawing primitives (top-left coordinates) */

    private function text(float $x, float $baseline, string $s, float $size, bool $bold, array $rgb): void
    {
        if ($s === '') {
            return;
        }
        $this->buf .= sprintf("BT %.3F %.3F %.3F rg /%s %.2F Tf %.2F %.2F Td %s Tj ET\n",
            $rgb[0], $rgb[1], $rgb[2], $bold ? 'F2' : 'F1', $size, $x, $this->h - $baseline, $this->pdfString($s));
    }

    private function cellText(float $x, float $w, float $pad, float $baseline, string $s, float $size, bool $bold, string $align, array $rgb): void
    {
        $tw = $this->strWidth($s, $size, $bold);
        $tx = $align === 'R' ? $x + $w - $pad - $tw : ($align === 'C' ? $x + ($w - $tw) / 2 : $x + $pad);
        $this->text($tx, $baseline, $s, $size, $bold, $rgb);
    }

    private function rect(float $x, float $y, float $w, float $h, ?array $fill, ?array $stroke = null): void
    {
        if ($fill === null && $stroke === null) {
            return;
        }
        $ops = '';
        if ($fill) {
            $ops .= sprintf('%.3F %.3F %.3F rg ', $fill[0], $fill[1], $fill[2]);
        }
        if ($stroke) {
            $ops .= sprintf('%.3F %.3F %.3F RG 0.6 w ', $stroke[0], $stroke[1], $stroke[2]);
        }
        $op = $fill && $stroke ? 'B' : ($fill ? 'f' : 'S');
        $this->buf .= $ops . sprintf("%.2F %.2F %.2F %.2F re %s\n", $x, $this->h - $y - $h, $w, $h, $op);
    }

    private function line(float $x1, float $y1, float $x2, float $y2, array $rgb): void
    {
        $this->buf .= sprintf("%.3F %.3F %.3F RG 0.6 w %.2F %.2F m %.2F %.2F l S\n",
            $rgb[0], $rgb[1], $rgb[2], $x1, $this->h - $y1, $x2, $this->h - $y2);
    }

    /* ---------------------------------------------------------------- text helpers */

    /** UTF-8 -> Windows-1252 (the core fonts' encoding); the peso sign has no glyph there, so it becomes "PHP ". */
    private function enc(string $s): string
    {
        $s = str_replace(['₱', "\r", "\n", "\t"], ['PHP ', ' ', ' ', ' '], $s);
        $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
        if ($out === false) {
            $out = preg_replace('/[^\x20-\x7E]/', '?', $s);
        }
        return $out;
    }

    private function pdfString(string $s): string
    {
        return '(' . strtr($this->enc($s), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ')';
    }

    private function strWidth(string $s, float $size, bool $bold): float
    {
        $table = $bold ? self::W_BOLD : self::W_REG;
        $w = 0;
        $bytes = $this->enc($s);
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($bytes[$i]);
            $w += ($c >= 32 && $c <= 126) ? $table[$c - 32] : (self::W_HIGH[$c] ?? 556);
        }
        return $w * $size / 1000;
    }

    /** Truncate with "..." so the text fits $maxW. */
    private function fit(string $s, float $maxW, float $size, bool $bold): string
    {
        if ($this->strWidth($s, $size, $bold) <= $maxW) {
            return $s;
        }
        $base = preg_replace('/\.\.\.$/', '', $s);
        while ($base !== '' && $this->strWidth($base . '...', $size, $bold) > $maxW) {
            $base = mb_substr($base, 0, -1, 'UTF-8');
        }
        return rtrim($base) . '...';
    }

    /** Greedy word wrap; "\n" starts a new line, words longer than a line are hard-broken. */
    private function wrap(string $s, float $maxW, float $size, bool $bold): array
    {
        if (strpos($s, "\n") !== false) {
            $out = [];
            foreach (explode("\n", $s) as $part) {
                if (trim($part) !== '') {
                    $out = array_merge($out, $this->wrap($part, $maxW, $size, $bold));
                }
            }
            return $out ?: [''];
        }
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        if ($s === '') {
            return [''];
        }
        $lines = [];
        $cur = '';
        foreach (explode(' ', $s) as $word) {
            $try = $cur === '' ? $word : $cur . ' ' . $word;
            if ($this->strWidth($try, $size, $bold) <= $maxW) {
                $cur = $try;
                continue;
            }
            if ($cur !== '') {
                $lines[] = $cur;
            }
            while ($this->strWidth($word, $size, $bold) > $maxW && mb_strlen($word, 'UTF-8') > 1) {
                $cut = mb_strlen($word, 'UTF-8');
                while ($cut > 1 && $this->strWidth(mb_substr($word, 0, $cut, 'UTF-8'), $size, $bold) > $maxW) {
                    $cut--;
                }
                $lines[] = mb_substr($word, 0, $cut, 'UTF-8');
                $word = mb_substr($word, $cut, null, 'UTF-8');
            }
            $cur = $word;
        }
        $lines[] = $cur;
        return $lines;
    }

    private function niceCeil(float $v): float
    {
        $exp = 10 ** floor(log10($v));
        foreach ([1, 2, 4, 6, 8, 10] as $m) {
            if ($m * $exp >= $v) {
                return $m * $exp;
            }
        }
        return 10 * $exp;
    }
}
