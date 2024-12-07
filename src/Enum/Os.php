<?php

declare(strict_types=1);

namespace MusaHCoding\SerialPort\Enum;

enum Os
{
    case not_set;
    case linux;
    case windows;
    case mac;
}
