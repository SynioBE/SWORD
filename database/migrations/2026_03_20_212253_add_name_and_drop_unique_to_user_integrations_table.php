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
        Schema::table('user_integrations', function (Blueprint $table) {
            // Add a standalone index on user_id so MySQL can use it for the FK
            // constraint after we drop the composite unique index.
            // Guard: already exists on the dev DB from a partial first run.
            $existingIndexNames = array_column(Schema::getIndexes('user_integrations'), 'name');
            if (! in_array('user_integrations_user_id_index', $existingIndexNames, true)) {
                $table->index('user_id', 'user_integrations_user_id_index');
            }

            if (in_array('user_integrations_user_id_provider_unique', $existingIndexNames, true)) {
                $table->dropUnique(['user_id', 'provider']);
            }

            if (! Schema::hasColumn('user_integrations', 'name')) {
                $table->string('name')->after('user_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_integrations', function (Blueprint $table) {
            if (Schema::hasColumn('user_integrations', 'name')) {
                $table->dropColumn('name');
            }
            $table->unique(['user_id', 'provider']);
            $existingIndexNames = array_column(Schema::getIndexes('user_integrations'), 'name');
            if (in_array('user_integrations_user_id_index', $existingIndexNames, true)) {
                $table->dropIndex('user_integrations_user_id_index');
            }
        });
    }
};
