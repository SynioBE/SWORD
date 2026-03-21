<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('site_label')->nullable();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->string('php_version')->default('8.3');
            $table->string('db_name');
            $table->string('db_user');
            $table->text('db_password');
            $table->string('install_token', 64)->unique();
            $table->string('callback_signature', 64)->unique();
            $table->string('status')->default('pending');
            $table->string('current_step')->nullable();
            $table->json('install_log')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
