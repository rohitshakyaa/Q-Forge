<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extracted candidates arrive with a unit *hint* at best, and many past papers
 * never print their marks. Rather than invent a unit or a `marks = 0`, such
 * candidates land as `pending` with the field null; approving one requires both
 * to be filled in. The generator only reads `approved` rows, so it never sees a
 * question missing either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->unsignedBigInteger('unit_id')->nullable()->change();
            $table->unsignedInteger('marks')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Unreviewed candidates cannot be represented once the columns are NOT NULL.
        DB::table('questions')
            ->whereNull('unit_id')
            ->orWhereNull('marks')
            ->delete();

        Schema::table('questions', function (Blueprint $table) {
            $table->unsignedBigInteger('unit_id')->nullable(false)->change();
            $table->unsignedInteger('marks')->nullable(false)->change();
        });
    }
};
