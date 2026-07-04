<?php
// Save as: database/migrations/xxxx_xx_xx_xxxxxx_add_location_fields_to_users_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('address')->nullable()->after('email');
            $table->string('state')->nullable()->after('address');
            $table->string('country')->nullable()->after('state');
            $table->decimal('latitude', 10, 7)->nullable()->after('country');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');

            // Single source of truth for "has this user finished location
            // setup" — checked by the frontend to decide whether to redirect
            // to /location-setup or straight to the dashboard.
            $table->boolean('has_location')->default(false)->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['address', 'state', 'country', 'latitude', 'longitude', 'has_location']);
        });
    }
};