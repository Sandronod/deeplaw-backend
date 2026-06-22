<?php

namespace Tests\Unit;

use App\Services\Matsne\ArticleDetectorService;
use App\Services\Matsne\CanonicalLawResolverService;
use App\Services\Legal\LegalIssueNormMapService;
use App\Services\Legal\LegalNormCoveragePlannerService;
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

    public function test_it_infers_large_casus_special_statutes_for_grounding(): void
    {
        $service = new ArticleDetectorService(new CanonicalLawResolverService());
        $method = new ReflectionMethod($service, 'inferConceptRefs');

        $result = $method->invoke(
            $service,
            'თანამშრომელი გაათავისუფლეს, აქვს არაკონკურენციის შეთანხმება, მოითხოვება 1 მლნ პირგასამტეხლო და პერსონალურ მონაცემთა გაჟონვის გამო ზიანის ანაზღაურება.',
            ['labor', 'civil'],
        );

        $this->assertContains(['num' => 16, 'code' => 'შრომის კოდექს'], $result);
        $this->assertContains(['num' => 44, 'code' => 'შრომის კოდექს'], $result);
        $this->assertContains(['num' => 46, 'code' => 'შრომის კოდექს'], $result);
        $this->assertContains(['num' => 60, 'code' => 'შრომის კოდექს'], $result);
        $this->assertContains(['num' => 417, 'code' => 'სკ'], $result);
        $this->assertContains(['num' => 420, 'code' => 'სკ'], $result);
        $this->assertContains(['num' => 17, 'code' => 'პერსონალურ მონაცემთა დაცვის შესახებ'], $result);
        $this->assertContains(['num' => 39, 'code' => 'პერსონალურ მონაცემთა დაცვის შესახებ'], $result);
        $this->assertContains(['num' => 43, 'code' => 'პერსონალურ მონაცემთა დაცვის შესახებ'], $result);
    }

    public function test_it_infers_admin_review_articles_for_personal_data_service_decision(): void
    {
        $service = new ArticleDetectorService(new CanonicalLawResolverService());
        $method = new ReflectionMethod($service, 'inferConceptRefs');

        $result = $method->invoke(
            $service,
            'პერსონალურ მონაცემთა დაცვის სამსახურის ადმინისტრაციული გადაწყვეტილების გასაჩივრება და სასამართლო კონტროლი უნდა შეფასდეს.',
            ['labor', 'civil'],
        );

        $this->assertContains(['num' => 22, 'code' => 'ადმ.საპ'], $result);
        $this->assertContains(['num' => 24, 'code' => 'ადმ.საპ'], $result);
        $this->assertContains(['num' => 32, 'code' => 'ადმ.საპ'], $result);
        $this->assertContains(['num' => 34, 'code' => 'ადმ.საპ'], $result);
        $this->assertContains(['num' => 60, 'code' => 'ზაკ'], $result);
        $this->assertContains(['num' => 96, 'code' => 'ზაკ'], $result);
    }

    public function test_it_infers_real_estate_mortgage_insolvency_and_inheritance_articles(): void
    {
        $service = new ArticleDetectorService(new CanonicalLawResolverService());
        $method = new ReflectionMethod($service, 'inferConceptRefs');

        $result = $method->invoke(
            $service,
            'მყიდველებს ბინის ფასი გადახდილი აქვთ, მაგრამ საკუთრება საჯარო რეესტრში არ არის რეგისტრირებული. ბანკს აქვს იპოთეკა და იწყებს ქონების რეალიზაციას. კომპანია გაკოტრების ზღვარზეა, ერთ-ერთი მყიდველი გარდაიცვალა, მეუღლე აცხადებს თანასაკუთრებას, აღძრულია თაღლითობის საქმე და 240 მყიდველი აპირებს კოლექტიურ სარჩელს.',
            ['civil', 'property', 'family'],
        );

        $this->assertContains(['num' => 183, 'code' => 'სკ'], $result);
        $this->assertContains(['num' => 312, 'code' => 'სკ'], $result);
        $this->assertContains(['num' => 286, 'code' => 'სკ'], $result);
        $this->assertContains(['num' => 300, 'code' => 'სკ'], $result);
        $this->assertContains(['num' => 5, 'code' => 'რეაბილიტაციისა და კრედიტორთა კოლექტიური დაკმაყოფილების შესახებ'], $result);
        $this->assertContains(['num' => 52, 'code' => 'რეაბილიტაციისა და კრედიტორთა კოლექტიური დაკმაყოფილების შესახებ'], $result);
        $this->assertContains(['num' => 1328, 'code' => 'სკ'], $result);
        $this->assertContains(['num' => 1339, 'code' => 'სკ'], $result);
        $this->assertContains(['num' => 1158, 'code' => 'სკ'], $result);
        $this->assertContains(['num' => 106, 'code' => 'სსკ'], $result);
        $this->assertContains(['num' => 86, 'code' => 'სსკ'], $result);
    }

    public function test_it_can_infer_articles_from_norm_registry_planner(): void
    {
        $config = require __DIR__ . '/../../config/legal_issue_norms.php';
        $planner = new LegalNormCoveragePlannerService(
            new LegalIssueNormMapService($config['issues']),
        );
        $service = new ArticleDetectorService(new CanonicalLawResolverService(), $planner);
        $method = new ReflectionMethod($service, 'inferConceptRefs');

        $result = $method->invoke(
            $service,
            'სარჩელის ფასი ზუსტად 50 000 ლარია და საქმე ეხება მაგისტრატი მოსამართლის განსჯადობას.',
            ['procedure'],
        );

        $this->assertContains(['num' => 9, 'code' => 'სსკ'], $result);
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
