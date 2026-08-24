<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use RuntimeException;

final class NoiseAnalysis
{
    /**
     * @param list<array<string, mixed>> $runs
     * @return array{
     *     run_count: int,
     *     method: string,
     *     sub_millisecond_ceiling_ms: float,
     *     absolute_noise_floor_ms: float,
     *     measurements: array<string, array<string, array{
     *         medians_ms: list<float>,
     *         minimum_ms: float,
     *         maximum_ms: float,
     *         range_ms: float,
     *         range_percent: float|null,
     *         included_in_floor: bool
     *     }>>
     * }
     */
    public static function repeatedIdenticalSource(array $runs, float $subMillisecondCeilingMs = 1.0): array
    {
        if (count($runs) < 3) {
            throw new RuntimeException('Noise analysis requires at least three identical-source runs.');
        }
        if ($subMillisecondCeilingMs <= 0.0) {
            throw new RuntimeException('The sub-millisecond ceiling must be positive.');
        }

        $runMedians = array_map(ComparisonAnalysis::medians(...), $runs);
        $expectedScenarios = array_keys($runMedians[0]);
        foreach ($runMedians as $medians) {
            if (array_keys($medians) !== $expectedScenarios) {
                throw new RuntimeException('Identical-source benchmark scenarios differ.');
            }
        }

        $floor = 0.0;
        $measurements = [];
        foreach ($runMedians[0] as $scenario => $operations) {
            foreach ($operations as $operation => $_median) {
                $samples = [];
                foreach ($runMedians as $medians) {
                    if (array_keys($medians[$scenario]) !== array_keys($operations)) {
                        throw new RuntimeException(sprintf(
                            'Identical-source benchmark operations differ for scenario "%s".',
                            $scenario,
                        ));
                    }
                    $samples[] = $medians[$scenario][$operation];
                }
                $minimum = min($samples);
                $maximum = max($samples);
                $range = round($maximum - $minimum, 6);
                $rangePercent = $minimum === 0.0
                    ? null
                    : round(($range / $minimum) * 100, 3);
                $included = $maximum < $subMillisecondCeilingMs;
                if ($included) {
                    $floor = max($floor, $range);
                }
                $measurements[$scenario][$operation] = [
                    'medians_ms' => $samples,
                    'minimum_ms' => $minimum,
                    'maximum_ms' => $maximum,
                    'range_ms' => $range,
                    'range_percent' => $rangePercent,
                    'included_in_floor' => $included,
                ];
            }
        }

        return [
            'run_count' => count($runs),
            'method' => 'maximum median range across repeated identical-source sub-millisecond operations',
            'sub_millisecond_ceiling_ms' => $subMillisecondCeilingMs,
            'absolute_noise_floor_ms' => round($floor, 6),
            'measurements' => $measurements,
        ];
    }
}
