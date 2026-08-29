<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Exceptions\DocumentRenderingFailedException;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\LaravelPdf\Facades\Pdf;
use Throwable;

/**
 * Any export, as paper.
 *
 * The rows are not gathered again here: the export class that already answers
 * for CSV and Excel is asked for its CSV, and that is what gets typeset. So a
 * PDF cannot list different rows, columns or totals than the spreadsheet
 * beside it, and no export class had to learn a third format - a column added
 * for the spreadsheet appears on the paper without anybody remembering to put
 * it there.
 */
class TableDocumentService
{
    /**
     * Whether this host can typeset at all.
     *
     * Rendering runs through a headless Chrome that a machine may simply not
     * have, so the screens ask before offering the format. A button that
     * always fails is worse than a format that is not on the menu.
     */
    public static function available(): bool
    {
        return class_exists(Pdf::class);
    }

    /**
     * @param  object  $export  A Maatwebsite export - anything the other two
     *                          formats already accept.
     * @return string The raw PDF bytes.
     *
     * @throws DocumentRenderingFailedException
     */
    public function render(object $export, string $title, string $subtitle = ''): string
    {
        if (! self::available()) {
            throw new DocumentRenderingFailedException($title);
        }

        $rows = $this->rows($export);
        $headings = array_shift($rows) ?? [];

        try {
            return Pdf::view('documents.table', [
                'title' => $title,
                'subtitle' => $subtitle,
                'issuer' => config('app.name'),
                'headings' => $headings,
                'rows' => $rows,
                'issued_at' => CarbonImmutable::now()->toDayDateTimeString(),
            ])
                /* A table wide enough for a ledger does not fit upright, and a
                   column that wraps into three lines is worse than a page a
                   reader turns sideways. */
                ->landscape()
                ->format('a4')
                ->margins(12, 12, 14, 12)
                ->generatePdfContent();
        } catch (Throwable $exception) {
            throw new DocumentRenderingFailedException($title, $exception);
        }
    }

    /**
     * The export's own rows, read back out of the CSV it already knows how to
     * produce. Headings, mapping and formatting all come along with it.
     *
     * @return array<int, array<int, string|null>>
     */
    public function rows(object $export): array
    {
        $csv = Excel::raw($export, ExcelWriter::CSV);

        $rows = [];

        foreach (preg_split('/\R/', trim((string) $csv)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            $rows[] = str_getcsv($line, ',', '"', '\\');
        }

        return $rows;
    }
}
