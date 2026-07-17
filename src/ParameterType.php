<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery;

enum ParameterType: string
{
    case Auto = 'auto';
    case Null = 'null';
    case Integer = 'integer';
    case String = 'string';
    case Lob = 'lob';
    case Binary = 'binary';
}
