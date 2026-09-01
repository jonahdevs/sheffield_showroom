<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

# =========================================================================
# One reward from the catalogue, put into one campaign
# =========================================================================
#
# `quantity` is not the number remaining and never falls. What is left is
# counted off the pool, the only place that can answer it correctly while
# somebody else is claiming a row.

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_rewards', function (Blueprint $table) {
            $table->id();

            $table->foreignId('campaign_id')
                ->constrained('reward_campaigns')
                ->cascadeOnDelete();

            # Restricted, unlike the campaign above: deleting a reward a
            # campaign is handing out would leave winners holding a result
            # that can no longer say what they won.
            $table->foreignId('reward_id')
                ->constrained('rewards')
                ->restrictOnDelete();

            $table->unsignedInteger('quantity');

            # Stamped onto the result at win time, never read back later, so
            # editing this cannot move a deadline somebody already has.
            $table->unsignedInteger('validity_days')->nullable();

            # Off without being detached: an attachment with winners must not
            # be removed. `PoolEntryStatus::Void` retires the unwon units.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['campaign_id', 'reward_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_rewards');
    }
};
