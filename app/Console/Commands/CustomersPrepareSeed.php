<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Customers\LegacyExtract;
use Illuminate\Console\Command;
use RuntimeException;

use function Laravel\Prompts\table;

/**
 * Turns the raw customer extract into the file `CustomersSeeder` reads.
 *
 * The seed file is committed, so the import is reviewable in a diff rather
 * than being whatever a script happened to produce on somebody's machine.
 * This command is committed with it so the file has a visible origin: change a
 * mapping rule in `LegacyExtract`, run this again, and the diff shows exactly
 * which customers the change moved.
 *
 * Reads and writes nothing else. `database/data/customers.json` is the record
 * of what was handed over and stays as it arrived.
 */
class CustomersPrepareSeed extends Command
{
    protected $signature = 'customers:prepare-seed
                            {--source= : The raw extract to read (defaults to database/data/customers.json)}
                            {--output= : Where to write the seed rows (defaults to database/data/customers-seed.json)}';

    protected $description = 'Reshape the customer extract from the old system into rows CustomersSeeder can insert';

    public function handle(LegacyExtract $extract): int
    {
        $source = $this->stringOption('source') ?? database_path('data/customers.json');
        $output = $this->stringOption('output') ?? database_path('data/customers-seed.json');

        if (! is_file($source)) {
            $this->error("There is no extract at {$source}.");

            return self::FAILURE;
        }

        try {
            $result = $extract->transform((string) file_get_contents($source));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        /* Pretty printed with slashes and unicode left alone: this file is
           read by people reviewing what the import will do, and an escaped
           blob is not reviewable. */
        file_put_contents($output, json_encode(
            $result['rows'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ).PHP_EOL);

        $this->report($result, $output);

        return self::SUCCESS;
    }

    /**
     * @param  array{
     *     rows: list<array<string, mixed>>,
     *     skipped: list<array{id: mixed, phone: string}>,
     *     duplicate_phones: array<string, int>,
     * }  $result
     */
    private function report(array $result, string $output): void
    {
        $collisions = array_sum($result['duplicate_phones']) - count($result['duplicate_phones']);

        table(
            ['Outcome', 'Count', 'Detail'],
            [
                ['Read', (string) (count($result['rows']) + count($result['skipped'])), basename($output)],
                ['Prepared', (string) count($result['rows']), ''],
                ['Skipped', (string) count($result['skipped']), 'No usable phone number'],
                ['Sharing a number', (string) $collisions, count($result['duplicate_phones']).' number(s) held by more than one customer'],
            ]
        );

        if ($result['skipped'] !== []) {
            /* Listed rather than counted, because the only way one of these
               comes back is somebody looking up the old record by its id and
               finding a number for it. */
            $this->warn('Left out (id => what the phone column held):');

            foreach ($result['skipped'] as $skipped) {
                $this->line(sprintf('  %s => %s', $skipped['id'] ?? '?', $skipped['phone']));
            }
        }
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
