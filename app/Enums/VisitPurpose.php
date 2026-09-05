<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum VisitPurpose: string
{
    case Enquiry = 'enquiry';
    case Quotation = 'quotation';
    case ProductViewing = 'product_viewing';
    case Order = 'order';
    case AfterSales = 'after_sales';
    # Two desks, two errands: goods leaving the yard with the customer is the
    # logistics window, and goods going out to them is a run somebody schedules.
    # Rows written before they were told apart still read 'collection'.
    case Collection = 'collection';
    case Delivery = 'delivery';
    # Errands that bring somebody in who is not shopping - a cheque runner, a
    # candidate - are deliberately not cases here. They are Other, and who the
    # caller was is `visits.visitor_type`'s question rather than this column's.
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Enquiry => 'Enquiry',
            self::Quotation => 'Quotation request',
            self::ProductViewing => 'Product viewing / demo',
            self::Order => 'Placing an order',
            self::AfterSales => 'After-sales / service',
            self::Collection => 'Collection',
            self::Delivery => 'Delivery',
            self::Other => 'Other',
        };
    }

    # `visits.purpose` is free text: the cases below are the menu the form
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
            fn (self $purpose) => ['value' => $purpose->value, 'label' => $purpose->label()],
            self::cases(),
        );
    }
}
