<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Visit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

# =========================================================================
# The front-desk day book carried over from the old system
# =========================================================================
#
# Must run after `CustomersSeeder`: rows match on `customers.legacy_id`, and
# one whose customer is not on file is left out and reported, never attached
# to whoever looks closest. `insert()` rather than the model, so the visits
# keep the dates they happened on.

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

        DB::transaction(function () use ($prepared): void {
            # Only the visits this import put there: a visit logged on the
            # floor since has no `legacy_id`, and a re-seed must not take it
            # out.
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
            $this->command?->warn(sprintf(
                'Left out %d visit(s) with no customer on file (old system id): %s',
                count($orphaned),
                implode(', ', array_map(strval(...), $orphaned)),
            ));
        }
    }
}
