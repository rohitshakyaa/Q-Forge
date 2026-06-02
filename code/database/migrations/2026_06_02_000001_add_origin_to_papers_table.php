<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M3.1 — Imported past papers re-opens the schema M3 froze (its own milestone +
 * diagram update, not a silent edit). Two deltas to `papers`, nothing else:
 *   - blueprint_id becomes nullable (imported exams have no blueprint)
 *   - origin enum ('generated','imported') default 'generated'
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('papers', function (Blueprint $table) {
            // Nullability only — the FK references the column by name/type, which
            // MySQL 8 keeps intact across a NOT NULL -> NULL change (no FK drop).
            $table->unsignedBigInteger('blueprint_id')->nullable()->change();
            $table->enum('origin', ['generated', 'imported'])
                ->default('generated')
                ->after('blueprint_id');
        });
    }

    public function down(): void
    {
        Schema::table('papers', function (Blueprint $table) {
            $table->dropColumn('origin');
            $table->unsignedBigInteger('blueprint_id')->nullable(false)->change();
        });
    }
};
