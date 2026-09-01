<?php

use App\Models\Customer;
use App\Models\Visit;
use Database\Seeders\CustomersSeeder;
use Database\Seeders\VisitsSeeder;

# 448 of the 453 extract rows carry a note; 29 belong to customers the
# import turns down for having no dialable phone.
const IMPORTED_VISITS = 419;

/**
 * Both halves, in the order they have to run: a visit matches its customer on
 * `legacy_id`, so there is nothing to match until the book has landed.
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

# A visit logged since the migration carries no `legacy_id`, which is what
# keeps a re-seed from clearing it.
it('leaves a visit logged in this application alone', function () {
    seedTheMigration();
    $logged = Visit::factory()->create();

    test()->seed(VisitsSeeder::class);

    $this->assertModelExists($logged);
    expect(Visit::query()->count())->toBe(IMPORTED_VISITS + 1);
});

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
