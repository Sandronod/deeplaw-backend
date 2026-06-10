<?php

namespace Tests\Unit;

use App\Services\Legal\LegalTriageService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class LegalTriageCaseTypeTest extends TestCase
{
    #[DataProvider('domainProvider')]
    public function test_it_resolves_case_type_from_all_substantive_domains(array $domains, ?string $expected): void
    {
        $service = (new ReflectionClass(LegalTriageService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'resolveCaseType');

        $this->assertSame($expected, $method->invoke($service, $domains));
    }

    public function test_it_relaxes_civil_filter_when_public_law_signals_are_present(): void
    {
        $service = (new ReflectionClass(LegalTriageService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'relaxCivilFilterForPublicLawSignals');

        $question = 'ზიანის ანაზღაურების ვალდებულება და ფინანსური პოლიციის პასუხისმგებლობა';

        $this->assertNull($method->invoke($service, $question, 'civil'));
        $this->assertSame('criminal', $method->invoke($service, $question, 'criminal'));
    }

    public static function domainProvider(): array
    {
        return [
            [['labor', 'procedure'], 'civil'],
            [['admin', 'procedure'], 'administrative'],
            [['procedure'], null],
            [['criminal'], 'criminal'],
            [['criminal', 'civil', 'echr'], null],
            [['echr'], null],
        ];
    }
}
