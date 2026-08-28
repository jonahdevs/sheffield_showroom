<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns spatie's table does not carry.
 *
 * `is_system` marks a role the application ships with: the checks it gates are
 * written into the code, so it is shown for reference and refused an edit.
 * `description` is what the Roles screen prints under the name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('permission.table_names.roles'), function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('guard_name');
            $table->string('description')->nullable()->after('is_system');
        });
    }

    public function down(): void
    {
        Schema::table(config('permission.table_names.roles'), function (Blueprint $table) {
            $table->dropColumn(['is_system', 'description']);
        });
    }
};
