<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable()->after('server_id')->constrained()->nullOnDelete();
        });

        Schema::table('backup_schedules', function (Blueprint $table) {
            $table->dropColumn('repo_initialized');
        });
    }

    public function down(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_id');
        });

        Schema::table('backup_schedules', function (Blueprint $table) {
            $table->boolean('repo_initialized')->default(false);
        });
    }
};
