<?php


namespace SwitcherCore\Modules;



use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;
use Exception;
use Monolog\Logger;
use SnmpWrapper\MultiWalkerInterface;
use SnmpWrapper\Response\PoollerResponse;
use SwitcherCore\Config\Objects\Model;
use SwitcherCore\Config\Objects\Trap;
use SwitcherCore\Config\OidCollector;
use SwitcherCore\Exceptions\IncompleteResponseException;
use SwitcherCore\Switcher\CacheInterface;
use SwitcherCore\Switcher\Device;
use SwitcherCore\Switcher\Objects\WrappedResponse;

abstract class AbstractModule
{
    /**
     * @var array | WrappedResponse[]
     */
    protected $response;

    /**
     * @Inject
     * @var OidCollector
     */
    protected $oids;


    /**
     * @Inject
     * @var MultiWalkerInterface
     */
    protected $snmp;

    /**
     * @Inject
     * @var Model
     */
    protected $model;

    /**
     * @Inject
     * @var Container
     */
    protected $container;


    /**
     * @Inject
     * @var Logger
     */
    protected $logger;

    /**
     * @Inject
     * @var Device
     */
    protected $device;

    /**
     * @param array $params
     * @return self
     */
    public abstract function run($params = []);

    public function trap(Trap  $trap, $data)
    {
        throw new \Exception("Not implemented catching trap in ".get_class($this));
    }

    /**
     * @return array
     */
    public function getRaw() {
        return  $this->response;
    }

    // Shared across every vendor's console modules (originally written for
    // HuaweiOLT\OntDelete/OntDeletePort, confirmed live against a real
    // device more than once) — recognizes the two raw meklis/console-client
    // exception shapes a dead/dying telnet session throws: a low-level
    // "fwrite(): ... Broken pipe" (the write itself fails), or "Couldn't
    // find the requested : '<prompt>', ... it was not in the data returned
    // from server: " (fgetc() hitting a clean EOF without a stream_timeout).
    // Distinct from a genuine "Stream timeout Nsec is reached" on a command
    // that's simply slow, though that's included too since a caller-chosen
    // timeout that's too short for a legitimately slow device command
    // deserves the same "can't confirm what happened, don't keep going"
    // treatment as an outright dropped connection.
    protected function isConnectionLostException(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        return stripos($msg, 'Broken pipe') !== false
            || stripos($msg, 'was not in the data returned from server') !== false
            || stripos($msg, 'Stream timeout') !== false
            || stripos($msg, 'Connection closed') !== false;
    }

