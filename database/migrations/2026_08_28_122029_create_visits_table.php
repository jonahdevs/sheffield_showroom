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

            # Restricted rather than cascaded: force-deleting a customer must
            # refuse rather than quietly take the history with it.
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            $table->dateTime('visited_at')->index();

            $table->string('purpose')->index();
            $table->string('source')->index();

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
