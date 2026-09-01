<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Customers\LegacyExtract;
use Illuminate\Console\Command;
use RuntimeException;

use function Laravel\Prompts\table;

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
