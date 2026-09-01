<?php

declare(strict_types=1);

namespace App\Services\Visits;

use App\Enums\CustomerSource;
use App\Enums\VisitPurpose;
use App\Services\Customers\LegacyExtract;

/**
 * The old system had no visits table - the front desk used the customer's `notes`
 * column as a day book, and this reads those notes back out as visits.
 */
class LegacyVisitLog
{
    /**
     * The log's own vocabulary, so a note ending on "Admin" is not read as a person. A
     * wrong name against a visit is worse than none - nobody goes looking for it.
     *
     * @var list<string>
     */
    private const NOT_A_NAME = [
        'showroom', 'visit', 'cold', 'coldroom', 'room', 'rational', 'laundry',
        'accounts', 'account', 'cheque', 'collection', 'logistics', 'service',
        'repair', 'installation', 'purchasing', 'purchase', 'sales', 'horeca',
        'hr', 'admin', 'imports', 'import', 'stores', 'production', 'marketing',
        'security', 'design', 'meeting', 'delivery', 'interview', 'inquiry',
        'enquiry', 'individual', 'equipment', 'documents', 'invoice', 'invoices',
        'samples', 'toner', 'printer', 'welder', 'job', 'meter', 'reading',
        'training', 'attachment', 'internship', 'inspection', 'payment',
    ];

    # Strict on purpose: "Som nath" is real staff and is missed, which is the price of
    # "Meter reading" not being read as a person.
    private const NAME = '[A-Z][a-z\']+(?:[ \t][A-Z][a-z\']+)?';

    /**
     * No `i` flag over the whole pattern: the capture must stay case-sensitive, or
     * `[A-Z][a-z]+` matches any word and "meeting the supplier" records a staff member.
     *
     * @var list<string>
     */
    private const RESPONDENT_CUES = [
        '/\b(?i:attended to by)[ \t]+(NAME)/',
        '/\b(?i:received by)[ \t]+(NAME)/',
        '/\b(?i:assisted by)[ \t]+(NAME)/',
        '/\b(?i:served by)[ \t]+(NAME)/',
        '/\b(?i:met by)[ \t]+(NAME)/',
        '/\b(?i:meeting)[ \t]+(NAME)/',
        '/\b(NAME)[ \t]+(?i:dealt with them)/',
    ];

