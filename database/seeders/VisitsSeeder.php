<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Visit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The front-desk day book carried over from the old system.
 *
 * The old system had nowhere to record a visit, so the front desk wrote each
 * one into the customer's `notes` column instead. `visits-seed.json` is that
 * log read back out - one visit per record that carries a note - and this puts
 * it where it can be counted.
 *
 * Runs after `CustomersSeeder` and depends on it: each row names the customer
 * it belongs to by the id that row had in the old system, and the customer
 * import is what carried those ids over into `customers.legacy_id`. Matching
 * on anything else would be guesswork - 46 telephone numbers in the extract
 * are shared between 115 records - so a row whose customer is not on file is
 * left out and reported rather than attached to whoever looks closest.
 *
 * Written with `insert()` rather than the model for the same reason the
 * customer import is: these visits happened between February and August and
 * saving them through Eloquent would stamp all 419 with the day the import
 * ran.
 *
 * `visits-seed.json` is produced by `visits:prepare-seed` from the raw extract
 * beside it. Change what gets imported there, not here.
 */
class VisitsSeeder extends Seeder
{
    /** Rows per insert, kept well inside the placeholder limit on any driver. */
    private const CHUNK = 100;

    public function run(): void
    {
        $rows = $this->seedRows();
        $customers = $this->customerIdsByLegacyId();

        $prepared = [];
        $orphaned = [];

        foreach ($rows as $row) {
            $legacyId = $row['legacy_id'];

            if (! isset($customers[$legacyId])) {
                $orphaned[] = $legacyId;

                continue;
            }

            $prepared[] = ['customer_id' => $customers[$legacyId], ...$row];
        }

        /* One transaction, because between the clear and the insert the log
           does not exist and this runs against a database somebody is looking
           at. */
        DB::transaction(function () use ($prepared): void {
            /* Only the visits this import put there, and only when it is
               putting them there again. A visit somebody logged on the floor
               since the migration has no `legacy_id` and is none of this
               seeder's business: it is the record the showroom is measured by,
               and a re-run of the seed must not be able to take it out. */
            Visit::withTrashed()->whereNotNull('legacy_id')->forceDelete();

            foreach (array_chunk($prepared, self::CHUNK) as $chunk) {
                Visit::query()->insert($chunk);
            }
        });

        $this->report(count($prepared), $orphaned);
    }

    /**
     * Which customer each row of the old system became.
     *
     * @return array<int, int>
     */
    private function customerIdsByLegacyId(): array
    {
        /* Trashed customers included. One of these being soft deleted is
           somebody tidying the list, not a statement that they never came in,
           and the visit still belongs on the record. */
        return Customer::withTrashed()
            ->whereNotNull('legacy_id')
            ->orderBy('id')
            ->pluck('id', 'legacy_id')
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function seedRows(): array
    {
        $path = database_path('data/visits-seed.json');

        if (! is_file($path)) {
            throw new RuntimeException(
                "There is no seed file at {$path}. Run `php artisan visits:prepare-seed` to build it from the extract."
            );
        }

        $rows = json_decode((string) file_get_contents($path), true);

        if (! is_array($rows) || $rows === []) {
            throw new RuntimeException("The seed file at {$path} holds no visits.");
        }

        return array_values($rows);
    }

    /**
     * @param  list<int>  $orphaned
     */
    private function report(int $imported, array $orphaned): void
    {
        $this->command?->info("Imported {$imported} visits from the front-desk log.");

        if ($orphaned !== []) {
            /* Almost always means the customer import has not been run, or
               has been run against a different extract. Named by the id the
               old system gave the row, because that is what both seed files
               are keyed by and the only way back to the record. */
            $this->command?->warn(sprintf(
                'Left out %d visit(s) with no customer on file (old system id): %s',
                count($orphaned),
                implode(', ', array_map(strval(...), $orphaned)),
            ));
        }
    }
}
