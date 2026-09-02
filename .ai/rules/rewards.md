---
paths:
  - 'app/Services/Rewards/**'
  - 'app/Models/{Reward,CampaignReward,RewardCampaign,RewardPoolEntry,ShuffleResult,ShuffleSession}.php'
  - app/Http/Controllers/Admin/RewardCampaignController.php
  - app/Http/Requests/Admin/RewardCampaignRequest.php
  - 'app/Policies/{RewardCampaignPolicy,RewardPolicy}.php'
  - 'resources/js/pages/admin/rewards/**'
  - 'database/migrations/*_{rewards,campaign_rewards,campaign_reward_product,reward_pool_entries,shuffle_results,shuffle_sessions}_table.php'
  - 'app/Http/Controllers/Admin/Reward{Winner,Redemption}Controller.php'
  - app/Services/Rewards/CampaignService.php
---

# Rewards

## Rewards live in a catalogue; `campaign_rewards` is the attachment
`rewards` is what the showroom can give away, described once and reused — name, type, worth, terms, and `product_id` when the reward is a thing off the floor (a tray, an accessory). `campaign_rewards` is one catalogue row put into one campaign, and carries only what a campaign decides: `quantity`, `validity_days`, `is_active`.

The pivot deliberately keeps the name `campaign_rewards` and its own `id`. Every unit in `reward_pool_entries` points at a row here, the claim statement narrows by `campaign_reward_id`, and `shuffle_results.expires_at` is stamped from `campaign_rewards.validity_days` — so the load-bearing parts of the shuffle were untouched by rewards moving up into a catalogue. Do not rename this table or repoint the pool at `rewards`.

`validity_days` is copied down from `rewards.default_validity_days` when the reward is attached, never read through at win time. Retuning the catalogue must not move a deadline a campaign has already promised.

`reward_id` is `restrictOnDelete` and unique per campaign. A reward *while a campaign holds it* cannot be deleted — retire it with `rewards.is_active`, which stops it going into anything new while leaving the campaigns already holding it alone. `RewardCampaignRequest` only refuses a retired reward that is being *added*, for that reason. Taking the attachment out of the campaign is the other way, and is allowed unless somebody has won one — see *The drawer is editable for the life of the campaign* below.

## The drawer is editable for the life of the campaign; a won reward is not
An attachment may be added or removed at any time, whatever state the campaign is in. An attachment with any claimed unit may never be removed: a `shuffle_results` row points at that unit, so erasing it would destroy the record — `reward_pool_entries` is `restrictOnDelete` from the results, and `RewardCampaignRequest::refuseRemovingWonRewards()` catches it first so the reward can be named instead of throwing. Everything else in the drawer is inventory, and inventory can be reshaped.

Publication does not close the drawer. It takes away exactly one field: **`quantity` is editable only while the campaign is a draft**, because `loaded` never falls and `loaded = available + claimed + void` must keep reconciling. An incoming quantity for an attachment the campaign already holds is *dropped*, not refused — a stale form is not an attack. Take unwon units off the table with `PoolEntryStatus::Void` instead, never by lowering a number.

`RewardCampaignController::writeRewards()` is a **diff, never a rewrite**: attachment ids are load-bearing, since `reward_pool_entries.campaign_reward_id` and the winners' results point at them and `shuffle_results.expires_at` was stamped from `campaign_rewards.validity_days`. A kept attachment is updated in place; a removed one is deleted and cascades its pool entries and `campaign_reward_product` rows; an added one on an *already published* campaign has its units written with it through `RewardPoolService::writeUnits()`, per attachment — calling `generate()` there would write a second pool for every attachment already standing.

`RewardPolicy::delete` is left alone by all of this: it already permits deleting a catalogue reward once nothing is attached to it, which is what makes tidying up possible at all.

## Deleting a campaign turns on turns, not on status
`RewardCampaignPolicy::delete` refuses a campaign that has any `shuffle_sessions` row, not a published one. A campaign nobody ever shuffled holds nothing but inventory and is disposable whatever state it is in; one with a turn behind it is history and is kept — stop it with `complete` instead. `shuffle_sessions.campaign_id` is `restrictOnDelete`, so the database draws the same line under the policy. `admin/rewards/Index.vue` mirrors it with `campaign.turns_given === 0`.

## Product pairing is resolved before the lock, never joined into it
A reward may name the products that qualify for it — buy the oven, win the tray — in `campaign_reward_product`, per campaign. **A reward naming no products qualifies against any purchase**, and that silence is the common case.

