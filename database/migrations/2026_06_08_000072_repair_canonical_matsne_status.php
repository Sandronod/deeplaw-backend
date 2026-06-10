<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgvector';

    public function up(): void
    {
        DB::connection('pgvector')->statement(<<<'SQL'
            WITH ranked AS (
                SELECT
                    md.id,
                    ROW_NUMBER() OVER (
                        PARTITION BY md.title
                        ORDER BY
                            (md.effective_to IS NULL) DESC,
                            md.matsne_id DESC,
                            md.effective_from DESC NULLS LAST
                    ) AS row_num
                FROM matsne_documents md
                WHERE md.title IN (
                    'საქართველოს სამოქალაქო კოდექსი',
                    'საქართველოს სამოქალაქო საპროცესო კოდექსი',
                    'საქართველოს სისხლის სამართლის კოდექსი',
                    'საქართველოს სისხლის სამართლის საპროცესო კოდექსი',
                    'საქართველოს ზოგადი ადმინისტრაციული კოდექსი',
                    'საქართველოს ადმინისტრაციული საპროცესო კოდექსი',
                    'საქართველოს შრომის კოდექსი',
                    'საქართველოს საგადასახადო კოდექსი',
                    'მეწარმეთა შესახებ',
                    'საქართველოს კონსტიტუცია',
                    'ადამიანის უფლებათა და ძირითად თავისუფლებათა დაცვის კონვენცია'
                )
                AND EXISTS (
                    SELECT 1
                    FROM matsne_chunks_v2 mc
                    WHERE mc.matsne_id = md.matsne_id
                      AND mc.embedding IS NOT NULL
                )
            ),
            selected AS (
                SELECT id FROM ranked WHERE row_num = 1
            )
            UPDATE matsne_documents md
            SET is_active = EXISTS (
                SELECT 1 FROM selected s WHERE s.id = md.id
            )
            WHERE md.title IN (
                'საქართველოს სამოქალაქო კოდექსი',
                'საქართველოს სამოქალაქო საპროცესო კოდექსი',
                'საქართველოს სისხლის სამართლის კოდექსი',
                'საქართველოს სისხლის სამართლის საპროცესო კოდექსი',
                'საქართველოს ზოგადი ადმინისტრაციული კოდექსი',
                'საქართველოს ადმინისტრაციული საპროცესო კოდექსი',
                'საქართველოს შრომის კოდექსი',
                'საქართველოს საგადასახადო კოდექსი',
                'მეწარმეთა შესახებ',
                'საქართველოს კონსტიტუცია',
                'ადამიანის უფლებათა და ძირითად თავისუფლებათა დაცვის კონვენცია'
            )
            SQL);

        DB::connection('pgvector')->statement(<<<'SQL'
            UPDATE matsne_chunks_v2 mc
            SET is_active = md.is_active
            FROM matsne_documents md
            WHERE md.matsne_id = mc.matsne_id
              AND md.id IN (
                  SELECT canonical.id
                  FROM matsne_documents canonical
                  WHERE canonical.title IN (
                      'საქართველოს სამოქალაქო კოდექსი',
                      'საქართველოს სამოქალაქო საპროცესო კოდექსი',
                      'საქართველოს სისხლის სამართლის კოდექსი',
                      'საქართველოს სისხლის სამართლის საპროცესო კოდექსი',
                      'საქართველოს ზოგადი ადმინისტრაციული კოდექსი',
                      'საქართველოს ადმინისტრაციული საპროცესო კოდექსი',
                      'საქართველოს შრომის კოდექსი',
                      'საქართველოს საგადასახადო კოდექსი',
                      'მეწარმეთა შესახებ',
                      'საქართველოს კონსტიტუცია',
                      'ადამიანის უფლებათა და ძირითად თავისუფლებათა დაცვის კონვენცია'
                  )
              )
              AND mc.is_active IS DISTINCT FROM md.is_active
            SQL);
    }

    public function down(): void
    {
        // Data correction is intentionally irreversible.
    }
};
