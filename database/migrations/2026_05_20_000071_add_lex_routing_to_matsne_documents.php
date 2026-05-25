<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgvector')->table('matsne_documents', function (Blueprint $table) {
            $table->string('domain', 30)->nullable()->index();
            // 1=constitution, 2=organic_law, 3=law/code, 4=presidential, 5=government, 6=ministerial, 7=local
            $table->smallInteger('hierarchy_level')->nullable();
        });

        // Pre-fill hierarchy_level from doc_type (no API call needed)
        DB::connection('pgvector')->statement("
            UPDATE matsne_documents SET hierarchy_level = CASE
                WHEN doc_type ILIKE '%კონსტიტუცია%'                          THEN 1
                WHEN doc_type ILIKE '%ორგანული კანონი%'                       THEN 2
                WHEN doc_type ILIKE '%კანონი%' OR doc_type ILIKE '%კოდექსი%'  THEN 3
                WHEN doc_type ILIKE '%პრეზიდენტის%'                           THEN 4
                WHEN doc_type ILIKE '%მთავრობის%'                             THEN 5
                WHEN doc_type ILIKE '%მინისტრის%' OR doc_type ILIKE '%ბრძანება%' THEN 6
                WHEN doc_type ILIKE '%საკრებულო%' OR doc_type ILIKE '%მუნიციპალ%' THEN 7
                ELSE 5
            END
        ");

        DB::connection('pgvector')->statement(
            'CREATE INDEX IF NOT EXISTS matsne_docs_domain_hierarchy_idx
             ON matsne_documents (domain, hierarchy_level ASC)'
        );
    }

    public function down(): void
    {
        DB::connection('pgvector')->statement('DROP INDEX IF EXISTS matsne_docs_domain_hierarchy_idx');
        Schema::connection('pgvector')->table('matsne_documents', function (Blueprint $table) {
            $table->dropColumn(['domain', 'hierarchy_level']);
        });
    }
};
