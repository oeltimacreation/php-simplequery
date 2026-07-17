<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\DatabaseProbe;

use JsonSerializable;
use Override;

/**
 * A redacted, serializable record produced by one behavior probe.
 *
 * @phpstan-type Observation array{
 *     name: non-empty-string,
 *     status: 'passed'|'failed'|'observed'|'skipped',
 *     details: array<string, mixed>
 * }
 */
final class ProbeReport implements JsonSerializable
{
    /** @var list<Observation> */
    private array $observations = [];

    /**
     * @param string $target
     * @param string $engine
     * @param array<string, mixed> $runtime
     */
    public function __construct(
        private readonly string $target,
        private readonly string $engine,
        private readonly array $runtime,
    ) {
    }

    /**
     * @param non-empty-string $name
     * @param array<string, mixed> $details
     */
    public function passed(string $name, array $details = []): void
    {
        $this->add($name, 'passed', $details);
    }

    /**
     * @param non-empty-string $name
     * @param array<string, mixed> $details
     */
    public function failed(string $name, array $details): void
    {
        $this->add($name, 'failed', $details);
    }

    /**
     * @param non-empty-string $name
     * @param array<string, mixed> $details
     */
    public function observed(string $name, array $details): void
    {
        $this->add($name, 'observed', $details);
    }

    /**
     * @param non-empty-string $name
     * @param array<string, mixed> $details
     */
    public function skipped(string $name, array $details): void
    {
        $this->add($name, 'skipped', $details);
    }

    public function hasFailures(): bool
    {
        foreach ($this->observations as $observation) {
            if ($observation['status'] === 'failed') {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'schema_version' => 1,
            'target' => $this->target,
            'engine' => $this->engine,
            'collected_at' => gmdate(DATE_ATOM),
            'runtime' => $this->runtime,
            'observations' => $this->observations,
        ];
    }

    /**
     * @param non-empty-string $name
     * @param 'passed'|'failed'|'observed'|'skipped' $status
     * @param array<string, mixed> $details
     */
    private function add(string $name, string $status, array $details): void
    {
        $this->observations[] = [
            'name' => $name,
            'status' => $status,
            'details' => $details,
        ];
    }
}
