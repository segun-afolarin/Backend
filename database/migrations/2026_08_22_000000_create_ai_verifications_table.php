<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Logs every REAL AI photo-verification result (report submissions and
     * report confirmations). Only rows where the AI actually ran and
     * returned a judgment get logged — skipped checks (missing key, Gemini
     * unreachable, unparseable response) are never inserted here, so
     * "AI Accuracy" computed from this table is never inflated by fail-open
     * cases.
     */
    public function up(): void
    {
        Schema::create('ai_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('context'); // 'report_submission' | 'report_confirmation'
            $table->string('category');
            $table->boolean('matches');
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_verifications');
    }
};