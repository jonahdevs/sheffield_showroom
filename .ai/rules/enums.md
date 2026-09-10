---
paths:
  - 'app/Enums/{VisitPurpose,VisitDepartment,CustomerSource,VisitorType}.php'
  - app/Enums/CustomerSegment.php
---

# Enums

## visits.purpose, .source, .department and .visitor_type are free text, never enum casts
Only `source` still takes typed text: its Other box is the one left. `purpose` and `department` are menu-only on write - `VisitRequest` checks both with `Rule::in(...::values())` and the form has no Other box for either, because reception was filing real errands under Other as prose that no filter or chart could group. `visitor_type` never had one.

All four columns stay uncast all the same. Rows written before those boxes were withdrawn still hold typed values, `VisitDepartment::RETIRED` still holds desks taken off the menu, and retiring a case while rows hold it must read as what somebody wrote rather than throw on every read. `Rule::in` over `Rule::enum` for the same reason: the value is checked without being cast.

Never put an enum cast on them in `Visit`: deleting a case while rows still hold it makes every read throw `"..." is not a valid backing value`. That took the app down once already. `@property` stays `string`, and factories write `->value`, never enum instances.

Read a stored value back through the enum's `readable()` (`tryFrom($value)?->label() ?? $value`). Anything grouping by one of these columns must iterate the buckets the query returned, not `::cases()`, or typed values vanish from the chart while still counting toward the total the shares divide by — see `DashboardController::breakdown()`. Filters take any string, clipped with `mb_substr(trim(...), 0, 120)`, including on `purpose` and `department` - the rows holding a typed value are exactly the ones somebody needs to look up.

A visit still holding a typed purpose or a retired desk opens on Other when it is edited: `admin/visits/Form.vue` runs both through `storedChoice`, which is what stops the form posting back a value the request would now reject.

`visits.referred_by` is deliberately NOT folded into `source`: a referral is still "Referral", and who made it is a second fact. `VisitRequest` requires it for a referral and prohibits it otherwise, and `visitAttributes()` writes it unconditionally because `prohibited` leaves the key out of `validated()` and nothing would otherwise clear it.</note>
</invoke>

## customers.segment is free text, never an enum cast
`customers.segment` (the old `industry` column, renamed) stores whatever the user typed under "Other". `CustomerSegment` is the menu the form suggests, not a closed set.

Never cast it on `Customer`: deleting a case while rows still hold it makes every read throw `"..." is not a valid backing value`. `@property` stays `string|null`, and `CustomerFactory` writes `->value`, never enum instances. Read a stored value back through `CustomerSegment::readable()`.

The extract's own column is still spelled `industry` - `database/data/customers.json` is the record of what was handed over and is never rewritten - so `LegacyExtract::toSeedRow` reads `$source['industry']` and writes `segment`. `LegacyExtract::segment()` folds legacy spellings through `CustomerSegment::match()` and drops a customer *type* ("INDIVIDUAL") that the old book's typists put in this column; anything else is kept as typed.

`CustomerExport` prints the label, not the stored value, because that sheet is also the import template and every label round-trips through `match()`.</note>
</invoke>
