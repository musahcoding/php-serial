<?php

namespace MusaHCoding\SerialPort;

use MusaHCoding\SerialPort\Enum\Os;
use MusaHCoding\SerialPort\Enum\SerialDeviceState;

/**
 * Serial port control class
 *
 * THIS PROGRAM COMES WITH ABSOLUTELY NO WARRANTIES !
 * USE IT AT YOUR OWN RISKS !
 */
class PhpSerial
{
    public ?string $device = null;
    public ?string $winDevice = null;

    /** @var null|resource */
    public mixed $handle = null;
    public SerialDeviceState $state = SerialDeviceState::not_set;
    public string $buffer = '';
    public Os $os = Os::not_set;

    /**
     * If buffer should be flushed by sendMessage (true) or manually (false)
     */
    public bool $autoFlush = true;

    public function __construct()
    {
        setlocale(LC_ALL, 'en_US');

        $sysName = php_uname();

        if (str_starts_with($sysName, 'Linux')) {
            $this->os = Os::linux;

            if ($this->exec('stty --version') === 0) {
                register_shutdown_function([$this, 'deviceClose']);
            } else {
                throw new PhpSerialException('No stty available, unable to run.');
            }
        } elseif (str_starts_with($sysName, 'Darwin')) {
            $this->os = Os::mac;
            /*
             * We know stty is available in Darwin. stty returns 1 when run from php, because 'stty: stdin isn't a
             * terminal skip this check:
             *
             * $this->_exec('stty') === 0
             */
            register_shutdown_function([$this, 'deviceClose']);
        } elseif (str_starts_with($sysName, 'Windows')) {
            $this->os = Os::windows;
            register_shutdown_function([$this, 'deviceClose']);
        } else {
            throw new PhpSerialException('Host OS is neither osx, linux nor windows, unable to run.');
        }
    }

    //
    // OPEN/CLOSE DEVICE SECTION -- {START}
    //

    /**
     * Device set function : used to set the device name/address.
     * -> linux : use the device address, like /dev/ttyS0
     * -> osx : use the device address, like /dev/tty.serial
     * -> windows : use the COMxx device name, like COM1 (can also be used
     *     with linux)
     */
    public function deviceSet(string $device): bool
    {
        if ($this->state !== SerialDeviceState::opened) {
            if ($this->os === Os::linux) {
                if (preg_match('@^COM(\d+):?$@i', $device, $matches)) {
                    $device = '/dev/ttyS' . ($matches[1] - 1);
                }

                if ($this->exec('stty -F ' . $device) === 0) {
                    $this->device = $device;
                    $this->state = SerialDeviceState::set;

                    return true;
                }
            } elseif ($this->os === Os::mac) {
                if ($this->exec('stty -f ' . $device) === 0) {
                    $this->device = $device;
                    $this->state = SerialDeviceState::set;

                    return true;
                }
            } elseif ($this->os === Os::windows) {
                if (
                    preg_match(
                        '@^COM(\d+):?$@i',
                        $device,
                        $matches
                    ) and
                    $this->exec(exec('mode ' . $device . ' xon=on BAUD=9600')) === 0
                ) {
                    $this->winDevice = 'COM' . $matches[1];
                    $this->device = '\\.\com' . $matches[1];
                    $this->state = SerialDeviceState::set;

                    return true;
                }
            }

            throw new PhpSerialException('Specified serial port is not valid');
        } else {
            throw new PhpSerialException('You must close your device before to set an other one');
        }
    }

    /**
     * Opens the device for reading and/or writing.
     */
    public function deviceOpen(string $mode = 'r+b'): void
    {
        if ($this->state === SerialDeviceState::opened) {
            throw new PhpSerialException('The device is already opened');
        }

        if ($this->state === SerialDeviceState::not_set) {
            throw new PhpSerialException('The device must be set before to be open');
        }

        if (!preg_match('@^[raw]\+?b?$@', $mode)) {
            throw new PhpSerialException('Invalid opening mode : ' . $mode . '. Use fopen() modes.');
        }

        $handle = fopen($this->device, $mode);

        if ($handle === false) {
            throw new PhpSerialException(
                sprintf(
                    'Unable to open device %s in mode %s',
                    $this->device,
                    $mode,
                ),
            );
        }

        $this->handle = $handle;

        stream_set_blocking($this->handle, 0);
        $this->state = SerialDeviceState::opened;
    }

