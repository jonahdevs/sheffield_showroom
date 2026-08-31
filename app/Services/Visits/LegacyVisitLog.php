<?php

declare(strict_types=1);

namespace App\Services\Visits;

use App\Enums\CustomerSource;
use App\Enums\VisitPurpose;
use App\Services\Customers\LegacyExtract;

/**
 * Reads the front-desk visit log out of the customer extract.
 *
 * The old system had no visits table. What it had was a `notes` column on the
 * customer, and the front desk used it as a day book: 448 of the 453 records
 * carry a line about why that person was in the building, who they came to
 * see, and which member of staff took them. That is a visit, written down
 * somewhere it could not be counted, and this reads it back out.
 *
 * A sibling of `LegacyExtract` rather than another method on it. The two read
 * the same export but answer different questions - that one turns the columns
 * of a row into a customer, this one reads the prose in one of them - and the
 * vocabulary below changes whenever the front desk's shorthand turns out to
 * mean something other than it looks, which has nothing to do with how a name
 * or a telephone number is mapped. What is genuinely shared is which rows the
 * export holds and which of them became customers, and both are asked of
 * `LegacyExtract` here rather than reimplemented: a row this cannot hang a
 * visit on is exactly a row that one refused to import.
 */
class LegacyVisitLog
{
    /**
     * Words that name a department or an errand rather than a person.
     *
     * A short line on its own is almost always the member of staff who took
     * the visit, which is how `respondent` gets filled in at all - but not
     * always: some notes end on "Admin", "Delivery" or "Cheque collection".
     * The log's own vocabulary is listed here so those lines are read as what
     * they are. A wrong name against a visit is worse than no name, because
     * nobody ever goes looking for the mistake.
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

    /**
     * One or two capitalised words and nothing else.
     *
     * Deliberately strict. "Som nath" is a member of staff and is missed by
     * it, which is the price of "Meter reading" and "Printer repair" not being
     * read as people.
     */
    private const NAME = '[A-Z][a-z\']+(?:[ \t][A-Z][a-z\']+)?';

    /**
     * A member of staff named in the middle of a sentence.
     *
     * The bare-name reading below catches most of the log; these lines put the
     * name inside the prose instead. Written without an `i` flag over the
     * whole pattern deliberately - the capture has to stay case-sensitive, or
     * `[A-Z][a-z]+` matches any word at all and "meeting the supplier" leaves
     * "the supplier" recorded as a member of staff.
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
     * The visit log, and what could not be read out of it.
     *
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

            /* A visit belongs to a customer or it belongs to nobody. The rows
               the customer import turned down for having no dialable number
               never became customers, and `visits.customer_id` is not
               nullable, so the note written against them cannot be imported
               however much it says. Listed rather than counted, so that a
               decision to rescue one of those customers by hand can be
               followed by a decision about its visit. */
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
     * One extract row as one `visits` row, or null when it holds no visit.
     *
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>|null
     */
    public function toSeedRow(array $source): ?array
    {
        $note = $this->note($source);
        $legacyId = $this->legacyId($source);
        $visitedAt = $this->text($source['created_at'] ?? null);

        /* Three things have to hold for this to be a visit: somebody wrote a
           note, the row can be tied back to the customer it became, and it
           happened at a knowable time. A visit with no `visited_at` cannot
           appear on any list in this application, which orders and groups by
           nothing else. */
        if ($note === null || $legacyId === null || $visitedAt === null) {
            return null;
        }

        return [
            'legacy_id' => $legacyId,

            /* When they came, which for these rows is when the front desk
               wrote them down: the note and the customer record were typed at
               the counter in the same breath. */
            'visited_at' => $visitedAt,

            'purpose' => $this->purposeFor($note)->value,

            /* A front-desk log is by definition somebody who walked in and was
               written down at the counter. Nothing in the extract says
               otherwise for any of them, and reading a referral or a website
               enquiry out of the prose would be inventing the one figure this
               column exists to report. */
            'source' => CustomerSource::WalkIn->value,

            'respondent' => $this->respondentIn($note),

            /* The whole note, not the part this understood. Where the
               department maps onto a purpose only approximately - a cheque
               collection recorded as `Collection`, a job interview as `Other`
               - the sentence is what says what actually happened, and this is
               the only copy of it. */
            'notes' => $note,

            /* The extract has nothing to say about it, and a follow-up date
               nobody set would put 448 chases in somebody's diary. */
            'expected_follow_up_on' => null,

            /* Nobody in this application logged these. Stamping them with the
               admin account that happens to run the seeder would credit 448
               visits to somebody who took none of them, and because
               `visits.view.own` scopes the list by this column it would drop
               the whole imported log into that one person's visits. */
            'created_by' => null,

            /* Written when they came, like the customer beside them. Stamping
               today would put a spike of 448 visits on the day of the import
               in front of anybody counting by when a visit was logged. */
            'created_at' => $visitedAt,
            'updated_at' => $this->text($source['updated_at'] ?? null) ?? $visitedAt,
        ];
    }

    /**
     * What the visit was about.
     *
     * The department is whatever stands in front of the first dash, colon or
     * line break; everything after it is the errand. Where the department is
     * one this does not know, the note is read for an enquiry before falling
     * back to Other - "Inquiry on coffee machine & ice cube makers" is a
     * showroom visit that whoever typed it forgot to write "Showroom" in front
     * of.
     */
    public function purposeFor(string $note): VisitPurpose
    {
        $purpose = $this->departmentPurpose(mb_strtolower($this->department($note)));

        /* Somebody came about a job. Two of these were written against the
           laundry desk rather than HR, and a candidate sitting an interview
           counted as a product viewing is a sale the showroom never made. */
        if ($this->readsAsRecruitment($note)) {
            return VisitPurpose::Other;
        }

        /* An enquiry and a viewing are both somebody standing on the floor,
           but only one of them is a customer with a question nobody has
           answered yet. The note tells them apart and the department cannot. */
        if (($purpose === null || $purpose === VisitPurpose::ProductViewing) && $this->readsAsEnquiry($note)) {
            return VisitPurpose::NewEnquiry;
        }

        return $purpose ?? VisitPurpose::Other;
    }

