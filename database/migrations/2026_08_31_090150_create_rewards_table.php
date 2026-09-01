<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

# =========================================================================
# The catalogue: everything the showroom is willing to give away
# =========================================================================

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rewards', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type');

            # `nullOnDelete` rather than cascade: removing a product must
            # never take a reward somebody has already won out of the
            # catalogue with it.
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('value', 12, 2)->nullable();
            $table->string('value_unit')->nullable();

            $table->text('terms')->nullable();

            # A suggestion, not a promise: the binding deadline is
            # `campaign_rewards.validity_days`, copied down at attachment.
            $table->unsignedInteger('default_validity_days')->nullable();

            # Whether it may go into *new* campaigns. Retiring it here leaves
            # every campaign already holding it untouched.
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('name');
            $table->index(['is_active', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rewards');
    }
};
