<?php
declare(strict_types=1);

namespace WCAA\Console;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Swoole\Process;
use WCAA\App;
use Swoole\Table;
use Swoole\WebSocket\Server;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class WebSocketServerCommand extends AbstractCommand
{
    protected static $defaultName = 'system:ws-server';

    /**
     * @Inject
     * @var \Superbalist\PubSub\Redis\RedisPubSubAdapter
     */
    protected $redisPubSub;

    protected function configure(): void
    {
        $this
            ->setDescription('Run WebSocket server')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Listen host', '0.0.0.0')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Listen port', '9501')
            ->addOption('workers', null, InputOption::VALUE_REQUIRED, 'worker_num', _env('WS_SERVER_WORKER_NUM', 100))
            ->addOption('heartbeat-idle', null, InputOption::VALUE_REQUIRED, 'heartbeat_idle_time', '60')
            ->addOption('heartbeat-interval', null, InputOption::VALUE_REQUIRED, 'heartbeat_check_interval', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $serverHost        = (string)$input->getOption('host');
        $serverPort        = (int)$input->getOption('port');
        $workerCount       = (int)$input->getOption('workers');
        $heartbeatIdle     = (int)$input->getOption('heartbeat-idle');
        $heartbeatInterval = (int)$input->getOption('heartbeat-interval');


        // Таблица подписок: ключ СТРОКА (fd приводим к string)
        $connectionChannels = new Table(8192);
        $connectionChannels->column('channels', Table::TYPE_STRING, 1024);
        $connectionChannels->column('user', Table::TYPE_STRING, 16000);
        $connectionChannels->create();

        // Гард от дублей доставки (ключ — md5 хэш "fd|event|sig", см. ниже)
        $deliverGuard = new Table(65536);
        $deliverGuard->column('seen', Table::TYPE_INT, 1);
        // fd stored as its own column, not parsed back out of the key —
        // the key itself is now a fixed-length hash (see the fix in
        // registerOnPipeMessageHandler), so cleanup-on-close needs this to
        // find a closed connection's own entries.
        $deliverGuard->column('fd', Table::TYPE_INT, 4);
        $deliverGuard->create();


        $server = new Server($serverHost, $serverPort, SWOOLE_PROCESS, SWOOLE_SOCK_TCP);
        $server->set([
            'worker_num'               => $workerCount,
            'log_level'                => SWOOLE_LOG_INFO,
            'heartbeat_idle_time'      => $heartbeatIdle,
            'heartbeat_check_interval' => $heartbeatInterval,
        ]);

        $this->registerOnOpenHandler($server, $connectionChannels);
        $this->registerOnMessageHandler($server, $connectionChannels);
        $this->registerOnCloseHandler($server, $connectionChannels, $deliverGuard);
        $this->registerOnPipeMessageHandler($server, $connectionChannels, $deliverGuard);


        // HTTP: /health
        $server->on('request', static function (\Swoole\Http\Request $req, \Swoole\Http\Response $res) {
            $uri = $req->server['request_uri'] ?? '/';
            if ($uri === '/health') { $res->end('ok'); return; }
            $res->status(404); $res->end('not found');
        });

        // Fan-out из Redis-процесса в воркеры -> клиентам


        $this->startRedisWorker($server, $workerCount);

        $this->log(sprintf('WS listening on ws://%s:%d', $serverHost, $serverPort));
        $server->start();

        return Command::SUCCESS;
    }

    private function log(string $message): void
    {
        $this->output->writeln(sprintf('[%s] %s', date('Y-m-d H:i:s'), $message));
    }

    // Per-channel subscribe authorization — the connect-time check (see
    // registerOnOpenHandler) only confirms the token is valid, it doesn't
    // check what the user's role is actually allowed to see, unlike every
    // REST route (PermissionCheckMiddleware). config/ws-permissions.yml
    // lists exact channel-name glob patterns and the permission(s) that
    // unlock them; anything not matched is denied — closed by default,
    // since some channels (sys_action:added in particular) carry other
    // users' full action details, not just this connection's own data.
    private function isSubscribeAllowed(?array $user, string $channel): bool
    {
        if (!$user) return false;
        // Mirrors PermissionCheckMiddleware's own special case: an
        // internal/system account (id/role id <= 0) bypasses permission
        // checks entirely on the REST side too.
        if (($user['id'] ?? 0) <= 0 || ($user['role']['id'] ?? 0) <= 0) {
            return true;
        }
        $userPerms = $user['role']['permissions'] ?? [];
        $rules = App::getInstance()->conf('api.auth.ws_permissions') ?: [];
        foreach ($rules as $rule) {
            $matched = false;
            foreach ((array)($rule['allow_to_subscribe'] ?? []) as $pattern) {
                $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/u';
                if (preg_match($regex, $channel)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) continue;
            $required = $rule['permission'] ?? null;
            if ($required === null || $required === '') return true; // explicitly public
            $required = (array)$required;
            if (array_intersect($required, $userPerms)) return true;
            return false; // matched the channel but lacks every listed permission
        }
        return false; // no rule matches this channel at all
    }

    private function vlog(string $message): void
    {
        if ($this->output->isVerbose()) {
            $this->output->writeln($message);
        }
    }

    function registerOnCloseHandler($server, $connectionChannels, $deliverGuard): void
    {
        $server->on('close', function (Server $ws, int $fd) use ($connectionChannels, $deliverGuard) {
            if ($connectionChannels->exists((string)$fd)) {
                $connectionChannels->del((string)$fd);
            }
            // $deliverGuard's keys ("fd|event|dataHash") never expired on
            // their own ("cleanup is not critical for a long-lived
            // process", per the original comment) — but Swoole recycles
            // low fd numbers for new connections, and confirmed live this
            // matters for correctness, not just memory: a brand new
            // connection landing on a just-freed fd could inherit a stale
            // guard entry from the PREVIOUS connection and never receive
            // an event/data combination that happens to repeat (e.g. a
            // generic signal with the same payload shape). Clearing this
            // connection's own entries on close means a reused fd always
            // starts with a clean slate for whoever connects next.
            // Matched on the row's own 'fd' column, not the key — the key
            // is a fixed-length hash (fixed alongside this, see
            // registerOnPipeMessageHandler), not a "fd|..."-prefixed
            // string, so it can't be prefix-matched anymore.
            $staleKeys = [];
            foreach ($deliverGuard as $key => $row) {
                if ((int)($row['fd'] ?? -1) === $fd) {
                    $staleKeys[] = $key;
                }
            }
            // Deleted in a separate pass, after the foreach above has
            // finished — mutating a Swoole\Table while iterating it is not
            // something to rely on being safe.
            foreach ($staleKeys as $key) {
                $deliverGuard->del($key);
            }
            $this->log("CLOSE #{$fd}");
        });
    }
    function registerOnMessageHandler($server, $connectionChannels)
    {
        $server->on('message', function (Server $ws, $frame) use ($connectionChannels) {
            $this->vlog("IN  #{$frame->fd}: {$frame->data}");

            $payload  = json_decode($frame->data, true) ?: [];
            $action   = $payload['action'] ?? '';
            $csv      = (string)($connectionChannels->get((string)$frame->fd, 'channels') ?: '');
            $channels = $csv === '' ? [] : explode(',', $csv);
            $userJson = (string)($connectionChannels->get((string)$frame->fd, 'user') ?: '');
            $user = json_decode($userJson, true) ?: null;
            switch ($action) {
                case 'ping':
                    $ws->push($frame->fd, '{"type":"pong","ts":' . time() . '}');
                    break;

                case 'subscribe':
                    $name = trim((string)($payload['channel'] ?? ''));
                    if ($name !== '' && !in_array($name, $channels, true)) {
                        // ?? 0 below: fixing the auth call above (see
                        // registerOnOpenHandler) means real user records
                        // reach here now instead of always being the
                        // "system" pseudo-user — confirmed live a real
                        // user's /api/v1/user/self response has no `group`
                        // key at all (only `device_groups`, plural), so
                        // this pre-existing check's direct array access
                        // fatal-errored (this project's error handler
                        // upgrades PHP warnings to thrown exceptions) on
                        // every subscribe attempt from a real user, killing
                        // the Swoole worker. Not redesigning this check's
                        // own wildcard-ownership semantics here, just
                        // making it not crash.
                        if(($user['id'] ?? 0) > 0 && ($user['group']['id'] ?? 0) > 0 && strpos($name, "*") !== false) {
                            $ws->push($frame->fd, json_encode(['type' => 'error', 'error' => 'forbidden', 'description' => 'Only owners allowed to subscribe to this channel.']));
                            break;
                        }
                        if (!$this->isSubscribeAllowed($user, $name)) {
                            $ws->push($frame->fd, json_encode(['type' => 'error', 'error' => 'forbidden', 'description' => 'Your role does not have permission to subscribe to this channel.']));
                            break;
                        }

                        $channels[] = $name;
                        $connectionChannels->set((string)$frame->fd, ['channels' => implode(',', $channels), 'user' => $userJson]);
                        $this->log(sprintf('SUB #%d -> %s', $frame->fd, $name));
                    }
                    $this->vlog(sprintf('subscribes #%d ->  %s', $frame->fd, json_encode($channels, JSON_UNESCAPED_UNICODE)));
                    $ws->push($frame->fd, json_encode(['type' => 'subscribed', 'channel' => $name]));
                    break;

                case 'unsubscribe':
                    $name = trim((string)($payload['channel'] ?? ''));
                    if ($name !== '') {
                        $channels = array_values(array_filter($channels, static fn($v) => $v !== $name));
                        $connectionChannels->set((string)$frame->fd, ['channels' => implode(',', $channels), 'user' => $userJson]);
                        $this->log(sprintf('UNSUB #%d -> %s', $frame->fd, $name));
                    }
                    $this->vlog(sprintf('subscribes #%d ->  %s', $frame->fd, json_encode($channels, JSON_UNESCAPED_UNICODE)));
                    $ws->push($frame->fd, json_encode(['type' => 'unsubscribed', 'channel' => $name]));
                    break;

                default:
                    $ws->push($frame->fd, '{"type":"error","error":"unsupported_action"}');
            }
        });
    }

    function registerOnPipeMessageHandler($server, $connectionChannels, $deliverGuard): void
    {
        $server->on('pipeMessage', function (Server $ws, int $_, $msg) use ($connectionChannels, $deliverGuard) {
            $eventName = (string)($msg['name'] ?? '');
            if ($eventName === '') return;

            $delivered = 0;
            foreach ($ws->connections as $fd) {
                if (!$ws->isEstablished($fd)) continue;

                $csv = (string)($connectionChannels->get((string)$fd, 'channels') ?: '');
                if ($csv === '') continue;
                $channels = explode(',', $csv);

                // поддержка маски service:* (mask в subscribe)
                $matched = false;
                foreach ($channels as $pattern) {
                    $pattern = trim($pattern);
                    if ($pattern === '') continue;
                    // превращаем маску в regex: * -> .*
                    $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/u';
                    if (preg_match($regex, $eventName)) {
                        $matched = true;
                        break;
                    }
                }

                if (!$matched) continue;
                $sig = md5(json_encode($msg['data'] ?? null));
                // Hashed, not the raw "fd|eventName|sig" string — confirmed
                // live this was actively crashing wca-ws workers (500/503,
                // "Fatal error: Uncaught ErrorException: Swoole\Table::set():
                // key[...] is too long") the moment a longer channel name
                // (e.g. "event:storage:c_events:added", added along with
                // the rest of the real-time rollout) pushed the combined
                // key past Swoole\Table's ~64-byte key length limit — a
                // pre-existing latent bug this only started hitting once
                // channel names got longer than the original short ones
                // (poller:*, etc.) it was written against. A fixed-length
                // md5 of the same inputs keeps the exact same uniqueness
                // guarantee with no length risk regardless of channel name.
                $guardKey = md5($fd . '|' . $eventName . '|' . $sig);
                if ($deliverGuard->exists($guardKey)) {
                    continue; // уже доставляли этим воркером/другим воркером
                }
                $deliverGuard->set($guardKey, ['seen' => 1, 'fd' => $fd]); // простая защита; очистка не критична для долгоживущего процесса

                $ws->push($fd, json_encode([
                    'type' =>  $msg['type'] ?? 'unknown',
                    'channel' => $eventName,
                    'data' => $msg['data'] ?? null,
                    'ts'   => time(),
                ], JSON_UNESCAPED_UNICODE));
                $delivered++;
            }
        });
    }

    function registerOnOpenHandler($server, $connectionChannels): void
    {
        $httpClient = new Client(['timeout' => 5.0, 'http_errors' => false]);
        // BUG FIX — two compounding issues meant this call ALWAYS resolved
        // to {"id":-1,"name":"system"} regardless of the token presented
        // (a valid one, an invalid one, or none), confirmed live:
        //
        // 1. It sent the token as `Authorization: X-Auth-Key <token>`, but
        //    AuthCheckMiddleware::checkKey() only ever reads a literal
        //    `X-Auth-Key` header (or `x-auth-key` query param) — fixed
        //    below by sending the header it actually looks for.
        // 2. More fundamentally: AuthCheckMiddleware::process() checks
        //    isWca($remoteIP) on the request's own PEER address FIRST,
        //    before checkKey() ever runs — and since this validation call
        //    is container-to-container (wca-ws -> wca:8080), its peer IP
        //    is always inside the trusted internal subnet, so it ALWAYS
        //    took the system-user shortcut and never even looked at the
        //    token. Fix #1 alone doesn't reach past this.
        //
        // The middleware already has an escape hatch for exactly this: its
        // getUserIp() only trusts a caller's own WCA-Real-Ip header when
        // the caller's PEER address is itself already a trusted WCA peer
        // (see its comment — this is what lets the bundled nginx report
        // the true external client IP without letting an arbitrary client
        // spoof it). wca-ws's own peer address already qualifies the same
        // way nginx's does, so forwarding the real browser IP (already
        // set by nginx's own `proxy_set_header WCA-Real-Ip $remote_addr`
        // on the /ws location, so it's already sitting on the incoming
        // Swoole request) makes AuthCheckMiddleware treat this call as
        // coming from that real, non-internal IP — which is NOT in the
        // trusted subnet, so it correctly falls through to checkKey()'s
        // real token check instead of the system-user shortcut.
        $checkToken = static function (string $token, string $realIp) use ($httpClient): ?array {
            if ($token === '') return null;
            try {
                $res = $httpClient->get('http://wca:8080/api/v1/user/self', [
                    'headers' => array_filter([
                        'X-Auth-Key'  => $token,
                        'Accept'      => 'application/json',
                        'WCA-Real-Ip' => $realIp,
                    ]),
                ]);
                if ($res->getStatusCode() !== 200) return null;
                $json = json_decode((string)$res->getBody(), true);
                return isset($json['data']) && is_array($json['data']) ? $json['data'] : null;
            } catch (RequestException $e) {
                return null;
            }
        };
        $server->on('open', function (Server $ws, $req) use ($connectionChannels, $checkToken) {
            $token  = (string)($req->get['token'] ?? '');
            $realIp = (string)($req->header['wca-real-ip'] ?? $req->server['remote_addr'] ?? '');
            $user   = $checkToken($token, $realIp);

            if (!$user) {
                $ws->push($req->fd, '{"type":"error","error":"unauthorized"}');
                $ws->disconnect($req->fd, 4401, 'unauthorized');
                return;
            }

            // Trim to only what this process actually needs (identity +
            // role/permissions for the subscribe-time check) before
            // storing or pushing anywhere. The full /api/v1/user/self
            // response is far larger than it looks — confirmed live a
            // real account's came to ~470KB, almost entirely its
            // `active_sessions` field, which the Swoole Table's 'user'
            // column (16000 bytes) silently truncated/rejected
            // (TableRow::set_value ... string value is too long), leaving
            // $user effectively empty on every subsequent lookup and
            // making isSubscribeAllowed() deny every subscribe regardless
            // of permissions. Also avoids pushing that much irrelevant
            // (and session-related) data to the browser on every connect.
            $identity = [
                'id' => $user['id'] ?? null,
                'name' => $user['name'] ?? ($user['login'] ?? null),
                'group' => $user['group'] ?? null,
                'role' => [
                    'id' => $user['role']['id'] ?? null,
                    'name' => $user['role']['name'] ?? null,
                    'permissions' => $user['role']['permissions'] ?? [],
                ],
            ];

            $connectionChannels->set((string)$req->fd, ['channels' => '', 'user' => json_encode($identity, JSON_UNESCAPED_UNICODE)]);
            $ws->push($req->fd, json_encode(['type' => 'ready', 'user' => $identity], JSON_UNESCAPED_UNICODE));

            $this->log(sprintf('OPEN #%d user=%s', $req->fd, $identity['id'] ?? $identity['name'] ?? 'unknown'));
        });

    }


    protected function startRedisWorker($server, $workerCount)
    {
        $process = new Process(function () use ($server, $workerCount) {
            @fwrite(STDOUT, sprintf("[%s] Redis SUB started: internal-events\n", date('Y-m-d H:i:s')));

            $this->redisPubSub->subscribe('internal-events', function ($message) use ($server, $workerCount) {
                $payload = is_string($message) ? json_decode($message, true) : $message;
                if (!is_array($payload)) $payload = ['name' => '', 'data' => $message];
                $payload['name'] = "event:" . $payload['name'];
                $payload['type'] = "event";
                for ($i = 0; $i < $workerCount; $i++) {
                    $server->sendMessage($payload, $i);
                }
            });

            @fwrite(STDOUT, sprintf("[%s] Redis SUB stopped: internal-events\n", date('Y-m-d H:i:s')));
        }, false, SOCK_DGRAM, true);
        $server->addProcess($process);

        $process = new Process(function () use ($server, $workerCount) {
            @fwrite(STDOUT, sprintf("[%s] Redis SUB started: updates\n", date('Y-m-d H:i:s')));

            $this->redisPubSub->subscribe('updates', function ($message) use ($server, $workerCount) {
                $payload = is_string($message) ? json_decode($message, true) : $message;
                if (!is_array($payload)) $payload = ['name' => '', 'data' => $message];
                $payload['name'] = "update:" . $payload['name'];
                $payload['type'] = "update";
                for ($i = 0; $i < $workerCount; $i++) {
                    $server->sendMessage($payload, $i);
                }
            });

            @fwrite(STDOUT, sprintf("[%s] Redis SUB stopped: updates\n", date('Y-m-d H:i:s')));
        }, false, SOCK_DGRAM, true);
        $server->addProcess($process);
    }
}
