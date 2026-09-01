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
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::WalkIn => 'Walk-in',
            self::Referral => 'Referral',
            self::Website => 'Website',
            self::SocialMedia => 'Social media',
            self::Other => 'Other',
        };
    }

    # `visits.source` is free text: the cases above are the menu the form
    # offers, not a closed set, so anything typed under Other is stored as
    # written and read back as written.
    public static function readable(string|self $value): string
    {
        return $value instanceof self
            ? $value->label()
            : self::tryFrom($value)?->label() ?? $value;
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
