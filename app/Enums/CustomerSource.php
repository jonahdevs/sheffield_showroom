<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum CustomerSource: string
{
    case WalkIn = 'walk_in';
    case Referral = 'referral';
    case Website = 'website';
    case SocialMedia = 'social_media';
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
