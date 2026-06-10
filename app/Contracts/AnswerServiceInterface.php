<?php

namespace App\Contracts;

use App\DTOs\ConfidenceResult;
use App\DTOs\IssueList;
use App\DTOs\TriageResult;

interface AnswerServiceInterface
{
    /**
     * Generate a full (non-streaming) legal answer.
     */
    public function answer(
        string           $userQuestion,
        array            $decisions,
        array            $historyMessages  = [],
        int              $totalFound       = 0,
        string           $mode             = 'explain',
        ConfidenceResult $confidence       = new ConfidenceResult(0.0, 'none', ''),
        array            $lawResults       = [],
        array            $echrResults      = [],
        array            $matsneResults    = [],
        array            $euResults        = [],
        array            $germanResults       = [],
        array            $constCourtResults  = [],
        array            $sources            = ['court', 'matsne', 'echr', 'eu', 'german', 'const_court'],
        ?IssueList       $issueList          = null,
        ?TriageResult    $triage             = null,
    ): string;

    /**
     * Stream answer tokens as a Generator.
     * Each yielded value is a string token.
     */
    public function streamTokens(
        string           $userQuestion,
        array            $decisions,
        array            $historyMessages    = [],
        int              $totalFound         = 0,
        string           $mode               = 'explain',
        ConfidenceResult $confidence         = new ConfidenceResult(0.0, 'none', ''),
        array            $lawResults         = [],
        array            $echrResults        = [],
        array            $matsneResults      = [],
        array            $euResults          = [],
        array            $germanResults      = [],
        array            $constCourtResults  = [],
        array            $sources            = ['court', 'matsne', 'echr', 'eu', 'german', 'const_court'],
        ?IssueList       $issueList          = null,
        ?TriageResult    $triage             = null,
    ): \Generator;
}
