<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One table for both kinds of customer.
 *
 * An individual and a company share every contact and address column and
 * differ only in who they name, so two tables would duplicate the shared half
 * and force a union on every list and search. The columns belonging to the
 * other type are null, and `CustomerRequest` is what holds that line.
 *
 * A person stands behind every customer, company or not. This was first built
 * as two half-records sharing a table - a name for an individual, a contact
 * person for a company - which was wrong about who walks into a showroom.
 * Somebody arrives, gives their name, and says whether they are buying for
 * themselves or for the business they work for: the name is the constant and
 * the company is what varies. So `name` belongs to both types and there is no
 * `contact_person`.
 *
 * `name` is nullable rather than required. `CustomerRequest` requires it, but
 * the legacy import can carry a company row with nobody recorded against it,
 * and inventing a name to satisfy a constraint would be worse than leaving the
 * gap visible until somebody edits the record.
 *
 * A date of birth, an occupation and a second phone number were all asked for
 * at one point and never used; a walk-in at the counter has better things to
 * answer. What is here is what the counter actually needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            /* Which row of the old system this was read out of.

               The extract carries two records in one row: the customer, and the
               note the front desk wrote when they came in. They are imported by
               two seeders that run at different times, and the second has to be
               able to say which customer a note belongs to. Phone number cannot
               answer that - 46 numbers in the extract are shared by 115 rows,
               several of them switchboards - and row order cannot either,
               because the customer seeder leaves behind any customer a visit
               already points at and the keys it hands out shift accordingly.

               Indexed but not unique, on the same reasoning as `id_number`: a
               seeder that leaves a record in place rather than replacing it
               would otherwise fail the import instead of leaving a duplicate
               somebody can see and merge. Null means "not from the old
               system", which is everybody entered here. */
            $table->unsignedBigInteger('legacy_id')->nullable();

            $table->string('type');

            // --- Who they are -------------------------------------------
            $table->string('name')->nullable();

            // --- Company, when they are buying for one -------------------
            $table->string('company_name')->nullable();
            $table->string('industry')->nullable();

            // --- Contact -------------------------------------------------
            /* Stored as given. Normalising a Kenyan number to E.164 loses the
               shape people recognise on a printed record, and matching is done
               on a stripped copy at query time rather than on the column. */
            $table->string('phone');
            $table->string('email')->nullable();

            /* The person's National ID. Indexed but not unique: it is entered
               from whatever they happen to have on them, often later than the
               rest of the record, and a half-typed number must not collide its
               way into being unenterable. */
            $table->string('id_number')->nullable();

            // --- Address --------------------------------------------------
            /* Two lines and a postal code, and `state` rather than `county`,
               so the address reads for anywhere rather than only for Kenya. */
            $table->string('street_address')->nullable();
            $table->string('area')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->default('Kenya');

            $table->text('notes')->nullable();

            /* Who logged them. Nulled rather than cascaded on delete: the
               customer outlives the staff account that recorded them. */
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('legacy_id');
            $table->index('type');
            /* The columns every list sorts and searches on. */
            $table->index('name');
            $table->index('company_name');
            $table->index('phone');
            $table->index('id_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
