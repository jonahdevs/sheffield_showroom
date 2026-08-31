<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One reward from the catalogue, put into one campaign.
 *
 * The attachment rather than the reward. What the thing *is* - its name, its
 * worth, its terms - is written once in `rewards` and not repeated here; this
 * table answers only the questions a campaign gets to decide for itself: how
 * many, for how long, and whether it is switched on.
 *
 * It keeps its name and its `id` through that change on purpose. Every unit in
 * `reward_pool_entries` points at a row here, the claim statement narrows by
 * `campaign_reward_id`, and `shuffle_results.expires_at` is stamped from
 * `validity_days` below. Those are the load-bearing parts of the shuffle and
 * they are untouched by rewards having moved up into a catalogue.
 *
 * `quantity` is not the number remaining and never falls. What is left is
 * counted off the pool, because that is the only place that can answer it
 * correctly while somebody else is claiming a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_rewards', function (Blueprint $table) {
            $table->id();

            /* Cascaded: an attachment has no meaning without its campaign, and
               a campaign that has published a pool cannot be deleted anyway -
               `shuffle_sessions` and the pool entries hold it in place. */
            $table->foreignId('campaign_id')
                ->constrained('reward_campaigns')
                ->cascadeOnDelete();

            /* Restricted, unlike the campaign above: deleting a reward that a
               campaign is handing out would leave winners holding a result
               that can no longer say what they won. Retire it with
               `rewards.is_active` instead. */
            $table->foreignId('reward_id')
                ->constrained('rewards')
                ->restrictOnDelete();

            /* How many units go into the pool at publication. Refused at zero
               by `RewardCampaignRequest`; a reward worth nothing to anybody is
               one that should not be in the campaign. */
            $table->unsignedInteger('quantity');

            /* Days from winning until it lapses, copied down from
               `rewards.default_validity_days` when the reward is attached and
               editable per campaign afterwards. Null means it does not lapse.

               Stamped onto the result when it is won rather than read back
               later, so editing this never moves a deadline somebody already
               has. */
            $table->unsignedInteger('validity_days')->nullable();

            /* Off without being detached. An attachment with winners must not
               be removed - see `PoolEntryStatus::Void` for taking the unwon
               units off the table. */
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            /* One attachment per reward per campaign. Two rows for the same
               reward would be two drawers of the same thing, and every count
               the campaign screen prints would have to know to add them up. */
            $table->unique(['campaign_id', 'reward_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_rewards');
    }
};
