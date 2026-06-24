<?php

namespace Tests\Unit;

use App\Services\AI\QueryParserService;
use App\Services\Legal\KnowledgeSourceRouter;
use Tests\TestCase;

class KnowledgeSourceRouterTest extends TestCase
{
    public function test_criminal_procedure_signal_enables_echr_source_plan(): void
    {
        $router = new KnowledgeSourceRouter();

        $plan = $router->plan('ბრალდებულის პატიმრობის ვადა და აღკვეთის ღონისძიება რამდენად შეესაბამება სამართლიან სასამართლოს?');

        $this->assertTrue($plan->useEchr);
    }

    public function test_query_parser_detects_convention_article_seven(): void
    {
        $parser = new QueryParserService();

        $parsed = $parser->parse('რა სტანდარტი აქვს ECHR article 7 nulla poena საკითხზე?', 'article 7 nulla poena');

        $this->assertSame('7', $parsed->echrArticle);
        $this->assertTrue($parsed->hasEchrHint());
    }

    public function test_german_practice_signal_enables_german_source_plan(): void
    {
        $router = new KnowledgeSourceRouter();

        $plan = $router->plan('გერმანიის სასამართლო პრაქტიკა შრომითი ხელშეკრულების შეწყვეტაზე მომიძებნე');

        $this->assertTrue($plan->useGerman);
        $this->assertContains('german', $plan->sourcesActive());
    }

    public function test_eu_signal_enables_eu_source_plan(): void
    {
        $router = new KnowledgeSourceRouter();

        $plan = $router->plan('ევროკავშირის სამართლის პრაქტიკა მომხმარებელთა დაცვის საკითხზე მომიძებნე');

        $this->assertTrue($plan->useEu);
        $this->assertContains('eu', $plan->sourcesActive());
    }

    public function test_constitutional_court_signal_enables_const_court_source_plan(): void
    {
        $router = new KnowledgeSourceRouter();

        $plan = $router->plan('საკონსტიტუციო სასამართლოს პრაქტიკა საკუთრების უფლებაზე მომიძებნე');

        $this->assertTrue($plan->useConstCourt);
        $this->assertContains('const_court', $plan->sourcesActive());
    }
}
