<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | The media library: one row per image, installation-wide rather than per
 | site, since a second copy of the same logo is not a feature. The files are
 | named from the hash, so the row holds what they cannot say: the original's
 | kind and name, its upright size, the focal point every crop keeps in
 | frame, and alt text per locale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mainstay_media', function (Blueprint $table) {
            $table->id();
            /* SHA-256 of the original's bytes, which the library holds once:
               the index refuses the second of two uploads racing with them. */
            $table->char('hash', 64)->unique();
            $table->string('type');
            $table->string('name');
            $table->unsignedBigInteger('bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedTinyInteger('focal_x');
            $table->unsignedTinyInteger('focal_y');
            /* Keyed by locale; an empty string marks the image decorative. */
            $table->json('alt');
            /* Who uploaded it; none for an image code uploaded on its own
               authority. No key, as on a content table. */
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->datetimes();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mainstay_media');
    }
};
