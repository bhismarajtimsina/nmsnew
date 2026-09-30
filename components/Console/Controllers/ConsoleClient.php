<?php

namespace WCC\Console\Controllers;

use Monolog\Logger;
use SwitcherCore\Switcher\Console\ConsoleInterface;
use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Models\Devices\Device;
use WCAA\SwitcherCore\SwitcherCore;

class ConsoleClient extends AbstractComponentController
{
    /**
     * @Inject
     * @var SwitcherCore
     */
    protected $swCore;

    protected $socket;

    /** @Inject
     * @var Logger
     */
    protected $logger;

    protected $logAll = ''; // общий журнал (ввод+вывод в хронологическом порядке)
    protected $logIn  = ''; // устройство -> консоль
    protected $logOut = ''; // консоль -> устройство

    function connect(Device $device)
    {
        $core = $this->swCore->getCore($device);

        if(!in_array('console', $core->getNeedInputs())) {
            throw new \Exception("Sorry, but console not supported yet on device {$device->getModel()->getKey()}. \nPlease, contact with developers for integrate");
        }

        /** @var $console ConsoleInterface */
        $console = $core->getContainer()->get(ConsoleInterface::class);
        $helper = $console->getDeviceHelper();
        $helper->setAfterLoginCommands([]);
        $console->connectAndLogin();
        $this->socket = $console->getStream();
        $this->_output->writeln("<info>Successfully connected and authenticated to {$device->getIp()} ({$device->getName()})!</info>");
    }

    /**
     * Сырой мост $this->socket ⇄ STDIN/STDOUT.
     * Без PTY, NAWS, IAC-обработки. Выход — Ctrl+].
     */
    public function attachConsole(bool $rawTTY = true, string $escape = "\x1D"): void
    {
        if (!is_resource($this->socket)) {
            throw new \RuntimeException('Telnet not connected.');
        }

        $in  = fopen('php://stdin',  'rb');
        $out = fopen('php://stdout', 'wb');
        if (!$in || !$out) {
            throw new \RuntimeException('Cannot open STDIN/STDOUT.');
        }

        stream_set_blocking($this->socket, false);
        stream_set_blocking($in,  false);
        stream_set_blocking($out, false);

        // raw локальный TTY: не генерировать сигналы (Ctrl+C -> 0x03 в устройство)
        $sttySaved = null;
        if ($rawTTY && function_exists('shell_exec')) {
            $sttySaved = trim((string)@shell_exec('stty -g 2>/dev/null'));
            @shell_exec('stty -icanon -echo -isig -ixon -ixoff min 1 time 0 2>/dev/null');
            // или: @shell_exec('stty raw -echo 2>/dev/null');
        }

        $quit = false;

        try {
            echo "For quit - press ^]";
            fwrite($this->socket, "\n");
            while (!$quit) {
                $r = [$this->socket, $in];
                $w = $e = null;
                if (stream_select($r, $w, $e, null) === false) break;

                foreach ($r as $src) {
                    if ($src === $this->socket) {
                        // Устройство -> консоль
                        $data = fread($this->socket, 65536);
                        if ($data === '' && feof($this->socket)) {
                            $this->_output->writeln("\n<info>Connection closed by foreign host!</info>\n");
                            $quit = true; break; }
                        if ($data !== '' && $data !== false) {
                            $this->safeWrite($out, $data);
                            // ЛОГ:
                            $this->logIn  .= $data;
                            $this->logAll .= $data;
                        }
                    } else {
                        // Консоль -> устройство, перехват Ctrl+]
                        $data = fread($in, 65536);
                        if ($data === '' && feof($in)) { $quit = true; break; }
                        if ($data !== '' && $data !== false) {
                            $pos = strpos($data, $escape);
                            if ($pos !== false) {
                                $chunk = substr($data, 0, $pos); // всё ДО ^]
                                if ($chunk !== '') {
                                    $this->safeWrite($this->socket, $chunk);
                                    // ЛОГ:
                                    $this->logOut .= $chunk;
                                    $this->logAll .= $chunk;
                                }
                                $this->_output->writeln("\n<info>Connection closed!</info>\n");
                                $quit = true;
                                break;
                            }
                            $this->safeWrite($this->socket, $data);
                            // ЛОГ:
                            $this->logOut .= $data;
                            $this->logAll .= $data;
                        }
                    }
                }
            }
        } finally {
            if ($rawTTY && $sttySaved) {
                @shell_exec('stty ' . escapeshellarg($sttySaved) . ' 2>/dev/null');
            }
            if (is_resource($in))  fclose($in);
            if (is_resource($out)) fclose($out);
        }
    }

    private function safeWrite($stream, string $data): void
    {
        $len = strlen($data); $off = 0;
        while ($off < $len) {
            $n = fwrite($stream, substr($data, $off));
            if ($n === false) { throw new \RuntimeException('write failed'); }
            if ($n === 0) { usleep(1000); continue; }
            $off += $n;
        }
    }

    public function getIoLog(): string { return $this->logAll; }  // общий поток
    public function getInLog(): string { return $this->logIn; }   // устройство -> консоль
    public function getOutLog(): string { return $this->logOut; } // консоль -> устройство
    public function clearLogs(): void { $this->logAll = $this->logIn = $this->logOut = ''; }
}
