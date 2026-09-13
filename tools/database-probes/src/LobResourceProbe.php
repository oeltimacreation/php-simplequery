<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\DatabaseProbe;

use Oeltima\SimpleQuery\Binding;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\ParameterType;
use Oeltima\SimpleQuery\RawQuery;
use RuntimeException;
use Throwable;

final class LobResourceProbe
{
    /** @return array<string, mixed> */
    public function run(Connection $connection): array
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Could not open synthetic LOB stream.');
        }
        try {
            fwrite($stream, 'abcdef');
            fseek($stream, 2);
            $query = $connection->query('SELECT ? AS payload', [new Binding($stream, ParameterType::Lob)]);
            $results = ['current_position' => $this->attempt($query)];
            $results['repeated'] = $this->attempt($query);
            rewind($stream);
            $results['caller_rewind'] = $this->attempt(clone $query);
            $results['position_after'] = ftell($stream);
            $results['caller_still_owns_stream'] = is_resource($stream);
            ftruncate($stream, 0);
            rewind($stream);
            $results['empty'] = $this->attempt($query);
        } finally {
            fclose($stream);
        }
        $results['closed_after_binding'] = $this->attempt($query);
        try {
            new Binding($stream, ParameterType::Lob);
            $results['closed_before_binding'] = 'accepted';
        } catch (Throwable $failure) {
            $results['closed_before_binding'] = $failure::class;
        }
        $context = stream_context_create();
        $results['non_stream_resource'] = $this->attempt(
            $connection->query('SELECT ? AS payload', [new Binding($context, ParameterType::Lob)]),
        );
        $results['non_seekable'] = $this->nonSeekable($connection);

        return $results;
    }

    /** @return array<string, mixed> */
    private function nonSeekable(Connection $connection): array
    {
        if (!function_exists('stream_socket_pair')) {
            return ['skipped' => 'stream_socket_pair unavailable'];
        }
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            return ['skipped' => 'socket pair unavailable'];
        }
        [$reader, $writer] = $pair;
        fwrite($writer, 'abcdef');
        fclose($writer);
        try {
            return $this->attempt(
                $connection->query('SELECT ? AS payload', [new Binding($reader, ParameterType::Lob)]),
            );
        } finally {
            fclose($reader);
        }
    }

    /** @return array<string, mixed> */
    private function attempt(RawQuery $query): array
    {
        $warnings = [];
        set_error_handler(static function (int $severity) use (&$warnings): bool {
            $warnings[] = $severity;

            return true;
        });
        try {
            $row = $query->firstAssociative();
            $value = $row['payload'] ?? null;

            return [
                'value_type' => get_debug_type($value),
                'bytes' => is_string($value) ? strlen($value) : null,
                'sha256' => is_string($value) ? hash('sha256', $value) : null,
                'warnings' => $warnings,
            ];
        } catch (Throwable $failure) {
            return [
                'exception' => $failure::class,
                'previous' => $failure->getPrevious() === null ? null : $failure->getPrevious()::class,
                'warnings' => $warnings,
            ];
        } finally {
            restore_error_handler();
        }
    }
}
