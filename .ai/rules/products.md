---
paths:
  - 'app/Services/Products/**'
---

# Products

## CatalogueSync must never set or overwrite ProductStatus::Inactive
`Inactive` is a local-only product status set by a person on the showroom floor. The main website has no equivalent field, so the sync must never assign it and must never overwrite it. `CatalogueSync::status()` returns null to mean "leave the local status alone" — do not collapse that null into a default.

The only exception is `prune()`: a product the website no longer offers is soft-deleted, so it is archived too, otherwise the status and `deleted_at` disagree.

A payload missing `is_published` / `deleted_at` is a feed with nothing to say, not a feed asserting Draft. New rows land Published; existing rows keep what they have. Mapping that silence onto Draft would take the whole catalogue offline.
