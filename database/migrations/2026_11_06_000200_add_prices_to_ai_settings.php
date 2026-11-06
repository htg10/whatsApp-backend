<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-provider price (INR per 1,000,000 tokens), editable by the Super Admin, so
 * the dashboard can turn measured token usage into an estimated ₹ cost. Defaults
 * are indicative starting points — the admin should set their provider's current
 * published rates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            // INR per 1M tokens. Defaults ~ provider list price x an illustrative
            // 90 INR/USD; the admin overrides with the real current rate.
            $table->decimal('gemini_price_in', 10, 2)->default(67.50);
            $table->decimal('gemini_price_out', 10, 2)->default(337.50);
            $table->decimal('openai_price_in', 10, 2)->default(13.50);
            $table->decimal('openai_price_out', 10, 2)->default(54.00);
            $table->decimal('anthropic_price_in', 10, 2)->default(90.00);
            $table->decimal('anthropic_price_out', 10, 2)->default(450.00);
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn([
                'gemini_price_in', 'gemini_price_out',
                'openai_price_in', 'openai_price_out',
                'anthropic_price_in', 'anthropic_price_out',
            ]);
        });
    }
};
