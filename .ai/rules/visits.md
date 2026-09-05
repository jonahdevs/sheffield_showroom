---
paths:
  - 'app/Services/Visits/**'
  - 'app/Models/Visit.php'
  - 'app/Http/Requests/Admin/VisitRequest.php'
  - 'app/Http/Controllers/Admin/VisitController.php'
  - 'database/migrations/*_create_visits_table.php'
---

# Visits

## Only a customer gets a row in `customers`; everybody else lives on the visit
Reception logs every caller and roughly a third of them are not shopping - the runner sent to collect a cheque, the courier, the candidate here for an interview. Those never reach the customers table. `visits.visitor_type` says which kind of caller it was, and `visitor_name` / `visitor_phone` / `visitor_organisation` hold their details.

The invariant, held by `VisitRequest` and by `LegacyVisitLog` for the imported log: **`visitor_type` reads `customer` if and only if `customer_id` is set, and the three `visitor_*` columns are filled if and only if it does not.** Both halves are written unconditionally on every save - a visit moved onto a customer has its `visitor_*` cleared and one moved off has `customer_id` nulled - or the row ends up with two answers to "who came in" and `Visit::visitorName()` reads the wrong one.

Do not "simplify" this into one table with a type column on `customers`. That was tried: because a non-customer's phone is optional, `Customer::matchingPhone` matches nobody and every courier visit files a fresh, near-empty customer row. You get the duplicates without the history.

`VisitRequest` refuses `customer_id`, `customer_type`, `email`, `id_number` and `segment` outright from a non-customer rather than accepting and dropping them - a stray id left on a form since switched to Courier would otherwise file that call against a customer who was never there. The form hides whatever the request would refuse.

## Reception is asked one question, not two
`VisitorType::menu()` is the single select: the customer arm split by `CustomerType` (`customer_individual`, `customer_company`) and every other visitor whole. The composite value is a form concern only - `admin/visits/Form.vue` splits it back into the `visitor_type` and `customer_type` fields the server takes. Do not add a second customer-type select; whether you buy for yourself or for a firm means nothing about a courier.

## Counting customers is not counting `customer_id`
`Visit::customerCount()` is the one definition, used by `DashboardController` and `VisitController` alike. `visits.created_by` and the `search` scope are qualified with the table name because the dashboard's product panels join `products`, which carries a `created_by` of its own.

## The note is read in one place, and both import pipelines share it
`VisitNote` reads a front-desk note: the desk, the errand, and whether the caller was buying. It is separate from `LegacyVisitLog` so `LegacyExtract` can ask the last question - the visit log already depends on the extract, and the other way round is a cycle the container cannot build.

A row `LegacyExtract::isCustomer()` drops from the book must be a visit `LegacyVisitLog` files as somebody else, and the other way round. Sharing `VisitNote` is what holds that; do not reimplement the reading in either.

## The old system's visit log lives in the customer extract's notes column
`database/data/customers.json` has no visits table. 448 of its 453 rows carry a front-desk note ("Showroom- Inquiry on ovens\r\nColins", "Accounts- Cheque collection\r\nRachael") and that note is the visit. `LegacyVisitLog` reads it: the text before the first dash/colon/newline is the department, mapped onto the nearest `VisitPurpose`, and the whole note is kept verbatim in `visits.notes` because several of those mappings are approximate.

Refine the department table there, not in the seeder, then re-run `php artisan visits:prepare-seed` and review the diff on `database/data/visits-seed.json`.

Respondent is only filled in when a note names somebody unambiguously - one or two capitalised words that are not in the log's own vocabulary. Keep it that way: a wrong name against a visit is worse than none, because nobody goes looking for the mistake.
