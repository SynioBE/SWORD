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
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('ip_address')->nullable();
            $table->string('hostname')->nullable();
            $table->string('timezone')->default('UTC');
            $table->string('region')->nullable();
            $table->string('provider')->nullable();
            $table->unsignedSmallInteger('ssh_port')->default(22);
            $table->string('provision_token', 64)->unique();
            $table->string('callback_signature', 64)->unique();
            $table->string('status')->default('pending');
            $table->string('current_step')->nullable();
            $table->json('provision_log')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
