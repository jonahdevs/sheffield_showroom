<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Exceptions\DocumentRenderingFailedException;
use App\Services\Documents\TableDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * One answer for every export on the admin screens: the same rows as a
 * spreadsheet or as paper, chosen by a query parameter.
 *
 * Written once because the lists that offer these formats do it over different
 * queries, and a format that behaved differently on one of them would be a bug
 * nobody would find until somebody opened the download.
 */
class ExportResponse
{
    /** @var array<int, string> */
    public const FORMATS = ['csv', 'xlsx', 'pdf'];

    /**
     * The formats this host can actually produce.
     *
     * Paper needs a headless Chrome that a machine may simply not have, and a
     * menu entry that always fails is worse than one format fewer. Asked here
     * rather than on each screen so the three lists that offer a download
     * cannot end up disagreeing about what this server can do.
     *
     * @return array<int, string>
     */
    public static function available(): array
    {
        return array_values(array_filter(
            self::FORMATS,
            fn (string $format): bool => $format !== 'pdf' || TableDocumentService::available(),
        ));
    }

    /**
     * CSV is the default and the fallback: it opens everywhere, and an
     * unrecognised format hands back a file rather than an error page for what
     * is only ever a mistyped query string.
     */
    public static function format(mixed $requested): string
    {
        return in_array($requested, self::FORMATS, true)
            ? (string) $requested
            : 'csv';
    }

    /**
     * @param  object  $export  A Maatwebsite export - the same one the other
     *                          formats are built from.
     * @param  string  $basename  The filename without its extension.
     */
    public static function make(
        object $export,
        string $basename,
        string $format,
        string $title,
        string $subtitle = '',
    ): BinaryFileResponse|Response|RedirectResponse {
        $format = self::format($format);
        $name = "{$basename}.{$format}";

        if ($format !== 'pdf') {
            return Excel::download(
                $export,
                $name,
                $format === 'xlsx' ? ExcelWriter::XLSX : ExcelWriter::CSV,
            );
        }

        /* Rendering needs headless Chrome, and a host without it is a
           deployment fact rather than a bug in this request, so the failure
           comes back as a toast instead of a stack trace. */
        try {
            $pdf = app(TableDocumentService::class)->render($export, $title, $subtitle);
        } catch (DocumentRenderingFailedException $exception) {
            report($exception);

            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('This export could not be rendered as a PDF. The renderer is unavailable on this server.'),
            ]);

            return back();
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }
}
