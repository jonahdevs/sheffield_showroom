<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A person behind every customer, company or not.
 *
 * The table was built as two half-records sharing a table: a name for an
 * individual, a contact person for a company. That was wrong about who walks
 * into a showroom. Somebody arrives, gives their name, and says whether they
 * are buying for themselves or for the business they work for - the name is
 * the constant and the company is what varies, so `name` now belongs to both
 * types and `contact_person` has nothing left to say.
 *
 * `contact_person` is copied into `name` before it goes, so the companies
 * already on file keep whoever was recorded against them.
 *
 * `name` stays nullable rather than being made required here. A company row
 * with no contact person recorded has no name to backfill from, and inventing
 * one to satisfy a constraint would be worse than leaving the gap visible;
 * `CustomerRequest` is what requires it from now on, so any such record is
 * filled in the next time it is edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('customers')
            ->whereNull('name')
            ->whereNotNull('contact_person')
            ->update(['name' => DB::raw('contact_person')]);

        Schema::table('customers', function (Blueprint $table) {
            /* The person's National ID. Indexed but not unique: it is entered
               from whatever they happen to have on them, often later than the
               rest of the record, and a half-typed number must not collide
               its way into being unenterable. */
            $table->string('id_number')->nullable()->after('email');
            $table->index('id_number');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->renameColumn('address_line_1', 'street_address');
            $table->renameColumn('address_line_2', 'area');
        });

        Schema::table('customers', function (Blueprint $table) {
            /* A date of birth and an occupation were asked for and never
               used; a second number was one more thing to ask a walk-in for
               at the counter. What is left is what the counter actually
               needs. */
            $table->dropColumn([
                'date_of_birth',
                'occupation',
                'contact_person',
                'contact_person_position',
                'alternative_phone',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('name');
            $table->string('occupation')->nullable()->after('date_of_birth');
            $table->string('contact_person')->nullable()->after('industry');
            $table->string('contact_person_position')->nullable()->after('contact_person');
            $table->string('alternative_phone')->nullable()->after('phone');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->renameColumn('street_address', 'address_line_1');
            $table->renameColumn('area', 'address_line_2');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['id_number']);
            $table->dropColumn('id_number');
        });
    }
};
