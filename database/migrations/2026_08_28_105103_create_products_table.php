<?php

use App\Enums\ProductStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

# =========================================================================
# What the showroom sells: a picture, a code and a name
# =========================================================================

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            $table->string('sku')->nullable()->unique();

            $table->string('model_number')->nullable();

            $table->string('image_path')->nullable();

            # Whether it belongs on the floor today, which is not the same
            # question as `deleted_at`.
            $table->string('status')->default(ProductStatus::Published->value);

            # Where the row came from, so a sync never overwrites something
            # typed in here by hand.
            $table->string('source')->default('manual');

            # Unique, so a re-sync updates the row it made rather than
            # adding another.
            $table->unsignedBigInteger('external_id')->nullable()->unique();
            $table->timestamp('synced_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
            $table->index('model_number');
            $table->index('status');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
