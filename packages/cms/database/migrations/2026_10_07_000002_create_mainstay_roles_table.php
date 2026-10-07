<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | Roles: a name and the capabilities it holds, the part an administrator
 | edits. The capabilities themselves are never stored; they are computed
 | from the registered types, so a role naming one no type derives any longer
 | holds nothing by it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mainstay_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->json('capabilities');
            $table->datetimes();
        });

        /* The two a package can ship. It cannot know a site's types, so the
           rest name each type's own capabilities and are the site's to make. */
        DB::table('mainstay_roles')->insert([
            ['name' => 'administrator', 'capabilities' => json_encode(['*'])],
            ['name' => 'editor', 'capabilities' => json_encode([
                'edit_entries', 'edit_published_entries', 'edit_others_entries', 'publish_entries', 'delete_entries', 'delete_others_entries',
                'edit_globals', 'publish_globals',
                'manage_terms',
                'upload_media', 'edit_others_media',
            ])],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('mainstay_roles');
    }
};
