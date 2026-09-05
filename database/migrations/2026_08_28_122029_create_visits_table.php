<?php

use App\Enums\InterestLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

# =========================================================================
# A call at the showroom: who came, why, when, and what they were shown
# =========================================================================

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();

            # See `customers.legacy_id`: the two imports run at different
            # times and this ties a note to the customer it belongs to.
            $table->unsignedBigInteger('legacy_id')->nullable();

            # Null for everybody who is not a customer - see `visitor_type`.
            # Restricted rather than cascaded: force-deleting a customer must
            # refuse rather than quietly take the history with it.
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();

            # ------------------------------------------------------------------
            # Who came in
            # ------------------------------------------------------------------
            # The front desk logs every caller and most are not shopping. Only a
            # customer gets a row in `customers`; everybody else is written here,
            # because somebody with no telephone cannot be matched against next
            # time and would file a fresh, near-empty customer on every call.
            #
            # The invariant, held by `VisitRequest`: this reads 'customer' if and
            # only if `customer_id` is set, and the three columns below are filled
            # if and only if it does not.
            $table->string('visitor_type')->default('customer')->index();

            $table->string('visitor_name')->nullable();
            $table->string('visitor_phone')->nullable();
            $table->string('visitor_organisation')->nullable();

            $table->dateTime('visited_at')->index();

            $table->string('purpose')->index();
            $table->string('source')->index();

            # Who made the referral, alongside the source rather than inside
            # it: `VisitRequest` requires one for a referral and refuses one
            # for every other source.
            $table->string('referred_by')->nullable();

            # Nullable because the legacy log names no desk on every note.
            $table->string('department')->nullable()->index();

            $table->date('expected_follow_up_on')->nullable()->index();

            $table->text('notes')->nullable();

            $table->string('respondent')->nullable();

            # What `visits.view.own` scopes to.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('legacy_id');
            $table->index(['created_by', 'visited_at']);
        });

        Schema::create('product_visit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('quantity')->default(1);

            $table->string('interest_level')->default(InterestLevel::Medium->value);

            $table->unique(['visit_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_visit');
        Schema::dropIfExists('visits');
    }
};
