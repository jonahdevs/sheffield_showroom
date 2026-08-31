<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Takes the unused duration off a visit.
 *
 * A file of its own rather than an edit to `create_visits_table`, which is
 * what this folder would normally take. The rule that folds a pre-launch
 * schema change back into the create migration holds only while the database
 * can be rebuilt from the seeders, and it no longer can: the reward campaigns
 * running against this log hold entries and draws that nothing reproduces, so
 * a `migrate:fresh` is no longer a way of applying a change. Editing a create
 * that has already run would do nothing to any database that ran it.
 *
 * Nothing is lost with the column. Of the 419 visits on file not one carries a
 * duration: the front-desk log the whole thing was imported from never
 * measured one, so `LegacyVisitLog` wrote null against every row, and nobody
 * has typed one into the form since.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn('duration_minutes');
        });
    }

    /**
     * Restores the column exactly as `create_visits_table` declared it.
     *
     * Nullable and unsigned as before, so rolling back leaves the table in the
     * shape the create migration describes rather than a near miss of it. The
     * values cannot come back, but there were none to lose.
     */
    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            /* Minutes. Null when nobody noted it - an unrecorded duration is
               not a visit of zero length, and an average has to be able to
               tell those apart. */
            $table->unsignedSmallInteger('duration_minutes')->nullable()->after('source');
        });
    }
};
