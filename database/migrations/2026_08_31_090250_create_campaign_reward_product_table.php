<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which purchases earn which reward: buy the oven, win the tray.
 *
 * A campaign attaches a reward and may then say what somebody has to have
 * bought to be in the running for it. Nothing here is the common case - a
 * reward with no rows in this table qualifies against any purchase at all, and
 * that silence is deliberate. Most promotions are "spend enough and shuffle";
 * this table is for the ones that pair a specific accessory to a specific
 * appliance.
 *
 * Per campaign rather than per reward: whether a tray is an oven's reward or
 * anybody's reward is a promotion's decision, and next month's promotion is
 * entitled to answer it differently.
 *
 * Read before the claim, never during it. `ShuffleRewardService` resolves the
 * qualifying `campaign_reward_id`s in a cheap query first and narrows the
 * locking statement with an `IN` - so the hot path stays one statement over
 * one table, which is what `reward_pool_entries` is shaped for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_reward_product', function (Blueprint $table) {
            $table->id();

            $table->foreignId('campaign_reward_id')
                ->constrained('campaign_rewards')
                ->cascadeOnDelete();

            /* Cascaded: a product removed from the floor takes its pairings
               with it. The reward stays, and with no pairings left it goes
               back to qualifying against any purchase - which is the right
               way to fail, because the alternative is a reward nobody can
               ever win and no screen explaining why. */
            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnDelete();

            /* Naming a product twice for one reward says nothing the first row
               did not. */
            $table->unique(['campaign_reward_id', 'product_id']);

            /* The lookup, from the other end: given the product somebody
               bought, which attachments does it qualify for. */
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_reward_product');
    }
};
