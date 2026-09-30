<?php


namespace SwitcherCore\Modules\HuaweiOLT;


use Exception;
use SnmpWrapper\Oid;
use SnmpWrapper\Response\PoollerResponse;
use SnmpWrapper\Response\SnmpResponse;
use SwitcherCore\Modules\AbstractModule;
use SwitcherCore\Modules\Helper;
use SwitcherCore\Switcher\Objects\WrappedResponse;

class OntDelete extends HuaweiOLTAbstractModule
{
    /**
     * @var WrappedResponse[]
     */
    protected $response = null ;

    // Step-by-step transcript of this run, same {command, output, success}
    // shape as console_command/multi_console_command already return for
    // macros and ONT registration — added so the delete/dereg UI can show
    // the same kind of terminal transcript instead of a bare success/fail
    // toast with no visibility into what actually happened on the device.
    protected $commandLog = [];

    // Tracks which command was in flight when a connection-lost exception
    // is caught in run() — set at the top of runSteps() so the outer
    // catch there can log something more useful than "(unknown step)".
    protected $lastCmd = null;

    private function logExec(string $command, string $output, bool $success): void
    {
        $this->commandLog[] = ['command' => $command, 'output' => $output, 'success' => $success];
    }

    // Same check RawConsoleCommand::validResponse() already uses for every
    // other console-driven module — confirmed live that a garbled/rejected
    // command here doesn't always contain the word "Failure" (VRP errors
    // like "% Unknown command" and "% Too many parameters" don't), and the
    // old check only ever looked for that one word, so a step that was
    // actually rejected by the device could still read as a success.
    private function isValidResponse($response): bool
    {
        if (preg_match('/Failure/', $response)) return false;
        if (preg_match('/% .*error/', $response)) return false;
        if (preg_match('/Unknown command/', $response)) return false;
        return true;
    }

    // PATCHED (v7): isConnectionLostException() moved up to the shared
    // AbstractModule base (every vendor's console modules extend it) so
    // the same recognition — and the same fix — applies everywhere
    // multiRawConsoleCommandRun()-driven macros run a console command too,
    // not just this hand-written delete flow. See AbstractModule's own
    // copy of this comment for the full history (v6 here was the
    // original; confirmed live, more than once, that a dying telnet
    // connection can surface at ANY of this module's console->exec()
    // calls, not just the "undo service-port" y/n wait).

    /**
     * @param array $filter
     * @return $this|AbstractModule
     * @throws Exception
     */
    public function run($filter = [])
    {
        $this->commandLog = [];
        $this->lastCmd = null;
        $iface = $this->parseInterface($filter['interface']);
        try {
            $this->runSteps($iface);
        } catch (\Exception $e) {
            if ($this->isConnectionLostException($e)) {
                $this->logExec($this->lastCmd ?? '(unknown step)', '(console session closed — ' . $e->getMessage() . ')', false);
                throw new \Exception("The device's console session closed while processing this deletion (a known device behavior — it can time out mid-command, especially for ONTs with several service ports). This step may have already completed on the device; refresh this ONT's status before retrying, to avoid deleting twice.");
            }
            // Our own already-clear exceptions (validation failures like
            // "Error entering config, resp from device: ...", or the
            // friendlier message the inner service-port try/catch below
            // already throws for its own connection-loss case) pass
            // through unchanged.
            throw $e;
        }
        return $this;
    }

