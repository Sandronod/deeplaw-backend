<?php

namespace App\Services\Matsne;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CanonicalLawResolverService
{
    private const LAW_TITLES = [
        'civil_code'              => ['საქართველოს სამოქალაქო კოდექსი'],
        'civil_procedure_code'    => ['საქართველოს სამოქალაქო საპროცესო კოდექსი'],
        'criminal_code'           => ['საქართველოს სისხლის სამართლის კოდექსი'],
        'criminal_procedure_code' => ['საქართველოს სისხლის სამართლის საპროცესო კოდექსი'],
        'general_admin_code'      => ['საქართველოს ზოგადი ადმინისტრაციული კოდექსი'],
        'admin_procedure_code'    => ['საქართველოს ადმინისტრაციული საპროცესო კოდექსი'],
        'labor_code'              => ['საქართველოს შრომის კოდექსი'],
        'tax_code'                => ['საქართველოს საგადასახადო კოდექსი'],
        'entrepreneurs_law'       => ['მეწარმეთა შესახებ'],
        'constitution'            => ['საქართველოს კონსტიტუცია'],
        'echr_convention'         => ['ადამიანის უფლებათა და ძირითად თავისუფლებათა დაცვის კონვენცია'],
    ];

    private const DOMAIN_LAWS = [
        'civil'           => ['civil_code'],
        'civil_law'       => ['civil_code'],
        'civil_procedure' => ['civil_procedure_code'],
        'procedure'       => ['civil_procedure_code'],
        'criminal'        => ['criminal_code', 'criminal_procedure_code'],
        'admin'           => ['general_admin_code', 'admin_procedure_code'],
        'administrative'  => ['general_admin_code', 'admin_procedure_code'],
        'labor'           => ['labor_code'],
        'property'        => ['civil_code'],
        'family'          => ['civil_code'],
        'corporate'       => ['entrepreneurs_law', 'civil_code'],
        'tax'             => ['tax_code'],
        'echr'            => ['echr_convention'],
    ];

    private const ALIASES = [
        'სკ'                                => 'civil_code',
        'სამ.კოდ'                           => 'civil_code',
        'სამოქ.კოდ'                         => 'civil_code',
        'სამ.კ'                             => 'civil_code',
        'სამოქალაქო კოდექს'                 => 'civil_code',
        'საქართველოს სამოქალაქო კოდექს'     => 'civil_code',
        'სსკ'                               => 'civil_procedure_code',
        'სამოქალაქო საპროცესო კოდექს'       => 'civil_procedure_code',
        'სსსკ'                              => 'criminal_procedure_code',
        'სსს'                               => 'criminal_procedure_code',
        'სისხლის სამართლის საპროცესო კოდექს' => 'criminal_procedure_code',
        'სისხლის სამართლის კოდექს'          => 'criminal_code',
        'ზაკ'                               => 'general_admin_code',
        'ზოგადი ადმინისტრაციული კოდექს'     => 'general_admin_code',
        'ადმ.საპ'                           => 'admin_procedure_code',
        'ადმ.საპ.კოდ'                       => 'admin_procedure_code',
        'ადმ.კოდ'                           => 'admin_procedure_code',
        'ადმინისტრაციული საპროცესო კოდექს'  => 'admin_procedure_code',
        'შრ.კოდ'                            => 'labor_code',
        'შრ.კ'                              => 'labor_code',
        'შრომის კოდექს'                     => 'labor_code',
        'საქართველოს შრომის კოდექს'         => 'labor_code',
        'საგ.კოდ'                           => 'tax_code',
        'საგადასახადო კოდექს'               => 'tax_code',
        'მეწარმეთა შესახებ'                 => 'entrepreneurs_law',
    ];

    public function resolveForDomains(array $domains, ?int $year = null): array
    {
        $keys = [];

        foreach ($domains as $domain) {
            foreach (self::DOMAIN_LAWS[$domain] ?? [] as $key) {
                $keys[$key] = true;
            }
        }

        return $this->resolveKeys(array_keys($keys), $year);
    }

    public function resolveAlias(string $alias, ?int $year = null): array
    {
        $key = $this->lawKeyForAlias($alias);

        return $key === null ? [] : $this->resolveKeys([$key], $year);
    }

    public function lawKeyForAlias(string $alias): ?string
    {
        $normalized = $this->normalizeAlias($alias);

        foreach (self::ALIASES as $candidate => $key) {
            if ($normalized === $this->normalizeAlias($candidate)) {
                return $key;
            }
        }

        return null;
    }

    public function aliases(): array
    {
        return array_keys(self::ALIASES);
    }

    public function resolveKeys(array $keys, ?int $year = null): array
    {
        $resolved = [];

        foreach (array_values(array_unique($keys)) as $key) {
            $document = $this->resolveKey($key, $year);
            if ($document !== null) {
                $resolved[$document['matsne_id']] = $document;
            }
        }

        return array_values($resolved);
    }

    private function resolveKey(string $key, ?int $year): ?array
    {
        $titles = self::LAW_TITLES[$key] ?? [];
        if (empty($titles)) {
            return null;
        }

        $cacheKey = 'canonical_law:' . $key . ':' . ($year ?? 'current');

        return Cache::remember($cacheKey, 3600, function () use ($titles, $year) {
            $rows = DB::connection('pgvector')
                ->table('matsne_documents as md')
                ->whereIn('md.title', $titles)
                ->whereExists(function ($query) {
                    $query->selectRaw('1')
                        ->from('matsne_chunks_v2 as mc')
                        ->whereColumn('mc.matsne_id', 'md.matsne_id')
                        ->whereNotNull('mc.embedding');
                })
                ->when($year !== null, function ($query) use ($year) {
                    $query->where(function ($q) use ($year) {
                        $q->whereNull('md.effective_from')
                            ->orWhereYear('md.effective_from', '<=', $year);
                    })->where(function ($q) use ($year) {
                        $q->whereNull('md.effective_to')
                            ->orWhereYear('md.effective_to', '>=', $year);
                    });
                })
                ->get([
                    'md.matsne_id',
                    'md.title',
                    'md.domain',
                    'md.is_active',
                    'md.effective_from',
                    'md.effective_to',
                    'md.hierarchy_level',
                ]);

            $best = $rows
                ->sortByDesc(fn ($row) => $this->documentPriority($row))
                ->first();

            return $best === null ? null : [
                'matsne_id'       => (int) $best->matsne_id,
                'title'           => $best->title,
                'domain'          => $best->domain,
                'is_active'       => (bool) $best->is_active,
                'effective_from'  => $best->effective_from,
                'effective_to'    => $best->effective_to,
                'hierarchy_level' => (int) ($best->hierarchy_level ?? 5),
            ];
        });
    }

    private function documentPriority(object $document): string
    {
        $active = $document->is_active ? '1' : '0';
        $openEnded = $document->effective_to === null ? '1' : '0';
        $id = str_pad((string) $document->matsne_id, 12, '0', STR_PAD_LEFT);
        $from = $document->effective_from
            ? str_replace('-', '', (string) $document->effective_from)
            : '00000000';

        return $active . $openEnded . $id . $from;
    }

    private function normalizeAlias(string $alias): string
    {
        return mb_strtolower(preg_replace('/[\s.]+/u', '', trim($alias)));
    }
}