    public function __construct(private readonly LegacyExtract $customers) {}

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     unlogged: int,
     *     without_customer: list<int|null>,
     *     purposes: array<string, int>,
     *     respondents: int,
     * }
     */
    public function transform(string $json): array
    {
        $rows = [];
        $unlogged = 0;
        $withoutCustomer = [];

        foreach ($this->customers->extractedRows($json) as $source) {
            if ($this->note($source) === null) {
                $unlogged++;

                continue;
            }

            # `visits.customer_id` is not nullable, so a note against a row the customer
            # import turned down has nowhere to go.
            if ($this->customers->toSeedRow($source) === null) {
                $withoutCustomer[] = $this->legacyId($source);

                continue;
            }

            $row = $this->toSeedRow($source);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return [
            'rows' => $rows,
            'unlogged' => $unlogged,
            'without_customer' => $withoutCustomer,
            'purposes' => $this->purposeCounts($rows),
            'respondents' => count(array_filter($rows, fn (array $row): bool => $row['respondent'] !== null)),
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>|null
     */
    public function toSeedRow(array $source): ?array
    {
        $note = $this->note($source);
        $legacyId = $this->legacyId($source);
        $visitedAt = $this->text($source['created_at'] ?? null);

        # A visit with no `visited_at` appears on no list - everything orders by it.
        if ($note === null || $legacyId === null || $visitedAt === null) {
            return null;
        }

        return [
            'legacy_id' => $legacyId,

            'visited_at' => $visitedAt,

            'purpose' => $this->purposeFor($note)->value,

            'source' => CustomerSource::WalkIn->value,

            'respondent' => $this->respondentIn($note),

            'notes' => $note,

            'expected_follow_up_on' => null,

            # Must stay null: `visits.view.own` scopes the list by this column, so
            # stamping the seeder's account drops the whole imported log into it.
            'created_by' => null,

            'created_at' => $visitedAt,
            'updated_at' => $this->text($source['updated_at'] ?? null) ?? $visitedAt,
        ];
    }

    public function purposeFor(string $note): VisitPurpose
    {
        $purpose = $this->departmentPurpose(mb_strtolower($this->department($note)));

        # Before the department: two of these were filed against the laundry desk, and
        # a candidate counted as a product viewing is a sale the showroom never made.
        if ($this->readsAsRecruitment($note)) {
            return VisitPurpose::Other;
        }

        if (($purpose === null || $purpose === VisitPurpose::ProductViewing) && $this->readsAsEnquiry($note)) {
            return VisitPurpose::NewEnquiry;
        }

        return $purpose ?? VisitPurpose::Other;
    }

    # Bare names are read from the end backwards - where a note holds both an errand
    # and a name, the name is written last.
    public function respondentIn(string $note): ?string
    {
        foreach (self::RESPONDENT_CUES as $cue) {
            if (preg_match(str_replace('NAME', self::NAME, $cue), $note, $matches) === 1) {
                return $this->honorificRemoved($matches[1]);
            }
        }

        foreach (array_reverse($this->nameCandidates($note)) as $candidate) {
            if ($this->isName($candidate)) {
                return $this->honorificRemoved($candidate);
            }
        }

        return null;
    }

    /**
     * The arms are read top to bottom and the first hit wins, which is what settles the
     * overlaps - a collection of a cheque is money at the accounts window, not goods off
     * the yard. Do not reorder them.
     *
     * @return VisitPurpose|null Null when the leading text names no department this knows.
     */
    private function departmentPurpose(string $department): ?VisitPurpose
    {
        return match (true) {
            $this->mentions($department, ['showroom', 'cold room', 'coldroom', 'laundry', 'rational']) => VisitPurpose::ProductViewing,

            $this->mentions($department, ['cheque', 'account']) => VisitPurpose::Collection,
            $this->mentions($department, ['logistic', 'collection']) => VisitPurpose::Collection,

            $this->mentions($department, ['service', 'repair', 'installation']) => VisitPurpose::AfterSales,

            $this->mentions($department, ['purchas', 'sales', 'horeca']) => VisitPurpose::Order,

            $this->mentions($department, [
                'hr', 'admin', 'import', 'production', 'marketing',
                'secur', 'design', 'meeting', 'deliver', 'interview',
            ]) => VisitPurpose::Other,

            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function nameCandidates(string $note): array
    {
        $candidates = [];

        foreach (explode("\n", $note) as $line) {
            foreach (explode('-', $line) as $segment) {
                $segment = trim($segment, " \t.,");

                if ($segment !== '') {
                    $candidates[] = $segment;
                }
            }
        }

        return $candidates;
    }

    private function isName(string $candidate): bool
    {
        if (preg_match('/^'.self::NAME.'$/', $candidate) !== 1) {
            return false;
        }

        foreach (explode(' ', mb_strtolower($candidate)) as $word) {
            if (in_array($word, self::NOT_A_NAME, strict: true)) {
                return false;
            }
        }

        return true;
    }

    private function honorificRemoved(string $name): string
    {
        return trim((string) preg_replace('/^(?i:mr|mrs|ms|dr)\.?[ \t]+/', '', $name));
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

    /**
     * Line endings only - the spelling and spacing are the only record of what was meant.
     *
     * @param  array<string, mixed>  $source
     */
    private function note(array $source): ?string
    {
        $note = $this->text($source['notes'] ?? null);

        return $note === null ? null : trim(str_replace(["\r\n", "\r"], "\n", $note));
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function legacyId(array $source): ?int
    {
        $id = $this->text($source['id'] ?? null);

        return $id !== null && ctype_digit($id) ? (int) $id : null;
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function purposeCounts(array $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $purpose = (string) $row['purpose'];
            $counts[$purpose] = ($counts[$purpose] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }
}
