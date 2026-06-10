<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class LegalBenchmarkFixtureTest extends TestCase
{
    public function test_core_fixture_is_valid_and_has_expected_targets(): void
    {
        $path = dirname(__DIR__, 2) . '/tests/Fixtures/legal_core_benchmark.json';
        $payload = json_decode(file_get_contents($path), true);

        $this->assertIsArray($payload);
        $this->assertSame(1, $payload['version']);
        $this->assertGreaterThanOrEqual(30, count($payload['scenarios']));

        foreach ($payload['scenarios'] as $scenario) {
            $this->assertNotEmpty($scenario['id']);
            $this->assertNotEmpty($scenario['query']);
            $this->assertNotEmpty($scenario['expected']['matsne'] ?? []);

            foreach ($scenario['expected']['matsne'] as $expectedLaw) {
                $this->assertIsInt($expectedLaw['matsne_id']);
                $this->assertNotEmpty($expectedLaw['articles'] ?? []);
            }
        }
    }
}
