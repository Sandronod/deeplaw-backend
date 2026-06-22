<?php

namespace Tests\Unit;

use App\Services\Legal\LegalIssueNormMapService;
use App\Services\Legal\LegalNormCoveragePlannerService;
use PHPUnit\Framework\TestCase;

class LegalNormCoveragePlannerServiceTest extends TestCase
{
    public function test_it_converts_matched_issues_to_article_detector_refs(): void
    {
        $refs = $this->planner()->articleRefs(
            'საჯარო რეესტრში დაურეგისტრირებელი ბინა, ბანკის იპოთეკა, გაკოტრება, გარდაცვლილი მყიდველის სამკვიდრო, მეუღლეთა თანასაკუთრება, თაღლითობის განაჩენი, მტკიცების ტვირთი და 240 მყიდველის ერთობლივი სარჩელი.',
            ['civil', 'property', 'family'],
        );

        $this->assertContains(['num' => 183, 'code' => 'სკ'], $refs);
        $this->assertContains(['num' => 312, 'code' => 'სკ'], $refs);
        $this->assertContains(['num' => 286, 'code' => 'სკ'], $refs);
        $this->assertContains(['num' => 300, 'code' => 'სკ'], $refs);
        $this->assertContains(['num' => 5, 'code' => 'რეაბილიტაციისა და კრედიტორთა კოლექტიური დაკმაყოფილების შესახებ'], $refs);
        $this->assertContains(['num' => 1328, 'code' => 'სკ'], $refs);
        $this->assertContains(['num' => 1158, 'code' => 'სკ'], $refs);
        $this->assertContains(['num' => 106, 'code' => 'სსკ'], $refs);
        $this->assertContains(['num' => 102, 'code' => 'სსკ'], $refs);
        $this->assertContains(['num' => 86, 'code' => 'სსკ'], $refs);
    }

    public function test_it_routes_magistrate_boundary_to_civil_procedure_article_9(): void
    {
        $refs = $this->planner()->articleRefs(
            'სარჩელის ფასი ზუსტად 50 000 ლარია და საქმე ეხება მაგისტრატ მოსამართლეს.',
            ['procedure'],
        );

        $this->assertContains(['num' => 9, 'code' => 'სსკ'], $refs);
    }

    private function planner(): LegalNormCoveragePlannerService
    {
        $config = require __DIR__ . '/../../config/legal_issue_norms.php';
        $map = new LegalIssueNormMapService($config['issues']);

        return new LegalNormCoveragePlannerService($map);
    }
}
