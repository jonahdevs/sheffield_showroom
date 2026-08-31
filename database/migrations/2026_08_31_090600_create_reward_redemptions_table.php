<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reward actually being handed over.
 *
 * A separate table rather than two columns on the result, because this is a
 * different event with a different actor at a different time - somebody won a
 * free installation in August and it was fitted in October by whoever was on
 * that day. Folding it into the result would lose the second half of that
 * sentence.
 *
 * Unique on the result: a reward is handed over once. The status on the result
 * says the same thing, and this index is what makes it true under a double
 * submit rather than merely intended.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_redemptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shuffle_result_id')
                ->constrained('shuffle_results')
                ->restrictOnDelete();

            /* Nulled rather than cascaded: the redemption outlives the staff
               account that recorded it, the same way `visits.created_by`
               does. */
            $table->foreignId('redeemed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->dateTime('redeemed_at');
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique('shuffle_result_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_redemptions');
    }
};
