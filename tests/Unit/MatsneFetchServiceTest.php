<?php

namespace Tests\Unit;

use App\Services\Matsne\MatsneFetchService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MatsneFetchServiceTest extends TestCase
{
    public function test_it_fetches_the_current_consolidated_publication(): void
    {
        Http::fake([
            'https://matsne.gov.ge/ka/document/view/1155567/0' => Http::response(
                str_repeat('current consolidated text ', 30),
                200,
            ),
        ]);

        (new MatsneFetchService())->fetchHtml(1155567);

        Http::assertSent(
            fn ($request) => $request->url() === 'https://matsne.gov.ge/ka/document/view/1155567/0'
        );
    }
}
