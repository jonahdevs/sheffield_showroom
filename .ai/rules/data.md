---
paths:
  - 'database/data/**'
---

# Data

## The customer extract is a record, not a working file
`database/data/customers.json` is the raw phpMyAdmin export handed over from the old system. Never edit, move or regenerate it - it is the record of what was received.

`database/data/customers-seed.json` is derived from it and is what `CustomersSeeder` inserts. Do not hand-edit it either: change the mapping in `App\Services\Customers\LegacyExtract`, re-run `php artisan customers:prepare-seed`, and review the diff.

Rows with no dialable phone number (29 of 453) are deliberately left out - a record whose only contact detail is "N/A" cannot be matched against a returning visitor.

## visits-seed.json is derived from the same extract
`database/data/visits-seed.json` is the front-desk log read out of `customers.json`'s `notes` column by `App\Services\Visits\LegacyVisitLog`. Do not hand-edit it: change the mapping there, run `php artisan visits:prepare-seed`, and review the diff.

419 of the 448 notes become visits. The other 29 were written against records the customer import turns down for having no dialable phone, and `visits.customer_id` is not nullable, so they have nowhere to go. The command lists them by their old system id.
