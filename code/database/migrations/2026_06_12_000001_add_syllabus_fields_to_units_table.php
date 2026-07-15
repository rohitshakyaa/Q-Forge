<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fields carried across from an imported syllabus.
 *
 * `content` is the unit's syllabus body as markdown. It is the grounding context
 * for AI question generation: a shortfall names a unit, so the prompt's context is
 * a primary-key lookup rather than a retrieval over the whole subject.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->unsignedInteger('hours')->nullable()->after('position');
            $table->text('content')->nullable()->after('hours');
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn(['hours', 'content']);
        });
    }
};
