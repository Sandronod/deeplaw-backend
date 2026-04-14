<?php

namespace App\Http\Controllers\Api;

use App\Models\CaseAdmin;
use App\Models\CaseCivil;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class FullCaseController extends Controller
{
    public function show(string $type, int $caseId): JsonResponse
    {
        $record = match ($type) {
            'civil'          => CaseCivil::with('DecisionCivil')->where('CaseID', $caseId)->first(),
            'administrative' => CaseAdmin::with('DecisionAdmin')->where('CaseID', $caseId)->first(),
            default          => null,
        };

        if (!$record) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $decision = $type === 'civil'
            ? $record->DecisionCivil
            : $record->DecisionAdmin;

        $html = $decision?->FileHtml ?? '';
        $text = $decision?->DecFullText ?? '';

        // Prefer HTML, fallback to plain text wrapped in <pre>
        if (!empty(trim(strip_tags($html)))) {
            $content     = $this->sanitizeHtml($html);
            $contentType = 'html';
        } elseif (!empty(trim($text))) {
            $content     = '<pre class="whitespace-pre-wrap">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</pre>';
            $contentType = 'text';
        } else {
            return response()->json(['error' => 'No content available'], 404);
        }

        return response()->json([
            'case_id'      => $caseId,
            'case_type'    => $type,
            'case_num'     => $record->CaseNum      ?? null,
            'case_date'    => $record->CaseDate      ? date('Y-m-d', strtotime($record->CaseDate)) : null,
            'content'      => $content,
            'content_type' => $contentType,
        ]);
    }

    private function sanitizeHtml(string $html): string
    {
        // Remove script/style/object/embed tags entirely
        $html = preg_replace('/<(script|style|object|embed|iframe|form)[^>]*>.*?<\/\1>/si', '', $html);
        // Remove on* event attributes and javascript: hrefs
        $html = preg_replace('/\s+on\w+="[^"]*"/i', '', $html);
        $html = preg_replace('/\s+on\w+=\'[^\']*\'/i', '', $html);
        $html = preg_replace('/href\s*=\s*["\']javascript:[^"\']*["\']/i', 'href="#"', $html);

        return $html;
    }
}
