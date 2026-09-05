<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum VisitDepartment: string
{
    case Accounts = 'accounts';
    # Two desks, not one: the floor that shows the equipment and the desk that
    # closes the sale. Rows written while they shared an option still read
    # 'showroom_sales' - see `RETIRED`.
    case Showroom = 'showroom';
    case Sales = 'sales';
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
            self::Accounts => 'Accounts',
            self::Showroom => 'Showroom',
            self::Sales => 'Sales',
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

    /**
     * Values taken off the menu that rows still hold. Named here rather than kept
     * as cases, so the form stops offering a desk that no longer exists while the
     * visits already filed under it keep reading as something a person wrote.
     * Nothing is migrated: the column is free text and only the people who took
     * those visits know which half of the old joint desk each one belonged to.
     *
     * @var array<string, string>
     */
    private const RETIRED = [
        'showroom_sales' => 'Showroom/Sales',
        'finance' => 'Finance',
    ];

    # `visits.department` is free text: the cases above are the menu the form
    # offers, not a closed set, so a desk somebody typed is stored as written
    # and read back as written.
    public static function readable(string|self $value): string
    {
        if ($value instanceof self) {
            return $value->label();
        }

        return self::tryFrom($value)?->label()
            ?? self::RETIRED[$value]
            ?? $value;
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
