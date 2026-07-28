<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\Migration;

use SplFileInfo;

final readonly class ConsumerPackageInspector
{
    private const PACKAGE = 'oeltimacreation/php-simplequery';

    public function __construct(private SplFileInfo $repository)
    {
    }

    public function version(): ?string
    {
        return $this->lockedVersion() ?? $this->requiredVersion();
    }

    public function isConsumer(): bool
    {
        $composer = $this->jsonObject(new SplFileInfo($this->repository->getPathname() . '/composer.json'));
        if ($composer === null) {
            return false;
        }

        $requires = array_merge(
            is_array($composer['require'] ?? null) ? $composer['require'] : [],
            is_array($composer['require-dev'] ?? null) ? $composer['require-dev'] : [],
        );

        return array_key_exists(self::PACKAGE, $requires);
    }

    private function lockedVersion(): ?string
    {
        $lock = $this->jsonObject(new SplFileInfo($this->repository->getPathname() . '/composer.lock'));
        if ($lock === null) {
            return null;
        }

        return $this->versionFromPackages($lock['packages'] ?? null)
            ?? $this->versionFromPackages($lock['packages-dev'] ?? null);
    }

    private function requiredVersion(): ?string
    {
        $composer = $this->jsonObject(new SplFileInfo($this->repository->getPathname() . '/composer.json'));
        if ($composer === null) {
            return null;
        }

        return $this->versionFromRequirements($composer['require'] ?? null)
            ?? $this->versionFromRequirements($composer['require-dev'] ?? null);
    }

    private function versionFromPackages(mixed $packages): ?string
    {
        if (!is_array($packages)) {
            return null;
        }

        foreach ($packages as $package) {
            $version = $this->versionFromPackage($package);
            if ($version !== null) {
                return $version;
            }
        }

        return null;
    }

    private function versionFromPackage(mixed $package): ?string
    {
        if (!is_array($package)) {
            return null;
        }
        if (($package['name'] ?? null) !== self::PACKAGE) {
            return null;
        }

        return $this->nonEmptyString($package['version'] ?? null);
    }

    private function versionFromRequirements(mixed $requirements): ?string
    {
        if (!is_array($requirements)) {
            return null;
        }

        return $this->nonEmptyString($requirements[self::PACKAGE] ?? null);
    }

    /** @return array<string, mixed>|null */
    private function jsonObject(SplFileInfo $file): ?array
    {
        $contents = $file->isFile() ? file_get_contents($file->getPathname()) : false;
        if (!is_string($contents)) {
            return null;
        }
        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return null;
        }
        if (array_is_list($decoded)) {
            return null;
        }

        $object = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $object[$key] = $value;
            }
        }

        return $object;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        if ($value === '') {
            return null;
        }

        return $value;
    }
}
