<?php

declare(strict_types=1);

namespace App\Services;

require_once dirname(__DIR__, 2) . '/lib/fpdf/fpdf.php';
require_once dirname(__DIR__, 2) . '/lib/qr.php';

/**
 * Gemeinsame PDF-Basis der Druckzentrale (FPDF 1.86, Kernschriften).
 *
 * Kernschriften können nur Latin-1 — deshalb läuft JEDE Textausgabe durch
 * t() bzw. fit() (beide konvertieren nach ISO-8859-1//TRANSLIT).
 *
 * Kopfzeile: Schulname + Edition + Seitentitel. Fußzeile: Erstellungsdatum
 * und "Seite x/y". Zusätzlich ein kleines Tabellen-Toolkit (setColumns /
 * drawHead / drawRow / ensureSpace), damit die einzelnen Berichte kurz bleiben.
 */
class Pdf extends \FPDF
{
    /** Y-Position (mm), an der der Inhalt unter der Kopfzeile beginnt. */
    public const CONTENT_TOP = 28.0;

    /** Y-Position (mm) für Dokumente ohne Kopfzeile (Karten-Layouts). */
    public const CONTENT_TOP_BARE = 12.0;

    private string $schoolName;
    private string $editionName;
    private string $documentTitle;
    private bool $showHeader;

    /** @var list<array{label: string, sub: string, width: float, align: string}> */
    private array $columns = [];

    private bool $rowAlt = false;

    public function __construct(
        string $orientation = 'P',
        string $schoolName = '',
        string $editionName = '',
        string $documentTitle = '',
        bool $showHeader = true,
    ) {
        parent::__construct($orientation, 'mm', 'A4');

        $this->schoolName = $schoolName;
        $this->editionName = $editionName;
        $this->documentTitle = $documentTitle;
        $this->showHeader = $showHeader;

        $this->SetMargins(10, $showHeader ? self::CONTENT_TOP : self::CONTENT_TOP_BARE, 10);
        $this->SetAutoPageBreak(true, 16);
        $this->AliasNbPages();
        $this->SetCreator('Berufsmesse');
        $this->SetTitle($this->t(trim($documentTitle . ' - ' . $editionName, ' -')));
        $this->SetFont('Helvetica', '', 9);
    }

    // ---------- Text-Konvertierung ----------

