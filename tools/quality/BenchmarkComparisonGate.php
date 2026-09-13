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
        $measurements = $this->measurements($report);
        $operations = [];
        foreach ($measurements as $scenario => $entries) {
            if (!is_string($scenario)) {
                throw new RuntimeException('Benchmark comparison report has a malformed scenario.');
            }
            array_push($operations, ...$this->scenarioReviews($scenario, $entries));
        }

        return $operations;
    }

    /** @param array<mixed> $report
     * @return array<mixed>
     */
    private function measurements(array $report): array
    {
        $review = $report['performance_review'] ?? null;
        if (!is_array($review)) {
            throw new RuntimeException('Benchmark comparison report has no performance review.');
        }
        $measurements = $review['measurements'] ?? null;
        if (!is_array($measurements)) {
            throw new RuntimeException('Benchmark comparison report has no measurements.');
        }

        return $measurements;
    }

    /** @return list<string> */
    private function scenarioReviews(string $scenario, mixed $entries): array
    {
        if (!is_array($entries)) {
            throw new RuntimeException('Benchmark comparison report has a malformed scenario.');
        }
        $operations = [];
        foreach ($entries as $operation => $measurement) {
            if (!is_string($operation)) {
                throw new RuntimeException('Benchmark comparison report has a malformed measurement.');
            }
            if ($this->reviewRequired($measurement)) {
                $operations[] = $scenario . '/' . $operation;
            }
        }

        return $operations;
    }

    private function reviewRequired(mixed $measurement): bool
    {
        if (!is_array($measurement)) {
            throw new RuntimeException('Benchmark comparison report has a malformed measurement.');
        }
        $reviewRequired = $measurement['review_required'] ?? null;
        if (!is_bool($reviewRequired)) {
            throw new RuntimeException('Benchmark comparison report has a malformed review flag.');
        }

        return $reviewRequired;
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
