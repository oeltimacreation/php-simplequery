<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal;

/**
 * Internal sentinel that distinguishes "argument not supplied" from an
 * explicit null in overloaded builder methods such as where()/having()/join().
 *
 * Public methods expose the sentinel as a private constant default, so the
 * reflection-visible parameter shape stays optional while the exact
 * two-operand versus three-operand behavior is preserved without
 * func_num_args() dispatch.
 *
 * @internal
 */
enum MissingArgument
{
    case Value;
}
