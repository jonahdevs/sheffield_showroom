<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum CustomerSegment: string
{
    case Hotels = 'hotels';
    case Restaurants = 'restaurants';
    case Healthcare = 'healthcare';
    case CoffeeShops = 'coffee_shops';
    case Bakery = 'bakery';
    case Schools = 'schools';
    case Ngo = 'ngo';
    case Corporate = 'corporate';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Hotels => 'Hotels',
            self::Restaurants => 'Restaurants',
            self::Healthcare => 'Healthcare',
            self::CoffeeShops => 'Coffee shops',
            self::Bakery => 'Bakery',
            self::Schools => 'Schools',
            self::Ngo => 'NGO',
            self::Corporate => 'Corporate',
            self::Other => 'Other',
        };
    }

    # `customers.segment` is free text: the cases here are the menu the form
    # offers, not a closed set, so a trade nobody anticipated is stored as
    # written and read back as written.
    public static function readable(string|self $value): string
    {
        return $value instanceof self
            ? $value->label()
            : self::tryFrom($value)?->label() ?? $value;
    }

    /**
     * The segment a legacy value names, or null when it names none.
     *
     * The imported book was typed by hand into a free-text box: `Restaurant`
     * and `BAKERIES` are this list in all but spelling, while `Cosim` and
     * `Kherdin` are company names somebody put in the wrong column. Only the
     * first kind is folded in - see `LegacyExtract`, which keeps the rest as
     * written rather than guessing.
     */
    public static function match(string $value): ?self
    {
        return match (mb_trim(mb_strtolower($value))) {
            'hotel', 'hotels' => self::Hotels,
            'restaurant', 'restaurants' => self::Restaurants,
            'healthcare', 'health care', 'hospital', 'hospitals' => self::Healthcare,
            'coffee shop', 'coffee shops', 'cafe', 'café' => self::CoffeeShops,
            'bakery', 'bakeries' => self::Bakery,
            'school', 'schools' => self::Schools,
            'ngo', 'ngos' => self::Ngo,
            'corporate', 'cooporate', 'corporates' => self::Corporate,
            default => null,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $segment) => ['value' => $segment->value, 'label' => $segment->label()],
            self::cases(),
        );
    }
}
