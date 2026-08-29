<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which row of the old system each imported record was read out of.
 *
 * The extract carries two records in one row: the customer, and the note the
 * front desk wrote when they came in. They are imported by two seeders that
 * run at different times, and the second one has to be able to say which
 * customer a note belongs to. Phone number cannot answer that - 46 numbers in
 * the extract are shared by 115 rows, several of them switchboards - and row
 * order cannot either, because the customer seeder leaves behind any customer
 * a visit already points at and the keys it hands out shift accordingly. The
 * id the old system gave the row is the only thing about it that is stable,
 * so it is carried over and both imports key off it.
 *
 * Indexed but not unique, on the same reasoning as `customers.id_number`: the
 * customer seeder can be made to leave a record in place rather than replace
 * it, and a unique index would turn that into a failed import rather than a
 * duplicate somebody can see and merge.
 *
 * Null for everybody entered in this application, which is most of them from
 * here on. It means "not from the old system", not "unknown".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_id')->nullable()->after('id');
            $table->index('legacy_id');
        });

        Schema::table('visits', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_id')->nullable()->after('id');
            $table->index('legacy_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['legacy_id']);
            $table->dropColumn('legacy_id');
        });

        Schema::table('visits', function (Blueprint $table) {
            $table->dropIndex(['legacy_id']);
            $table->dropColumn('legacy_id');
        });
    }
};
