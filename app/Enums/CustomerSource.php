<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How the customer came to be standing in the showroom.
 *
 * Recorded per visit rather than on the customer: the same person can find you
 * through an exhibition once and come back on their own the next time, and it
 * is the second visit that tells you the first one worked.
 */
#[TypeScript]
enum CustomerSource: string
{
    case WalkIn = 'walk_in';
    case Referral = 'referral';
    case Website = 'website';
    case SocialMedia = 'social_media';
    case Exhibition = 'exhibition';
    case Repeat = 'repeat';
    case Advertisement = 'advertisement';
    case SalesCall = 'sales_call';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::WalkIn => 'Walk-in',
            self::Referral => 'Referral',
            self::Website => 'Website',
            self::SocialMedia => 'Social media',
            self::Exhibition => 'Exhibition / trade show',
            self::Repeat => 'Existing customer',
            self::Advertisement => 'Advertisement',
            self::SalesCall => 'Sales call',
            self::Other => 'Other',
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
            fn (self $source) => ['value' => $source->value, 'label' => $source->label()],
            self::cases(),
        );
    }
}
