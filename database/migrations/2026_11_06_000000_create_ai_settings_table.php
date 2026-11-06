<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Singleton AI-provider settings, managed by the Super Admin from the dashboard:
 * which provider the chatbot uses (gemini | openai | anthropic), whether AI is
 * enabled at all, and the per-provider API keys/models. Keys are encrypted via
 * the model cast. Falls back to the .env config when a value is blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->default('gemini');
            $table->boolean('enabled')->default(true);
            $table->text('gemini_key')->nullable();
            $table->text('openai_key')->nullable();
            $table->text('anthropic_key')->nullable();
            $table->string('gemini_model')->nullable();
            $table->string('openai_model')->nullable();
            $table->string('anthropic_model')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};
