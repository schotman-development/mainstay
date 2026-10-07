<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | The people who sign in to the admin: Mainstay's own, beside whatever
 | members the host site has, who are visitors rather than editors. One role
 | each, which the key keeps from being deleted while it is held, and the
 | capabilities granted or denied to this account alone.
 |
 | The email is held lowercased, since the unique index folds case on MySQL
 | and SQL Server and not on the others.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mainstay_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->foreignId('role_id')->constrained('mainstay_roles');
            $table->json('grants');
            $table->json('denials');
            $table->rememberToken();
            $table->datetimes();
        });

        Schema::create('mainstay_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->dateTime('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mainstay_password_reset_tokens');
        Schema::dropIfExists('mainstay_users');
    }
};
