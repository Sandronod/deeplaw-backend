<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class FullCaseController extends Controller
{
    public function show(string $type, int $caseId): JsonResponse
    {
        return $this->buildFromPgvector($caseId, $type);
    }

    public function showById(int $caseId): JsonResponse
    {
        return $this->buildFromPgvector($caseId, null);
    }

    private function buildFromPgvector(int $caseId, ?string $type): JsonResponse
    {
        $query = DB::connection('pgvector')
            ->table('cases')
            ->where('case_id', $caseId);

        if ($type) {
            $query->where('case_type', $type);
        }

        $chunks = $query
            ->orderByRaw("(meta->>'chunk_index')::int ASC")
            ->get(['content', 'case_num', 'case_date', 'case_type', 'meta']);

        if ($chunks->isEmpty()) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $first    = $chunks->first();
        $fullText = $chunks->pluck('content')->implode("\n\n");

        $content = '<div class="whitespace-pre-wrap leading-relaxed text-sm">'
            . htmlspecialchars($fullText, ENT_QUOTES, 'UTF-8')
            . '</div>';

        return response()->json([
            'case_id'      => $caseId,
            'case_type'    => $first->case_type,
            'case_num'     => $first->case_num,
            'case_date'    => $first->case_date,
            'content'      => $content,
            'content_type' => 'text',
        ]);
    }
}