    /**
     * Sets the I/O blocking or not blocking
     */
    public function setBlocking(bool $blocking): void
    {
        stream_set_blocking($this->handle, $blocking);
    }

    public function deviceClose(): void
    {
        if ($this->state !== SerialDeviceState::opened) {
            return;
        }

        if (fclose($this->handle)) {
            $this->handle = null;
            $this->state = SerialDeviceState::set;

            return;
        }

        throw new PhpSerialException('Unable to close the device', E_USER_ERROR);
    }

    //
    // OPEN/CLOSE DEVICE SECTION -- {STOP}
    //

    //
    // CONFIGURE SECTION -- {START}
    //

    /**
     * Configure the Baud Rate
     * Possible rates : 110, 150, 300, 600, 1200, 2400, 4800, 9600, 38400,
     * 57600, 115200, 230400, 460800, 500000, 576000, 921600, 1000000,
     * 1152000, 1500000, 2000000, 2500000, 3000000, 3500000 and 4000000
     *
     * @param int $rate the rate to set the port in
     */
    public function confBaudRate(int $rate): void
    {
        if ($this->state !== SerialDeviceState::set) {
            throw new PhpSerialException('Unable to set the baud rate : the device is either not set or opened');
        }

        $validBauds = [
            110 => 11,
            150 => 15,
            300 => 30,
            600 => 60,
            1200 => 12,
            2400 => 24,
            4800 => 48,
            9600 => 96,
            19200 => 19,
        ];

        $extraBauds = [
            38400,
            57600,
            115200,
            230400,
            460800,
            500000,
            576000,
            921600,
            1000000,
            1152000,
            1500000,
            2000000,
            2500000,
            3000000,
            3500000,
            4000000,
        ];

        foreach ($extraBauds as $extraBaud) {
            $validBauds[$extraBaud] = $extraBaud;
        }

        if (isset($validBauds[$rate])) {
            if ($this->os === Os::linux) {
                $ret = $this->exec('stty -F ' . $this->device . ' ' . $rate, $out);
            } elseif ($this->os === Os::mac) {
                $ret = $this->exec('stty -f ' . $this->device . ' ' . $rate, $out);
            } elseif ($this->os === Os::windows) {
                $ret = $this->exec('mode ' . $this->winDevice . ' BAUD=' . $validBauds[$rate], $out);
            } else {
                return;
            }

            if ($ret !== 0) {
                throw new PhpSerialException('Unable to set baud rate: ' . $out[1]);
            }
        } else {
            throw new PhpSerialException('Unknown baud rate: ' . $rate);
        }
    }

    /**
     * Configure parity.
     * Modes : odd, even, none
     *
     * @param string $parity one of the modes
     */
    public function confParity(string $parity): void
    {
        if ($this->state !== SerialDeviceState::set) {
            throw new PhpSerialException('Unable to set parity : the device is either not set or opened');
        }

        $args = [
            'none' => '-parenb',
            'odd' => 'parenb parodd',
            'even' => 'parenb -parodd',
        ];

        if (!isset($args[$parity])) {
            throw new PhpSerialException('Parity mode not supported');
        }

        if ($this->os === Os::linux) {
            $ret = $this->exec('stty -F ' . $this->device . ' ' . $args[$parity], $out);
        } elseif ($this->os === Os::mac) {
            $ret = $this->exec('stty -f ' . $this->device . ' ' . $args[$parity], $out);
        } else {
            $ret = $this->exec('mode ' . $this->winDevice . ' PARITY=' . $parity[0], $out);
        }

        if ($ret === 0) {
            return;
        }

        throw new PhpSerialException('Unable to set parity : ' . $out[1]);
    }

