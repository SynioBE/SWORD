<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->foreignId('integration_id')
                ->nullable()
                ->after('user_id')
                ->constrained('user_integrations')
                ->nullOnDelete();
            $table->string('server_type')->nullable()->after('provider');
            $table->string('image')->nullable()->after('server_type');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('integration_id');
            $table->dropColumn(['server_type', 'image']);
        });
    }
};
