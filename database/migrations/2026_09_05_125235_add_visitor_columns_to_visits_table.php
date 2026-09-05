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

        # Ensure visitor_name column exists
        if (! Schema::hasColumn('visits', 'visitor_name')) {
            Schema::table('visits', function (Blueprint $table) {
                $table->string('visitor_name')->nullable()->after('visitor_type');
            });
        }

        # Ensure visitor_phone column exists
        if (! Schema::hasColumn('visits', 'visitor_phone')) {
            Schema::table('visits', function (Blueprint $table) {
                $table->string('visitor_phone')->nullable()->after('visitor_name');
            });
        }

        # Ensure visitor_organisation column exists
        if (! Schema::hasColumn('visits', 'visitor_organisation')) {
            Schema::table('visits', function (Blueprint $table) {
                $table->string('visitor_organisation')->nullable()->after('visitor_phone');
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
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn(['visitor_type', 'visitor_name', 'visitor_phone', 'visitor_organisation']);
        });
    }
};