    /**
     * The member of staff who took the visit, where the note names one.
     *
     * Most notes end on a bare first name - "Rachael", "Som Nath", "Colins" -
     * and a handful say it in a sentence instead. Both readings are tried, the
     * sentence first because it is unambiguous, and every candidate is held to
     * the same strict test: one or two capitalised words that are not part of
     * the log's own vocabulary. Anything else is left null.
     *
     * The bare names are read from the end of the note backwards, because
     * where a note holds both an errand and a name the name is written last.
     */
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
     * Every note opens with the department the visitor came to see, and it is
     * the department - not the sentence after it - that says what kind of call
     * this was. The nearest `VisitPurpose` is recorded and the note kept
     * verbatim beside it, because several of these mappings are close rather
     * than exact and the sentence is the only thing that can settle an
     * argument about one later.
     *
     * Matched on a contained word rather than an exact token, because the log
     * was typed by hand: "Cheque collection", "Collection of cheque" and
     * "Accounts- Cheque collection" are one errand written three ways, and
     * "Delivery of documents for imports department" is the imports desk with
     * the errand in front of it. Order settles the overlaps - a collection of
     * a cheque is money at the accounts window, not goods off the yard - so
     * the groups are read top to bottom and the first hit wins.
     *
     * Two of these say something other than the department name alone would
     * suggest, and both were settled against the notes themselves. Logistics
     * is fifteen lines of "Collection of equipment" and a delivery, so it is a
     * collection rather than a piece of back-office traffic. Laundry is the
     * laundry-equipment half of the showroom floor, asked about in the same
     * words and by the same staff as the kitchen half, so it is shown the same
     * way.
     *
     * @return VisitPurpose|null Null when the leading text names no department
     *                           this knows, which is left for the caller to
     *                           read further.
     */
    private function departmentPurpose(string $department): ?VisitPurpose
    {
        return match (true) {
            /* The floor. Somebody came in to look at equipment. */
            $this->mentions($department, ['showroom', 'cold room', 'coldroom', 'laundry', 'rational']) => VisitPurpose::ProductViewing,

            /* The window where money and goods change hands. */
            $this->mentions($department, ['cheque', 'account']) => VisitPurpose::Collection,
            $this->mentions($department, ['logistic', 'collection']) => VisitPurpose::Collection,

            /* The workshop: something already sold has come back. */
            $this->mentions($department, ['service', 'repair', 'installation']) => VisitPurpose::AfterSales,

            /* Business being placed in one direction or the other - a supplier
               at the purchasing desk, a hotel buyer at sales or HORECA. Order
               is the nearest thing on a list drawn up for the showroom. */
            $this->mentions($department, ['purchas', 'sales', 'horeca']) => VisitPurpose::Order,

            /* The rest of the building. These are real visitors and they are
               imported, but none of them came about a sale, and filing them as
               anything but Other would put staff interviews and cheque runs
               into the figures the showroom is judged by. */
            $this->mentions($department, [
                'hr', 'admin', 'import', 'production', 'marketing',
                'secur', 'design', 'meeting', 'deliver', 'interview',
            ]) => VisitPurpose::Other,

            default => null,
        };
    }

    /**
     * Every piece of a note that could be somebody's name on its own.
     *
     * A note is lines, and a line is often "Department- Name" or even
     * "Coldroom- Alphonse-Inquiry on coldroom solution", so each line is cut
     * at its dashes as well and the pieces judged one at a time.
     *
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

    /**
     * "Mr Hezekiah" and "Hezekiah" are one man, and a count of who took the
     * most visits should not show them as two.
     */
    private function honorificRemoved(string $name): string
    {
        return trim((string) preg_replace('/^(?i:mr|mrs|ms|dr)\.?[ \t]+/', '', $name));
    }

    /**
     * The text in front of the errand: whatever precedes the first dash, colon
     * or line break.
     */
    private function department(string $note): string
    {
        $parts = preg_split('/[-:\n]/', $note);

        return trim($parts === false ? $note : ($parts[0] ?? ''));
    }

    /**
     * Whether the note reads as somebody asking about something.
     *
     * Both spellings, because the log says "Inquiry" throughout and nothing
     * makes the next person to type into it do the same.
     */
    private function readsAsEnquiry(string $note): bool
    {
        return preg_match('/\b(?i:inquir|enquir)/', $note) === 1;
    }

    /**
     * Whether the note is about somebody wanting work rather than equipment.
     *
     * A tight list on purpose. "Attachment" is the word HR uses for a student
     * placement and also the word the floor uses for the thing that bolts onto
     * a mixer, so it is not here.
     */
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
     * The note as written, with only its line endings put right.
     *
     * The export carries Windows line endings, and a note stored with them
     * shows a stray character in every textarea it is opened in. Nothing else
     * about the text is touched: the double spaces and the spelling are how
     * the front desk wrote it, and this is the only record of what was meant.
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
     * How many visits landed on each purpose, largest first.
     *
     * Reported because it is the one number that says whether the department
     * reading is still working: a change that quietly sends everything to
     * Other shows up here and nowhere else.
     *
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
