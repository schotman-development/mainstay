<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | What editors are working on, beside what the site shows: per draft, the
 | fields that differ from what is live, as their columns hold them, and every
 | locale the draft adds. One shared table of JSON rather than one per type
 | mirroring its columns -- a draft is read whole and never filtered, it holds
 | an entry's terms, which live in a pivot, and it may be incomplete, which a
 | NOT NULL column cannot hold.
 |
 | `entry_id` is the entry it changes; 0 for a global, the site's one and not
 | addressed by id; null for an entry not published yet, each its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mainstay_drafts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('type');
            $table->unsignedBigInteger('entry_id')->nullable();
            $table->json('changes');
            /* Who started it, and so who owns a new entry it publishes. */
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->datetimes();

            $table->foreign('site_id')->references('id')->on('sites');

            /* One draft per entry, the index deciding two first saves
               racing. SQL Server counts nulls equal in a unique index, which
               would allow one unpublished entry per type, so there it is
               filtered to the rows with an entry; the others let nulls
               repeat. */
            if (DB::getDriverName() !== 'sqlsrv') {
                $table->unique(['site_id', 'type', 'entry_id']);
            }
        });

        /* By statement, with the connection's prefix, which Blueprint would
           have put on both names. */
        if (DB::getDriverName() === 'sqlsrv') {
            $prefix = DB::getTablePrefix();

            DB::statement("create unique index {$prefix}mainstay_drafts_site_id_type_entry_id_unique on {$prefix}mainstay_drafts (site_id, type, entry_id) where entry_id is not null");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mainstay_drafts');
    }
};
