---
paths:
  - 'app/Enums/{VisitPurpose,VisitDepartment,CustomerSource}.php'
  - app/Enums/CustomerSegment.php
---

# Enums

## visits.purpose, .source and .department are free text, never enum casts
All three columns store whatever the user typed under "Other". The enums are the menu the form suggests, not a closed set.

Never put an enum cast on them in `Visit`: deleting a case while rows still hold it makes every read throw `"..." is not a valid backing value`. That took the app down once already. `@property` stays `string`, and factories write `->value`, never enum instances.

Read a stored value back through the enum's `readable()` (`tryFrom($value)?->label() ?? $value`). Anything grouping by one of these columns must iterate the buckets the query returned, not `::cases()`, or typed values vanish from the chart while still counting toward the total the shares divide by — see `DashboardController::breakdown()`. Filters take any string, clipped with `mb_substr(trim(...), 0, 120)`.

`visits.referred_by` is deliberately NOT folded into `source`: a referral is still "Referral", and who made it is a second fact. `VisitRequest` requires it for a referral and prohibits it otherwise, and `visitAttributes()` writes it unconditionally because `prohibited` leaves the key out of `validated()` and nothing would otherwise clear it.</note>
</invoke>

## customers.segment is free text, never an enum cast
`customers.segment` (the old `industry` column, renamed) stores whatever the user typed under "Other". `CustomerSegment` is the menu the form suggests, not a closed set.

Never cast it on `Customer`: deleting a case while rows still hold it makes every read throw `"..." is not a valid backing value`. `@property` stays `string|null`, and `CustomerFactory` writes `->value`, never enum instances. Read a stored value back through `CustomerSegment::readable()`.

The extract's own column is still spelled `industry` - `database/data/customers.json` is the record of what was handed over and is never rewritten - so `LegacyExtract::toSeedRow` reads `$source['industry']` and writes `segment`. `LegacyExtract::segment()` folds legacy spellings through `CustomerSegment::match()` and drops a customer *type* ("INDIVIDUAL") that the old book's typists put in this column; anything else is kept as typed.

`CustomerExport` prints the label, not the stored value, because that sheet is also the import template and every label round-trips through `match()`.</note>
</invoke>
