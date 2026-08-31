---
paths:
  - 'database/migrations/**'
---

# Migrations

## Pre-launch schema changes are folded into the create migration
This folder holds one migration per table, not a history. While the application has not been deployed anywhere with real data, a schema change is made by editing the `create_*_table` migration in place — do not add an `add_*` / `restructure_*` file for a table whose create migration you can still edit. The published vendor migrations (`create_users_table`, `create_permission_tables`) carry our extra columns inline, each marked with a comment saying it is ours: re-publishing Fortify's or spatie's file would drop them.

This stops the moment a database exists that you cannot rebuild. From then on a change is a new migration, because editing a create that has already run does nothing to a database that already ran it.

An existing database is unaffected by a consolidation: the `create_*` files keep their original filenames and are already recorded as run, so `migrate` stays a no-op. The rows left in the `migrations` table naming deleted files are harmless, but `migrate:rollback` past them fails — rebuild with `migrate:fresh` instead.
