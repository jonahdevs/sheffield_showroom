<?php

use App\Models\Customer;
use App\Models\Visit;
use Database\Seeders\CustomersSeeder;
use Database\Seeders\VisitsSeeder;

/**
 * 448 of the 453 rows in the extract carry a front-desk note. 29 of those were
 * written against a record with nothing dialable in the phone column, which
 * the customer import turns down, and a visit cannot exist without the
 * customer it belongs to. The rest is the log.
 */
const IMPORTED_VISITS = 419;

/**
 * Both halves of the migration, in the order they have to run: a visit names
 * its customer by the id that customer had in the old system, and there is
 * nothing to match against until the book has landed.
 */
function seedTheMigration(): void
{
    test()->seed(CustomersSeeder::class);
    test()->seed(VisitsSeeder::class);
}

it('seeds every visit in the prepared file', function () {
    seedTheMigration();

    expect(Visit::query()->count())->toBe(IMPORTED_VISITS);
});

/**
 * The crux of the whole import. The extract's own id is the only thing that
 * ties a note to the customer that row became - telephone numbers are shared
 * between records and the keys this table hands out depend on what was in it
 * beforehand - so it is spot checked here against a record that can be
 * recognised by name.
 */
it('hangs each visit on the customer whose record the note was written on', function () {
    seedTheMigration();

    $visit = Visit::query()->where('legacy_id', 9)->sole();

    expect($visit->customer->name)->toBe('Isaac')
        ->and($visit->customer->phone)->toBe('+254728912898')
        ->and($visit->customer->legacy_id)->toBe(9)
        ->and($visit->notes)->toBe("Service of equipment\nGirraj dealt with them");
});

it('keeps the times the front desk wrote rather than stamping today', function () {
    seedTheMigration();

    expect(Visit::query()->min('visited_at'))->toBe('2026-02-25 11:26:47')
        ->and(Visit::query()->max('visited_at'))->toBe('2026-08-28 06:30:19');
});

/**
 * Everything in the log came through the front door and was written down at
 * the counter, and nobody in this application logged any of it.
 */
it('records the whole log as walk-ins that nobody here logged', function () {
    seedTheMigration();

    expect(Visit::query()->where('source', 'walk_in')->count())->toBe(IMPORTED_VISITS)
        ->and(Visit::query()->whereNotNull('created_by')->count())->toBe(0)
        ->and(Visit::query()->whereNotNull('expected_follow_up_on')->count())->toBe(0);
});

it('reads a purpose other than Other out of most of the log', function () {
    seedTheMigration();

    expect(Visit::query()->where('purpose', '!=', 'other')->count())->toBeGreaterThan(300);
});

it('imports each visit once when run twice', function () {
    seedTheMigration();
    test()->seed(VisitsSeeder::class);

    expect(Visit::query()->count())->toBe(IMPORTED_VISITS);
});

/**
 * A visit somebody logged on the floor since the migration carries no
 * `legacy_id`, is none of the import's business, and is the record the
 * showroom is measured by. Re-running the seed must not be able to take it
 * out.
 */
it('leaves a visit logged in this application alone', function () {
    seedTheMigration();
    $logged = Visit::factory()->create();

    test()->seed(VisitsSeeder::class);

    $this->assertModelExists($logged);
    expect(Visit::query()->count())->toBe(IMPORTED_VISITS + 1);
});

/**
 * The seeder is not what decides who gets imported - `visits:prepare-seed`
 * did that - but it cannot invent a customer either, and a row it cannot
 * place has to be left out rather than attached to whoever looks closest.
 */
it('leaves out a visit whose customer is not on file', function () {
    $this->seed(CustomersSeeder::class);
    Customer::query()->where('legacy_id', 9)->forceDelete();

    $this->seed(VisitsSeeder::class);

    expect(Visit::query()->count())->toBe(IMPORTED_VISITS - 1)
        ->and(Visit::query()->where('legacy_id', 9)->exists())->toBeFalse();
});

it('imports nothing at all when the book has not been seeded', function () {
    $this->seed(VisitsSeeder::class);

    expect(Visit::query()->count())->toBe(0);
});
