<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\DatabaseProbe;

use InvalidArgumentException;
use PDO;

final readonly class ProbeTarget
{
    /**
     * @param string $name
     * @param string $engine
     * @param string $dsn
     * @param array<int, bool|int|string> $options
     */
    private function __construct(
        public string $name,
        public string $engine,
        public string $dsn,
        public ?string $username,
        public ?string $password,
        public array $options,
    ) {
    }

    public static function named(string $name): self
    {
        if ($name === 'sqlite') {
            return new self(
                name: 'sqlite',
                engine: 'sqlite',
                dsn: 'sqlite::memory:',
                username: null,
                password: null,
                options: self::commonOptions(),
            );
        }

        $targets = [
            'mariadb' => ['MARIADB', 'mariadb', '13306'],
            'mysql' => ['MYSQL', 'mysql', '13307'],
            'proxysql' => ['PROXYSQL', 'mariadb', '16033'],
            'maxscale' => ['MAXSCALE', 'mariadb', '14006'],
        ];

        if (!isset($targets[$name])) {
            throw new InvalidArgumentException(sprintf('Unknown probe target "%s".', $name));
        }

        [$prefix, $engine, $port] = $targets[$name];
        $defaultDsn = sprintf('mysql:host=127.0.0.1;port=%s;dbname=simplequery;charset=utf8mb4', $port);
        $dsn = self::environment($prefix . '_DSN', $defaultDsn);
        $username = self::environment($prefix . '_USER', 'simplequery');
        $password = self::environment($prefix . '_PASSWORD', 'simplequery');
        $options = self::commonOptions();

        if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = self::environmentBoolean('PROBE_BUFFERED', true);
        }

        if (defined('PDO::MYSQL_ATTR_FOUND_ROWS')) {
            $options[PDO::MYSQL_ATTR_FOUND_ROWS] = false;
        }

        $options[PDO::ATTR_EMULATE_PREPARES] = self::environmentBoolean('PROBE_EMULATE_PREPARES', false);

        return new self($name, $engine, $dsn, $username, $password, $options);
    }

    public function connect(): PDO
    {
        return new PDO($this->dsn, $this->username, $this->password, $this->options);
    }

    /** @return array<int, bool|int|string> */
    private static function commonOptions(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
    }

    private static function environment(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function environmentBoolean(string $name, bool $default): bool
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new InvalidArgumentException(sprintf('%s must be a Boolean value.', $name));
        }

        return $parsed;
    }
}
