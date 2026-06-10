<?php

namespace Tests\Unit;

use App\DTOs\EchrResult;
use App\Services\Echr\EchrCitationBuilder;
use PHPUnit\Framework\TestCase;

class EchrCitationBuilderTest extends TestCase
{
    public function test_it_builds_the_frontend_echr_citation_shape(): void
    {
        $citation = (new EchrCitationBuilder())->build([
            new EchrResult(
                caseId: 1,
                hudocItemId: 'ITEM-1',
                applicationNumber: '57292/16',
                title: 'HURBAIN v. BELGIUM',
                judgmentDate: '2023-07-04',
                documentType: 'GRANDCHAMBER',
                importance: 1,
                echrArticles: ['10'],
                excerpt: 'Freedom of expression and reputation.',
                similarity: 0.8,
                sourceUrl: 'https://hudoc.echr.coe.int/example',
            ),
        ])[0];

        $this->assertSame('57292/16', $citation['application_no']);
        $this->assertSame('HURBAIN v. BELGIUM', $citation['case_name']);
        $this->assertSame(['10'], $citation['articles_violated']);
        $this->assertSame('https://hudoc.echr.coe.int/example', $citation['url']);
    }
}
