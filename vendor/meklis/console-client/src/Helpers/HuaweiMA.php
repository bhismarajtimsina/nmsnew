<?php

namespace Meklis\Network\Console\Helpers;

class HuaweiMA extends DefaultHelper
{
    protected $prompt = '^[^{}].*?[>#]$';
    protected $userPrompt = 'ame:';
    protected $passwordPrompt = 'ord:';
    protected $afterLoginCommands = [
        "enable",
        "scroll\r\n\r\n"
    ];
    protected $beforeLogoutCommands = [
        ['command' => 'quit', 'no_wait' => true, 'usleep' => 1000],
        ['command' => 'y', 'no_wait' => true],
    ];
    protected $eol = "\r\n";
    protected $doubleLoginPrompt = true;
    protected $enableMagicControl = false;
    protected $paginationDetect = '/---- More.*----/';
    // PATCHED: was null (no NAWS negotiation at all), meaning the device
    // fell back to its own default terminal width (almost certainly 80
    // columns, standard for VRP). Long raw commands — service-port lines
    // with vlan/gpon-port/ont/gemport/multi-service/user-vlan/tag-transform
    // all on one line easily exceed that — got line-wrapped by the device,
    // which injects a cursor-repositioning escape sequence (confirmed live:
    // "...mul\x1b[1Dti-service...") into the echoed command mid-word. That
    // breaks the simple end-of-line prompt regex, which is why those
    // specific commands hung waiting for a prompt that could never cleanly
    // match ("Stream timeout 5sec is reached") while every shorter command
    // in the same session (ont add, ont ipconfig, etc.) worked fine.
    // Negotiating a wide terminal here stops the device from wrapping at
    // all, removing the escape sequence at its source rather than working
    // around the symptom per-command.
    protected $windowSize = [512, 50];
    protected $waitingResponseTimeout = 0.5;

    // PATCHED: confirmed live against two real MA5683T OLTs (Pathari,
    // Sanichare) that sending 'enable' (or the next command right after
    // it) immediately upon matching the login prompt regex — with zero
    // pause — leaves the device never responding at all: the write is
    // accepted by the socket, but the device's own CLI never processes it,
    // so waitPrompt() hangs until the app's outer timeouts eventually kill
    // the request. A human using ttyd against the exact same account never
    // hits this, because reading the prompt/banner before typing the next
    // command naturally introduces a pause. Reproduced the fix directly:
    // inserting ~0.6s before writing 'enable' made the same call that had
    // just hung succeed in well under a second. Scoped to this device
    // family only (huawei_ma) since every other device type is confirmed
    // working today and doesn't need the extra latency.
    protected $preWriteDelayUs = 600000;

    function getWaitingResponseTimeout()
    {
        if ($this->waitingResponseTimeout && $this->connectionType === 'ssh') {
            return $this->waitingResponseTimeout;
        }
        return null;
    }

    /**
     * @return string
     */
    public function getPrompt()
    {
        if ($this->connectionType === 'ssh') return '';
        return $this->prompt;
    }

}
