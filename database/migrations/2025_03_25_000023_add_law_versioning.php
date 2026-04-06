<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgvector';

    public function up(): void
    {
        // ── 1. law_versions ──────────────────────────────────────────────────
        Schema::connection('pgvector')->create('law_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('law_id')->constrained('laws')->cascadeOnDelete();
            $table->date('version_date')->nullable();
            $table->string('version_label', 64)->nullable(); // e.g. "2024-01-01 რედაქცია"
            $table->boolean('is_current')->default(true);
            $table->text('change_note')->nullable();
            $table->timestamp('fetched_at')->useCurrent();
        });

        // ── 2. law_version_id on law_articles (nullable — backward compat) ───
        Schema::connection('pgvector')->table('law_articles', function (Blueprint $table) {
            $table->foreignId('law_version_id')
                  ->nullable()
                  ->constrained('law_versions')
                  ->nullOnDelete();
        });

        // ── 3. current_version_id on laws ────────────────────────────────────
        Schema::connection('pgvector')->table('laws', function (Blueprint $table) {
            $table->foreignId('current_version_id')
                  ->nullable()
                  ->constrained('law_versions')
                  ->nullOnDelete();
        });

        // ── 4. Back-fill: create a default version for each existing law ─────
        $laws = DB::connection('pgvector')->table('laws')->get();
        foreach ($laws as $law) {
            $versionId = DB::connection('pgvector')->table('law_versions')->insertGetId([
                'law_id'        => $law->id,
                'version_date'  => $law->updated_at ?? now(),
                'version_label' => 'initial',
                'is_current'    => true,
                'fetched_at'    => now(),
            ]);

            DB::connection('pgvector')
                ->table('law_articles')
                ->where('law_id', $law->id)
                ->update(['law_version_id' => $versionId]);

            DB::connection('pgvector')
                ->table('laws')
                ->where('id', $law->id)
                ->update(['current_version_id' => $versionId]);
        }
    }

    public function down(): void
    {
        Schema::connection('pgvector')->table('laws', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_version_id');
        });
        Schema::connection('pgvector')->table('law_articles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('law_version_id');
        });
        Schema::connection('pgvector')->dropIfExists('law_versions');
    }
};
