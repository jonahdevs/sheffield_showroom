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
 * Any export, as paper. The rows are never gathered again here - the export class is
 * asked for its CSV and that is what gets typeset, so a PDF cannot disagree with the
 * spreadsheet beside it and no export has to learn a third format.
 */
class TableDocumentService
{
    /**
     * Rendering needs a headless Chrome the host may not have, so screens ask before
     * offering the format at all.
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
                ->landscape()
                ->format('a4')
                ->margins(12, 12, 14, 12)
                ->generatePdfContent();
        } catch (Throwable $exception) {
            throw new DocumentRenderingFailedException($title, $exception);
        }
    }

    /**
     * Read back out of the CSV the export already produces, so headings, mapping and
     * formatting all come along with it.
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
