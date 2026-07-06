<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('category');
            $table->string('title');
            $table->text('description');

            // Location — 'state' is the field we match reports to a viewer's location on
            $table->string('address');
            $table->string('state')->nullable()->index();
            $table->string('country')->default('Nigeria');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->enum('status', ['pending', 'in_progress', 'resolved'])->default('pending');

            // AI confidence score shown as "AI Priority" on the frontend (0-100)
            $table->unsignedTinyInteger('ai_score')->default(70);

            // Evidence photos submitted with the original report (array of storage paths)
            $table->json('images')->nullable();

            $table->unsignedInteger('required_confirmations')->default(5);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};