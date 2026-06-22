<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class LargeCasusBenchmarkFixtureTest extends TestCase
{
    public function test_public_procurement_expropriation_fixture_is_valid(): void
    {
        $path = dirname(__DIR__, 2) . '/tests/Fixtures/large_casus_public_procurement_expropriation.json';
        $payload = json_decode(file_get_contents($path), true);

        $this->assertIsArray($payload);
        $this->assertSame(1, $payload['version']);
        $this->assertNotEmpty($payload['scenarios']);

        $scenario = $payload['scenarios'][0];
        $this->assertSame('admin_procurement_expropriation_large_casus', $scenario['id']);
        $this->assertSame('large_casus_answer_quality', $scenario['type']);
        $this->assertGreaterThan(1200, mb_strlen($scenario['query']));

        $expected = $scenario['expected'];
        foreach (['domains', 'issue_keys', 'facts', 'answer_must_include', 'forbidden_claims', 'preferred_structure'] as $key) {
            $this->assertNotEmpty($expected[$key] ?? [], "{$key} should not be empty");
        }

        $this->assertContains('procurement.disqualification_legality', $expected['issue_keys']);
        $this->assertContains('expropriation.fair_compensation', $expected['issue_keys']);
        $this->assertContains('procedure.active_standing_geobuild', $expected['issue_keys']);

        foreach ($expected['facts'] as $fact) {
            $this->assertNotEmpty($fact['key'] ?? null);
            $this->assertArrayHasKey('value', $fact);
        }

        $forbidden = implode("\n", $expected['forbidden_claims']);
        $this->assertStringContainsString('55-ე მუხლი', $forbidden);
        $this->assertStringContainsString('ავტომატურად', $forbidden);
    }
}
