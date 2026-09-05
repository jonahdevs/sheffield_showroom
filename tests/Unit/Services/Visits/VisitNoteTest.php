<?php

use App\Enums\VisitorType;
use App\Services\Visits\VisitNote;

function visitNote(): VisitNote
{
    return new VisitNote;
}

# The notes behind these are unambiguous about which way the money went:
# "Collection of cheque" is somebody being paid, and "Delivery of invoices" is
# somebody billing us. Both are a supplier's clerk, not a customer.
it('reads the errands that say the caller was never buying', function (string $note, VisitorType $visitor) {
    expect(visitNote()->visitorTypeFor($note))->toBe($visitor);
})->with([
    'a cheque collected at the accounts window' => [
        "Accounts- Cheque collection\nRachael",
        VisitorType::Supplier,
    ],
    'an invoice delivered to accounts' => [
        "Accounts\nDelivery of invoices\nRachael",
        VisitorType::Supplier,
    ],
    'an interview, whichever desk took it' => [
        'Laundry- Interview Christine',
        VisitorType::JobApplicant,
    ],
    'an internship enquiry' => [
        'HR-Inquiry on internship vacancy.',
        VisitorType::JobApplicant,
    ],
]);

# The old book had no other kind of record, so anything this cannot read plainly
# stays a customer - a wrong guess buries a real one.
it('leaves every other errand as a customer', function (string $note) {
    expect(visitNote()->visitorTypeFor($note))->toBe(VisitorType::Customer);
})->with([
    'a showroom enquiry' => ["Showroom\nInquiry on coffee machines\nColins"],
    'a collection off the yard' => ['Logistics-Collection of meat mincer'],
    'a repair' => ['Service- fryer repair'],
    'an order' => ["Purchasing\nDelivery of samples"],
    'a desk nobody recognises' => ['Wickerwork'],
]);

# `LegacyExtract` drops a row from the customer book on exactly this answer while
# `LegacyVisitLog` keeps the caller's name on the visit, so the two pipelines
# cannot disagree about who was a customer.
#
# The purpose says Other here on purpose - the menu has no case for a cheque run -
# which is why the visitor is read off the note rather than off the purpose.
it('still names the visitor when the errand itself files as Other', function () {
    $note = "Accounts- Cheque collection\nRachael";

    expect(visitNote()->purposeFor($note)->value)->toBe('other')
        ->and(visitNote()->visitorTypeFor($note))->toBe(VisitorType::Supplier);
});
