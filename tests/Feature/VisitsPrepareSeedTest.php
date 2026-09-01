<?php

it('writes the rows the seeder reads', function () {
    $source = tempnam(sys_get_temp_dir(), 'extract').'.json';
    $output = tempnam(sys_get_temp_dir(), 'visits').'.json';

    file_put_contents($source, (string) json_encode([
        ['type' => 'header'],
        ['type' => 'database', 'name' => 'sheffieldafrica_showroom'],
        ['type' => 'table', 'name' => 'customers', 'data' => [
            ['id' => 6, 'first_name' => 'Samuel', 'phone_primary' => '0716552760', 'created_at' => '2026-02-25 11:26:47', 'notes' => "Showroom\r\nInquiry on ovens\r\nColins"],
            ['id' => 7, 'first_name' => 'Nobody', 'phone_primary' => '0722000111', 'created_at' => '2026-02-26 07:08:56', 'notes' => ''],
        ]],
    ]));

    $this->artisan('visits:prepare-seed', ['--source' => $source, '--output' => $output])
        ->assertSuccessful();

    expect(json_decode((string) file_get_contents($output), true))
        ->toHaveCount(1)
        ->and(json_decode((string) file_get_contents($output), true)[0])
        ->legacy_id->toBe(6)
        ->purpose->toBe('new_enquiry')
        ->respondent->toBe('Colins')
        ->visited_at->toBe('2026-02-25 11:26:47');

    unlink($source);
    unlink($output);
});

# Must not write an empty seed file over the top of a good one.
it('fails without writing anything when the extract is missing', function () {
    $output = tempnam(sys_get_temp_dir(), 'visits').'.json';

    $this->artisan('visits:prepare-seed', [
        '--source' => sys_get_temp_dir().'/there-is-no-extract-here.json',
        '--output' => $output,
    ])->assertFailed();

    expect(file_exists($output))->toBeFalse();
});
