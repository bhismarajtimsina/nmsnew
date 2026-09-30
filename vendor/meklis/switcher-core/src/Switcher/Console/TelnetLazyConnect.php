<?php


namespace SwitcherCore\Switcher\Console;

use Exception;
use Meklis\Network\Console\Helpers\HelperInterface;
use Meklis\Network\Console\Telnet;

class TelnetLazyConnect extends Telnet implements ConsoleInterface
{
    protected $host;
    protected $port;
    protected $username;
    protected $password;
    protected $isLogined = false;

    /**
     * Re-entrancy guard for the connect+login sequence.
     *
     * REQUIRED, confirmed live: Telnet::login() finishes by calling
     * runAfterLoginCommands(), which for this device family runs 'enable'
     * and 'scroll' through $this->exec(). exec() below is itself the lazy
     * connector, so while we are still INSIDE login(), isLogined is not yet
     * true and those nested exec() calls would each start another
     * connect+login — which calls runAfterLoginCommands() again, and so on:
     * unbounded recursion that opens a brand new telnet session to the
     * device every cycle. That is what produced both the "stuck on
     * Connecting to device…" hang (the request died at the 300s console
     * timeout having never run a single real command) and the pile of
     * leaked sessions that exhausted the OLT's VTY pool.
     *
     * The original code happened to avoid this by setting isLogined=true
     * BEFORE connecting, but that had its own worse bug: any failure left
     * the object permanently convinced it was logged in. This guard keeps
     * that fix (flag set only on success, so a failure retries cleanly)
     * while restoring the re-entrancy protection it removed.
     */
    protected $isConnecting = false;


    public function exec($command, $add_newline = true, $prompt = null)
    {
        // PATCHED (2 fixes, found live, both real):
        //
        // 1. isLogined used to be set true BEFORE connect()/login() ran,
        // not after. If either threw (confirmed live: a transient login
        // hiccup, real device, no session-limit or credential issue —
        // just an occasional slow/dropped handshake), this object was
        // permanently left believing it was logged in when it never
        // successfully connected at all. Every subsequent exec() call on
        // the SAME object then skipped reconnecting entirely and wrote
        // straight onto a dead/half-open socket — confirmed live this
        // produces garbled responses (leftover unread bytes from the
        // failed handshake mixing into the next command's echo) rather
        // than a clean error. connectAndLogin() below already gets this
        // right (sets the flag only after both calls succeed); this
        // brings exec()'s inline lazy-connect in line with that.
        //
        // 2. This object is not request-scoped — CoreConnector::getOrInit()
        // caches one Core (and its Console) per device IP for the
        // lifetime of the CoreConnector instance, which in this app's
        // RoadRunner runtime is the whole worker process's uptime, not
        // one request. Combined with bug #1, a single transient failure
        // used to permanently poison every subsequent request for that
        // device on that worker until a manual `rr reset` — confirmed
        // live (a fresh, isolated connection succeeded instantly and
        // repeatably; going through the cached path kept failing the
        // same way every time afterward). Fixing #1 alone means a failed
        // attempt now leaves isLogined false, so the NEXT exec() call on
        // this same cached object correctly retries a fresh connect+login
        // instead of reusing the broken one — self-healing without
        // needing a worker restart.
        //
        // 3. Bounding the login phase to a fixed, moderate window (20s)
        // rather than leaving it at whatever this connection's normal
        // command timeout is configured to (60s here) — confirmed live
        // that login() can involve several sequential prompt waits
        // (username, then password, then the initial shell prompt), and
        // on a slow/transient hiccup each one independently eating up to
        // the full configured timeout compounds into minutes, not
        // seconds — one observed case ran long enough to hit this app's
        // own RoadRunner worker max_execution_time (180s) and got the
        // whole worker killed mid-request, a far worse failure than a
        // clean, fast error. 20s comfortably covers a login that's
        // genuinely just slow (a normal one completes in well under 1s,
        // confirmed live) without risking that compounding.
        // $isConnecting: see the property's own comment. Without it, the
        // after-login commands that login() itself issues re-enter this
        // method and recurse into another connect+login forever.
        if (!$this->isLogined && !$this->isConnecting) {
            $lastTimeout = $this->timeout;
            $lastStreamTimeout = $this->stream_timeout_sec;
            $this->setTimeout(20);
            $this->setStreamTimeout(10);
            $this->isConnecting = true;
            try {
                $this->connect($this->host, $this->port);
                $this->login($this->username, $this->password);
                $this->isLogined = true;
            } finally {
                $this->isConnecting = false;
                $this->setTimeout($lastTimeout);
                $this->setStreamTimeout($lastStreamTimeout);
            }
        }
        return parent::exec($command, $add_newline, $prompt); // TODO: Change the autogenerated stub
    }

    function connectAndLogin()
    {
        // Same re-entrancy guard as exec() — this is the other entry point
        // into login(), and login()'s own after-login commands land in
        // exec() while this is still in flight.
        if (!$this->isLogined && !$this->isConnecting) {
            $this->isConnecting = true;
            try {
                $this->connect($this->host, $this->port);
                $this->login($this->username, $this->password);
                $this->isLogined = true;
            } finally {
                $this->isConnecting = false;
            }
        }
        return $this;
    }

    function connectOnly()
    {
        if (!$this->isLogined) {
            $this->connect($this->host, $this->port);
        }
        return $this;
    }

    /**
     * PATCHED: isLogined MUST be cleared whenever the socket goes away,
     * otherwise this object keeps believing it holds a live session — the
     * lazy-connect in exec() above would then skip reconnecting and write
     * straight onto a closed socket. The parent's disconnect() knows
     * nothing about this subclass's flag, so without this override any
     * caller that disconnects (notably the per-request console cleanup in
     * app/server.php, added to stop workers from holding a device's telnet
     * session open for their whole lifetime and exhausting its VTY pool)
     * would poison the cached instance instead of freeing it.
     */
    public function disconnect()
    {
        $this->isLogined = false;
        return parent::disconnect();
    }


    function setAccess($username, $password)
    {
        $this->username = $username;
        $this->password = $password;
        return $this;
    }

    function setHost($host, $port = 23)
    {
        $this->host = $host;
        $this->port = $port;
        return $this;
    }

    /**
     * @return \Meklis\Network\Console\Helpers\DefaultHelper|HelperInterface
     */
    function getDeviceHelper()
    {
        return $this->helper;
    }

    function setTimeout($timeout)
    {
        $this->timeout = $timeout;
    }

    function setStreamTimeout($timeout)
    {
        $this->stream_timeout_sec = $timeout;
    }

    function getTimeout()
    {
        return $this->timeout;
    }

    function getStreamTimeout()
    {
        return $this->stream_timeout_sec;
    }

    function getStream()
    {
        return $this->socket;
    }
}
