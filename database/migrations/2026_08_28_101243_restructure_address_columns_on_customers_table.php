<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A fuller address: two lines rather than one, a postal code, and `county`
 * generalised to `state` so the field reads for an address anywhere rather
 * than only a Kenyan one.
 *
 * Renamed rather than dropped and recreated, so what has already been entered
 * carries over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->renameColumn('address_line', 'address_line_1');
            $table->renameColumn('county', 'state');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('address_line_2')->nullable()->after('address_line_1');
            $table->string('postal_code')->nullable()->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['address_line_2', 'postal_code']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->renameColumn('address_line_1', 'address_line');
            $table->renameColumn('state', 'county');
        });
    }
};
