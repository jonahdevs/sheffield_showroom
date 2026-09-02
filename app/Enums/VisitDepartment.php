<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum VisitDepartment: string
{
    case Finance = 'finance';
    case ShowroomSales = 'showroom_sales';
    case Marketing = 'marketing';
    case Hr = 'hr';
    case Crm = 'crm';
    case It = 'it';
    case Design = 'design';
    case Imports = 'imports';
    case Logistics = 'logistics';
    case Horeca = 'horeca';
    case Production = 'production';
    case Stores = 'stores';
    case Service = 'service';
    case Installation = 'installation';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Finance => 'Finance',
            self::ShowroomSales => 'Showroom/Sales',
            self::Marketing => 'Marketing',
            self::Hr => 'HR',
            self::Crm => 'CRM',
            self::It => 'IT',
            self::Design => 'Design',
            self::Imports => 'Imports',
            self::Logistics => 'Logistics',
            self::Horeca => 'Horeca',
            self::Production => 'Production',
            self::Stores => 'Stores',
            self::Service => 'Service',
            self::Installation => 'Installation',
            self::Other => 'Other',
        };
    }

    # `visits.department` is free text: the cases above are the menu the form
    # offers, not a closed set, so a desk somebody typed is stored as written
    # and read back as written.
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
            fn (self $department) => ['value' => $department->value, 'label' => $department->label()],
            self::cases(),
        );
    }
}
