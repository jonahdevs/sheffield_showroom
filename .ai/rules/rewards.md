---
paths:
  - 'app/Services/Rewards/**'
---

# Rewards

## The reward claim is one statement, and the unique indexes are the backstop
Claiming a reward must select and lock in the same statement — a randomised `lockForUpdate()` over `reward_pool_entries` where `campaign_id = ? and status = 'available'`. Do not follow the order in the architecture document ("lock an available entry, then randomly choose one"): locking first and choosing second lets two concurrent shuffles pick the same row.

`reward_pool_entries.campaign_id` is denormalised on purpose so that statement never joins — a lock across two tables on the hottest path in the app.

Three unique indexes are the safety net under the lock, and none of them may be dropped: `shuffle_results.shuffle_session_id` (one reward per turn), `shuffle_results.reward_pool_entry_id` (one unit per person), `shuffle_sessions.purchase_id` (one turn per sale — nullable, so staff-run sessions with no purchase still repeat freely). If the locking is ever wrong the second writer gets an integrity error, which is the correct failure.

`claimed` is one-way: a won unit never returns to the pool, even if the result is cancelled or expires. Use `PoolEntryStatus::Void` to take unwon units off the table — reporting counts void as loaded, which is what makes `loaded = available + claimed + void` reconcile.

Expiry is stamped onto `shuffle_results.expires_at` at win time from `campaign_rewards.validity_days`, never recomputed, so editing a definition cannot move a deadline somebody already has. `rewards:expire` (scheduled daily in bootstrap/app.php) only tidies statuses — `isShuffleable()` and `isRedeemable()` read the date, so an unswept row is already refused.