    /**
     * @param array $params
     * @return self
     * @throws Exception
     */
    protected function rawConsoleCommandRun($params = [])
    {
        if (!property_exists($this, 'console') || !$this->console) {
            throw new Exception("Module required console connection");
        }
        if (!isset($params['command'])) {
            throw new Exception("Command parameter is required");
        }

        // Every 'command' field this method ever puts into $this->response
        // (and so, every {command, output, success} transcript entry shown
        // to the UI) uses $command, not $params['command'] — confirmed
        // live this actually mattered: $params['command'] is the raw
        // template line BEFORE any macro tag (<confirm-optional=...>,
        // <cr>, <prompt=...>, etc.) gets stripped, so a user reading the
        // transcript would see the literal tag syntax next to "command
        // sent to device", which reads as if that syntax was sent
        // literally (it never is — it's stripped before write(), same as
        // it always was; only the LABEL was wrong). $command is
        // reassigned to the stripped, real text inside whichever branch
        // below runs, so by the time any response is built it already
        // reflects what was actually transmitted.
        $command = $params['command'];
        $oldStreamTimeout = null;
        if (strpos($command, "<stream_timeout=") !== false) {
            if (preg_match('/<stream_timeout=([0-9]+(?:\.[0-9]+)?)>/', $command, $m)) {
                if ((float)$m[1] <= 0) {
                    $this->response = [
                        'command' => $command,
                        'output' => '',
                        'success' => "ERROR PARSE STREAM TIMEOUT",
                    ];
                    return $this;
                }
                $oldStreamTimeout = $this->console->getStreamTimeout();
                $this->console->setStreamTimeout((float)$m[1]);
                $command = trim(str_replace($m[0], '', $command));
            } else {
                $this->response = [
                    'command' => $command,
                    'output' => '',
                    'success' => "ERROR PARSE STREAM TIMEOUT",
                ];
                return $this;
            }
        }

        // PATCHED: every write()/waitPrompt()/exec() below used to be
        // unguarded — a telnet session that dies mid-command (a low-level
        // "fwrite(): ... Broken pipe", or "Couldn't find the requested :
        // '<prompt>', ... it was not in the data returned from server: "
        // on a clean EOF) threw straight out of this method as a raw
        // meklis/console-client exception, all the way up through
        // multiRawConsoleCommandRun()/MacrosGateway::execute() to the API
        // response — confirmed live (see OntDelete.php/OntDeletePort.php,
        // hardened the same way for the same reason) that this is a real,
        // recurring failure mode, not a hypothetical one: the device's own
        // idle-timeout can close the session while a slow command is still
        // being processed device-side. Wrapping this in one try/catch and
        // recognizing that failure shape turns it into the same
        // {command, output, success:false} shape every other rejected
        // command already produces — which macro execution already knows
        // how to show in its transcript and stop on (multiRawConsoleCommandRun's
        // break_on_error) — instead of a raw exception with an
        // implementation-level message reaching the UI.
        try {
            if (strpos($command, "<cr>") !== false) {
                $command = trim(str_replace("<cr>", "", $command));
                $this->console->write($command);
                usleep(100000);
                $this->console->write("");
                $this->console->waitPrompt($this->console->getDeviceHelper()->getPrompt());
                $response = $this->console->getBuffer();
            } elseif (strpos($command, "<prompt=") !== false) {
                if (preg_match("/<prompt=[\"'](.*?)[\"']>/", $command, $m) || preg_match('/<prompt=(.*?)>/', $command, $m)) {
                    $command = trim(str_replace($m[0], '', $command));
                    $this->console->write($command);
                    usleep(300000);
                    $this->console->waitPrompt($m[1]);
                    $response = $this->console->getBuffer();
                } else {
                    $this->response = [
                        'command' => $command,
                        'output' => '',
                        'success' => "ERROR PARSE PROMPT",
                    ];
                    if ($oldStreamTimeout !== null) {
                        $this->console->setStreamTimeout($oldStreamTimeout);
                    }
                    return $this;
                }
            } elseif (strpos($command, "<confirm-optional=") !== false) {
                // Handles a two-stage exchange <confirm-if=> can't: an
                // OPTIONAL intermediate prompt (only shown sometimes —
                // e.g. Huawei VRP's "undo service-port" shows a
                // menu-style "{ <cr>|gemport<K> }:" only when the ONT
                // actually has more than one gem-port to choose from),
                // answered first if it appears, THEN a required
                // confirmation prompt. Ported verbatim from
                // HuaweiOLT\OntDelete's hand-tuned v3-v6 logic (confirmed
                // live, repeatedly, against a real device) rather than
                // reinventing it — each bounded wait is short (the
                // prompts either show up fast or don't show up at all),
                // but the final wait for the device to finish and return
                // to its normal prompt is deliberately long (120s): VRP's
                // own message warns some confirmations "will take several
                // minutes" and that the console itself can time out
                // waiting.
                if (preg_match('/<confirm-optional=(.*?)>/', $command, $m) && $co = $this->parseRawConsoleConfirmOptionalArguments($m[1])) {
                    $command = trim(str_replace($m[0], '', $command));
                    $this->console->write($command);
                    usleep(300000);
                    // Widened from 2s/3s (OntDelete.php's original values)
                    // — confirmed live that a miss here (device just a
                    // touch slower than the bounded window) means the
                    // confirmation never actually gets sent, and the whole
                    // exchange then pointlessly burns the full long wait
                    // below before failing. A few extra seconds here is
                    // cheap; a missed confirm is not.
                    // Patterns used AS REGEXES (see rawConsolePromptPattern)
                    // — preg_quote() here is what made this hang. Note
                    // <confirm-if=> below deliberately keeps preg_quote:
                    // its existing macros pass literal prompt text, and
                    // reinterpreting that as a regex would silently change
                    // their matching.
                    if ($co['menu_prompt'] !== '' && $this->rawConsoleWaitPrompt($this->rawConsolePromptPattern($co['menu_prompt']), 4)) {
                        $this->console->write($co['menu_answer']);
                        usleep(300000);
                    }
                    if ($co['confirm_prompt'] !== '' && $this->rawConsoleWaitPrompt($this->rawConsolePromptPattern($co['confirm_prompt']), 5)) {
                        $this->console->write($co['confirm_answer']);
                    }
                    $this->rawConsoleWaitPromptLong($this->console->getDeviceHelper()->getPrompt(), 280);
                    $response = $this->console->getBuffer();
                } else {
                    $this->response = [
                        'command' => $command,
                        'output' => '',
                        'success' => "ERROR PARSE PROMPT",
                    ];
                    if ($oldStreamTimeout !== null) {
                        $this->console->setStreamTimeout($oldStreamTimeout);
                    }
                    return $this;
                }
            } elseif (strpos($command, "<confirm-if=") !== false) {
                if (preg_match('/<confirm-if=(.*?)>/', $command, $m) && $confirmIf = $this->parseRawConsoleConfirmIfArguments($m[1])) {
                    $command = trim(str_replace($m[0], '', $command));
                    $this->console->write($command);
                    usleep(300000);

                    if ($this->rawConsoleWaitPrompt(preg_quote($confirmIf['prompt'], '/'), 2)) {
                        $this->console->write($confirmIf['confirm']);
                        $this->console->waitPrompt($this->console->getDeviceHelper()->getPrompt());
                    }
                    $response = $this->console->getBuffer();
                } else {
                    $this->response = [
                        'command' => $command,
                        'output' => '',
                        'success' => "ERROR PARSE PROMPT",
                    ];
                    if ($oldStreamTimeout !== null) {
                        $this->console->setStreamTimeout($oldStreamTimeout);
                    }
                    return $this;
                }
            } elseif (strpos($command, "<confirm=") !== false) {
                if (preg_match("/<confirm=[\"'](.*?)[\"']>/", $command, $m) || preg_match('/<confirm=(.*?)>/', $command, $m)) {
                    $command = trim(str_replace($m[0], '', $command));
                    $this->console->write($command);
                    usleep(300000);
                    $this->console->write($m[1]);
                    $this->console->waitPrompt($this->console->getDeviceHelper()->getPrompt());
                    $response = $this->console->getBuffer();
                } else {
                    $this->response = [
                        'command' => $command,
                        'output' => '',
                        'success' => "ERROR PARSE PROMPT",
                    ];
                    if ($oldStreamTimeout !== null) {
                        $this->console->setStreamTimeout($oldStreamTimeout);
                    }
                    return $this;
                }
            } elseif (isset($params['prompt'])) {
                $response = $this->console->exec($command, true, $params['prompt']);
            } else {
                $response = $this->console->exec($command);
            }
        } catch (Exception $e) {
            if ($oldStreamTimeout !== null) {
                $this->console->setStreamTimeout($oldStreamTimeout);
            }
            if ($this->isConnectionLostException($e)) {
                $this->response = [
                    'command' => $command,
                    'output' => "(console session closed — {$e->getMessage()})",
                    'success' => false,
                ];
                return $this;
            }
            throw $e;
        }

        if ($oldStreamTimeout !== null) {
            $this->console->setStreamTimeout($oldStreamTimeout);
        }

        $this->response = [
            'command' => $command,
            'output' => $response,
            'success' => $this->validResponse($response),
        ];
        return $this;
    }

