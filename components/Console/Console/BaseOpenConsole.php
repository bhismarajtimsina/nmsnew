<?php


namespace WCC\Console\Console;


use DI\Annotation\Inject;
use http\Exception\RuntimeException;
use Monolog\Logger;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Api\Auth;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Infrastructure\Components\AbstractComponentCommand;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\UserStorage;
use WCC\Console\Controllers\ConsoleClient;
use WCC\Console\Models\ConsoleHistory;
use WCC\Console\Storage\ConsoleHistoryStorage;

abstract class BaseOpenConsole extends AbstractComponentCommand
{
    /**
     * @Inject
     * @var ConsoleClient
     */
    protected $swc;
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $devStorage;
    /**
     * @Inject
     * @var UserStorage
     */
    protected $userStorage;

    /**
     * @Inject
     * @var Auth
     */
    protected $auth;

    /**
     * @var User
     */
    protected $user = null;


    /**
     * @Inject
     * @var ConsoleHistoryStorage
     */
    protected $consoleHistoryStorage;

    abstract function checkPermissionsAndSetUser();

    /**
     * @var ConsoleHistory
     */
    protected $history;

    protected $cancelByTimeoutPid = 0;

    public function __construct(ComponentInjector $componentInjector, Logger $logger)
    {
        $this->history = (new ConsoleHistory())
            ->setIsAutologin(true)
            ->setStartAt(date('Y-m-d H:i:s'));
        parent::__construct($componentInjector, $logger);
    }


