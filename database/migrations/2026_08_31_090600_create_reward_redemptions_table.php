<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

# =========================================================================
# The reward actually being handed over
# =========================================================================

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_redemptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shuffle_result_id')
                ->constrained('shuffle_results')
                ->restrictOnDelete();

            $table->foreignId('redeemed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->dateTime('redeemed_at');
            $table->text('notes')->nullable();

            $table->timestamps();

            # A reward is handed over once. The status on the result says the
            # same thing; this index is what makes it true under a double
            # submit.
            $table->unique('shuffle_result_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_redemptions');
    }
};
