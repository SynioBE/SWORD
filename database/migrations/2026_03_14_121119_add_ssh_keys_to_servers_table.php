<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->text('ssh_public_key')->nullable()->after('ssh_port');
            $table->text('ssh_private_key')->nullable()->after('ssh_public_key');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn(['ssh_public_key', 'ssh_private_key']);
        });
    }
};
