<?php

use App\Enums\PurchaseStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

# =========================================================================
# What somebody bought, and for how much
# =========================================================================
#
# An eligibility record, not a ledger: no tax, no payment method and no
# reconciliation, because each would be a second place holding a number the
# till already holds. Which products were on the sale is in `purchase_product`.

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();

            # Restricted, like `visits.customer_id`: force-deleting a
            # customer must refuse rather than take the sale with it.
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reference')->nullable();

            # `decimal`, not a float: eligibility compares this against a
            # threshold, and one you can fall the wrong side of by a rounding
            # error is worse than no threshold at all.
            $table->decimal('amount', 12, 2);

            $table->string('status')->default(PurchaseStatus::Completed->value);

            $table->dateTime('purchased_at');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('purchased_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
