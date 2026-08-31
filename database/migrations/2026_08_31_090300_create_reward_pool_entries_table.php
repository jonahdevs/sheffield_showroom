<?php

use App\Enums\PoolEntryStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One reward unit. A hundred rewards is a hundred rows.
 *
 * This is the table the whole feature turns on. There is no probability
 * anywhere in this system: a shuffle locks one `available` row and takes it,
 * so what can still be won is exactly what is still here, and two customers
 * can never be handed the same unit.
 *
 * A row is written once and changes state once. `claimed` is one-way - a
 * reward that has been won is never returned to the pool, even if the result
 * is later cancelled, because the customer was already told they had won it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_pool_entries', function (Blueprint $table) {
            $table->id();

            /* The campaign, carried here as well as on the definition above.
               Denormalised on purpose and the one place in this schema that
               is: claiming a reward locks a row while a transaction is open,
               and it has to pick that row out of the whole campaign's pool. A
               join to reach the campaign would mean locking across two tables
               on the hottest, most contended statement in the application. */
            $table->foreignId('campaign_id')
                ->constrained('reward_campaigns')
                ->cascadeOnDelete();

            $table->foreignId('campaign_reward_id')
                ->constrained('campaign_rewards')
                ->cascadeOnDelete();

            $table->string('status')->default(PoolEntryStatus::Available->value);
            $table->dateTime('claimed_at')->nullable();

            $table->timestamps();

            /* The claim query, exactly: the available units of one campaign.
               `campaign_id` leads because it is the equality, `status` follows
               because it narrows it, and nothing else is read to choose. */
            $table->index(['campaign_id', 'status']);

            /* What the campaign screen counts: how much of each reward is
               left. */
            $table->index(['campaign_reward_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_pool_entries');
    }
};
