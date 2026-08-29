<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many of a product somebody was after.
 *
 * A showroom customer asks after twenty sheets rather than one, and a
 * write-up that records only which product they looked at cannot tell a
 * roofing job from a repair. Defaulted to one so a row already attached reads
 * as the single item it was entered as, and so adding a product costs nobody a
 * number they do not yet know.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_visit', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->default(1)->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_visit', function (Blueprint $table) {
            $table->dropColumn('quantity');
        });
    }
};
