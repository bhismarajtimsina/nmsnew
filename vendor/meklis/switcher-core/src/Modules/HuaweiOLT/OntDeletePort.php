<?php


namespace SwitcherCore\Modules\HuaweiOLT;


use Exception;
use SwitcherCore\Modules\AbstractModule;

// "Clear PON" — deletes every ONT on a PON port with VRP's own bulk
// command ("ont delete <port> all") instead of the per-ONT loop OntDelete
// runs one at a time. Deliberately NOT a wrapper around OntDelete: this
// class only ever issues this one command and reports back whatever the
// device itself reports, on purpose (per explicit product decision) —
// confirmed live that the device still requires each ONT's service-ports
// to already be clear (same precondition as a single "ont delete"), so on
// a port where ONTs still have active services this can legitimately
// report "success: 0" — that's the device's real answer, not a bug here,
// and this module does not fall back to the slower per-ONT service-port
// dance to compensate for it.
class OntDeletePort extends HuaweiOLTAbstractModule
{
    protected $commandLog = [];

    private function logExec(string $command, string $output, bool $success): void
    {
        $this->commandLog[] = ['command' => $command, 'output' => $output, 'success' => $success];
    }

    private function isValidResponse($response): bool
    {
        if (preg_match('/Failure/', $response)) return false;
        if (preg_match('/% .*error/', $response)) return false;
        if (preg_match('/Unknown command/', $response)) return false;
        return true;
    }

    // isConnectionLostException() lives on the shared AbstractModule base
    // now (every vendor's console modules extend it) — same two raw
    // meklis/console-client exception shapes confirmed live, repeatedly,
    // against this same device/port.

    /**
     * @param array $filter ['interface' => <PON port interface id/name, e.g. the port itself, not an ONT>]
     * @return $this|AbstractModule
     * @throws Exception
     */
    public function run($filter = [])
    {
        $this->commandLog = [];
        // A bare port interface parses to _onu === null (see
        // HuaweiOLTAbstractModule::parseInterface's PON-port branch) —
        // this module only accepts that; an ONT-level id would still
        // parse, but silently targeting the wrong port would be far worse
        // than failing loudly, so that's checked for explicitly.
        $iface = $this->parseInterface($filter['interface']);
        if (!empty($iface['_onu'])) {
            throw new \Exception("Expected a PON port interface, got an ONT interface ({$iface['name']}).");
        }

        $lastCmd = null;
        try {
            $cmd = "config";
            $lastCmd = $cmd;
            $out = $this->console->exec($cmd);
            $ok = $this->isValidResponse($out);
            $this->logExec($cmd, $out, $ok);
            if (!$ok) {
                throw new \Exception("Error entering config, resp from device: " . $out);
            }

            $cmd = "interface {$iface['_technology']} {$iface['_shelf']}/{$iface['_slot']}";
            $lastCmd = $cmd;
            $out = $this->console->exec($cmd);
            $ok = $this->isValidResponse($out);
            $this->logExec($cmd, $out, $ok);
            if (!$ok) {
                throw new \Exception("Error entering interface, resp from device: " . $out);
            }

            // Confirmed live: "ont delete <port> all" shows one y/n
            // confirmation ("Are you sure to execute this command?
            // (y/n)[n]:"), then prints its own progress/result summary
            // ("Command is being executed. Please wait. Number of ONTs
            // that can be deleted: X, success: Y") before returning to
            // the prompt. No menu-style prompt like the per-ONT
            // "undo service-port" has — just the one y/n.
            $cmd = "ont delete {$iface['_port']} all";
            $lastCmd = $cmd;
            $this->console->write($cmd);
            usleep(300000);
            // Widened from 3s — see OntDelete.php v7 for why (a missed
            // bounded wait burns the whole long wait below for nothing).
            if ($this->rawConsoleWaitPrompt('.*\(y\/n\)\[n\]', 5)) {
                $this->console->write("y");
                // Deleting every ONT on a full port (up to 128) is the
                // slowest single operation this module issues — matching
                // OntDelete's own 120s wait for its analogous confirm-then-
                // wait step, since the device's "please wait" message
                // implies no fixed bound. rawConsoleWaitPromptLong(), not a
                // direct waitPrompt() call — confirmed live (OntDelete.php
                // v7) that a direct call doesn't reliably honor 120s.
                $this->rawConsoleWaitPromptLong($this->console->getDeviceHelper()->getPrompt(), 280);
            }
        } catch (\Exception $e) {
            if ($this->isConnectionLostException($e)) {
                $this->logExec($lastCmd ?? '(unknown step)', '(console session closed — ' . $e->getMessage() . ')', false);
                throw new \Exception("The device's console session closed while clearing this port (a known device behavior — deleting many ONTs at once can take a while and the console can time out mid-wait). Refresh this port's ONT list before retrying to see what actually went through.");
            }
            throw $e;
        }

        $out = $this->console->getBuffer();
        $this->logExec($cmd, $out, $this->isValidResponse($out));

        // The device's own summary line, e.g. "Number of ONTs that can be
        // deleted: 67, success: 0" — surfaced as-is so the caller/UI can
        // show the real number rather than inferring anything.
        $successCount = null;
        $totalCount = null;
        if (preg_match('/Number of ONTs that can be deleted:\s*(\d+)\s*,\s*success:\s*(\d+)/i', $out, $m)) {
            $totalCount = (int)$m[1];
            $successCount = (int)$m[2];
        }

        return [
            'raw' => $out,
            'total' => $totalCount,
            'success' => $successCount,
            'commands' => $this->commandLog,
        ];
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
