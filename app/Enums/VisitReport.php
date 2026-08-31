<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Role;
use App\Models\User;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Which visit log somebody gets when they press Export.
 *
 * The list on the screen is one thing and the file off it is another. Everyone
 * looking at the visits page is reading the same rows, but what they need to
 * carry away from it differs by desk: the floor wants the write-up and the
 * follow-up date, and the front desk wants a sheet it can put beside a phone.
 *
 * Chosen by role rather than offered as a menu - see `forViewer()`. A report is
 * a column set and nothing more: every one of them is still the same filtered,
 * already-authorised query, mapped through the same `VisitRowData`, so no
 * report can show a row the viewer could not already see on the page.
 */
#[TypeScript]
enum VisitReport: string
{
    /** The log as the showroom keeps it, write-up and all. */
    case Full = 'full';

    /** The front desk's sheet: who is coming in, and who to put them with. */
    case Reception = 'reception';

    /**
     * The columns this report carries, in order, as key => heading.
     *
     * The key names a field `VisitExport` knows how to read; the heading is
     * what somebody sees at the top of the column. Both live here so that
     * adding a report is one case rather than an edit in three files.
     *
     * `Full` keeps the headings it has always had. A download that quietly
     * renamed its columns would break every spreadsheet somebody has already
     * built on top of it.
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
                'source' => 'Source',
                'date' => 'Date',
                'time' => 'Time',
                'products' => 'Products shown',
                'respondent' => 'Respondent',
                'follow_up' => 'Follow-up due',
                'notes' => 'Notes',
            ],

            /* Deliberately without the notes. That column is the write-up of
               what was actually said on the floor - it is the reason the full
               export exists - and it is not the front desk's to read. The
               source, the date window and the products shown are left off for
               a plainer reason: none of them helps somebody decide who to walk
               a visitor over to. */
            self::Reception => [
                'customer_name' => 'Visitor name',
                'customer_company' => 'Company',
                'customer_phone' => 'Contact',
                'purpose' => 'Nature of visit',
                'respondent' => 'Respondent',
            ],
        };
    }

    /** The sheet's tab, and the heading printed on the PDF. */
    public function title(): string
    {
        return match ($this) {
            self::Full => 'Visits',
            self::Reception => 'Reception visits',
        };
    }

    /** The downloaded file's name, before its date and extension. */
    public function basename(): string
    {
        return match ($this) {
            self::Full => 'visits',
            self::Reception => 'reception-visits',
        };
    }

    /**
     * The report this viewer's role calls for.
     *
     * Named rather than derived from permissions, the same way super admin is:
     * reception's sheet is narrower than their permissions are, so no subset
     * test over what they may do could arrive at it.
     *
     * The narrow sheet goes only to somebody whose whole job is the front
     * desk. A manager who has also been given the reception role still gets
     * the full log - a wider role must never be quietly narrowed by a second
     * one, or somebody loses the notes column and has no way to tell why.
     */
    public static function forViewer(User $viewer): self
    {
        $roles = $viewer->getRoleNames();

        return $roles->count() === 1 && $roles->first() === Role::RECEPTION
            ? self::Reception
            : self::Full;
    }
}
