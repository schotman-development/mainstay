<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->string('handle')->unique();
            $table->string('name');
            /* Null matches any host, which is what a single site wants: local,
               staging and production reach the same row. */
            $table->string('hostname')->nullable()->unique();
        });

        /* Seeded here rather than by a seeder, because every content table
           references a site and an installation with none cannot hold content. */
        DB::table('sites')->insert([
            'handle' => 'default',
            'name' => config('app.name'),
            'hostname' => null,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