    /**
     * @throws Exception
     */
    private function runSteps(array $iface)
    {
        $cmd = "config";
        $this->lastCmd = $cmd;
        $out = $this->console->exec($cmd);
        $ok = $this->isValidResponse($out);
        $this->logExec($cmd, $out, $ok);
        if (!$ok) {
            throw new \Exception("Error entering config, resp from device: " . $out);
        }

        //Remove service port
        //
        //PATCHED (v3): confirmed live (real terminal, typed by hand) that
        //this command actually shows TWO prompts in sequence, not one —
        //first a menu-style "{ <cr>|gemport<K> }:" (the same shape the ONT
        //ADD/registration macro already has to answer with a literal <cr>
        //for its own service-port commands), THEN, only after answering
        //that with a blank Enter, the real "Are you sure to release
        //service virtual port(s)? (y/n)[n]:" confirmation. v1/v2 of this
        //fix only ever waited for the y/n prompt directly and never
        //answered the menu prompt first — so on any ONT that actually had
        //a service-port to remove, the device sat waiting on the menu
        //prompt for the whole exchange, our wait for "(y/n)" timed out
        //with nothing sent, and the NEXT command we wrote ("interface gpon
        //...") got fed to that still-open menu prompt instead of the top-
        //level shell — which is what actually produced the garbled/merged
        //echo and "% Too many parameters"/"% Unknown command" failures
        //seen live, not a client-side timing race as first suspected.
        //
        //When there's genuinely no service-port to remove (e.g. an ONT
        //that was never provisioned), the device skips both prompts and
        //replies immediately with something like "Error: The service port
        //does not exist.", already back at the normal prompt — handled by
        //simply not finding either prompt within the short wait below.
        //
        //The device's own message warns deletion "will take several
        //minutes" for an ONT with many service ports — the wait after
        //confirming is deliberately long (120s) rather than the short
        //bounded waits used to detect the two optional prompts.
        $cmd = "undo service-port port {$iface['_shelf']}/{$iface['_slot']}/{$iface['_port']} ont {$iface['_onu']}";
        $this->lastCmd = $cmd;
        // PATCHED (v5): v4 only wrapped the WAIT after writing "y" — but
        // confirmed live this connection can already be dead (a broken
        // pipe, not just a slow reply) by the time we get here, and once
        // that's true EVERY further write on this same socket fails the
        // exact same way — there is no auto-reconnect on this console
        // object, so v4's "continue to the next command anyway" plan was
        // provably futile (it just hit the identical broken-pipe error one
        // command later, on "interface gpon ..."). So this whole exchange
        // — the command itself, the optional menu <cr>, and the y/n
        // confirmation — is now one block: the first sign of the
        // connection being gone stops it immediately with one clear
        // message instead of cascading into more confusing errors.
        $connectionLost = false;
        try {
            $this->console->write($cmd);
            usleep(300000);
            // PATCHED (v7): widened from 2s/3s — confirmed live a miss
            // here (device just a touch slower than the bounded window)
            // means the confirmation never actually gets sent, and the
            // exchange then pointlessly burns the whole long wait below
            // before failing. A few extra seconds here is cheap.
            if ($this->rawConsoleWaitPrompt('\{[^}]*\}:', 4)) {
                // Menu prompt shown — answer with the <cr> default, which
                // then leads into the real y/n confirmation.
                $this->console->write("");
                usleep(300000);
            }
            if ($this->rawConsoleWaitPrompt('.*\(y\/n\)\[n\]', 5)) {
                $this->console->write("y");
                // The device's own message warns deletion "will take
                // several minutes" for an ONT with many service ports —
                // this wait is deliberately long (120s) rather than the
                // short bounded waits used to detect the two optional
                // prompts above. Its own "console may timeout" warning is
                // real too, confirmed live: this can still throw even with
                // 120s given, if the device's own idle-timeout closes the
                // session first — caught below like every other failure
                // in this block.
                // PATCHED (v7): was a direct waitPrompt() call — confirmed
                // live this doesn't reliably honor 120s (see
                // AbstractModule::rawConsoleWaitPromptLong()'s own comment
                // for the full explanation: waitPrompt()'s $timeout
                // argument only reaches the per-read stream timeout, never
                // the separate property the overall loop deadline is
                // computed from).
                $this->rawConsoleWaitPromptLong($this->console->getDeviceHelper()->getPrompt(), 280);
            }
        } catch (\Exception $e) {
            $connectionLost = true;
        }
        if ($connectionLost) {
            $this->logExec($cmd, '(console session closed mid-exchange — see thrown message)', false);
            // Confirmed live, more than once: an attempt that failed
            // exactly this way, checked minutes later with a fresh
            // read-only session, showed the ONT genuinely already gone —
            // the deletion keeps running device-side regardless of
            // whether our CLI session is still attached to watch it. So
            // this reads as "can't confirm from here", not "nothing
            // happened" — but since every next write on this connection
            // fails the same way, there's nothing more this run can safely
            // attempt.
            throw new \Exception("The device's console session closed partway through confirming this deletion (a known device behavior for ONTs with several service ports — it warns \"console may timeout\" for exactly this case). The service-port removal likely already completed on the device; refresh this ONT's status before retrying, to avoid deleting twice.");
        }
        $out = $this->console->getBuffer();
        // Not throwing on failure here: an "already gone"/"doesn't exist"
        // reply is expected and fine — the ont delete step below is the
        // real gate that must succeed for the dereg to count.
        $this->logExec($cmd, $out, $this->isValidResponse($out));

        //Remove ONT
        $cmd = "interface {$iface['_technology']} {$iface['_shelf']}/{$iface['_slot']}";
        $this->lastCmd = $cmd;
        $out = $this->console->exec($cmd);
        $ok = $this->isValidResponse($out);
        $this->logExec($cmd, $out, $ok);
        if (!$ok) {
            throw new \Exception("Error entering interface, resp from device: " . $out);
        }

        $cmd = "ont delete {$iface['_port']} {$iface['_onu']}";
        $this->lastCmd = $cmd;
        $resp = $this->console->exec($cmd);
        $success = $this->isValidResponse($resp);
        // "Failure: The ONT does not exist" is a real, distinct case from
        // every other failure this checks for — confirmed live (a fresh
        // read-only "display ont info" against the real device, no state
        // change) that this exact ONU genuinely isn't there anymore, most
        // likely already removed by an earlier dereg that succeeded on the
        // device but never got the chance to clear our own stale
        // device_interfaces record / cache (e.g. this same command failing
        // on some OTHER, real reason first). The end state the caller
        // wanted — this ONT gone — is already true, so this counts as
        // success rather than throwing and leaving that stale record
        // behind to keep failing the exact same way on every retry.
        $alreadyGone = stripos($resp, 'does not exist') !== false;
        $this->logExec($cmd, $resp, $success || $alreadyGone);
        if(!$success && !$alreadyGone) {
            throw new \Exception("Error delete, resp from device: " . $resp);
        }
        return $this;
    }

    public function getPretty()
    {
        return $this->commandLog;
    }

    public function getPrettyFiltered($filter = [], $fromCache = false)
    {
        return $this->commandLog;
    }
}
