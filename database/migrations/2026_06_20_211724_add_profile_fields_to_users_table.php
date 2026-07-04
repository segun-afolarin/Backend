<?php
// Save this file as:
// database/migrations/xxxx_xx_xx_xxxxxx_add_profile_fields_to_users_table.php
//
// Generate it properly with:
//   php artisan make:migration add_profile_fields_to_users_table --table=users
// then replace the generated file's contents with the code below.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->string('location')->nullable()->after('phone');
            $table->string('avatar')->nullable()->after('location'); // stores file PATH, not the file itself
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'location', 'avatar']);
        });
    }
};