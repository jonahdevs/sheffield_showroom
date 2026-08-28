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
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('type')->index();

            // --- Individual ---------------------------------------------
            $table->string('name')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('occupation')->nullable();

            // --- Company ------------------------------------------------
            $table->string('company_name')->nullable();
            $table->string('industry')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('contact_person_position')->nullable();

            // --- Shared contact -----------------------------------------
            /* Stored as given. Normalising a Kenyan number to E.164 loses the
               shape people recognise on a printed record, and matching is done
               on a stripped copy at query time rather than on the column. */
            $table->string('phone');
            $table->string('alternative_phone')->nullable();
            $table->string('email')->nullable();

            // --- Address ------------------------------------------------
            $table->string('address_line')->nullable();
            $table->string('city')->nullable();
            $table->string('county')->nullable();
            $table->string('country')->default('Kenya');

            $table->text('notes')->nullable();

            /* Who logged them. Nulled rather than cascaded on delete: the
               customer outlives the staff account that recorded them. */
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            /* The two columns every list sorts and searches on. */
            $table->index('name');
            $table->index('company_name');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
