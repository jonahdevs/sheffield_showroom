<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The catalogue: everything the showroom is willing to give away, described
 * once and reused.
 *
 * A reward is written here on its own, before any campaign wants it. That is
 * the whole point of the table - "a free kitchen audit" is the same offer in
 * March as it is in November, and typing it out again for each promotion is
 * how two campaigns end up handing out subtly different versions of one thing.
 *
 * What lives here is what does not change between campaigns: what the reward
 * is, what it is worth, what its terms say. How many of it exist and how long
 * a winner has to claim it are decisions a campaign makes, and live on
 * `campaign_rewards` where each campaign can answer them differently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rewards', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type');

            /* The item won, when the reward is a thing off the showroom floor
               rather than a discount or a service - a tray, an accessory, the
               sort of reward that already has a row in `products` with a
               picture and a code. Null for every other kind.

               `nullOnDelete` rather than cascade: a product being removed must
               never take a reward somebody has already won out of the
               catalogue with it. The reward survives, described by the name
               and terms written here. */
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            /* The number on the card, and how to read it: ten per cent off is
               not ten shillings off. Both nullable, because most rewards here
               are services and products whose worth is not a figure. */
            $table->decimal('value', 12, 2)->nullable();
            $table->string('value_unit')->nullable();

            $table->text('terms')->nullable();

            /* What a campaign starts from when it attaches this reward, not
               what any winner is held to. The binding deadline is the one on
               `campaign_rewards.validity_days`, copied down at attachment and
               stamped onto the result at the moment of winning. Editing this
               changes what the next campaign suggests and nothing else. */
            $table->unsignedInteger('default_validity_days')->nullable();

            /* Whether it may still be put into new campaigns. Retiring a
               reward here leaves every campaign already holding it untouched -
               those are inventory, and `campaign_rewards.is_active` is the
               switch for them. */
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('name');

            /* The picker on the campaign form: the rewards still on offer,
               grouped by what kind of thing they are. */
            $table->index(['is_active', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rewards');
    }
};
