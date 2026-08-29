<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The customer book carried over from the old system.
 *
 * This is a replacement, not an addition: the rows in `customers-seed.json`
 * are the whole book, so what is already in the table goes first. Anything
 * this application put there was factory data standing in until the real
 * customers arrived.
 *
 * Written with `insert()` rather than the model, so the `created_at` each
 * customer came over with is the one that lands. Saving them through Eloquent
 * would stamp every one of them with the day the import ran.
 *
 * `customers-seed.json` is produced by `customers:prepare-seed` from the raw
 * extract beside it. Change what gets imported there, not here.
 */
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
     * The book, less the customers it is already sitting on.
     *
     * A customer a visit points at cannot be cleared out, and once the visit
     * import has run that is every customer in the book. Without this a second
     * `db:seed` would spare all 424 of them and then insert the same 424 again
     * on top, doubling the list on a command that is supposed to be repeatable.
     *
     * Recognised by the id the row had in the old system, which is the only
     * thing that survives the round trip: the name, the address and the
     * telephone are all things somebody may have corrected in the meantime,
     * and correcting one must not be enough to make the import forget it
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
     * Empties the table of everything it is allowed to empty, and returns the
     * customers it had to leave behind.
     *
     * `visits.customer_id` is restricted on delete, deliberately: a visit is
     * the record the showroom floor is measured by and it must not disappear
     * because somebody cleared the customer list. So a customer somebody has
     * already logged a visit against is kept, rather than the visit being
     * deleted to make room for the import. That leaves a handful of records
     * from before the migration sitting alongside the imported book, which is
     * the right way round: a stale customer row is a nuisance somebody can
     * merge by hand, a lost visit is gone.
     *
     * @return Collection<int, Customer> The customers it had to leave behind
     */
    private function clearExisting(): Collection
    {
        /* Straight from the table rather than through the model: `Visit` soft
           deletes, and a soft-deleted visit still holds the foreign key that
           would refuse the delete. */
        $visited = DB::table('visits')->distinct()->pluck('customer_id')->all();

        $spared = Customer::withTrashed()->whereIn('id', $visited)->get();

        /* Force deleted, not soft deleted. A row left behind with a
           `deleted_at` is still in the way of every phone lookup the import is
           meant to make reliable. */
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
            /* Named rather than counted. These are the records from before the
               migration that the book has nothing to say about, and the only
               way one of them stops sitting next to its imported twin is
               somebody reading the name and merging it by hand. */
            $this->command?->warn(sprintf(
                'Kept %d customer(s) from outside the extract that a visit is logged against: %s',
                $strangers->count(),
                $strangers->map(fn (Customer $customer): string => $customer->displayName())->implode(', '),
            ));
        }
    }
}