    function exec(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->history->setPid(posix_getpid());
            if (!extension_loaded('pcntl')) {
                throw new \RuntimeException('PCNTL required');
            }

            if (function_exists('pcntl_async_signals')) {
                pcntl_async_signals(true);     // включаем асинхронные сигналы
            } else {
                declare(ticks=1);              // fallback для 7.0 и ниже
            }
            register_shutdown_function(fn() => $this->closeGracefully());
            $graceful = function (int $sig) {
                $this->closeGracefully();
                exit(0);
            };

            pcntl_signal(SIGPIPE, $graceful);
            pcntl_signal(SIGHUP,  $graceful);
            pcntl_signal(SIGTERM, $graceful);
            pcntl_signal(SIGINT,  $graceful);

            $this->checkPermissionsAndSetUser();
            if (preg_match('/^((25[0-5]|2[0-4][0-9]|1\d{2}|[1-9]?\d)\.){3}(25[0-5]|2[0-4][0-9]|1\d{2}|[1-9]?\d)$/', $input->getArgument("ip-id"))) {
                $device = $this->devStorage->getByIp($input->getArgument('ip-id'));
            } else {
                $device = $this->devStorage->getById($input->getArgument('ip-id'));
            }
            $this->history->setUser($this->user)->setDevice($device);
            if ($input->getOption("without-login")) {
                $this->history->setIsAutologin(false);
                $credentials = $this->promptLoginPassword();
                if(!$credentials) {
                    $this->output->writeln("<comment>Exiting...</comment>");
                    return self::INVALID;
                }
                $device->getAccess()->setLogin($credentials['username']);
                $device->getAccess()->setPassword($credentials['password']);
            }

            $this->swc->setConsoleOutput($output);
            $this->swc->connect($device);
            $this->cancelByTimeoutPid = $this->spawnTimeoutKiller(getmypid(), 1800, 10);
            $this->history = $this->consoleHistoryStorage->add($this->history);
            $this->swc->attachConsole();
        } catch (\Throwable $e) {
            $this->history->setError(
                [
                    'message' => $e->getMessage(),
                    'line' => "{$e->getFile()}:{$e->getLine()}",
                    'stacktrace' => $e->getTraceAsString(),
                ]);
            throw $e;
        }
        return self::SUCCESS;
    }

    function __destruct()
    {
        $this->closeGracefully();
    }

    function promptLoginPassword()
    {
        $username = $this->questionWithTimeout("Username", '', function ($answer) {
            if (!$answer) throw new \Exception("Username can't be empty");
            return $answer;
        }, [], false, 60);
        if($username === null) {
            $this->output->writeln("\n<comment>Input timeout exceeded!</comment>");
            return null;
        }
        $password = $this->promptPassword('Password: ', 60);
        if($password === null) {
            $this->output->writeln("\n<comment>Input timeout exceeded!</comment>");
            return null;
        }
        return [
            "username" => $username,
            "password" => $password,
        ];
    }

    function promptPassword($prompt = 'Password: ', $timeout = 10)
    {
            echo $prompt;
            shell_exec('stty -echo');
            $password = '';
            $start = time();

            // Ожидание ввода с таймаутом
            while (true) {
                // Проверка таймаута
                if ((time() - $start) >= $timeout) {
                    shell_exec('stty echo');
                    return null;
                }

                // stream_select для ожидания символа
                $read   = [STDIN];
                $write  = [];
                $except = [];
                // Ожидание 1 секунду
                $seconds_left = $timeout - (time() - $start);
                $ready = stream_select($read, $write, $except, $seconds_left > 1 ? 1 : $seconds_left);

                if ($ready === false) {
                    // Ошибка
                    break;
                } elseif ($ready > 0) {
                    $char = stream_get_contents(STDIN, 1);
                    if ($char === "\n" || $char === "\r") {
                        break;
                    }
                    $password .= $char;
                    echo '*';
                }
                // иначе просто ждём дальше
            }
            shell_exec('stty echo');
            echo "\n";
            return $password;
    }


    private bool $closing = false;

    private function closeGracefully(): void
    {
        if ($this->closing) return;
        $this->closing = true;
        $this->cancelTimeoutKiller($this->cancelByTimeoutPid);
        $history = $this->history;
        if ($history && $history->getUser() && $history->getDevice()) {
            $log = $this->cleanText($this->swc->getIoLog());
            $history->setLog($log)
                ->setStopAt(date('Y-m-d H:i:s'));
            $history->getId()
                ? $this->consoleHistoryStorage->update($history)
                : $this->consoleHistoryStorage->add($history);
        }
        $this->swc = null;
    }
    function cleanText(string $s): string {
        // 1) Удалить ANSI escape-последовательности (CSI/OSC/DCS/SOS/PM/APC/ESC single)
        // CSI: ESC [ ... cmd
        $s = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/u', '', $s);
        // OSC: ESC ] ... BEL  ИЛИ ESC ] ... ESC \
        $s = preg_replace('/\x1B\][^\x07]*(?:\x07|\x1B\\\\)/u', '', $s);
        // DCS/SOS/PM/APC: ESC P/ X/ ^/ _  ...  ST(ESC\)
        $s = preg_replace('/\x1B[\x50\x58\x5E\x5F].*?\x1B\\\\/us', '', $s);
        // 1-символьные ESC-последовательности: ESC [@-Z\^_]
        $s = preg_replace('/\x1B[@-Z\\\^_]/u', '', $s);

        // На всякий случай убрать одиночные BS/DEL, если остались
        $s = str_replace(["\x08", "\x7F"], '', $s);

        // 3) Удалить прочие управляющие C0, кроме \n (0x0A) и \r (0x0D)
        $s = preg_replace('/[\x00-\x09\x0B-\x0C\x0E-\x1F]/u', '', $s);

        return $s;
    }


    function spawnTimeoutKiller(int $targetPid, int $timeoutSec, int $graceSec = 2 ): int
    {
        if (!extension_loaded('pcntl')) {
            throw new RuntimeException('pcntl required');
        }
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('fork failed');
        }
        if ($pid === 0) {
            // CHILD: сторож
            @sleep($timeoutSec);
            @posix_kill($targetPid, SIGTERM);
            if ($graceSec > 0) {
                @sleep($graceSec);
                // если ещё жив — добиваем
                if (posix_kill($targetPid, 0)) {
                    @posix_kill($targetPid, SIGKILL);
                }
            }
            exit(0);
        }
        // PARENT: вернуть PID сторожа
        return $pid;
    }

    /** Останавливает сторожа по его PID. Безопасно вызывать многократно. */
    function cancelTimeoutKiller(?int $killerPid): void
    {
        if (!$killerPid || $killerPid <= 0) return;
        // попросим завершиться
        @posix_kill($killerPid, SIGKILL);
        // дождёмся, чтобы не оставлять зомби
        pcntl_waitpid($killerPid, $status, 0);
    }
}