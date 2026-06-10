<?php

namespace Tests\Unit;

use App\Services\Legal\LegalDomainClassifier;
use PHPUnit\Framework\TestCase;

class LegalDomainClassifierTest extends TestCase
{
    public function test_it_detects_labor_domain_from_dismissal_word_forms(): void
    {
        $classifier = new LegalDomainClassifier();

        $this->assertSame(
            'labor',
            $classifier->classifyText('დამსაქმებელმა გამათავისუფლა წერილობითი დასაბუთების გარეშე')
        );
    }
}
