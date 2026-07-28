<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\Quality;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class PublicApiManifest
{
    private const ANNOTATION_PATTERN =
        '/^@(template(?:-covariant|-contravariant)?|extends|implements|param|return'
        . '|phpstan-(?:param|return|assert))\b/';

    /** @return array{schema_version: int, package: string, types: list<array<string, mixed>>} */
    public function build(string $root): array
    {
        $types = [];
        foreach ($this->discoverTypeNames($root) as $typeName) {
            if (!$this->loadType($typeName)) {
                throw new \RuntimeException(sprintf('Could not load public type %s.', $typeName));
            }
            $types[] = $this->describeType(new ReflectionClass($typeName));
        }

        return [
            'schema_version' => 1,
            'package' => 'oeltimacreation/php-simplequery',
            'types' => $types,
        ];
    }

    public function encode(string $root): string
    {
        return json_encode(
            $this->build($root),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /** @return list<class-string> */
    private function discoverTypeNames(string $root): array
    {
        $sourceRoot = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'src';
        $names = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($sourceRoot) + 1, -4);
            if (str_starts_with($relative, 'Internal' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            /** @var class-string $name */
            $name = 'Oeltima\\SimpleQuery\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
            $names[] = $name;
        }
        sort($names);

        return $names;
    }

    /** @param class-string $typeName */
    private function loadType(string $typeName): bool
    {
        return class_exists($typeName) || interface_exists($typeName) || enum_exists($typeName);
    }

    /**
     * @param ReflectionClass<object> $type
     * @return array<string, mixed>
     */
    private function describeType(ReflectionClass $type): array
    {
        $description = [
            'name' => $type->getName(),
            'kind' => $this->kind($type),
            'compatibility' => $this->compatibility($type->getDocComment()),
            'final' => $type->isFinal(),
            'abstract' => $type->isAbstract(),
            'readonly' => $type->isReadOnly(),
            'extends' => $this->parentName($type),
            'implements' => $this->interfaceNames($type),
            'annotations' => $this->annotations($type->getDocComment()),
            'constants' => $this->constants($type),
            'properties' => $this->properties($type),
            'methods' => $this->methods($type),
        ];
        if ($type->isEnum()) {
            $description['cases'] = $this->enumCases($type);
        }

        return $description;
    }

    /** @param ReflectionClass<object> $type */
    private function kind(ReflectionClass $type): string
    {
        if ($type->isEnum()) {
            return 'enum';
        }

        return $type->isInterface() ? 'interface' : 'class';
    }

    /** @param ReflectionClass<object> $type */
    private function parentName(ReflectionClass $type): ?string
    {
        $parent = $type->getParentClass();

        return $parent === false ? null : $parent->getName();
    }

    /** @param ReflectionClass<object> $type
     * @return list<string>
     */
    private function interfaceNames(ReflectionClass $type): array
    {
        $interfaces = $type->getInterfaceNames();
        sort($interfaces);

        return $interfaces;
    }

    /** @param ReflectionClass<object> $type
     * @return list<array<string, mixed>>
     */
    private function constants(ReflectionClass $type): array
    {
        $constants = [];
        foreach ($type->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
            if ($constant->getDeclaringClass()->getName() !== $type->getName()) {
                continue;
            }
            $value = $constant->getValue();
            if ($value instanceof \UnitEnum) {
                continue;
            }
            $constants[] = [
                'name' => $constant->getName(),
                'final' => $constant->isFinal(),
                'value' => $value,
            ];
        }
        usort($constants, static fn (array $left, array $right): int => $left['name'] <=> $right['name']);

        return $constants;
    }

    /** @param ReflectionClass<object> $type
     * @return list<array<string, mixed>>
     */
    private function properties(ReflectionClass $type): array
    {
        $properties = [];
        foreach ($type->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() !== $type->getName()) {
                continue;
            }
            $properties[] = [
                'name' => $property->getName(),
                'static' => $property->isStatic(),
                'readonly' => $property->isReadOnly(),
                'type' => $this->typeName($property->getType()),
            ];
        }
        usort($properties, static fn (array $left, array $right): int => $left['name'] <=> $right['name']);

        return $properties;
    }

    /** @param ReflectionClass<object> $type
     * @return list<array<string, mixed>>
     */
    private function methods(ReflectionClass $type): array
    {
        $methods = [];
        foreach ($type->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $type->getName()) {
                continue;
            }
            $methods[] = $this->describeMethod($method);
        }
        usort($methods, static fn (array $left, array $right): int => $left['name'] <=> $right['name']);

        return $methods;
    }

    /** @return array<string, mixed> */
    private function describeMethod(ReflectionMethod $method): array
    {
        return [
            'name' => $method->getName(),
            'compatibility' => $this->compatibility($method->getDocComment()),
            'static' => $method->isStatic(),
            'final' => $method->isFinal(),
            'return_type' => $this->typeName($method->getReturnType()),
            'returns_reference' => $method->returnsReference(),
            'annotations' => $this->annotations($method->getDocComment()),
            'parameters' => array_map($this->describeParameter(...), $method->getParameters()),
        ];
    }

    /** @return array<string, mixed> */
    private function describeParameter(ReflectionParameter $parameter): array
    {
        $description = [
            'name' => $parameter->getName(),
            'type' => $this->typeName($parameter->getType()),
            'by_reference' => $parameter->isPassedByReference(),
            'variadic' => $parameter->isVariadic(),
            'optional' => $parameter->isOptional(),
            'attributes' => array_map(
                static fn (ReflectionAttribute $attribute): string => $attribute->getName(),
                $parameter->getAttributes(),
            ),
        ];
        if ($parameter->isDefaultValueAvailable()) {
            if ($parameter->isDefaultValueConstant()) {
                $description['default_constant'] = $parameter->getDefaultValueConstantName();
            } else {
                $description['default'] = $parameter->getDefaultValue();
            }
        }

        return $description;
    }

    private function typeName(?ReflectionType $type): ?string
    {
        if ($type === null) {
            return null;
        }
        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();

            return $type->allowsNull() && $name !== 'mixed' && $name !== 'null' ? '?' . $name : $name;
        }
        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map($this->typeName(...), $type->getTypes()));
        }
        if ($type instanceof ReflectionIntersectionType) {
            return implode('&', array_map($this->typeName(...), $type->getTypes()));
        }

        throw new \LogicException('Unsupported reflection type.');
    }

    private function compatibility(string|false $docComment): string
    {
        return is_string($docComment) && str_contains($docComment, '@internal') ? 'internal' : 'public';
    }

    /** @return list<string> */
    private function annotations(string|false $docComment): array
    {
        if (!is_string($docComment)) {
            return [];
        }

        $annotations = [];
        $lines = preg_split('/\R/', $docComment);
        if ($lines === false) {
            return [];
        }

        foreach ($lines as $line) {
            $line = trim($line, " \t\n\r\0\x0B/*");
            if (preg_match(self::ANNOTATION_PATTERN, $line) === 1) {
                $annotations[] = preg_replace('/\s+/', ' ', $line) ?? $line;
            }
        }

        return $annotations;
    }

    /** @param ReflectionClass<object> $enum
     * @return list<array{name: string, value?: int|string}>
     */
    private function enumCases(ReflectionClass $enum): array
    {
        $cases = [];
        foreach ($enum->getReflectionConstants() as $constant) {
            $case = $constant->getValue();
            if (!$case instanceof \UnitEnum) {
                continue;
            }
            $description = ['name' => $case->name];
            if ($case instanceof \BackedEnum) {
                $description['value'] = $case->value;
            }
            $cases[] = $description;
        }

        return $cases;
    }
}
