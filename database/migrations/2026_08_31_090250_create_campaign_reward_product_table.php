<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

# =========================================================================
# Which purchases earn which reward: buy the oven, win the tray
# =========================================================================
#
# A reward with no rows here qualifies against any purchase, and that silence
# is the common case. Read before the claim, never during it: do not turn this
# into a join or a `whereHas` on the locking statement.

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_reward_product', function (Blueprint $table) {
            $table->id();

            $table->foreignId('campaign_reward_id')
                ->constrained('campaign_rewards')
                ->cascadeOnDelete();

            # Cascaded, so a force-deleted product takes its pairings with
            # it and the reward - paired to nothing - goes back to qualifying
            # against any purchase. The alternative is a reward nobody can
            # ever win.
            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unique(['campaign_reward_id', 'product_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_reward_product');
    }
};
