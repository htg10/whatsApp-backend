<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let businesses write as much AI knowledge/instructions as they want — widen
 * ai_instructions from TEXT (64 KB) to MEDIUMTEXT (16 MB).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->mediumText('ai_instructions')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->text('ai_instructions')->nullable()->change();
        });
    }
};
