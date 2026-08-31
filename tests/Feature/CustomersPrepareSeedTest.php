<?php

it('writes the rows the seeder reads', function () {
    $source = tempnam(sys_get_temp_dir(), 'extract').'.json';
    $output = tempnam(sys_get_temp_dir(), 'seed').'.json';

    file_put_contents($source, (string) json_encode([
        ['type' => 'header'],
        ['type' => 'database', 'name' => 'sheffieldafrica_showroom'],
        ['type' => 'table', 'name' => 'customers', 'data' => [
            ['id' => 1, 'customer_type' => 'individual', 'first_name' => 'Wanjiru', 'last_name' => 'Kamau', 'phone_primary' => '0722000111'],
            ['id' => 2, 'customer_type' => 'individual', 'first_name' => 'Nobody', 'last_name' => 'Reachable', 'phone_primary' => 'N/A'],
        ]],
    ]));

    $this->artisan('customers:prepare-seed', ['--source' => $source, '--output' => $output])
        ->assertSuccessful();

    expect(json_decode((string) file_get_contents($output), true))
        ->toHaveCount(1)
        ->and(json_decode((string) file_get_contents($output), true)[0])
        ->name->toBe('Wanjiru Kamau')
        ->phone->toBe('+254722000111');

    unlink($source);
    unlink($output);
});

/**
 * The raw extract is the record of what was handed over. A run that cannot
 * find it must say so rather than write an empty seed file over the top of a
 * good one.
 */
it('fails without writing anything when the extract is missing', function () {
    $output = tempnam(sys_get_temp_dir(), 'seed').'.json';

    $this->artisan('customers:prepare-seed', [
        '--source' => sys_get_temp_dir().'/there-is-no-extract-here.json',
        '--output' => $output,
    ])->assertFailed();

    expect(file_exists($output))->toBeFalse();
});
