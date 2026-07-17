<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery;

use Oeltima\SimpleQuery\Exception\ConfigurationException;

final readonly class ConnectionOptions
{
    public function __construct(
        public ?bool $emulatePrepares = null,
        public ?bool $bufferedQueries = null,
        public ?bool $foundRows = null,
        public ?bool $persistent = null,
        public ?int $sqliteBusyTimeoutMilliseconds = null,
        public ?string $label = null,
    ) {
        if ($this->sqliteBusyTimeoutMilliseconds !== null && $this->sqliteBusyTimeoutMilliseconds < 0) {
            throw new ConfigurationException('SQLite busy timeout cannot be negative.');
        }

        if ($this->label !== null && ($this->label === '' || preg_match('/[\x00-\x1F\x7F]/', $this->label) === 1)) {
            throw new ConfigurationException('Connection label must be non-empty and contain no control characters.');
        }
    }

    public function validateFor(Driver $driver): void
    {
        if (
            $driver === Driver::Sqlite
            && (
                $this->emulatePrepares !== null
                || $this->bufferedQueries !== null
                || $this->foundRows !== null
            )
        ) {
            throw new ConfigurationException('MySQL-family connection options cannot be used with SQLite.');
        }

        if ($driver !== Driver::Sqlite && $this->sqliteBusyTimeoutMilliseconds !== null) {
            throw new ConfigurationException('SQLite busy timeout is only valid for SQLite.');
        }

        if ($this->foundRows === true || $this->persistent === true) {
            throw new ConfigurationException(
                'FOUND_ROWS and persistent connections are outside the supported profile.',
            );
        }
    }
}
