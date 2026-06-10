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

    public function test_it_infers_civil_defect_fraud_and_limitation_articles_for_grounding(): void
    {
        $service = new ArticleDetectorService(new CanonicalLawResolverService());
        $method = new ReflectionMethod($service, 'inferConceptRefs');

        $result = $method->invoke(
            $service,
            'უძრავი ქონების ნასყიდობაში გამყიდველმა მყიდველი მოატყუა, არსებობდა დაფარული ნაკლი და ხანდაზმულობის ვადები შესაფასებელია.',
            ['civil_law', 'property'],
        );

        $this->assertContains(['num' => 81, 'code' => 'სკ'], $result);
        $this->assertContains(['num' => 84, 'code' => 'სკ'], $result);
        $this->assertContains(['num' => 490, 'code' => 'სკ'], $result);
        $this->assertContains(['num' => 491, 'code' => 'სკ'], $result);
        $this->assertContains(['num' => 492, 'code' => 'სკ'], $result);
        $this->assertContains(['num' => 494, 'code' => 'სკ'], $result);
        $this->assertContains(['num' => 495, 'code' => 'სკ'], $result);
        $this->assertContains(['num' => 497, 'code' => 'სკ'], $result);
        $this->assertContains(['num' => 129, 'code' => 'სკ'], $result);
        $this->assertContains(['num' => 130, 'code' => 'სკ'], $result);
    }

    public function test_it_infers_civil_article_55_for_price_disproportion(): void
    {
        $service = new ArticleDetectorService(new CanonicalLawResolverService());
        $method = new ReflectionMethod($service, 'inferConceptRefs');

        $result = $method->invoke(
            $service,
            'ნასყიდობაში ფასი საბაზრო ღირებულებაზე 3-ჯერ მეტია და მხარე ამორალურ გარიგებაზე დავობს.',
            ['civil_law'],
        );

        $this->assertContains(['num' => 54, 'code' => 'სკ'], $result);
        $this->assertContains(['num' => 55, 'code' => 'სკ'], $result);
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
