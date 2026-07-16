<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Difficulty (easy | medium | hard) was display-only metadata: the generation
 * engine never filtered or ranked on it. Removed end-to-end (API, UI, seeds).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('difficulty');
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->string('difficulty')->nullable()->after('marks');
        });
    }
};