    /**
     * Sets the length of a character.
     *
     * @param int $int length of a character (5 <= length <= 8)
     */
    public function confCharacterLength(int $int): void
    {
        if ($this->state !== SerialDeviceState::set) {
            throw new PhpSerialException(
                'Unable to set length of a character : the device is either not set or opened'
            );
        }

        if ($int < 5) {
            $int = 5;
        } elseif ($int > 8) {
            $int = 8;
        }

        if ($this->os === Os::linux) {
            $ret = $this->exec('stty -F ' . $this->device . ' cs' . $int, $out);
        } elseif ($this->os === Os::mac) {
            $ret = $this->exec('stty -f ' . $this->device . ' cs' . $int, $out);
        } else {
            $ret = $this->exec('mode ' . $this->winDevice . ' DATA=' . $int, $out);
        }

        if ($ret === 0) {
            return;
        }

        throw new PhpSerialException('Unable to set character length : ' . $out[1]);
    }

    /**
     * Sets the length of stop bits.
     *
     * @param float $length the length of a stop bit. It must be either 1,
     * 1.5 or 2. 1.5 is not supported under linux and on some computers.
     */
    public function confStopBits(float $length): void
    {
        if ($this->state !== SerialDeviceState::set) {
            throw new PhpSerialException(
                'Unable to set the length of a stop bit : the device is either not set or opened'
            );
        }

        if ($length !== 1.0 and $length !== 2.0 and $length !== 1.5) {
            throw new PhpSerialException('Specified stop bit length is invalid');
        }

        if ($this->os === Os::linux) {
            $ret = $this->exec('stty -F ' . $this->device . ' ' . (($length == 1) ? '-' : '') . 'cstopb', $out);
        } elseif ($this->os === Os::mac) {
            $ret = $this->exec('stty -f ' . $this->device . ' ' . (($length == 1) ? '-' : '') . 'cstopb', $out);
        } else {
            $ret = $this->exec('mode ' . $this->winDevice . ' STOP=' . $length, $out);
        }

        if ($ret === 0) {
            return;
        }

        throw new PhpSerialException('Unable to set stop bit length : ' . $out[1]);
    }

    /**
     * Configures the flow control
     *
     * @param string $mode Set the flow control mode. Available modes :
     *    -> 'none' : no flow control
     *    -> 'rts/cts' : use RTS/CTS handshaking
     *    -> 'xon/xoff' : use XON/XOFF protocol
     */
    public function confFlowControl(string $mode): void
    {
        if ($this->state !== SerialDeviceState::set) {
            throw new PhpSerialException('Unable to set flow control mode : the device is either not set or opened');
        }

        $linuxModes = [
            'none' => 'clocal -crtscts -ixon -ixoff',
            'rts/cts' => '-clocal crtscts -ixon -ixoff',
            'xon/xoff' => '-clocal -crtscts ixon ixoff'
        ];
        $windowsModes = [
            'none' => 'xon=off octs=off rts=on',
            'rts/cts' => 'xon=off octs=on rts=hs',
            'xon/xoff' => 'xon=on octs=off rts=on',
        ];

        if ($mode !== 'none' and $mode !== 'rts/cts' and $mode !== 'xon/xoff') {
            throw new PhpSerialException('Invalid flow control mode specified');
        }

        if ($this->os === Os::linux) {
            $ret = $this->exec('stty -F ' . $this->device . ' ' . $linuxModes[$mode], $out);
        } elseif ($this->os === Os::mac) {
            $ret = $this->exec('stty -f ' . $this->device . ' ' . $linuxModes[$mode], $out);
        } else {
            $ret = $this->exec('mode ' . $this->winDevice . ' ' . $windowsModes[$mode], $out);
        }
        if ($ret === 0) {
            return;
        }
        throw new PhpSerialException('Unable to set flow control : ' . $out[1]);
    }

