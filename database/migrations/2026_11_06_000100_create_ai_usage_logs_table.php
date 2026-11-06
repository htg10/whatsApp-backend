<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per AI API call, capturing the token usage Meta/Gemini/OpenAI/Anthropic
 * report back. Used to estimate spend (tokens x the admin's per-1M-token rate) on
 * the super-admin dashboard. This is OUR measured usage — the exact amount a
 * provider charges still lives in that provider's own billing console.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('provider', 20);
            $table->string('model', 100)->nullable();
            $table->string('kind', 20)->default('reply'); // reply | classify | vision
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
