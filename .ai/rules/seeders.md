---
paths:
  - 'database/seeders/**'
---

# Seeders

## CustomersSeeder replaces the book but never deletes a visit
`visits.customer_id` is `constrained()->restrictOnDelete()`, so clearing the customers table fails on any customer a visit points at (soft-deleted visits included - the FK still holds).

`CustomersSeeder` force-deletes only the customers no visit references and leaves the rest in place, reporting them. Do not "fix" this by deleting visits or dropping the constraint: a stale customer row can be merged by hand, a lost visit is gone.

It writes with `Customer::insert()` rather than the model so the `created_at` each customer came over with survives; saving through Eloquent would stamp them all with the day the import ran.

## Half the visits carry no customer, so every visits query here needs whereNotNull
`visits.customer_id` is nullable: a caller who was not buying keeps their name on the visit and gets no customer row - see `.ai/rules/visits.md`. `CustomersSeeder::clearExisting()` builds its "spared" list from that column, and one null in it turns `whereNotIn('id', ...)` into `id NOT IN (1, 2, NULL)`, which SQL evaluates as never true. Nothing is cleared, and the re-insert then files every unvisited customer a second time - a silent duplicate on each re-seed, caught only by the count in `CustomersSeederTest`.

`VisitsSeeder` reads `visitor_type` for the same reason: a row that is not a customer visit is inserted with a null `customer_id` rather than looked up and reported as orphaned.

## Both imports are keyed on customers.legacy_id, and the order matters
`legacy_id` on `customers` and `visits` is the id the row had in the old system. It is what ties an imported visit to its customer: phone numbers are shared (46 numbers over 115 rows) and the keys the customers table hands out shift with whatever it was already holding.

`VisitsSeeder` must run after `CustomersSeeder`; a visit whose `legacy_id` matches no customer is left out and reported, never attached to the closest match.

Both are repeatable. `CustomersSeeder` skips a book row whose `legacy_id` is already on file, because once the visits are imported every customer is spared by the FK and would otherwise be inserted a second time. `VisitsSeeder` clears only `whereNotNull('legacy_id')`, so a visit logged on the floor since the migration survives a re-seed.
