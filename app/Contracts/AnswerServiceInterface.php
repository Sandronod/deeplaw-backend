<?php

namespace App\Contracts;

use App\DTOs\ConfidenceResult;

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
    ): string;

    /**
     * Stream answer tokens as a Generator.
     * Each yielded value is a string token.
     */
    public function streamTokens(
        string           $userQuestion,
        array            $decisions,
        array            $historyMessages  = [],
        int              $totalFound       = 0,
        string           $mode             = 'explain',
        ConfidenceResult $confidence       = new ConfidenceResult(0.0, 'none', ''),
        array            $lawResults       = [],
        array            $echrResults      = [],
    ): \Generator;
}
