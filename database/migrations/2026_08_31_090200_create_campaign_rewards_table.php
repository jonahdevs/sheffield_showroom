<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a campaign is handing out, and how many of each.
 *
 * A definition, not a reward. Twenty discounts are one row here with a
 * `quantity` of twenty, and twenty rows in `reward_pool_entries` once the
 * campaign is published. Keeping the two apart is what lets an administrator
 * edit a draft freely and lets the pool be controlled inventory afterwards.
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

            /* Cascaded: a definition has no meaning without its campaign, and
               a campaign that has published a pool cannot be deleted anyway -
               `shuffle_sessions` and the pool entries below hold it in
               place. */
            $table->foreignId('campaign_id')
                ->constrained('reward_campaigns')
                ->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type');

            /* The number on the card, and how to read it: ten per cent off is
               not ten shillings off. Both nullable, because most of these are
               services whose worth is written in their terms. */
            $table->decimal('value', 12, 2)->nullable();
            $table->string('value_unit')->nullable();

            /* How many units go into the pool at publication. Refused at zero
               by `RewardRequest`; a definition worth nothing to anybody is a
               definition that should not be in the campaign. */
            $table->unsignedInteger('quantity');

            /* Days from winning until it lapses. Null means it does not - a
               free installation with no deadline is a legitimate promise.
               Stamped onto the result when it is won rather than read back
               later, so editing this never moves a deadline somebody already
               has. */
            $table->unsignedInteger('validity_days')->nullable();

            $table->text('terms')->nullable();

            /* Off without being deleted. A definition with winners must not be
               removed - see `PoolEntryStatus::Void` for taking the unwon units
               off the table. */
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_rewards');
    }
};
