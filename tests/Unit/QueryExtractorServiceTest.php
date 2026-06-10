<?php

namespace Tests\Unit;

use App\Services\AI\QueryExtractorService;
use ReflectionClass;
use ReflectionMethod;
use PHPUnit\Framework\TestCase;

class QueryExtractorServiceTest extends TestCase
{
    public function test_it_discards_criminal_extraction_for_administrative_question(): void
    {
        $service = (new ReflectionClass(QueryExtractorService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'isClearlyUnrelatedExtraction');

        $discard = $method->invoke(
            $service,
            'საქართველოს ფინანსთა სამინისტროს საკასაციო საჩივრის დასაშვებლობის საკითხი ადმინისტრაციულ საქმეთა პროცესში.',
            "სასჯელი\nდანაშაული\nბრალი",
            'criminal',
        );

        $this->assertTrue($discard);
    }

    public function test_it_keeps_criminal_extraction_for_criminal_question(): void
    {
        $service = (new ReflectionClass(QueryExtractorService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'isClearlyUnrelatedExtraction');

        $discard = $method->invoke(
            $service,
            'სისხლის სამართლის საქმეში სასჯელის დანიშვნის საკითხი',
            "სასჯელი\nდანაშაული\nბრალი",
            'criminal',
        );

        $this->assertFalse($discard);
    }
}
