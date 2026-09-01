<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

# =========================================================================
# What was on the receipt, so a paired reward can find it
# =========================================================================
#
# No price and no quantity, deliberately: this answers "which products were on
# this sale" and nothing else. Not a ledger.

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_product', function (Blueprint $table) {
            $table->id();

            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();

            # Cascaded, like `campaign_reward_product`: withdrawing a product
            # is a soft delete and touches nothing here.
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->unique(['purchase_id', 'product_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_product');
    }
};
