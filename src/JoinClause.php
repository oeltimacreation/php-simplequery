<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery;

use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\Expression\RawExpression;
use Oeltima\SimpleQuery\Internal\Ast\ComparisonOperator;
use Oeltima\SimpleQuery\Internal\Ast\ComparisonPredicate;
use Oeltima\SimpleQuery\Internal\Ast\ConditionCollection;
use Oeltima\SimpleQuery\Internal\Ast\ConditionTerm;
use Oeltima\SimpleQuery\Internal\Ast\ExpressionComparisonPredicate;
use Oeltima\SimpleQuery\Internal\Ast\ExpressionIdentifierComparisonPredicate;
use Oeltima\SimpleQuery\Internal\Ast\IdentifierComparisonPredicate;
use Oeltima\SimpleQuery\Internal\Ast\Predicate;
use Oeltima\SimpleQuery\Internal\Ast\RawPredicate;
use Oeltima\SimpleQuery\Internal\InputNormalizer;
use Oeltima\SimpleQuery\Internal\MissingArgument;

final class JoinClause
{
    private const MISSING = MissingArgument::Value;

    private readonly ConditionCollection $conditions;

    /** @internal */
    public function __construct()
    {
        $this->conditions = new ConditionCollection();
    }

    public function on(
        RawExpression|string|Identifier $left,
        mixed $operator = self::MISSING,
        mixed $right = self::MISSING,
        mixed ...$extra,
    ): self {
        return $this->addIdentifierCondition(false, $left, $operator, $right, $extra);
    }

    public function orOn(
        RawExpression|string|Identifier $left,
        mixed $operator = self::MISSING,
        mixed $right = self::MISSING,
        mixed ...$extra,
    ): self {
        return $this->addIdentifierCondition(true, $left, $operator, $right, $extra);
    }

    public function onValue(
        RawExpression|string|Identifier $expression,
        string $operator,
        mixed $value,
    ): self {
        return $this->addValueCondition(false, $expression, $operator, $value);
    }

    public function orOnValue(
        RawExpression|string|Identifier $expression,
        string $operator,
        mixed $value,
    ): self {
        return $this->addValueCondition(true, $expression, $operator, $value);
    }

    public function where(
        RawExpression|string|Identifier $expression,
        mixed $operatorOrValue,
        mixed $value = self::MISSING,
    ): self {
        $operator = $value === self::MISSING ? '=' : $operatorOrValue;
        $comparisonValue = $value === self::MISSING ? $operatorOrValue : $value;
        if (!is_string($operator)) {
            throw new InvalidQueryException('Join value operator must be a string.');
        }

        return $this->addValueCondition(false, $expression, $operator, $comparisonValue);
    }

    /** @internal */
    public function snapshot(): ConditionCollection
    {
        if ($this->conditions->isEmpty()) {
            throw new InvalidQueryException('A join condition cannot be empty.');
        }

        return $this->conditions->copy();
    }

    /** @param array<array-key, mixed> $extra */
    private function addIdentifierCondition(
        bool $or,
        RawExpression|string|Identifier $left,
        mixed $operator,
        mixed $right,
        array $extra = [],
    ): self {
        if ($operator === self::MISSING && $extra === []) {
            return $this->addRawCondition($or, $left);
        }
        if ($right === self::MISSING || $extra !== []) {
            throw new InvalidQueryException(
                'Join on() requires expression/identifier, operator, and expression/identifier operands.',
            );
        }

        $predicate = $this->identifierPredicate(
            $this->joinOperand($left),
            $this->joinOperator($operator),
            $this->joinOperand($right),
        );
        $this->conditions->add(new ConditionTerm($predicate, $or));

        return $this;
    }

    private function addRawCondition(bool $or, RawExpression|string|Identifier $condition): self
    {
        if (!$condition instanceof RawExpression) {
            throw new InvalidQueryException(
                'Join on() requires expression/identifier, operator, and expression/identifier operands.',
            );
        }

        $this->conditions->add(new ConditionTerm(new RawPredicate($condition), $or));

        return $this;
    }

    private function identifierPredicate(
        RawExpression|Identifier $left,
        ComparisonOperator $operator,
        RawExpression|Identifier $right,
    ): Predicate {
        if ($left instanceof RawExpression) {
            return $this->rawLeftPredicate($left, $operator, $right);
        }
        if ($right instanceof RawExpression) {
            return new ExpressionIdentifierComparisonPredicate($left, $operator, $right);
        }

        return new IdentifierComparisonPredicate($left, $operator, $right);
    }

    private function rawLeftPredicate(
        RawExpression $left,
        ComparisonOperator $operator,
        RawExpression|Identifier $right,
    ): ExpressionIdentifierComparisonPredicate {
        if ($right instanceof RawExpression) {
            throw new InvalidQueryException('A structured join comparison accepts only one trusted raw expression.');
        }

        return new ExpressionIdentifierComparisonPredicate($left, $operator, $right);
    }

    private function joinOperand(mixed $operand): RawExpression|Identifier
    {
        if ($operand instanceof RawExpression) {
            return $operand;
        }
        if ($operand instanceof Identifier) {
            return $operand;
        }
        if (is_string($operand)) {
            return InputNormalizer::identifier($operand);
        }

        throw new InvalidQueryException(
            'Join on() requires expression/identifier, operator, and expression/identifier operands.',
        );
    }

    private function joinOperator(mixed $operator): ComparisonOperator
    {
        if (!is_string($operator)) {
            throw new InvalidQueryException(
                'Join on() requires expression/identifier, operator, and expression/identifier operands.',
            );
        }

        return ComparisonOperator::normalize($operator);
    }

    private function addValueCondition(
        bool $or,
        RawExpression|string|Identifier $expression,
        string $operator,
        mixed $value,
    ): self {
        $binding = Binding::fromValue($value);
        if ($binding->type === ParameterType::Null) {
            throw new InvalidQueryException('Use a trusted raw condition for join null semantics.');
        }
        $normalizedOperator = ComparisonOperator::normalize($operator);
        $predicate = $expression instanceof RawExpression
            ? new ExpressionComparisonPredicate($expression, $normalizedOperator, $binding)
            : new ComparisonPredicate(InputNormalizer::identifier($expression), $normalizedOperator, $binding);
        $this->conditions->add(new ConditionTerm($predicate, $or));

        return $this;
    }
}
