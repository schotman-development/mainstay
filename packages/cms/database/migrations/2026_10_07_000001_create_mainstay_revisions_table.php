<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | What was live before something replaced it: the whole entry -- its shared
 | fields, every locale's localized ones, its view and its terms -- in the
 | shape a draft holds, so restoring one is saving it as a draft. `entry_id`
 | is 0 for a global. `created_at` is when the content stopped being live,
 | and `published_by` who had put it there, copied from the entry's row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mainstay_revisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('type');
            $table->unsignedBigInteger('entry_id');
            $table->json('snapshot');
            $table->unsignedBigInteger('published_by')->nullable();
            $table->dateTime('created_at');

            $table->foreign('site_id')->references('id')->on('sites');
            /* So listing and pruning one entry's read its rows and no
               others'. */
            $table->index(['site_id', 'type', 'entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mainstay_revisions');
    }
};
