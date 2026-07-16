<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A question may span several units (e.g. "compare X from Unit 2 with Y from
 * Unit 3"). `questions.unit_id` remains the *primary* unit — the one the
 * question is listed and allocated under — while this pivot records every unit
 * the question touches, primary included. Invariant: whenever `unit_id` is set
 * it also appears here, so a single-unit question has exactly one pivot row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_unit', function (Blueprint $table) {
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();

            $table->unique(['question_id', 'unit_id']);
            $table->index('unit_id');
        });

        // Backfill: every tagged question starts with its primary unit linked.
        DB::statement(
            'INSERT INTO question_unit (question_id, unit_id)
             SELECT id, unit_id FROM questions WHERE unit_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('question_unit');
    }
};
