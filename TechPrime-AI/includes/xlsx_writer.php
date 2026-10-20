<?php
/**
 * Minimal .xlsx (Office Open XML) writer for staff exports — no external library and no ZipArchive
 * (the Render image has no zip extension), so the zip container is written by hand with zlib deflate.
 *
 * Cells are plain values (string / int / float / null) or arrays:
 *   ['v' => value, 's' => style]   value with a style (see STYLE_* constants)
 *   ['f' => 'SUM(H2:H9)', 's' => style]   formula
 * Usage:
 *   $x = new IasXlsxWriter();
 *   $x->addSheet('Deliveries', $rows, ['widths' => [12, 30], 'freeze' => 2, 'filter' => 'A2:J10']);
 *   $x->output('report.xlsx');   // streams the file and exits
 */
class IasXlsxWriter
{
    public const STYLE_DEFAULT = 0;
    public const STYLE_HEADER = 1;      // white bold on brand green, bordered
    public const STYLE_TITLE = 2;       // bold 14pt, brand dark green
    public const STYLE_MONEY = 3;       // ₱#,##0.00
    public const STYLE_DATETIME = 4;    // mmm d, yyyy h:mm AM/PM
    public const STYLE_PERCENT = 5;     // 0.0%
    public const STYLE_TOTAL_MONEY = 6; // bold ₱, top border
    public const STYLE_BOLD = 7;
    public const STYLE_NOTE = 8;        // grey italic
    public const STYLE_INT = 9;         // #,##0
    public const STYLE_TOTAL_INT = 10;  // bold #,##0, top border
    public const STYLE_TOTAL_LABEL = 11; // bold text, top border
    public const STYLE_WRAP = 12;       // wrapped text, top aligned

    private array $sheets = [];

    /**
     * @param array $rows list of rows (each a list of cells)
     * @param array $opt  widths (list of column widths in characters), freeze (number of top rows to freeze),
     *                    filter (autofilter range, e.g. "A2:J10"), merges (list of ranges, e.g. ["A1:J1"])
     */
    public function addSheet(string $name, array $rows, array $opt = []): void
    {
        $name = mb_substr(preg_replace('#[\[\]\*\?/\\\\:]#', ' ', $name), 0, 31);
        $this->sheets[] = ['name' => $name, 'rows' => $rows, 'opt' => $opt];
    }

