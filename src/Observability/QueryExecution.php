<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Observability;

use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\ParameterType;

final readonly class QueryExecution
{
    /** @var list<ParameterType> */
    public array $parameterTypes;

    /**
     * @param list<ParameterType> $parameterTypes
     */
    public function __construct(
        public string $sql,
        array $parameterTypes,
        public float $durationMilliseconds,
        public bool $successful,
        public ?int $affectedRows,
        public Driver $driver,
        public ?string $connectionLabel,
        public ?int $transactionDepth,
    ) {
        $this->parameterTypes = self::validatedParameterTypes($parameterTypes);
        if (trim($this->sql) === '') {
            throw new InvalidQueryException('Observed SQL cannot be empty.');
        }
        if (!is_finite($this->durationMilliseconds) || $this->durationMilliseconds < 0) {
            throw new InvalidQueryException('Observed duration must be a finite non-negative value.');
        }
        self::validateNonNegative($this->affectedRows, 'Observed affected rows cannot be negative.');
        self::validateNonNegative($this->transactionDepth, 'Observed transaction depth cannot be negative.');
    }

    private static function validateNonNegative(?int $value, string $message): void
    {
        if ($value !== null && $value < 0) {
            throw new InvalidQueryException($message);
        }
    }

    /**
     * @param array<mixed> $parameterTypes
     * @return list<ParameterType>
     */
    private static function validatedParameterTypes(array $parameterTypes): array
    {
        if (!array_is_list($parameterTypes)) {
            throw new InvalidQueryException('Observed parameter types must be a list.');
        }

        $validated = [];
        foreach ($parameterTypes as $parameterType) {
            if (!$parameterType instanceof ParameterType) {
                throw new InvalidQueryException('Every observed parameter type must be a ParameterType.');
            }
            $validated[] = $parameterType;
        }

        return $validated;
    }
}
