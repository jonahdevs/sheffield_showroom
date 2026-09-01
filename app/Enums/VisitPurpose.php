<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum VisitPurpose: string
{
    case NewEnquiry = 'new_enquiry';
    case Quotation = 'quotation';
    case ProductViewing = 'product_viewing';
    case FollowUp = 'follow_up';
    case Order = 'order';
    case AfterSales = 'after_sales';
    case Complaint = 'complaint';
    case Collection = 'collection';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NewEnquiry => 'New enquiry',
            self::Quotation => 'Quotation request',
            self::ProductViewing => 'Product viewing / demo',
            self::FollowUp => 'Follow-up',
            self::Order => 'Placing an order',
            self::AfterSales => 'After-sales / service',
            self::Complaint => 'Complaint',
            self::Collection => 'Collection / delivery',
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
            fn (self $purpose) => ['value' => $purpose->value, 'label' => $purpose->label()],
            self::cases(),
        );
    }
}
