<?php

namespace Tests\Unit;

use App\Services\AI\EvalJudgeService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class EvalJudgeServiceTest extends TestCase
{
    public function test_prompt_includes_non_domestic_sources_for_citation_validation(): void
    {
        $service = (new ReflectionClass(EvalJudgeService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(EvalJudgeService::class, 'buildPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke(
            $service,
            'ფასთა დისპროპორცია და ამორალური გარიგება',
            'საკონსტიტუციო სასამართლოს გადაწყვეტილება N3/7/679 მნიშვნელოვანია დამხმარე კონტექსტად.',
            'advise',
            [],
            [
                [
                    'title' => 'საქართველოს სამოქალაქო კოდექსი',
                    '_article_num' => 54,
                    'url' => 'https://matsne.gov.ge/ka/document/view/31702',
                    'excerpt' => 'მუხლი 54. ბათილია გარიგება, რომელიც ეწინააღმდეგება ზნეობის ნორმებს.',
                ],
            ],
            [],
            [],
            [],
            [
                [
                    'case_number' => 'N3/7/679',
                    'legal_id' => '1038',
                    'decision_date' => '2017-12-29',
                    'url' => 'https://constcourt.ge/ka/judicial-acts?legal=1038',
                    'excerpt' => 'ფასთა შორის სხვაობა თავისთავად არ ნიშნავს გარიგების ბათილობას.',
                ],
            ],
        );

        $this->assertStringContainsString('Constitutional Court: N3/7/679', $prompt);
        $this->assertStringContainsString('article=54', $prompt);
        $this->assertStringContainsString('Constitutional Court, ECHR, EU, and German sources listed below are valid', $prompt);
    }
}
