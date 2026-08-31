<?php

use App\Enums\InterestLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A call at the showroom: who came, why, when, and what they were shown.
 *
 * The visit is the record the floor is measured by, so the columns worth
 * counting - purpose and source - are enums rather than prose, and the prose
 * sits beside them in its own columns. Which products were looked at is a
 * many-to-many, because one visit walks past several and one product is walked
 * past by many.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();

            /* Which row of the old system this note was read out of. See
               `customers.legacy_id`: the two imports run at different times
               and this is what ties a note to the customer it belongs to. */
            $table->unsignedBigInteger('legacy_id')->nullable();

            /* Restricted rather than cascaded: a customer with visits against
               them is soft deleted, never dropped, and the day somebody
               force-deletes one this should refuse rather than quietly take
               the history with it. */
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            /* When they came, not when the row was typed. Those differ every
               time a visit is written up at the end of the day. */
            $table->dateTime('visited_at')->index();

            $table->string('purpose')->index();
            $table->string('source')->index();

            /* Minutes. Null when nobody noted it - an unrecorded duration is
               not a visit of zero length, and an average has to be able to
               tell those apart. */
            $table->unsignedSmallInteger('duration_minutes')->nullable();

            /* When to chase them. A date rather than a full moment: nobody
               books a follow-up for twenty past three. */
            $table->date('expected_follow_up_on')->nullable()->index();

            $table->text('notes')->nullable();

            /* Who took the visit, written rather than picked. Not always the
               person who typed it up - a manager writing up the floor at the
               end of the day is the logger, not the respondent - and not
               always somebody with an account either, which is why this is a
               name and not a foreign key. */
            $table->string('respondent')->nullable();

            /* Who logged it. This is also who `visits.view.own` scopes to, so
               it is what the list filters on for a salesperson. */
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('legacy_id');

            /* The shape the list asks for: a salesperson's own visits, newest
               first. */
            $table->index(['created_by', 'visited_at']);
        });

        Schema::create('product_visit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            /* How many they were after. A showroom customer asks about twenty
               sheets rather than one, and a write-up that records only which
               product they looked at cannot tell a roofing job from a repair.
               Defaulted to one, so adding a product costs nobody a number they
               do not yet know. */
            $table->unsignedInteger('quantity')->default(1);

            /* How keen they were on this one, on the pivot rather than on the
               visit: somebody shown four things is rarely equally interested
               in all four, and which one they leaned towards is what the
               write-up is for. Defaulted rather than nullable - being shown
               something is itself a middling amount of interest. */
            $table->string('interest_level')->default(InterestLevel::Medium->value);

            /* One product cannot be shown twice on the same visit. Without
                this a double submit records the same tour twice. */
            $table->unique(['visit_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_visit');
        Schema::dropIfExists('visits');
    }
};
