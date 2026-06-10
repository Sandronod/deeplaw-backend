<?php

namespace Tests\Unit;

use App\Services\Matsne\MatsneHtmlParserService;
use Tests\TestCase;

class MatsneHtmlParserServiceTest extends TestCase
{
    public function test_it_extracts_current_old_style_matsne_articles_separately(): void
    {
        $html = <<<'HTML'
            <html>
              <head><title>საქართველოს შრომის კოდექსი</title></head>
              <body>
                <div id="maindoc">
                  <p><a class="oldStyleDocumentPart" name="part_174"><b>მუხლი 48. შრომითი ხელშეკრულების შეწყვეტის წესი</b></a></p>
                  <p>4. დასაქმებულს უფლება აქვს 30 კალენდარული დღის ვადაში მოითხოვოს წერილობითი დასაბუთება.</p>
                  <p>5. დამსაქმებელი ვალდებულია 7 კალენდარული დღის ვადაში უპასუხოს.</p>
                  <p><a class="oldStyleDocumentPart" name="part_175"><b>მუხლი 49. მასობრივი დათხოვნა</b></a></p>
                  <p>მასობრივი დათხოვნის სპეციალური წესი.</p>
                </div>
              </body>
            </html>
            HTML;

        $articles = (new MatsneHtmlParserService())->parse($html, 1155567)['articles'];

        $this->assertCount(2, $articles);
        $this->assertSame('მუხლი 48', $articles[0]['article_num']);
        $this->assertSame('შრომითი ხელშეკრულების შეწყვეტის წესი', $articles[0]['article_title']);
        $this->assertStringContainsString('7 კალენდარული დღის', $articles[0]['content']);
        $this->assertStringNotContainsString('მასობრივი დათხოვნის სპეციალური წესი', $articles[0]['content']);
        $this->assertSame('მუხლი 49', $articles[1]['article_num']);
    }
}
