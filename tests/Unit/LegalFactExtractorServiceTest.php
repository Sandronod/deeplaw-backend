<?php

namespace Tests\Unit;

use App\Services\AI\LegalFactExtractorService;
use PHPUnit\Framework\TestCase;

class LegalFactExtractorServiceTest extends TestCase
{
    public function test_it_assigns_multiple_claim_values_to_the_nearest_claim_context(): void
    {
        $extractor = new LegalFactExtractorService();

        $facts = $extractor->extract(
            'ძირითადი სარჩელის ფასია 20 000 ლარი, ხოლო შეგებებული სარჩელის ფასია 50 000 ლარი. ორივე მაგისტრატი მოსამართლის განსჯადია?'
        );

        $byKey = [];
        foreach ($facts as $fact) {
            $byKey[$fact['key']][] = $fact['value'];
        }

        $this->assertSame([20000], $byKey['main_claim_value'] ?? []);
        $this->assertSame([50000], $byKey['counterclaim_value'] ?? []);
    }

    public function test_it_extracts_deadlines_with_contextual_keys(): void
    {
        $extractor = new LegalFactExtractorService();

        $facts = $extractor->extract(
            'გადაწყვეტილების გასაჩივრების ვადაა 30 დღე, ხოლო მოთხოვნაზე ვრცელდება 3 წლის ხანდაზმულობის ვადა.'
        );

        $byKey = [];
        foreach ($facts as $fact) {
            if (($fact['type'] ?? null) !== 'deadline') {
                continue;
            }

            $byKey[$fact['key']][] = [$fact['value'], $fact['unit']];
        }

        $this->assertSame([[30, 'day']], $byKey['appeal_deadline'] ?? []);
        $this->assertSame([[3, 'year']], $byKey['limitation_period'] ?? []);
    }

    public function test_it_extracts_procedural_stage_facts(): void
    {
        $extractor = new LegalFactExtractorService();

        $facts = $extractor->extract(
            'მოსამზადებელ სხდომაზე მოპასუხემ შემოიტანა შეგებებული სარჩელი.'
        );

        $stages = array_values(array_map(
            fn (array $fact) => $fact['value'],
            array_filter($facts, fn (array $fact) => ($fact['key'] ?? null) === 'procedural_stage')
        ));

        $this->assertContains('preparatory_hearing', $stages);
    }
}
