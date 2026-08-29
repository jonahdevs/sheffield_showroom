<?php

use App\Enums\InterestLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The model number a salesperson reads off the sheet, and how keen somebody was
 * on each thing they were shown.
 *
 * `model_number` is the website's, alongside the SKU rather than instead of it:
 * a SKU is what the till knows a product by and a model number is what the
 * manufacturer stamped on it, and a customer asking after "BP-28" is asking by
 * the second. Not unique - two products can share a model and differ by
 * length or gauge.
 *
 * `interest_level` belongs on the pivot rather than on the visit: somebody
 * shown four things is rarely equally interested in all four, and which one
 * they leaned towards is what the write-up is for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('model_number')->nullable()->after('sku');
            $table->index('model_number');
        });

        Schema::table('product_visit', function (Blueprint $table) {
            /* Defaulted rather than nullable, so every row already attached
               reads as somebody who was interested enough to be shown it and
               no more than that. */
            $table->string('interest_level')
                ->default(InterestLevel::Medium->value)
                ->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_visit', function (Blueprint $table) {
            $table->dropColumn('interest_level');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['model_number']);
            $table->dropColumn('model_number');
        });
    }
};
