<?php

use App\Enums\ShuffleSessionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One customer's single turn.
 *
 * A session is minted when a purchase qualifies, and it is the only thing that
 * can be shuffled. Everything the customer touches is addressed by `token` -
 * the QR code carries nothing else - so a public URL never names a customer,
 * a purchase, or a row number anybody could count upwards from.
 *
 * Two rules are held by the database rather than by code, because both of them
 * are what somebody would attack:
 *
 * - `token` is unique, so a guessed one either finds a session or nothing.
 * - `purchase_id` is unique, so one sale can only ever mint one turn. MySQL
 *   allows repeated nulls in a unique index, which is exactly what is wanted:
 *   a staff-run session with no purchase behind it is still possible, and
 *   several of them do not collide.
 *
 * The transition out of `pending` happens inside the claiming transaction, so
 * a refreshed page, a double tap and two phones on the same QR all lose the
 * race rather than winning a second reward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shuffle_sessions', function (Blueprint $table) {
            $table->id();

            /* Restricted throughout. A campaign, a customer or a purchase with
               a turn against it is history now, and history does not get
               deleted out from under a reward somebody won. */
            $table->foreignId('campaign_id')
                ->constrained('reward_campaigns')
                ->restrictOnDelete();

            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_id')->nullable()->constrained()->restrictOnDelete();

            /* Opaque and random - see `ShuffleSessionService`. Long enough
               that guessing is pointless and short enough to survive a QR
               code at the size a phone reads across a counter. */
            $table->string('token', 64)->unique();

            $table->dateTime('expires_at')->nullable();
            $table->string('status')->default(ShuffleSessionStatus::Pending->value);

            /* Which member of staff gave them the turn. */
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            /* One turn per sale. The rule lives here rather than in the
               eligibility service because a service can be raced and a unique
               index cannot. */
            $table->unique('purchase_id');

            /* What the campaign cap asks: how many turns has this person had
               in this campaign. */
            $table->index(['campaign_id', 'customer_id', 'status']);

            /* What `rewards:expire` sweeps. */
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shuffle_sessions');
    }
};
