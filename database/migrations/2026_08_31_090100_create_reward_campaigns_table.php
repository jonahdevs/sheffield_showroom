<?php

use App\Enums\CampaignStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

# =========================================================================
# One promotion: its dates, its rules, and the pool behind it
# =========================================================================
#
# Only one campaign may be `active` at a time. Not enforced here because MySQL
# has no partial unique index to express it with; `CampaignService` holds it.

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();

            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();

            $table->string('status')->default(CampaignStatus::Draft->value);

            # A ceiling across the campaign, not the thing that grants a turn:
            # a turn is earned by a qualifying purchase, and the unique index
            # on `shuffle_sessions.purchase_id` is what stops one sale being
            # shuffled twice.
            $table->unsignedInteger('max_shuffles_per_customer')->default(1);

            $table->decimal('minimum_purchase_amount', 12, 2)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_campaigns');
    }
};
