<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

# =========================================================================
# The customer book carried over from the old system
# =========================================================================
#
# `insert()` rather than the model, so each customer keeps the `created_at` it
# came over with. `customers-seed.json` is produced by
# `customers:prepare-seed` - change what gets imported there, not here.

class CustomersSeeder extends Seeder
{
    /** Rows per insert, kept well inside the placeholder limit on any driver. */
    private const CHUNK = 100;

    public function run(): void
    {
        $rows = $this->seedRows();

        DB::transaction(function () use ($rows): void {
            $spared = $this->clearExisting();
            $incoming = $this->notAlreadyOnFile($rows, $spared);

            foreach (array_chunk($incoming, self::CHUNK) as $chunk) {
                Customer::query()->insert($chunk);
            }

            $this->report(count($incoming), $spared);
        });
    }

    /**
     * Recognised by `legacy_id`, the only thing that survives the round trip:
     * a corrected name or telephone must not make the import forget it
     * already imported that person.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<int, Customer>  $spared
     * @return list<array<string, mixed>>
     */
    private function notAlreadyOnFile(array $rows, Collection $spared): array
    {
        $onFile = $spared->pluck('legacy_id')->filter()->map(intval(...))->all();

        return array_values(array_filter(
            $rows,
            fn (array $row): bool => ! in_array((int) $row['legacy_id'], $onFile, strict: true),
        ));
    }

    /**
     * @return Collection<int, Customer> The customers it had to leave behind
     */
    private function clearExisting(): Collection
    {
        # `whereNotNull` is load-bearing: half the log is callers who were never
        # customers and carry a null `customer_id`. One null in the list makes
        # `whereNotIn('id', ...)` below `id NOT IN (1, 2, NULL)`, which is never
        # true for any row, so nothing is cleared and the re-insert files every
        # unvisited customer a second time.
        $visited = DB::table('visits')
            ->whereNotNull('customer_id')
            ->distinct()
            ->pluck('customer_id')
            ->all();

        $spared = Customer::withTrashed()->whereIn('id', $visited)->get();

        # Force deleted: a row left with a `deleted_at` is still in the way
        # of the phone lookups the import exists to make reliable.
        Customer::withTrashed()->whereNotIn('id', $visited)->forceDelete();

        return $spared;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function seedRows(): array
    {
        $path = database_path('data/customers-seed.json');

        if (! is_file($path)) {
            throw new RuntimeException(
                "There is no seed file at {$path}. Run `php artisan customers:prepare-seed` to build it from the extract."
            );
        }

        $rows = json_decode((string) file_get_contents($path), true);

        if (! is_array($rows) || $rows === []) {
            throw new RuntimeException("The seed file at {$path} holds no customers.");
        }

        return array_values($rows);
    }

    /**
     * @param  Collection<int, Customer>  $spared
     */
    private function report(int $imported, Collection $spared): void
    {
        $this->command?->info("Imported {$imported} customers from the extract.");

        [$reimported, $strangers] = $spared->partition(
            fn (Customer $customer): bool => $customer->legacy_id !== null,
        );

        if ($reimported->isNotEmpty()) {
            $this->command?->info(sprintf(
                'Left %d already-imported customer(s) in place; a visit is logged against them.',
                $reimported->count(),
            ));
        }

        if ($strangers->isNotEmpty()) {
            $this->command?->warn(sprintf(
                'Kept %d customer(s) from outside the extract that a visit is logged against: %s',
                $strangers->count(),
                $strangers->map(fn (Customer $customer): string => $customer->displayName())->implode(', '),
            ));
        }
    }
}
