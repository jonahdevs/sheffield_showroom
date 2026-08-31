---
paths:
  - 'database/data/**'
---

# Data

## The customer extract is a record, not a working file
`database/data/customers.json` is the raw phpMyAdmin export handed over from the old system. Never edit, move or regenerate it - it is the record of what was received.

`database/data/customers-seed.json` is derived from it and is what `CustomersSeeder` inserts. Do not hand-edit it either: change the mapping in `App\Services\Customers\LegacyExtract`, re-run `php artisan customers:prepare-seed`, and review the diff.

Rows with no dialable phone number (29 of 453) are deliberately left out - a record whose only contact detail is "N/A" cannot be matched against a returning visitor.

## Every phone number is stored as `+<country><subscriber>`
`LegacyExtract::phone` rewrites what it is given into the one shape `PhoneInput` produces: a plus, a country code, no separators, no trunk zero. `0722000111` becomes `+254722000111`.

A leading zero is a trunk prefix and Kenya is the country behind it - 419 of the 424 dialable extract rows are `07...` or `01...`. A number that already carries a `+` keeps its own code; a bare nine digits is a Kenyan national number typed without the zero; `00` is treated as the older spelling of the plus.

The database never needed this - `Customer::matchingPhone` compares stripped subscriber tails, so both spellings were already one telephone. The form did: it splits a stored value on its dialling code, and a value with no code to find opens on the wrong country with the trunk zero still in it.

`App\Imports\CustomerImport` shares `LegacyExtract` on purpose, so a CSV uploaded on the floor is normalised the same way. Change the rule in one place and both paths follow.

## visits-seed.json is derived from the same extract
`database/data/visits-seed.json` is the front-desk log read out of `customers.json`'s `notes` column by `App\Services\Visits\LegacyVisitLog`. Do not hand-edit it: change the mapping there, run `php artisan visits:prepare-seed`, and review the diff.

419 of the 448 notes become visits. The other 29 were written against records the customer import turns down for having no dialable phone, and `visits.customer_id` is not nullable, so they have nowhere to go. The command lists them by their old system id.
