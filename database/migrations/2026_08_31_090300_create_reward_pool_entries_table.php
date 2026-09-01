<?php

use App\Enums\PoolEntryStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

# =========================================================================
# One reward unit. A hundred rewards is a hundred rows
# =========================================================================
#
# No probability anywhere: a shuffle locks one `available` row and takes it.
# `claimed` is one-way - a won unit never returns to the pool, even if the
# result is cancelled.

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_pool_entries', function (Blueprint $table) {
            $table->id();

            # Denormalised on purpose, and the one place in this schema that
            # is: a join to reach the campaign would mean locking across two
            # tables on the hottest statement in the application.
            $table->foreignId('campaign_id')
                ->constrained('reward_campaigns')
                ->cascadeOnDelete();

            $table->foreignId('campaign_reward_id')
                ->constrained('campaign_rewards')
                ->cascadeOnDelete();

            $table->string('status')->default(PoolEntryStatus::Available->value);
            $table->dateTime('claimed_at')->nullable();

            $table->timestamps();

            $table->index(['campaign_id', 'status']);

            $table->index(['campaign_reward_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_pool_entries');
    }
};
