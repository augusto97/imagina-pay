<?php

declare(strict_types=1);

namespace ImaginaPay\Support;

enum LogLevel: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
}
