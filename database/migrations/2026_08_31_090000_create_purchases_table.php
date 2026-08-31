<?php

use App\Enums\PurchaseStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What somebody bought, and for how much.
 *
 * The first money this application has ever held. Until now the showroom
 * recorded interest - who came, what they were shown, how keen they seemed -
 * and left every figure with a currency sign on it to the systems that own
 * one. This table exists because the reward shuffle has to be earned by
 * something, and "spent enough to qualify" is not a question the visit log can
 * answer.
 *
 * It is deliberately narrow. This is an eligibility record, not a ledger:
 * there are no line items, no tax, no payment method and no reconciliation,
 * because nothing here needs them and every column added would be a second
 * place holding a number the till already holds. `reference` is the hook for
 * the day somebody wants to tie one of these back to a receipt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();

            /* Restricted, like `visits.customer_id`: a customer with money
               against their name is soft deleted, never dropped, and the day
               somebody force-deletes one this should refuse rather than take
               the sale with it. */
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            /* The call it happened on, where there was one. Nullable because
               a sale can be settled over the phone against a visit logged
               weeks earlier, or against none at all. */
            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();

            /* The receipt or invoice number as the till wrote it. Free text
               and not unique - it is somebody else's key, typed in by hand,
               and a mistyped one must not stop a sale being recorded. */
            $table->string('reference')->nullable();

            /* Shillings. `decimal` rather than a float because this is the
               number eligibility compares against a threshold, and a
               threshold you can fall the wrong side of by a rounding error is
               worse than no threshold at all. */
            $table->decimal('amount', 12, 2);

            /* Complete by default: a purchase typed in on the floor is one
               that already happened. Only a completed purchase earns a
               shuffle - see `PurchaseStatus::isQualifying()`. */
            $table->string('status')->default(PurchaseStatus::Completed->value);

            /* When they bought, not when the row was typed - the same
               distinction `visits.visited_at` draws, and for the same reason:
               a sale written up at the end of the day did not happen then. */
            $table->dateTime('purchased_at');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            /* The list reads newest first; eligibility filters on the status.
               Nothing else here is searched yet, so nothing else is indexed
               yet. */
            $table->index('purchased_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
