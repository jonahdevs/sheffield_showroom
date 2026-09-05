<?php

declare(strict_types=1);

namespace App\Services\Visits;

use App\Enums\VisitDepartment;
use App\Enums\VisitorType;
use App\Enums\VisitPurpose;

/**
 * Reads one front-desk note from the old system: which desk it was filed against,
 * what the errand was, and whether the caller was buying at all.
 *
 * Lifted out of `LegacyVisitLog` so `LegacyExtract` can ask the last question
 * without depending on it - the visit log already depends on the extract, and the
 * other way round would be a cycle the container cannot build.
 */
class VisitNote
{
    /** What `VisitRequest` accepts in `department`, so the backfill cannot outgrow the form. */
    private const MAX_DEPARTMENT = 120;

    public function purposeFor(string $note): VisitPurpose
    {
        $purpose = $this->departmentPurpose(mb_strtolower($this->department($note)));

        # Before the department: two of these were filed against the laundry desk, and
        # a candidate counted as a product viewing is a sale the showroom never made.
        # Other rather than a case of their own - `visitorTypeFor()` is where a
        # candidate is told apart from a buyer.
        if ($this->readsAsRecruitment($note)) {
            return VisitPurpose::Other;
        }

        if (($purpose === null || $purpose === VisitPurpose::ProductViewing) && $this->readsAsEnquiry($note)) {
            return VisitPurpose::Enquiry;
        }

        return $purpose ?? VisitPurpose::Other;
    }

    /**
     * Who the caller was, read off the note itself rather than off `purposeFor()`.
     *
     * Both of these errands land in `Other` now that neither has a purpose of its
     * own, and Other says nothing about who walked in - so the words are read here
     * directly. Only two say anything at all, and both say it plainly: the notes
     * behind them read "Accounts- Cheque collection" and "Delivery of invoices",
     * which is somebody being paid rather than paying, and "Interview" or
     * "internship vacancy". Everything else is left as a customer, because the old
     * book had no other kind and guessing wrongly buries a real one.
     */
    public function visitorTypeFor(string $note): VisitorType
    {
        if ($this->readsAsRecruitment($note)) {
            return VisitorType::JobApplicant;
        }

        return $this->mentions(mb_strtolower($this->department($note)), ['cheque', 'account'])
            ? VisitorType::Supplier
            : VisitorType::Customer;
    }

    /**
     * The desk the note was filed against, as a `VisitDepartment` where one of the
     * cases fits and as the leading text where none does - `visits.department` is
     * free text, so a desk the enum does not name is kept rather than dropped.
     *
     * @return string|null Null when the note names no desk at all.
     */
    public function departmentFor(string $note): ?string
    {
        $department = $this->department($note);

        $matched = $this->departmentCase(mb_strtolower($department));

        if ($matched !== null) {
            return $matched->value;
        }

        return $department === ''
            ? null
            : mb_substr($department, 0, self::MAX_DEPARTMENT);
    }

    /**
     * The arms are read top to bottom and the first hit wins, which is what settles the
     * overlaps - a collection of a cheque is money at the accounts window, not goods off
     * the yard. Do not reorder them.
     *
     * A cheque at that window is also the clearest sign the caller was never a customer:
     * somebody sent round to be paid is a supplier's clerk. The purpose no longer
     * carries that - the arm below answers Other like the rest of the desks that are
     * not shopping - so `visitorTypeFor()` reads the same words for itself. Change
     * the words in one place and change them in the other.
     *
     * @return VisitPurpose|null Null when the leading text names no department this knows.
     */
    private function departmentPurpose(string $department): ?VisitPurpose
    {
        return match (true) {
            $this->mentions($department, ['showroom', 'cold room', 'coldroom', 'laundry', 'rational']) => VisitPurpose::ProductViewing,

            # Ahead of the collection arm and staying there even though both answers
            # differ now: a cheque collection is money at the accounts window, and
            # reading it as goods off the yard would file 113 of these as a handover
            # that never happened.
            $this->mentions($department, ['cheque', 'account']) => VisitPurpose::Other,
            $this->mentions($department, ['logistic', 'collection']) => VisitPurpose::Collection,

            $this->mentions($department, ['service', 'repair', 'installation']) => VisitPurpose::AfterSales,

            $this->mentions($department, ['purchas', 'sales', 'horeca']) => VisitPurpose::Order,

            # Lifted out of the catch-all below because a run out to the customer is
            # its own errand. The word is disjoint from the ones left there, so this
            # reads the same way round as it did. "Interview" and "Meeting" are not
            # here: neither says what the errand was, so both stay in Other.
            $this->mentions($department, ['deliver']) => VisitPurpose::Delivery,

            $this->mentions($department, [
                'hr',
                'admin',
                'import',
                'production',
                'marketing',
                'secur',
                'design',
            ]) => VisitPurpose::Other,

            default => null,
        };
    }

    /**
     * Read top to bottom like `departmentPurpose()` and settling the same overlaps -
     * a cheque collection is money at the accounts window, not goods off the yard,
     * and "Import stores" is the imports desk rather than the stores. Do not reorder.
     *
     * @return VisitDepartment|null Null when the leading text names no desk this knows.
     */
    private function departmentCase(string $department): ?VisitDepartment
    {
        return match (true) {
            $this->mentions($department, ['cheque', 'account', 'finance']) => VisitDepartment::Accounts,

            $this->mentions($department, ['showroom', 'cold room', 'coldroom', 'laundry', 'rational']) => VisitDepartment::Showroom,

            $this->mentions($department, ['logistic', 'collection']) => VisitDepartment::Logistics,

            $this->mentions($department, ['installation']) => VisitDepartment::Installation,
            $this->mentions($department, ['service', 'repair']) => VisitDepartment::Service,

            $this->mentions($department, ['horeca']) => VisitDepartment::Horeca,
            $this->mentions($department, ['sales']) => VisitDepartment::Sales,

            $this->mentions($department, ['import']) => VisitDepartment::Imports,
            $this->mentions($department, ['purchas', 'store']) => VisitDepartment::Stores,

            $this->mentions($department, ['production']) => VisitDepartment::Production,
            $this->mentions($department, ['marketing']) => VisitDepartment::Marketing,
            $this->mentions($department, ['design']) => VisitDepartment::Design,
            $this->mentions($department, ['crm']) => VisitDepartment::Crm,
            $this->mentions($department, ['hr', 'interview']) => VisitDepartment::Hr,

            default => null,
        };
    }

    private function department(string $note): string
    {
        $parts = preg_split('/[-:\n]/', $note);

        return trim($parts === false ? $note : ($parts[0] ?? ''));
    }

    private function readsAsEnquiry(string $note): bool
    {
        return preg_match('/\b(?i:inquir|enquir)/', $note) === 1;
    }

    # A tight list on purpose: "Attachment" is HR's word for a student placement and
    # also the floor's word for the thing that bolts onto a mixer, so it is not here.
    private function readsAsRecruitment(string $note): bool
    {
        return preg_match('/\b(?i:interview|internship|vacancy)/', $note) === 1;
    }

    /**
     * @param  list<string>  $words
     */
    private function mentions(string $department, array $words): bool
    {
        foreach ($words as $word) {
            if (str_contains($department, $word)) {
                return true;
            }
        }

        return false;
    }
}
