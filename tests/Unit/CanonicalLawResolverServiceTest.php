<?php

namespace Tests\Unit;

use App\Services\Matsne\CanonicalLawResolverService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CanonicalLawResolverServiceTest extends TestCase
{
    #[DataProvider('aliasProvider')]
    public function test_it_maps_law_aliases_to_canonical_keys(string $alias, string $expected): void
    {
        $resolver = new CanonicalLawResolverService();

        $this->assertSame($expected, $resolver->lawKeyForAlias($alias));
    }

    public static function aliasProvider(): array
    {
        return [
            ['სკ', 'civil_code'],
            ['სსკ', 'civil_procedure_code'],
            ['სსსკ', 'criminal_procedure_code'],
            ['ადმ.საპ', 'admin_procedure_code'],
            ['შრ.კოდ', 'labor_code'],
            ['შრომის კოდექს', 'labor_code'],
        ];
    }
}
