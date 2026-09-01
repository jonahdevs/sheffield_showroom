<?php

use App\Enums\ShuffleSessionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

# =========================================================================
# One customer's single turn
# =========================================================================
#
# Addressed only by `token`, so a public URL names nothing countable upwards
# from. The transition out of `pending` happens inside the claiming
# transaction, so a refreshed page, a double tap and two phones on one QR all
# lose the race rather than winning a second reward.

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shuffle_sessions', function (Blueprint $table) {
            $table->id();

            # Restricted throughout: nothing with a turn against it gets
            # deleted out from under a reward somebody won.
            $table->foreignId('campaign_id')
                ->constrained('reward_campaigns')
                ->restrictOnDelete();

            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_id')->nullable()->constrained()->restrictOnDelete();

            # Opaque and random - see `ShuffleSessionService`. Unique, so a
            # guessed token either finds a session or nothing.
            $table->string('token', 64)->unique();

            $table->dateTime('expires_at')->nullable();
            $table->string('status')->default(ShuffleSessionStatus::Pending->value);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            # One turn per sale, held here because a service can be raced
            # and an index cannot. Repeated nulls are wanted: a staff-run
            # session with no purchase must still be possible.
            $table->unique('purchase_id');

            $table->index(['campaign_id', 'customer_id', 'status']);

            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shuffle_sessions');
    }
};
