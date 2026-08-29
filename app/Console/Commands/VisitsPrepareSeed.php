<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Visits\LegacyVisitLog;
use Illuminate\Console\Command;
use RuntimeException;

use function Laravel\Prompts\table;

/**
 * Turns the front-desk log buried in the customer extract into the file
 * `VisitsSeeder` reads.
 *
 * The sibling of `customers:prepare-seed`, and for the same reason: the seed
 * file is committed, so a change to how a note is read shows up as a diff
 * against 448 visits rather than as a number that moved on somebody's
 * dashboard. Change a rule in `LegacyVisitLog`, run this, and the diff says
 * exactly which visits the change moved and where to.
 *
 * Reads and writes nothing else. `database/data/customers.json` is the record
 * of what was handed over and stays as it arrived.
 */
class VisitsPrepareSeed extends Command
{
    protected $signature = 'visits:prepare-seed
                            {--source= : The raw extract to read (defaults to database/data/customers.json)}
                            {--output= : Where to write the seed rows (defaults to database/data/visits-seed.json)}';

    protected $description = 'Read the front-desk visit log out of the customer extract into rows VisitsSeeder can insert';

    public function handle(LegacyVisitLog $log): int
    {
        $source = $this->stringOption('source') ?? database_path('data/customers.json');
        $output = $this->stringOption('output') ?? database_path('data/visits-seed.json');

        if (! is_file($source)) {
            $this->error("There is no extract at {$source}.");

            return self::FAILURE;
        }

        try {
            $result = $log->transform((string) file_get_contents($source));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        /* Pretty printed with slashes and unicode left alone, like the
           customer seed file beside it: this is read by people deciding
           whether a mapping rule got it right, and an escaped blob cannot be
           read that way. */
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
     *     unlogged: int,
     *     without_customer: list<int|null>,
     *     purposes: array<string, int>,
     *     respondents: int,
     * }  $result
     */
    private function report(array $result, string $output): void
    {
        $prepared = count($result['rows']);

        table(
            ['Outcome', 'Count', 'Detail'],
            [
                ['Prepared', (string) $prepared, basename($output)],
                ['No note written', (string) $result['unlogged'], 'The row records a customer and no visit'],
                ['No customer to hang it on', (string) count($result['without_customer']), 'The customer import turned the row down'],
                ['Named who took it', (string) $result['respondents'], sprintf('%d left blank', $prepared - $result['respondents'])],
            ]
        );

        table(
            ['Purpose', 'Visits'],
            array_map(
                fn (string $purpose, int $count): array => [$purpose, (string) $count],
                array_keys($result['purposes']),
                array_values($result['purposes']),
            )
        );

        if ($result['without_customer'] !== []) {
            /* Listed by the id they had in the old system, because the only
               way one of these comes back is somebody looking that record up
               and finding a telephone number for it. */
            $this->warn(sprintf(
                'Left out, no customer was imported for them (old system id): %s',
                implode(', ', array_map(fn (?int $id): string => (string) ($id ?? '?'), $result['without_customer'])),
            ));
        }
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