    /**
     * Sets a setserial parameter (cf man setserial)
     * NO MORE USEFUL !
     *    -> No longer supported
     *    -> Only use it if you need it
     *
     * @param string $param parameter name
     * @param string $arg parameter value
     */
    public function setSetSerialFlag(string $param, string $arg = ''): void
    {
        $this->ensureOpen();

        $return = exec('setserial ' . $this->device . ' ' . $param . ' ' . $arg . ' 2>&1');

        if ($return[0] === 'I') {
            throw new PhpSerialException('setserial: Invalid flag');
        } elseif ($return[0] === '/') {
            throw new PhpSerialException('setserial: Error with device file');
        }
    }

    //
    // CONFIGURE SECTION -- {STOP}
    //

    //
    // I/O SECTION -- {START}
    //

    /**
     * Sends a string to the device
     *
     * @param string $str string to be sent to the device
     * @param float $waitForReply time to wait for the reply (in seconds)
     */
    public function sendMessage(string $str, float $waitForReply = 0.1): void
    {
        $this->buffer .= $str;

        if ($this->autoFlush === true) {
            $this->serialFlush();
        }

        usleep((int)($waitForReply * 1000000));
    }

    /**
     * Reads one line and returns after a \r or \n
     */
    public function readLine(): string
    {
        $line = '';

        $this->setBlocking(true);
        while (true) {
            $c = $this->readPort(1);

            if ($c != '\r' && $c != '\n') {
                $line .= $c;
            } else {
                if ($line) {
                    break;
                }
            }
        }
        $this->setBlocking(false);

        return $line;
    }

    public function readFlush(): void
    {
        while ($this->dataAvailable()) {
            $this->readPort(1);
        }
    }

    public function dataAvailable(): false|int
    {
        $read = [$this->handle];
        $write = null;
        $except = null;

        return stream_select($read, $write, $except, 0);
    }

    /**
     * Reads the port until no new data are available, then return the content.
     *
     * @pararm int $count number of characters to be read (will stop before
     *    if less characters are in the buffer)
     */
    public function readPort($count = 0): string
    {
        if ($this->state !== SerialDeviceState::opened) {
            throw new PhpSerialException('Device must be opened to read it');
        }

        if ($this->os === Os::linux || $this->os === Os::mac) {
            // Behavior in OSX isn't to wait for new data to recover, but just grabs what's there!
            // Doesn't always work perfectly for me in OSX
            $content = '';

            $count = $count ?: 128;

            for ($i = 0; $i < $count;) {
                $content .= fread($this->handle, min($count - $i, 128));
                $i += strlen($content);
            }

            return $content;
        } elseif ($this->os === Os::windows) {
            // Windows port reading procedures still buggy
            $content = '';
            $i = 0;

            if ($count !== 0) {
                do {
                    if ($i > $count) {
                        $content .= fread($this->handle, ($count - $i));
                    } else {
                        $content .= fread($this->handle, 128);
                    }
                } while (($i += 128) === strlen($content));
            } else {
                do {
                    $content .= fread($this->handle, 128);
                } while (($i += 128) === strlen($content));
            }

            return $content;
        }

        throw new PhpSerialException('Invalid OS ' . $this->os->name);
    }

    /**
     * Flushes the output buffer
     * Renamed from flush for osx compat. issues
     */
    public function serialFlush(): void
    {
        $this->ensureOpen();

        if (fwrite($this->handle, $this->buffer) !== false) {
            $this->buffer = '';

            return;
        }

        $this->buffer = '';
        throw new PhpSerialException('Error while sending message');
    }

    //
    // I/O SECTION -- {STOP}
    //

    private function ensureOpen(): void
    {
        if ($this->state !== SerialDeviceState::opened) {
            throw new PhpSerialException('Device must be opened');
        }
    }

    private function exec($cmd, &$out = null): int
    {
        $desc = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($cmd, $desc, $pipes);

        $ret = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $retVal = proc_close($proc);

        if (func_num_args() == 2) {
            $out = [$ret, $err];
        }

        return $retVal;
    }
}
