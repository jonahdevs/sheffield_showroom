<?php

use App\Enums\RewardResultStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

# =========================================================================
# What was won. Permanent, and never deleted
# =========================================================================
#
# The two unique indexes below are the safety net under the claiming lock and
# neither may be dropped: one result per session, one per pool entry. If the
# lock is ever wrong the second writer gets an integrity error, which is the
# correct failure.

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shuffle_results', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shuffle_session_id')
                ->constrained('shuffle_sessions')
                ->restrictOnDelete();

            $table->foreignId('reward_pool_entry_id')
                ->constrained('reward_pool_entries')
                ->restrictOnDelete();

            $table->string('code', 16)->unique();

            $table->dateTime('won_at');

            # Stamped at win time, never derived later, so editing a reward
            # cannot move a deadline somebody already has.
            $table->dateTime('expires_at')->nullable();

            $table->string('status')->default(RewardResultStatus::Unredeemed->value);

            $table->timestamps();

            $table->unique('shuffle_session_id');
            $table->unique('reward_pool_entry_id');

            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shuffle_results');
    }
};
