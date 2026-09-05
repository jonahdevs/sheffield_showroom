<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Who reception is looking at. The front desk logs everybody who comes through
 * the door and most of them are not shopping - the runner sent to collect a
 * cheque, the courier, the candidate here for an interview.
 *
 * Only `Customer` puts a record in the customers table. Everybody else is written
 * on the visit itself (`visits.visitor_name` and friends), because somebody with
 * no telephone cannot be matched against next time and would file a fresh,
 * near-empty customer row on every call.
 */
#[TypeScript]
enum VisitorType: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Contractor = 'contractor';
    case Staff = 'staff';
    case Courier = 'courier';
    case JobApplicant = 'job_applicant';
    case Official = 'official';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::Supplier => 'Supplier',
            self::Contractor => 'Contractor',
            self::Staff => 'Staff',
            self::Courier => 'Courier',
            self::JobApplicant => 'Job applicant',
            self::Official => 'Official',
            self::Other => 'Other visitor',
        };
    }

    /** What somebody here for this reason is normally doing on the premises. */
    public function hint(): string
    {
        return match ($this) {
            self::Customer => 'Buying, or looking to',
            self::Supplier => 'Selling to us, or collecting payment',
            self::Contractor => 'Working on site for us',
            self::Staff => 'Ours, or a sister company\'s',
            self::Courier => 'Dropping off or picking up',
            self::JobApplicant => 'Interview, attachment or internship',
            self::Official => 'Inspection, utilities, a government office',
            self::Other => 'Anybody the list above does not cover',
        };
    }

    public function isCustomer(): bool
    {
        return $this === self::Customer;
    }

    # `visits.visitor_type` is free text, for the same reason `purpose` is: retiring
    # a case while rows still hold it must read as what somebody wrote rather than
    # throw on every read. The cases are the menu, not a cast.
    public static function readable(string|self $value): string
    {
        return $value instanceof self
            ? $value->label()
            : self::tryFrom($value)?->label() ?? $value;
    }

    /**
     * The one question reception is asked, as a single list.
     *
     * Customer is the only arm split by `CustomerType`, because buying for yourself
     * or for a firm is a distinction that only means something to somebody buying -
     * a courier is neither. The composite `value` is a form concern: `VisitRequest`
     * takes `visitor_type` and `customer_type` as two fields, and the select splits
     * its choice back into them.
     *
     * @return array<int, array{value: string, label: string, hint: string, visitor_type: string, customer_type: string|null}>
     */
    public static function menu(): array
    {
        $menu = [];

        foreach (CustomerType::cases() as $type) {
            $menu[] = [
                'value' => self::Customer->value.'_'.$type->value,
                'label' => 'Customer - '.mb_strtolower($type->label()),
                'hint' => $type === CustomerType::Company
                    ? 'Buying for the firm they work for'
                    : 'Buying in their own name',
                'visitor_type' => self::Customer->value,
                'customer_type' => $type->value,
            ];
        }

        foreach (self::cases() as $visitor) {
            if ($visitor->isCustomer()) {
                continue;
            }

            $menu[] = [
                'value' => $visitor->value,
                'label' => $visitor->label(),
                'hint' => $visitor->hint(),
                'visitor_type' => $visitor->value,
                'customer_type' => null,
            ];
        }

        return $menu;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, array{value: string, label: string, hint: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $visitor) => [
                'value' => $visitor->value,
                'label' => $visitor->label(),
                'hint' => $visitor->hint(),
            ],
            self::cases(),
        );
    }
}