    protected function parseRawConsoleConfirmIfArguments($arguments)
    {
        if (!preg_match('/^\s*(?:"([^"]*)"|\'([^\']*)\'|([^,]*))\s*,\s*(?:"([^"]*)"|\'([^\']*)\'|(.*))\s*$/', $arguments, $m)) {
            return false;
        }

        $confirm = $m[1] !== '' ? $m[1] : ($m[2] !== '' ? $m[2] : $m[3]);
        $prompt = $m[4] !== '' ? $m[4] : ($m[5] !== '' ? $m[5] : $m[6]);
        if (trim($prompt) === '') {
            return false;
        }

        return [
            'confirm' => trim($confirm),
            'prompt' => trim($prompt),
        ];
    }

    // <confirm-optional=(menu_prompt, menu_answer, confirm_prompt, confirm_answer)>
    // menu_prompt/menu_answer may both be empty ('', '') to skip straight
    // to the required confirm — confirm_prompt/confirm_answer are the ones
    // that matter and mirror <confirm-if=>'s own arguments, just in
    // (prompt, answer) reading order instead of confirm-if's
    // (answer, prompt) order, since this tag is read left-to-right as
    // "what you'll see, what to send" for each of its two stages.
    protected function parseRawConsoleConfirmOptionalArguments($arguments)
    {
        // Strip the tag's own surrounding parentheses first. The capture
        // from /<confirm-optional=(.*?)>/ keeps them, and without removing
        // them the FIRST and LAST arguments never match the quoted
        // alternatives below — they fall through to the bare [^,]* branch
        // and come back still wrapped in punctuation. Confirmed live on the
        // stock delete macro: menu_prompt parsed as "('{[^}]*}:'" (a leading
        // "(" makes it an invalid regex, so it silently degraded to a
        // literal that could never match the device's "{ <cr>|all<K> }:")
        // and confirm_answer parsed as "'y')" — meaning the literal text
        // "'y')" would have been sent to the device instead of "y". Between
        // them, the confirmation was never completed and the delete hung at
        // its final long wait.
        $arguments = trim($arguments);
        if (strlen($arguments) >= 2 && $arguments[0] === '(' && substr($arguments, -1) === ')') {
            $arguments = substr($arguments, 1, -1);
        }
        $part = '(?:"([^"]*)"|\'([^\']*)\'|([^,]*))';
        if (!preg_match("/^\\s*{$part}\\s*,\\s*{$part}\\s*,\\s*{$part}\\s*,\\s*{$part}\\s*\$/", $arguments, $m)) {
            return false;
        }
        $pick = function ($a, $b, $c) use ($m) {
            return $m[$a] !== '' ? $m[$a] : ($m[$b] !== '' ? $m[$b] : $m[$c]);
        };
        $confirmPrompt = trim($pick(7, 8, 9));
        if ($confirmPrompt === '') {
            return false;
        }
        return [
            'menu_prompt' => trim($pick(1, 2, 3)),
            'menu_answer' => trim($pick(4, 5, 6)),
            'confirm_prompt' => $confirmPrompt,
            'confirm_answer' => trim($pick(10, 11, 12)),
        ];
    }

