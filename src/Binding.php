<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery;

use BackedEnum;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;

final readonly class Binding
{
    public function __construct(
        public mixed $value,
        public ParameterType $type = ParameterType::Auto,
    ) {
        $this->validate();
    }

    public function concrete(): self
    {
        if ($this->type !== ParameterType::Auto) {
            return $this;
        }

        return self::fromAutomaticValue($this->value);
    }

    public static function fromValue(mixed $value): self
    {
        if ($value instanceof self) {
            return $value->concrete();
        }

        if (!self::isAutomaticValue($value)) {
            throw new InvalidQueryException('Value is invalid for parameter type auto.');
        }

        return self::fromAutomaticValue($value);
    }

    private static function fromAutomaticValue(mixed $value): self
    {
        $value = $value instanceof BackedEnum ? $value->value : $value;

        return match (true) {
            $value === null => new self(null, ParameterType::Null),
            is_bool($value) => new self($value ? 1 : 0, ParameterType::Integer),
            is_int($value) => new self($value, ParameterType::Integer),
            is_float($value) => new self(self::normalizeFloat($value), ParameterType::String),
            is_string($value) => new self($value, ParameterType::String),
            default => throw new InvalidQueryException('The value cannot be normalized as a query binding.'),
        };
    }

    private static function isAutomaticValue(mixed $value): bool
    {
        return $value === null
            || is_bool($value)
            || is_int($value)
            || is_float($value)
            || is_string($value)
            || $value instanceof BackedEnum;
    }

    private function validate(): void
    {
        $valid = match ($this->type) {
            ParameterType::Auto => self::isAutomaticValue($this->value),
            ParameterType::Null => $this->value === null,
            ParameterType::Integer => is_int($this->value),
            ParameterType::String, ParameterType::Binary => is_string($this->value),
            ParameterType::Lob => is_resource($this->value),
        };

        if (!$valid) {
            throw new InvalidQueryException(sprintf('Value is invalid for parameter type %s.', $this->type->value));
        }
    }

    private static function normalizeFloat(float $value): string
    {
        if (!is_finite($value)) {
            throw new InvalidQueryException('Non-finite floats cannot be bound.');
        }

        return json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
