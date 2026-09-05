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

    public function __construct(
        private readonly LegacyExtract $customers,
        private readonly VisitNote $notes = new VisitNote,
    ) {}

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

            # A caller who was buying needs the customer row this note's own record
            # became, and the import turns down anybody with no dialable number.
            # Everybody else carries their details on the visit and needs nothing.
            if ($this->isCustomerVisit($source) && $this->customers->toSeedRow($source) === null) {
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

        $visitor = $this->notes->visitorTypeFor($note);

        # Filled only for a caller who was not buying: they get no customer row, so
        # the visit is the only place their name can live. The invariant the schema
        # states, held here as it is in `VisitRequest`.
        $details = $visitor->isCustomer()
            ? ['visitor_name' => null, 'visitor_phone' => null, 'visitor_organisation' => null]
            : [
                'visitor_name' => $this->customers->displayNameFor($source),
                'visitor_phone' => $this->customers->phoneFor($source),
                'visitor_organisation' => $this->text($source['company_name'] ?? null),
            ];

        return [
            'legacy_id' => $legacyId,

            'visited_at' => $visitedAt,

            'visitor_type' => $visitor->value,
            ...$details,

            'purpose' => $this->purposeFor($note)->value,

            'department' => $this->departmentFor($note),

            'source' => CustomerSource::WalkIn->value,

            'referred_by' => null,

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
        return $this->notes->purposeFor($note);
    }

    /**
     * The desk the note was filed against - see `VisitNote`.
     *
     * @return string|null Null when the note names no desk at all.
     */
    public function departmentFor(string $note): ?string
    {
        return $this->notes->departmentFor($note);
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

    /**
     * @param  array<string, mixed>  $source
     */
    private function isCustomerVisit(array $source): bool
    {
        $note = $this->note($source);

        return $note === null || $this->notes->visitorTypeFor($note)->isCustomer();
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
