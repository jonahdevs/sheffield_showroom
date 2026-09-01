<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Visits\LegacyVisitLog;
use Illuminate\Console\Command;
use RuntimeException;

use function Laravel\Prompts\table;

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
