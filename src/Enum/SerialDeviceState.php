<?php

declare(strict_types=1);

namespace MusaHCoding\SerialPort\Enum;

enum SerialDeviceState
{
    case not_set;
    case set;
    case opened;
    case closed;
}
