<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\Migration;

use SplFileInfo;

final readonly class ConsumerSource
{
    public ?string $contents;

    public function __construct(
        public SplFileInfo $file,
        private SplFileInfo $repository,
    ) {
        $contents = file_get_contents($this->file->getPathname());
        $this->contents = is_string($contents) ? $contents : null;
    }

    public function relativePath(): string
    {
        return ltrim(
            substr($this->file->getPathname(), strlen($this->repository->getPathname())),
            DIRECTORY_SEPARATOR,
        );
    }
}
