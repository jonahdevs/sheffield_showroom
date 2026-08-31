<?php

use App\Enums\ProductStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the showroom sells, as a salesperson needs it on the floor: a picture,
 * a code and a name. Prices, specs and copy live on the main website and are
 * not repeated here - two places holding the same price is one place holding
 * the wrong one.
 *
 * The last three columns are what make a sync from that website possible
 * later: `external_id` is the only thing that can match a row on a second run
 * without creating a duplicate, and it costs nothing to leave the door open.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            /* Unique where present. A blank SKU is a product nobody has coded
               yet, and several of those can coexist - which is why this is
               nullable rather than defaulted to an empty string. */
            $table->string('sku')->nullable()->unique();

            /* The website's model number, alongside the SKU rather than
               instead of it: a SKU is what the till knows a product by and a
               model number is what the manufacturer stamped on it, and a
               customer asking after "BP-28" is asking by the second. Not
               unique - two products can share a model and differ by length or
               gauge. */
            $table->string('model_number')->nullable();

            /* Relative to the public disk, as `Storage::url()` wants it. */
            $table->string('image_path')->nullable();

            /* Whether it belongs on the floor today, which is not the same
               question as `deleted_at` - whether the row exists at all. A
               product can be perfectly real, still sold, and deliberately kept
               off the tiles for a season. Defaulted to published, because a
               row somebody has just typed in is a row they mean to sell. */
            $table->string('status')->default(ProductStatus::Published->value);

            /* Where the row came from, so a sync never overwrites something
               typed in here by hand. */
            $table->string('source')->default('manual');

            /* The main website's product id. Unique so a re-sync updates the
               row it made rather than adding another. */
            $table->unsignedBigInteger('external_id')->nullable()->unique();
            $table->timestamp('synced_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
            $table->index('model_number');
            /* The tab strip on the products screen asks this column a question
               on every visit. */
            $table->index('status');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