`ShuffleRewardService` resolves the qualifying `campaign_reward_id`s in one cheap query *before* the transaction takes any lock (`RewardEligibilityService::qualifyingRewardIds()`), then narrows the locking statement with a literal `whereIn`. Do not turn this into a join or a `whereHas` on the claim: the whole point of the denormalised `campaign_id` is that the hot statement reads one table through one index.

A purchase naming none of a reward's products draws only from the unpaired rewards. It has not met "buy the oven", and guessing otherwise hands the tray to anybody. What a sale names lives in `purchase_product` — see *A purchase names many products, through purchase_product* below. The sale is still an eligibility record and not a ledger, which is why that pivot carries no price and no quantity.

## The reward claim is one statement, and the unique indexes are the backstop
Claiming a reward must select and lock in the same statement — a randomised `lockForUpdate()` over `reward_pool_entries` where `campaign_id = ? and status = 'available'`. Do not follow the order in the architecture document ("lock an available entry, then randomly choose one"): locking first and choosing second lets two concurrent shuffles pick the same row.

`reward_pool_entries.campaign_id` is denormalised on purpose so that statement never joins — a lock across two tables on the hottest path in the app.

Three unique indexes are the safety net under the lock, and none of them may be dropped: `shuffle_results.shuffle_session_id` (one reward per turn), `shuffle_results.reward_pool_entry_id` (one unit per person), `shuffle_sessions.purchase_id` (one turn per sale — nullable, so staff-run sessions with no purchase still repeat freely). If the locking is ever wrong the second writer gets an integrity error, which is the correct failure.

`claimed` is one-way: a won unit never returns to the pool, even if the result is cancelled or expires. Use `PoolEntryStatus::Void` to take unwon units off the table — reporting counts void as loaded, which is what makes `loaded = available + claimed + void` reconcile.

Expiry is stamped onto `shuffle_results.expires_at` at win time from `campaign_rewards.validity_days`, never recomputed, so editing a definition cannot move a deadline somebody already has. `rewards:expire` (scheduled daily in bootstrap/app.php) only tidies statuses — `isShuffleable()` and `isRedeemable()` read the date, so an unswept row is already refused.

## A purchase names many products, through purchase_product
`purchases.product_id` is gone. A sale names any number of products through the `purchase_product` pivot (`Purchase::products()`), because one column could only ever be right by luck on a receipt carrying an oven and a coffee machine - whichever the salesperson clicked decided whether the paired tray went out.

Eligibility matches on **any** one of them: `RewardEligibilityService::qualifyingRewardIds($campaign, $productIds)` and `availableCountFor($campaign, $productIds)` take an array, and a reward paired to the oven qualifies as soon as the oven is on the sale, whatever else is beside it. Read the ids with `RewardEligibilityService::productIdsOn($purchase)`, which reads a loaded relation when there is one - a list that does not eager-load `products` pays a query per row. An empty array behaves exactly as the old null did: it draws only from the unpaired rewards, because a sale that recorded nothing has not met "buy the oven".

Still not a ledger. The pivot carries no price and no quantity, deliberately - it answers "which products were on this sale" and nothing else. `Purchase::products()` is `withTrashed()` so a withdrawn product still names itself on a historical sale; `CampaignReward::qualifyingProducts()` deliberately keeps the default scope, so a pairing to a withdrawn product stops qualifying anybody.

## The redemption counter is a dialog on the winners list, not a screen
`RewardRedemptionController` holds only `store`. The code lookup lives in `RewardWinnerController::lookup()` and ships as the `redeem` prop on `admin/rewards/Winners`, read from `?redeem=CODE` in the query string beside the list's own filters.

`RedeemRewardDialog.vue` looks a code up with `router.reload({ only: ['redeem'], data: { redeem } })`, so typing never redraws the table. The dialog's open state is derived from `redeem.searched`, not set on the click — `store` answers with `back()`, which rebuilds the page, and a click-set flag would close the dialog on the handover it just made.

## Cancelling a campaign is reversible; completing it is not
Publishing stays one-way — it writes the pool, and `RewardCampaignPolicy::publish` hides Publish the moment the status leaves draft. The way back into a stopped campaign is `activate()`, not a second publish.

`CampaignStatus::isFinal()` (Completed only) is what `activate()` refuses, so a **cancelled** campaign restarts on the pool it was called off with — no units are written, claimed ones stay claimed. `isClosed()` (Completed *or* Cancelled) still guards `moveTo()`, so pause/complete/cancel out of a closed campaign are refused; Restart stands alone.

Only one campaign is active at a time, so a restart still runs `refuseIfAnotherIsRunning()`. `admin/rewards/Form.vue` mirrors these by hand in `stateMoves` — a move the service would refuse must not be drawn.</note>
</invoke>
