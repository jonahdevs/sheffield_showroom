<?php

use App\Enums\CampaignStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One promotion: its dates, its rules, and the pool of rewards behind it.
 *
 * A campaign is inventory rather than probability. An administrator says how
 * many of each reward exist, and publishing turns those numbers into rows in
 * `reward_pool_entries` - one row per reward unit. Nothing rolls dice: the
 * shuffle takes an available row, so the odds are whatever is left in the
 * drawer, and they cannot be wrong.
 *
 * `max_shuffles_per_customer` is a ceiling across the whole campaign, not the
 * thing that grants a turn. A turn is earned by a qualifying purchase - see
 * the unique index on `shuffle_sessions.purchase_id`, which is what stops one
 * sale being shuffled twice.
 *
 * Only one campaign may be `active` at a time. That is not enforced here
 * because MySQL has no partial unique index to express it with; `CampaignService`
 * holds it, and there is a test that says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();

            /* Both nullable: a campaign can be opened and closed by hand
               rather than by the calendar, and one with no end date runs until
               somebody stops it or the pool runs out. */
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();

            $table->string('status')->default(CampaignStatus::Draft->value);

            /* One turn each unless a showroom decides otherwise. The default
               is the conservative reading of the promotion, so a campaign
               created without thinking about this cannot hand somebody the
               whole pool. */
            $table->unsignedInteger('max_shuffles_per_customer')->default(1);

            /* What a purchase has to reach to qualify. Nullable, meaning any
               completed purchase earns a turn - a campaign with no threshold
               is a legitimate promotion, not a misconfigured one. */
            $table->decimal('minimum_purchase_amount', 12, 2)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            /* Eligibility asks one question on every qualifying purchase:
               which campaign is running right now. */
            $table->index(['status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_campaigns');
    }
};
