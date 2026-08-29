<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Services\Products\CatalogueSync;
use Illuminate\Console\Command;
use RuntimeException;

use function Laravel\Prompts\table;

/**
 * Runs the catalogue sync from a terminal.
 *
 * The same service the Fetch button calls, reachable without a browser. That
 * matters for the two occasions the button is no use: a first import on a
 * server nobody has logged into yet, and working out why a sync went wrong -
 * where a stack trace on the console beats a toast that says something broke.
 *
 * Also the natural thing to hang on the scheduler when somebody decides the
 * catalogue should keep itself current overnight.
 */
class ProductsSync extends Command
{
    protected $signature = 'products:sync
                            {--include-unpublished : Ask the website for drafts as well, which land here as Draft}';

    protected $description = 'Pull the product catalogue from the main website';

    public function handle(CatalogueSync $sync): int
    {
        $this->line('Fetching from '.config('services.main_website.url').' ...');

        try {
            $summary = $sync->run((bool) $this->option('include-unpublished'));
        } catch (RuntimeException $exception) {
            /* The service's messages are written for a person rather than for
               a log, so they are shown as they stand. */
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        table(
            ['Outcome', 'Count'],
            [
                ['Returned by the website', (string) $summary['total']],
                ['Created', (string) $summary['created']],
                ['Updated', (string) $summary['updated']],
                ['Already current', (string) $summary['unchanged']],
                ['No longer offered', (string) $summary['removed']],
            ]
        );

        $this->statuses();

        return self::SUCCESS;
    }

    /**
     * Where the catalogue now stands, which is the answer the sync is actually
     * being run for: a count that says "updated 400" tells nobody whether the
     * floor has anything on it.
     */
    private function statuses(): void
    {
        $live = Product::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $removed = Product::onlyTrashed()->count();

        table(
            ['Status', 'On the floor'],
            array_map(
                fn (ProductStatus $status) => [
                    $status->label(),
                    (string) (int) $live->get($status->value, 0),
                ],
                ProductStatus::cases(),
            )
        );

        if ($removed > 0) {
            $this->line("{$removed} product(s) are soft-deleted and not counted above.");
        }
    }
}