    /** UTF-8 → ISO-8859-1 (mit Transliteration). Pflicht vor jeder Ausgabe. */
    public function t(string $utf8): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $utf8);

        return $converted === false ? preg_replace('/[^\x20-\x7E]/', '', $utf8) ?? '' : $converted;
    }

    /**
     * Wie t(), kürzt den Text aber auf die Zellenbreite (mm) — FPDF schneidet
     * selbst nicht ab, zu langer Text würde sonst über die Zelle hinauslaufen.
     */
    public function fit(string $utf8, float $width): string
    {
        $text = $this->t($utf8);
        $max = $width - 2.0;
        if ($max <= 0 || $this->GetStringWidth($text) <= $max) {
            return $text;
        }

        while ($text !== '' && $this->GetStringWidth($text . '...') > $max) {
            $text = substr($text, 0, -1);
        }

        return $text . '...';
    }

    // ---------- Kopf- und Fußzeile ----------

    public function Header(): void // phpcs:ignore
    {
        if (!$this->showHeader) {
            return;
        }

        $pageWidth = $this->GetPageWidth();

        $this->SetFillColor(240, 243, 247);
        $this->Rect(0, 0, $pageWidth, 21, 'F');
        $this->SetDrawColor(190, 198, 208);
        $this->SetLineWidth(0.3);
        $this->Line(0, 21, $pageWidth, 21);

        $this->SetTextColor(25, 30, 38);
        $this->SetFont('Helvetica', 'B', 13);
        $this->SetXY(10, 5);
        $this->Cell($pageWidth - 80, 6, $this->fit($this->schoolName, $pageWidth - 80), 0, 0, 'L');

        $this->SetTextColor(95, 105, 118);
        $this->SetFont('Helvetica', '', 9);
        $this->SetXY(10, 12);
        $this->Cell($pageWidth - 80, 5, $this->fit($this->editionName, $pageWidth - 80), 0, 0, 'L');

        // Seitentitel rechts — bewusst nur hier, damit er sich links nicht wiederholt.
        $this->SetTextColor(45, 52, 62);
        $this->SetFont('Helvetica', 'B', 10);
        $this->SetXY($pageWidth - 75, 9);
        $title = $this->documentTitle !== '' ? $this->documentTitle : 'Bericht';
        $this->Cell(65, 6, $this->fit($title, 65), 0, 0, 'R');

        $this->SetTextColor(0, 0, 0);
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.2);
        $this->SetY(self::CONTENT_TOP);
    }

    public function Footer(): void // phpcs:ignore
    {
        // Karten-Layouts (ohne Kopfzeile) sollen auch keine Fußzeile bekommen —
        // dort ist jeder Millimeter für das Schnittraster reserviert.
        if (!$this->showHeader) {
            return;
        }

        $this->SetY(-12);
        $this->SetFont('Helvetica', 'I', 8);
        $this->SetTextColor(140, 145, 152);

        $half = ($this->GetPageWidth() - 20) / 2;
        $this->SetX(10);
        $this->Cell($half, 6, $this->t('Erstellt am ' . date('d.m.Y, H:i') . ' Uhr'), 0, 0, 'L');
        $this->Cell($half, 6, $this->t('Seite ' . $this->PageNo() . '/{nb}'), 0, 0, 'R');

        $this->SetTextColor(0, 0, 0);
    }

    // ---------- Bausteine ----------

    /** Nutzbare Inhaltsbreite (Seitenbreite abzüglich Ränder) in mm. */
    public function contentWidth(): float
    {
        return $this->GetPageWidth() - 20.0;
    }

    /** Überschrift innerhalb des Inhalts. */
    public function heading(string $text, float $size = 12.0, float $height = 8.0): void
    {
        $this->SetFont('Helvetica', 'B', $size);
        $this->SetTextColor(20, 25, 32);
        $this->Cell(0, $height, $this->fit($text, $this->contentWidth()), 0, 1, 'L');
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Helvetica', '', 9);
    }

    /** Abschnitts-Balken (z. B. je Slot oder je Klasse). */
    public function band(string $text, string $right = '', float $height = 7.0): void
    {
        $this->SetFont('Helvetica', 'B', 9.5);
        $this->SetFillColor(228, 234, 241);
        $this->SetDrawColor(190, 198, 208);
        $this->SetLineWidth(0.2);

        $width = $this->contentWidth();
        $rightWidth = $right === '' ? 0.0 : 55.0;
        $this->Cell($width - $rightWidth, $height, $this->fit($text, $width - $rightWidth), 1, $right === '' ? 1 : 0, 'L', true);
        if ($right !== '') {
            $this->SetFont('Helvetica', '', 8.5);
            $this->Cell($rightWidth, $height, $this->fit($right, $rightWidth), 1, 1, 'R', true);
        }

        $this->SetFont('Helvetica', '', 9);
    }

    /** Kleiner grauer Hinweistext. */
    public function note(string $text, float $size = 8.0): void
    {
        $this->SetFont('Helvetica', '', $size);
        $this->SetTextColor(110, 118, 128);
        $this->MultiCell(0, 4.4, $this->t($text), 0, 'L');
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Helvetica', '', 9);
    }

    /** Zentrierter Platzhalter, wenn ein Bericht keine Daten enthält. */
    public function emptyState(string $text): void
    {
        $this->SetFont('Helvetica', '', 11);
        $this->SetTextColor(110, 118, 128);
        $this->Cell(0, 18, $this->fit($text, $this->contentWidth()), 0, 1, 'C');
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Helvetica', '', 9);
    }

    // ---------- Tabellen-Toolkit ----------

    /**
     * Spaltendefinition setzen.
     *
     * @param list<array{0: string, 1: float, 2?: string, 3?: string}> $columns
     *        [Beschriftung, Breite in mm, Ausrichtung (L|C|R), zweite Kopfzeile]
     */
    public function setColumns(array $columns): void
    {
        $this->columns = [];
        foreach ($columns as $column) {
            $this->columns[] = [
                'label' => (string) $column[0],
                'width' => (float) $column[1],
                'align' => (string) ($column[2] ?? 'L'),
                'sub' => (string) ($column[3] ?? ''),
            ];
        }
        $this->rowAlt = false;
    }

    /** Summe der Spaltenbreiten in mm. */
    public function columnsWidth(): float
    {
        $sum = 0.0;
        foreach ($this->columns as $column) {
            $sum += $column['width'];
        }

        return $sum;
    }

    /** Zeichnet die Kopfzeile der aktuellen Spaltendefinition. */
    public function drawHead(float $height = 8.0): void
    {
        if ($this->columns === []) {
            return;
        }

        $hasSub = false;
        foreach ($this->columns as $column) {
            if ($column['sub'] !== '') {
                $hasSub = true;
                break;
            }
        }
        if ($hasSub && $height < 9.5) {
            $height = 9.5;
        }

        $x = $this->lMargin;
        $y = $this->GetY();

        $this->SetFillColor(232, 236, 242);
        $this->SetDrawColor(110, 118, 128);
        $this->SetLineWidth(0.25);

        foreach ($this->columns as $column) {
            $this->Rect($x, $y, $column['width'], $height, 'FD');

            $this->SetFont('Helvetica', 'B', 8);
            $this->SetXY($x, $hasSub ? $y + 0.8 : $y + ($height - 4.2) / 2);
            $this->Cell($column['width'], 4.2, $this->fit($column['label'], $column['width']), 0, 0, $column['align']);

            if ($column['sub'] !== '') {
                $this->SetFont('Helvetica', '', 7);
                $this->SetTextColor(90, 98, 108);
                $this->SetXY($x, $y + 5.0);
                $this->Cell($column['width'], 3.8, $this->fit($column['sub'], $column['width']), 0, 0, $column['align']);
                $this->SetTextColor(0, 0, 0);
            }

            $x += $column['width'];
        }

        $this->SetXY($this->lMargin, $y + $height);
        $this->SetFont('Helvetica', '', 8);
        $this->rowAlt = false;
    }

    /**
     * Zeichnet eine Datenzeile passend zur Spaltendefinition.
     *
     * @param list<string|int|float|null> $values
     */
    public function drawRow(array $values, float $height = 5.8, bool $bold = false): void
    {
        if ($this->columns === []) {
            return;
        }

        $this->SetFont('Helvetica', $bold ? 'B' : '', 8);
        $this->SetDrawColor(175, 182, 190);
        $this->SetLineWidth(0.15);
        $this->SetFillColor(246, 248, 250);

        $fill = $this->rowAlt;
        $this->SetX($this->lMargin);

        foreach ($this->columns as $index => $column) {
            $value = $values[$index] ?? '';
            $this->Cell(
                $column['width'],
                $height,
                $this->fit((string) $value, $column['width']),
                1,
                0,
                $column['align'],
                $fill,
            );
        }

        $this->Ln();
        $this->rowAlt = !$this->rowAlt;
    }

    /** Zebra-Streifen zurücksetzen (z. B. am Beginn einer neuen Gruppe). */
    public function resetBanding(): void
    {
        $this->rowAlt = false;
    }

    /**
     * Sorgt dafür, dass noch $needed mm Platz auf der Seite sind; sonst
     * Seitenumbruch (optional mit wiederholter Tabellen-Kopfzeile).
     */
    public function ensureSpace(float $needed, bool $repeatHead = false): bool
    {
        if ($this->GetY() + $needed <= $this->PageBreakTrigger) {
            return false;
        }

        $this->AddPage($this->CurOrientation);
        if ($repeatHead) {
            $this->drawHead();
        }

        return true;
    }

    // ---------- QR & Ausgabe ----------

    /** Zeichnet einen QR-Code (lokal gerendert, keine externen Dienste). */
    public function qr(string $data, float $x, float $y, float $size): void
    {
        qrDrawFpdf($this, $data, $x, $y, $size);
        $this->SetFillColor(255, 255, 255);
        $this->SetDrawColor(0, 0, 0);
    }

    /**
     * Gibt das PDF aus und beendet den Request.
     * 'D' = Download, 'I' = im Browser anzeigen.
     */
    public function emit(string $filename, string $dest = 'D'): never
    {
        $this->Output($dest === 'I' ? 'I' : 'D', self::safeFilename($filename));

        exit;
    }

    /** Dateiname ohne Umlaute/Sonderzeichen (für Content-Disposition). */
    public static function safeFilename(string $name): string
    {
        $name = strtr($name, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
        ]);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $name);
        if ($ascii === false) {
            $ascii = $name;
        }
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $ascii) ?? 'dokument';
        $ascii = trim($ascii, '_');

        return $ascii === '' ? 'dokument.pdf' : $ascii;
    }
}
