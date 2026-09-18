<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal;

/**
 * Wrapper lifecycle state shared with active cursors so the per-row
 * discarded-connection guard is a single property read.
 *
 * @internal
 */
final class ConnectionState
{
    public bool $closed = false;
}
