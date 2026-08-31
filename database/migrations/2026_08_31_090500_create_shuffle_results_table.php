<?php

use App\Enums\RewardResultStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What was won. Permanent, and never deleted.
 *
 * The two unique indexes here are the safety net under the whole feature. The
 * claiming transaction takes a row lock and should make both of them
 * impossible to violate - but a lock is code, and code can be got wrong by
 * somebody adding a second path to this table a year from now. The database
 * cannot be:
 *
 * - one result per session, so a turn cannot produce two rewards;
 * - one result per pool entry, so a reward unit cannot be handed to two
 *   people.
 *
 * If the locking is ever wrong, the second writer gets an integrity error and
 * nobody gets a reward twice. That is the correct failure.
 */
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

            /* What the customer quotes when they come back for it, weeks
               later, on a different day, to different staff. Short enough to
               read off a phone screen and type in, and unique so it is the
               only thing redemption needs. */
            $table->string('code', 16)->unique();

            $table->dateTime('won_at');

            /* Stamped from the reward's `validity_days` at the moment of
               winning rather than derived later, so an administrator editing
               the definition afterwards cannot move a deadline somebody was
               already given. Null means it does not lapse. */
            $table->dateTime('expires_at')->nullable();

            $table->string('status')->default(RewardResultStatus::Unredeemed->value);

            $table->timestamps();

            $table->unique('shuffle_session_id');
            $table->unique('reward_pool_entry_id');

            /* What `rewards:expire` sweeps, and what the campaign report
               groups by. */
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shuffle_results');
    }
};