    /** Excel serial date for a branch-local 'Y-m-d H:i:s' string ('' stays empty). */
    public static function excelDate(string $local): ?float
    {
        if ($local === '') {
            return null;
        }
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $local, new DateTimeZone('UTC'));
        return $dt ? $dt->getTimestamp() / 86400 + 25569 : null;
    }

    /** "A".."Z","AA".. for a 0-based column index. */
    public static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    public function output(string $filename): void
    {
        $data = $this->build();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($data));
        header('Cache-Control: private, max-age=0, must-revalidate');
        echo $data;
        exit;
    }

    /** The finished workbook as a binary string. */
    public function build(): string
    {
        $files = [];
        $n = count($this->sheets);
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
        for ($i = 1; $i <= $n; $i++) {
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $files['[Content_Types].xml'] = $ct . '</Types>';
        $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '</Relationships>';
        $files['docProps/core.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>TechPrime AI</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created>'
            . '</cp:coreProperties>';

        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $defined = '';
        foreach ($this->sheets as $i => $sh) {
            $id = $i + 1;
            $wb .= '<sheet name="' . $this->esc($sh['name']) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
            $rels .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $id . '.xml"/>';
            if (!empty($sh['opt']['filter'])) {   // Excel expects the hidden _FilterDatabase name for an autofilter
                [$a, $b] = explode(':', $sh['opt']['filter']);
                $abs = fn($ref) => preg_replace_callback('/^([A-Z]+)(\d+)$/', fn($m) => '$' . $m[1] . '$' . $m[2], $ref);
                $defined .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">\''
                    . $this->esc(str_replace("'", "''", $sh['name'])) . '\'!' . $abs($a) . ':' . $abs($b) . '</definedName>';
            }
            $files['xl/worksheets/sheet' . $id . '.xml'] = $this->sheetXml($sh, $i === 0);
        }
        $wb .= '</sheets>' . ($defined !== '' ? '<definedNames>' . $defined . '</definedNames>' : '') . '</workbook>';
        $files['xl/workbook.xml'] = $wb;
        $id = $n + 1;
        $files['xl/_rels/workbook.xml.rels'] = $rels
            . '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
        $files['xl/styles.xml'] = $this->stylesXml();

        return $this->zip($files);
    }

    /* ---------------------------------------------------------------- parts */

    private function sheetXml(array $sh, bool $selected): string
    {
        $opt = $sh['opt'];
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $x .= '<sheetViews><sheetView workbookViewId="0"' . ($selected ? ' tabSelected="1"' : '') . '>';
        $freeze = (int)($opt['freeze'] ?? 0);
        if ($freeze > 0) {
            $x .= '<pane ySplit="' . $freeze . '" topLeftCell="A' . ($freeze + 1) . '" activePane="bottomLeft" state="frozen"/>'
                . '<selection pane="bottomLeft" activeCell="A' . ($freeze + 1) . '" sqref="A' . ($freeze + 1) . '"/>';
        }
        $x .= '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/>';
        if (!empty($opt['widths'])) {
            $x .= '<cols>';
            foreach (array_values($opt['widths']) as $i => $w) {
                $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float)$w . '" customWidth="1"/>';
            }
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        foreach (array_values($sh['rows']) as $r => $row) {
            $rn = $r + 1;
            $x .= '<row r="' . $rn . '">';
            foreach (array_values((array)$row) as $c => $cell) {
                $x .= $this->cellXml(self::col($c) . $rn, $cell);
            }
            $x .= '</row>';
        }
        $x .= '</sheetData>';
        if (!empty($opt['filter'])) {
            $x .= '<autoFilter ref="' . $this->esc($opt['filter']) . '"/>';
        }
        if (!empty($opt['merges'])) {
            $x .= '<mergeCells count="' . count($opt['merges']) . '">';
            foreach ($opt['merges'] as $m) {
                $x .= '<mergeCell ref="' . $this->esc($m) . '"/>';
            }
            $x .= '</mergeCells>';
        }
        $x .= '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
            . '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>';
        return $x . '</worksheet>';
    }

    private function cellXml(string $ref, $cell): string
    {
        $style = 0;
        if (is_array($cell)) {
            $style = (int)($cell['s'] ?? 0);
            if (isset($cell['f'])) {
                return '<c r="' . $ref . '"' . ($style ? ' s="' . $style . '"' : '') . '><f>' . $this->esc((string)$cell['f']) . '</f></c>';
            }
            $cell = $cell['v'] ?? null;
        }
        $s = $style ? ' s="' . $style . '"' : '';
        if ($cell === null || $cell === '') {
            return $style ? '<c r="' . $ref . '"' . $s . '/>' : '';
        }
        if (is_int($cell) || is_float($cell)) {
            return '<c r="' . $ref . '"' . $s . '><v>' . (is_float($cell) ? rtrim(rtrim(sprintf('%.10F', $cell), '0'), '.') : $cell) . '</v></c>';
        }
        $str = $this->esc(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$cell));
        return '<c r="' . $ref . '" t="inlineStr"' . $s . '><is><t xml:space="preserve">' . $str . '</t></is></c>';
    }

    private function stylesXml(): string
    {
        // numFmt ids >= 164 are custom
        $numFmts = '<numFmts count="3">'
            . '<numFmt numFmtId="164" formatCode="&quot;₱&quot;#,##0.00"/>'
            . '<numFmt numFmtId="165" formatCode="mmm d, yyyy h:mm AM/PM"/>'
            . '<numFmt numFmtId="166" formatCode="0.0%"/>'
            . '</numFmts>';
        $fonts = '<fonts count="5">'
            . '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="14"/><color rgb="FF3D7422"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><i/><sz val="10"/><color rgb="FF6B7280"/><name val="Calibri"/><family val="2"/></font>'
            . '</fonts>';
        $fills = '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF3D7422"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>';
        $borders = '<borders count="3">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFD9E0D9"/></left><right style="thin"><color rgb="FFD9E0D9"/></right><top style="thin"><color rgb="FFD9E0D9"/></top><bottom style="thin"><color rgb="FFD9E0D9"/></bottom><diagonal/></border>'
            . '<border><left/><right/><top style="medium"><color rgb="FF3D7422"/></top><bottom/><diagonal/></border>'
            . '</borders>';
        // order must match the STYLE_* constants
        $xfs = [
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>',
            '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>',
            '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>',
            '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>',
            '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="left"/></xf>',
            '<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>',
            '<xf numFmtId="164" fontId="3" fillId="0" borderId="2" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1"/>',
            '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>',
            '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/>',
            '<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>',
            '<xf numFmtId="3" fontId="3" fillId="0" borderId="2" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1"/>',
            '<xf numFmtId="0" fontId="3" fillId="0" borderId="2" xfId="0" applyFont="1" applyBorder="1"/>',
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>',
        ];
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $numFmts . $fonts . $fills . $borders
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($xfs) . '">' . implode('', $xfs) . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Zip container (deflate via zlib, or stored when zlib is missing). */
    private function zip(array $files): string
    {
        $out = '';
        $central = '';
        $t = getdate();
        $dosTime = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
        $dosDate = (max(1980, $t['year']) - 1980) << 9 | ($t['mon'] << 5) | $t['mday'];
        foreach ($files as $name => $data) {
            $crc = crc32($data);
            $deflated = function_exists('gzdeflate') ? gzdeflate($data, 6) : false;
            [$method, $body] = $deflated !== false ? [8, $deflated] : [0, $data];
            $offset = strlen($out);
            $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $method, $dosTime, $dosDate, $crc, strlen($body), strlen($data), strlen($name), 0)
                . $name . $body;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, $method, $dosTime, $dosDate, $crc, strlen($body), strlen($data), strlen($name), 0, 0, 0, 0, 0, $offset)
                . $name;
        }
        return $out . $central
            . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), strlen($out), 0);
    }
}
