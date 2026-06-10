<?php

namespace Tests\Unit;

use App\Services\Matsne\ArticleDetectorService;
use App\Services\Matsne\CanonicalLawResolverService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ArticleDetectorServiceTest extends TestCase
{
    #[DataProvider('referenceProvider')]
    public function test_it_extracts_article_references(string $query, array $expected): void
    {
        $service = new ArticleDetectorService(new CanonicalLawResolverService());
        $method = new ReflectionMethod($service, 'extractRefs');

        $this->assertSame($expected, $method->invoke($service, $query));
    }

    public function test_it_infers_labor_termination_articles_for_grounding(): void
    {
        $service = new ArticleDetectorService(new CanonicalLawResolverService());
        $method = new ReflectionMethod($service, 'inferConceptRefs');

        $this->assertSame(
            [
                ['num' => 47, 'code' => 'შრომის კოდექს'],
                ['num' => 48, 'code' => 'შრომის კოდექს'],
            ],
            $method->invoke($service, 'დასაქმებული გაათავისუფლეს წერილობითი დასაბუთების გარეშე', ['labor'])
        );
    }

    public static function referenceProvider(): array
    {
        return [
            ['სკ-ის 54-ე მუხლი', [['num' => 54, 'code' => 'სკ']]],
            ['ადმ.საპ-ის 22-ე მუხლი', [['num' => 22, 'code' => 'ადმ.საპ']]],
            ['შრომის კოდექსის 47-ე მუხლი', [['num' => 47, 'code' => 'შრომის კოდექს']]],
            ['54-ე მუხლი', [['num' => 54, 'code' => '']]],
        ];
    }
}
