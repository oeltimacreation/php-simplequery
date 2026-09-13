<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\Quality;

use RuntimeException;

final class BenchmarkComparisonGate
{
    /**
     * @param array<mixed> $baselineFirst
     * @param array<mixed> $candidateFirst
     * @return array{blocking: list<string>, controls: list<string>, signals: list<string>}
     */
    public function evaluate(array $baselineFirst, array $candidateFirst): array
    {
        $baseline = $this->reviewedOperations($baselineFirst);
        $candidate = $this->reviewedOperations($candidateFirst);
        $blocking = [];
        $controls = [];
        foreach (array_values(array_intersect($baseline, $candidate)) as $key) {
            if ($this->isControl($key)) {
                $controls[] = $key;
            } else {
                $blocking[] = $key;
            }
        }
        sort($blocking);
        sort($controls);
        $signals = array_values(array_unique([...$baseline, ...$candidate]));
        sort($signals);

        return ['blocking' => $blocking, 'controls' => $controls, 'signals' => $signals];
    }

    /** @param array<mixed> $report
     * @return list<string>
     */
    private function reviewedOperations(array $report): array
    {
        $review = $report['performance_review'] ?? null;
        if (!is_array($review)) {
            throw new RuntimeException('Benchmark comparison report has no performance review.');
        }
        $measurements = $review['measurements'] ?? null;
        if (!is_array($measurements)) {
            throw new RuntimeException('Benchmark comparison report has no measurements.');
        }
        $operations = [];
        foreach ($measurements as $scenario => $entries) {
            if (!is_string($scenario) || !is_array($entries)) {
                throw new RuntimeException('Benchmark comparison report has a malformed scenario.');
            }
            foreach ($entries as $operation => $measurement) {
                if (!is_string($operation) || !is_array($measurement)) {
                    throw new RuntimeException('Benchmark comparison report has a malformed measurement.');
                }
                $reviewRequired = $measurement['review_required'] ?? null;
                if (!is_bool($reviewRequired)) {
                    throw new RuntimeException('Benchmark comparison report has a malformed review flag.');
                }
                if ($reviewRequired) {
                    $operations[] = $scenario . '/' . $operation;
                }
            }
        }

        return $operations;
    }

    private function isControl(string $key): bool
    {
        $separator = strrpos($key, '/');
        $operation = $separator === false ? $key : substr($key, $separator + 1);

        return $operation === 'pdo'
            || str_starts_with($operation, 'pdo_')
            || str_ends_with($operation, '_control');
    }
}
