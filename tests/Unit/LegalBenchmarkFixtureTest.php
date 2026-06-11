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

            foreach (['rule_triggers', 'outcome_categories', 'outcomes'] as $optionalStringList) {
                if (!isset($scenario['expected'][$optionalStringList])) {
                    continue;
                }

                $this->assertIsArray($scenario['expected'][$optionalStringList]);
                foreach ($scenario['expected'][$optionalStringList] as $item) {
                    $this->assertIsString($item);
                    $this->assertNotEmpty($item);
                }
            }

            if (isset($scenario['expected']['facts'])) {
                $this->assertIsArray($scenario['expected']['facts']);
                foreach ($scenario['expected']['facts'] as $fact) {
                    $this->assertIsArray($fact);
                    $this->assertNotEmpty($fact['key'] ?? null);
                    $this->assertArrayHasKey('value', $fact);
                }
            }
        }
    }
}
