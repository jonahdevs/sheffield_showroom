<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Role;
use App\Models\User;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

# A report is a column set only: every one runs the same filtered, already-authorised
# query, so no report can show a row the viewer could not already see.
#[TypeScript]
enum VisitReport: string
{
    case Full = 'full';

    case Reception = 'reception';

    /**
     * The columns this report carries, in order, as key => heading.
     *
     * `Full` keeps the headings it has always had — renaming them breaks every
     * spreadsheet already built on the download.
     *
     * @return array<string, string>
     */
    public function columns(): array
    {
        return match ($this) {
            self::Full => [
                'id' => 'ID',
                'customer_name' => 'Customer',
                'customer_company' => 'Company',
                'customer_type' => 'Type',
                'customer_phone' => 'Phone',
                'purpose' => 'Nature of visit',
                'department' => 'Department',
                'source' => 'Source',
                'date' => 'Date',
                'time' => 'Time',
                'products' => 'Products shown',
                'respondent' => 'Respondent',
                'follow_up' => 'Follow-up due',
                'notes' => 'Notes',
            ],

            # `department` is on it because routing a caller to a desk is the
            # front desk's own job. The write-up sits beside the purpose rather
            # than instead of it: the menu label says what kind of visit it was,
            # the notes say what was actually asked for.
            self::Reception => [
                'customer_name' => 'Visitor name',
                'customer_company' => 'Company',
                'customer_phone' => 'Contact',
                'department' => 'Department',
                'purpose' => 'Nature of visit',
                'notes' => 'Visit notes',
                'respondent' => 'Respondent',
            ],
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::Full => 'Visits',
            self::Reception => 'Reception visits',
        };
    }

    public function basename(): string
    {
        return match ($this) {
            self::Full => 'visits',
            self::Reception => 'reception-visits',
        };
    }

    # Named, never derived: reception's sheet is narrower than their permissions,
    # so no subset test could reach it. Reception-and-nothing-else only — a wider
    # role must never be quietly narrowed by a second one.
    public static function forViewer(User $viewer): self
    {
        $roles = $viewer->getRoleNames();

        return $roles->count() === 1 && $roles->first() === Role::RECEPTION
            ? self::Reception
            : self::Full;
    }
}
