---
paths:
  - 'app/Services/Visits/**'
---

# Visits

## The old system's visit log lives in the customer extract's notes column
`database/data/customers.json` has no visits table. 448 of its 453 rows carry a front-desk note ("Showroom- Inquiry on ovens\r\nColins", "Accounts- Cheque collection\r\nRachael") and that note is the visit. `LegacyVisitLog` reads it: the text before the first dash/colon/newline is the department, mapped onto the nearest `VisitPurpose`, and the whole note is kept verbatim in `visits.notes` because several of those mappings are approximate.

Refine the department table there, not in the seeder, then re-run `php artisan visits:prepare-seed` and review the diff on `database/data/visits-seed.json`.

Respondent is only filled in when a note names somebody unambiguously - one or two capitalised words that are not in the log's own vocabulary. Keep it that way: a wrong name against a visit is worse than none, because nobody goes looking for the mistake.