    /**
     * <confirm-optional=>'s prompt arguments are written as REGEXES by
     * macro authors — the stock "Huawei GPON - Delete ONT" macro uses
     * '{[^}]*}:' and '.*\(y\/n\)\[n\]' — and waitPrompt() treats its
     * argument as a regex as well. This code used to wrap them in
     * preg_quote(), which turned them into literals that could never match
     * the device's actual "{ <cr>|all<K> }:" or "(y/n)[n]:" output. The
     * answers were therefore never sent, the device sat waiting at its
     * menu prompt, and the exchange burned its full 280s final wait before
     * dying — confirmed live, this is precisely what made "Delete ONT"
     * hang after the "config" step.
     *
     * A pattern that isn't valid regex falls back to preg_quote(), so a
     * macro written with a plain literal prompt still behaves as before.
     */
    protected function rawConsolePromptPattern($pattern)
    {
        if (@preg_match('/' . $pattern . '/', '') === false) {
            return preg_quote($pattern, '/');
        }
        return $pattern;
    }

    protected function rawConsoleWaitPrompt($prompt, $timeout)
    {
        $lastTimeout = $this->console->getTimeout();
        $lastStreamTimeout = $this->console->getStreamTimeout();
        $this->console->setTimeout($timeout);
        $this->console->setStreamTimeout($timeout);

        try {
            $this->console->waitPrompt($prompt, $timeout);
            return true;
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Stream timeout') !== false || strpos($e->getMessage(), "Couldn't find the requested") !== false) {
                return false;
            }
            throw $e;
        } finally {
            $this->console->setTimeout($lastTimeout);
            $this->console->setStreamTimeout($lastStreamTimeout);
        }
    }

    // For the long "wait for the device to finish and return to its normal
    // prompt" step every multi-step confirm flow ends with. Confirmed live
    // that just calling $this->console->waitPrompt($prompt, $timeoutSec)
    // directly does NOT reliably wait that long: $timeoutSec only reaches
    // Telnet::getc()'s per-read stream_set_timeout() call — it never
    // touches $this->timeout, the SEPARATE property readTo()'s own overall
    // loop deadline ($until_t = time() + $this->timeout) is computed from.
    // If that property is still whatever short baseline the connection (or
    // an earlier bounded rawConsoleWaitPrompt() probe) left it at, the
    // whole wait can still fail well before the intended duration — seen
    // live as a "Stream timeout Nsec is reached" exception whose own N,
    // confusingly, is unrelated to $timeoutSec too (the library's error
    // message always reports $this->stream_timeout_sec, not whichever
    // value the specific getc() call actually used). Setting both
    // properties explicitly first, like rawConsoleWaitPrompt() already
    // does for its own bounded probes, is the fix. Unlike
    // rawConsoleWaitPrompt(), a genuine timeout here is real news — it
    // propagates rather than being swallowed into a false return.
    protected function rawConsoleWaitPromptLong($prompt, $timeoutSec)
    {
        $lastTimeout = $this->console->getTimeout();
        $lastStreamTimeout = $this->console->getStreamTimeout();
        $this->console->setTimeout($timeoutSec);
        $this->console->setStreamTimeout($timeoutSec);
        try {
            return $this->console->waitPrompt($prompt, $timeoutSec);
        } finally {
            $this->console->setTimeout($lastTimeout);
            $this->console->setStreamTimeout($lastStreamTimeout);
        }
    }

    /**
     * @param array $params
     * @return self
     * @throws Exception
     */
    protected function multiRawConsoleCommandRun($params = [])
    {
        if (!isset($params['commands'])) {
            throw new Exception("Commands parameter is required");
        }
        if (!is_array($params['commands']) && is_string($params['commands'])) {
            $commands = explode("\n", $params['commands']);
        } else {
            $commands = $params['commands'];
        }

        // Optional real-time progress: a caller (ExecuteMacros.php) that
        // supplies a caller-generated execution_id gets this run's
        // {command,output,success} transcript written to the app's own
        // shared cache after EVERY step, not just once at the very end —
        // requested live, since a macro that legitimately takes over a
        // minute (confirmed live earlier this session) previously gave
        // zero feedback about which step it was even on until the whole
        // thing finished. A lightweight polling endpoint
        // (GetExecutionProgress.php) reads the same key while the main
        // request is still in flight. Total step count is known upfront
        // (count($commands)) even though some may never run (an early
        // break_on_error stop, or an <exception=...> abort) — the poller
        // can tell "still going" from "done" by whether $doneCount has
        // reached $totalCount, without needing a separate "finished" flag.
        $executionId = $params['execution_id'] ?? null;
        $totalCount = count($commands);

        // PATCHED: publish a distinct "connecting" checkpoint before the
        // first real command runs. Confirmed live this was a real gap —
        // connect+login happens lazily inside the first command's own
        // write()/exec() call, so a slow or hung login looked IDENTICAL,
        // from the UI's point of view, to "nothing has started yet": the
        // progress cache stayed empty either way, leaving the transcript
        // modal frozen on its static "Connecting to device…" placeholder
        // with no way to tell whether login itself had even succeeded.
        // Forcing the connect+login to happen explicitly here (instead of
        // implicitly on the first real command) lets us publish a real
        // checkpoint the instant it succeeds or fails. This entry is only
        // ever written to the live-progress cache, never merged into
        // $response/$this->response — the transcript actually returned to
        // the caller at the end is unaffected, so nothing downstream that
        // inspects the final command list (success/failure checks, etc.)
        // sees this synthetic step.
        // Unconditional, so it cannot be silently skipped the way the
        // console-gated checkpoint below can — this one appearing proves the
        // command runner was actually entered, which distinguishes "hung
        // before ever reaching the runner" (nothing published) from "hung
        // inside the runner's own console I/O".
        if ($executionId) {
            $this->publishMacroProgress($executionId, [[
                'command' => '(runner)',
                'output' => 'Command runner reached — ' . $totalCount . ' command(s) queued',
                'success' => true,
            ]], $totalCount);
        }

        if ($executionId && property_exists($this, 'console') && $this->console) {
            // Published BEFORE the connect attempt, not after — confirmed
            // live this ordering matters: publishing only on success meant
            // a hang INSIDE connectAndLogin() looked exactly like a hang
            // before the runner started (nothing in the cache either way),
            // which is the blind spot this whole checkpoint exists to
            // close. With this entry written first, the UI flips off its
            // static "Connecting to device…" placeholder the moment the
            // command runner is actually reached, so "stuck showing
            // connecting" now genuinely means "stuck inside login" rather
            // than "stuck somewhere unknown upstream".
            $this->publishMacroProgress($executionId, [[
                'command' => '(connect)',
                'output' => 'Connecting and logging in to device…',
                'success' => true,
            ]], $totalCount);
            try {
                $this->console->connectAndLogin();
                $this->publishMacroProgress($executionId, [[
                    'command' => '(connect)',
                    'output' => 'Connected and logged in to device',
                    'success' => true,
                ]], $totalCount);
            } catch (\Throwable $e) {
                $this->publishMacroProgress($executionId, [[
                    'command' => '(connect)',
                    'output' => 'Failed to connect/login to device: ' . $e->getMessage(),
                    'success' => false,
                ]], $totalCount);
                throw $e;
            }
        }

        $response = [];
        foreach ($commands as $command) {
            if (preg_match("/\<\s*?exception[ =]*?['\"](.*)['\"].*?\>/", $command, $match)) {
                throw new Exception($match[1]);
            }
            if (preg_match('/\<\s*?sleep[ =]*?([0-9]{1,3}).*?\>/', $command, $match)) {
                sleep($match[1]);
                continue;
            }
            if (preg_match('/^\<f\>(.*)$/', $command, $match)) {
                $resp = $this->getModule('console_command')->run(['command' => trim($match[1])])->getPretty();
                $resp['success'] = true;
                $response[] = $resp;
                $this->publishMacroProgress($executionId, $response, $totalCount);
                continue;
            }

            $prompt = null;
            if (preg_match("/^(.*)\<\s*?prompt[ =]*?['\"](.*)['\"].*?\>/i", $command, $match)) {
                $command = $match[1];
                $prompt = $match[2];
            }

            $resp = $this->getModule('console_command')->run(['command' => trim($command), 'prompt' => $prompt])->getPretty();
            $response[] = $resp;
            $this->publishMacroProgress($executionId, $response, $totalCount);
            if (!$resp['success'] && ($params['break_on_error'] ?? 'yes') != 'no') {
                break;
            }
        }
        $this->response = $response;

        return $this;
    }

    /**
     * @return array
     */
    public abstract function getPretty();
    public abstract function getPrettyFiltered($filter = []);

    /**
     * @param PoollerResponse[] $response
     * @return WrappedResponse[]
     *
     * @throws Exception
     */
    protected function formatResponse($response) {
        $formated = [];
        foreach ($response as $resp) {
            $oid = $this->oids->findOidById($resp->getOid());
            if(isset($formated[$oid->getName()])) {
                $formated[$oid->getName()]->addElements($resp, $oid->getValues());
            } else {
                $formated[$oid->getName()] = WrappedResponse::init($resp, $oid->getValues());
            }
        }

        return $formated;
    }

    /**
     * @param $name
     * @return WrappedResponse
     * @throws IncompleteResponseException
     */
    protected function getResponseByName($name, &$sourceMap = null) {
        if($sourceMap) {
            if(!isset($sourceMap[$name])) {
                throw  new IncompleteResponseException("Response with oid $name not found");
            }
            return $sourceMap[$name];
        }
        if(!isset($this->response[$name])) {
            throw  new IncompleteResponseException("Response with oid $name not found");
        }
        return $this->response[$name];
    }
    public function __toString()
    {
        return get_class($this);
    }

    /**
     * @param $moduleName
     * @return AbstractModule
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getModule($moduleName) {
        return $this->container->get("module.{$moduleName}");
    }

    /**
     *
     * Method for working with cache.
     * Cache method generate unique prefix key for isolating over device and modules
     *
     * @param $key
     * @return mixed|null
     * @throws DependencyException
     * @throws NotFoundException
     */
    protected function getCache($key, $withoutClass = false) {
        if(!$this->container->has(CacheInterface::class)) {
            return null;
        }
        $cache = $this->container->get(CacheInterface::class);
        $md5 = md5($key);
        if($withoutClass) {
            $key = "NO_CLASS_" . $this->device->getIp() . ":" . $md5;
        } else {
            $key = get_class($this) . ":" . $this->device->getIp() . ":" . $md5;
        }
        return $cache->get($key);
    }

    /**
     *
     * Method for set value to cache
     * Cache method generate unique prefix key for isolating over device and modules
     *
     * @param $key
     * @param mixed $value Any value, not allow streams
     * @param int $timeout Timeouts in sec
     * @return bool
     * @throws DependencyException
     * @throws NotFoundException
     */
    protected function setCache($key, $value, $timeout = -1, $withoutClass = false) {
        if(!$this->device->getIp()) {
            throw new NotFoundException("Incorrect injected device, without device");
        }
        if(!$this->container->has(CacheInterface::class)) {
            $this->logger->notice("Cache interface not setted");
            return false;
        }
        $md5 = md5($key);
        if($withoutClass) {
            $key = "NO_CLASS_" . $this->device->getIp() . ":" . $md5;
        } else {
            $key = get_class($this) . ":" . $this->device->getIp() . ":" . $md5;
        }
        $this->container->get(CacheInterface::class)->set($key, $value, $timeout);
        return  true;
    }

    // Deliberately bypasses this class's own getCache()/setCache() above —
    // those key by get_class($this) + device IP, which the polling read
    // side (a plain WCAA Action, not a module instance) has no clean way
    // to reproduce. Reaches directly into the app's own cache singleton
    // instead, under a key the caller fully controls (execution_id), which
    // both sides can agree on trivially. Reaching into \WCAA\App from a
    // vendor module is unusual, but this whole file already carries
    // several app-specific fixes made directly against this vendor
    // package this session — consistent with that, not a new pattern.
    // Silently does nothing if $executionId is empty (no progress
    // requested) or if the app cache isn't reachable for any reason —
    // progress reporting is a nice-to-have, never worth failing the
    // actual command execution over.
    protected function publishMacroProgress($executionId, array $commandLog, int $totalCount): void
    {
        if (!$executionId || !class_exists('\WCAA\App')) {
            return;
        }
        try {
            $cache = \WCAA\App::getInstance()->getContainer()->get(\WCAA\Interfaces\CacheInterface::class);
            $cache->set("macro_progress:{$executionId}", [
                'commands' => $commandLog,
                'done' => count($commandLog),
                'total' => $totalCount,
            ], 300);
        } catch (\Throwable $e) {
            // Best-effort only.
        }
    }

    function convertHexToString($string, $trimNulls = false) {
        if($trimNulls) {
            $string = rtrim($string, "0");
        }
        $symbols = explode(":", $string);
        $str = '';
        foreach ($symbols as $symbol) {
            if(!hexdec($symbol)) continue;
            $char = Helper::hexToStr($symbol);
            if(!mb_detect_encoding($char, 'ASCII', true)) {
                continue;
            }

            $str .= $char;
        }
        return $str;
    }
    function convertHexToStringWithoutDelimiter($string, $trimNulls = false) {
        if($trimNulls) {
            $string = rtrim($string, "0");
        }
        $symbols = str_split($string, 2);
        $str = '';
        foreach ($symbols as $symbol) {
            if(!hexdec($symbol)) continue;
            $char = Helper::hexToStr($symbol);
            if(!mb_detect_encoding($char, 'ASCII', true)) {
                continue;
            }

            $str .= $char;
        }
        return $str;
    }


    /**
     * @param PoollerResponse[] $responses
     * @return void
     */
    protected function checkSnmpRespError($responses) {
        foreach ($responses as $response) {
            if($response->error) {
                throw new \SNMPException($response->error);
            }
        }
        return;
    }

    function getInterfaceCountersOids(): array
    {
        return [
            $this->oids->getOidByName('if.InErrors'),
            $this->oids->getOidByName('if.OutErrors'),
            $this->oids->getOidByName('if.InDiscards'),
            $this->oids->getOidByName('if.OutDiscards'),
            $this->oids->getOidByName('if.HCInOctets'),
            $this->oids->getOidByName('if.HCOutOctets'),
//            $this->oids->getOidByName('if.HCInMulticastPkts'),
//            $this->oids->getOidByName('if.HCOutMulticastPkts'),
//            $this->oids->getOidByName('if.HCInBroadcastPkts'),
//            $this->oids->getOidByName('if.HCOutBroadcastPkts'),
        ];
    }
}
