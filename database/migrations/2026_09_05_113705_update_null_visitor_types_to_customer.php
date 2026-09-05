<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        # Ensure visitor_type column exists with proper default and index
        if (! Schema::hasColumn('visits', 'visitor_type')) {
            Schema::table('visits', function (Blueprint $table) {
                $table->string('visitor_type')->default('customer')->index()->after('customer_id');
            });
        }

        # Any visit with a null visitor_type gets the default 'customer'.
        # The column has a default, but rows created before that default
        # was added may still be null.
        DB::table('visits')
            ->whereNull('visitor_type')
            ->update(['visitor_type' => 'customer']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        # No reversal: we cannot tell which rows were null before.
    }
};
