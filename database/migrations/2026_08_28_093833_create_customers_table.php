<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

# =========================================================================
# One table for both kinds of customer
# =========================================================================

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            # The only thing tying an imported visit to its customer: phone
            # numbers are shared (46 over 115 rows) and row order shifts
            # between runs. Not unique, so a re-seed leaves a visible
            # duplicate rather than failing the import.
            $table->unsignedBigInteger('legacy_id')->nullable();

            $table->string('type');

            $table->string('name')->nullable();

            # --------------------------------------------------------------
            # Company, when they are buying for one
            # --------------------------------------------------------------
            $table->string('company_name')->nullable();
            $table->string('industry')->nullable();

            # --------------------------------------------------------------
            # Contact
            # --------------------------------------------------------------
            # Stored as given. `Customer::matchingPhone` compares stripped
            # subscriber tails at query time, never the column itself.
            $table->string('phone');
            $table->string('email')->nullable();

            $table->string('id_number')->nullable();

            # --------------------------------------------------------------
            # Address
            # --------------------------------------------------------------
            $table->string('street_address')->nullable();
            $table->string('area')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->default('Kenya');

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('legacy_id');
            $table->index('type');
            $table->index('name');
            $table->index('company_name');
            $table->index('phone');
            $table->index('id_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
