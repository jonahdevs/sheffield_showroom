<?php

use App\Enums\ProductStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a product belongs on the floor today.
 *
 * Not the same question as `deleted_at`, which is whether the row exists at
 * all. A product can be perfectly real, still sold, and deliberately kept off
 * the tiles for a season - and until now there was nowhere to say so, only the
 * blunt choice between showing it and deleting it.
 *
 * Backfilled to `published` rather than `draft`. Every row already here is a
 * row the floor has been working from, and defaulting the other way would take
 * the whole catalogue down the moment this ran. Soft-deleted rows go to
 * `archived` so the two columns agree from the first day: a row that is gone
 * should not also read as on sale.
 *
 * Indexed because the list filters on it - the tab strip on the products screen
 * asks this column a question on every visit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('status')
                ->default(ProductStatus::Published->value)
                ->after('image_path');

            $table->index('status');
        });

        /* The column default has already put every existing row on the floor,
           so the backfill only has to correct the ones that had left it. */
        DB::table('products')
            ->whereNotNull('deleted_at')
            ->update(['status' => ProductStatus::Archived->value]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });
    }
};
