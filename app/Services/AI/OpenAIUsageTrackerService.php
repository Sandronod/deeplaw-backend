<?php

namespace App\Services\AI;

class OpenAIUsageTrackerService
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $records = [];

    public function reset(): void
    {
        $this->records = [];
    }

    /**
     * @param array<string, mixed>|null $usage
     */
    public function recordChat(string $operation, string $model, ?array $usage): void
    {
        $this->record($operation, 'chat.completions', $model, $usage);
    }

    /**
     * @param array<string, mixed>|null $usage
     */
    public function recordEmbedding(string $operation, string $model, ?array $usage): void
    {
        $this->record($operation, 'embeddings', $model, $usage);
    }

    /**
     * @param array<string, mixed>|null $usage
     */
    private function record(string $operation, string $endpoint, string $model, ?array $usage): void
    {
        if (!(bool) config('openai.cost_tracking.enabled', true) || empty($usage)) {
            return;
        }

        $inputTokens = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
        $totalTokens = (int) ($usage['total_tokens'] ?? ($inputTokens + $outputTokens));
        $cachedInputTokens = (int) (
            $usage['prompt_tokens_details']['cached_tokens']
            ?? $usage['input_tokens_details']['cached_tokens']
            ?? 0
        );

        $pricing = $this->pricingForModel($model);
        $billableInputTokens = max(0, $inputTokens - $cachedInputTokens);
        $inputUsd = $billableInputTokens * ($pricing['input'] ?? 0.0) / 1_000_000;
        $cachedInputUsd = $cachedInputTokens * ($pricing['cached_input'] ?? ($pricing['input'] ?? 0.0)) / 1_000_000;
        $outputUsd = $outputTokens * ($pricing['output'] ?? 0.0) / 1_000_000;
        $costUsd = $inputUsd + $cachedInputUsd + $outputUsd;

        $this->records[] = [
            'operation' => $operation,
            'endpoint' => $endpoint,
            'model' => $model,
            'pricing_model' => $pricing['model'] ?? $model,
            'input_tokens' => $inputTokens,
            'cached_input_tokens' => $cachedInputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
            'input_usd' => $this->roundUsd($inputUsd),
            'cached_input_usd' => $this->roundUsd($cachedInputUsd),
            'output_usd' => $this->roundUsd($outputUsd),
            'cost_usd' => $this->roundUsd($costUsd),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        if (!(bool) config('openai.cost_tracking.enabled', true)) {
            return [
                'enabled' => false,
                'currency' => (string) config('openai.cost_tracking.currency', 'USD'),
                'calls' => 0,
            ];
        }

        $totals = [
            'input_tokens' => 0,
            'cached_input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'cost_usd' => 0.0,
        ];
        $byModel = [];

        foreach ($this->records as $record) {
            $model = (string) ($record['model'] ?? 'unknown');

            foreach (['input_tokens', 'cached_input_tokens', 'output_tokens', 'total_tokens'] as $key) {
                $totals[$key] += (int) ($record[$key] ?? 0);
            }
            $totals['cost_usd'] += (float) ($record['cost_usd'] ?? 0.0);

            $byModel[$model] ??= [
                'calls' => 0,
                'input_tokens' => 0,
                'cached_input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'cost_usd' => 0.0,
            ];
            $byModel[$model]['calls']++;
            foreach (['input_tokens', 'cached_input_tokens', 'output_tokens', 'total_tokens'] as $key) {
                $byModel[$model][$key] += (int) ($record[$key] ?? 0);
            }
            $byModel[$model]['cost_usd'] += (float) ($record['cost_usd'] ?? 0.0);
        }

        foreach ($byModel as $model => $modelTotals) {
            $byModel[$model]['cost_usd'] = $this->roundUsd((float) $modelTotals['cost_usd']);
            $byModel[$model]['cost_cents'] = $this->roundCents((float) $modelTotals['cost_usd'] * 100);
        }

        $totalUsd = $this->roundUsd((float) $totals['cost_usd']);

        return [
            'enabled' => true,
            'estimated' => true,
            'currency' => (string) config('openai.cost_tracking.currency', 'USD'),
            'calls' => count($this->records),
            'input_tokens' => $totals['input_tokens'],
            'cached_input_tokens' => $totals['cached_input_tokens'],
            'output_tokens' => $totals['output_tokens'],
            'total_tokens' => $totals['total_tokens'],
            'total_usd' => $totalUsd,
            'total_cents' => $this->roundCents($totalUsd * 100),
            'by_model' => $byModel,
            'operations' => $this->records,
            'pricing_source' => 'config.openai.cost_tracking.pricing_per_1m_tokens',
            'calculated_at' => now()->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pricingForModel(string $model): array
    {
        $pricing = (array) config('openai.cost_tracking.pricing_per_1m_tokens', []);
        $normalized = $this->pricingModelKey($model, $pricing);
        $rates = (array) ($pricing[$normalized] ?? []);

        return [
            'model' => $normalized,
            'input' => (float) ($rates['input'] ?? 0.0),
            'cached_input' => (float) ($rates['cached_input'] ?? ($rates['input'] ?? 0.0)),
            'output' => (float) ($rates['output'] ?? 0.0),
        ];
    }

    /**
     * @param array<string, mixed> $pricing
     */
    private function pricingModelKey(string $model, array $pricing): string
    {
        if (array_key_exists($model, $pricing)) {
            return $model;
        }

        $keys = array_keys($pricing);
        usort($keys, static fn (string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ($keys as $key) {
            if (str_starts_with($model, $key)) {
                return $key;
            }
        }

        return 'default';
    }

    private function roundUsd(float $value): float
    {
        return round($value, 8);
    }

    private function roundCents(float $value): float
    {
        return round($value, 4);
    }
}
