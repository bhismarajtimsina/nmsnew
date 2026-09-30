<?php

namespace Meklis\Network\Console;

use Meklis\Network\Console\Helpers\HelperInterface;

/**
 * Telnet class
 *
 * Used to execute remote commands via telnet connection
 * Usess sockets functions and fgetc() to process result
 *
 * All methods throw Exceptions on error
 */
class Telnet extends AbstractConsole implements ConsoleInterface
{
    protected $socket = null;

    public function connect($host, $port = 23, ?HelperInterface $helper = null)
    {
        if ($helper) {
            $this->helper = $helper;
        }
        $this->host = $host;
        $this->port = $port;
        // check if we need to convert host to IP
        if (!preg_match('/([0-9]{1,3}\\.){3,3}[0-9]{1,3}/', $this->host)) {
            $ip = gethostbyname($this->host);

            if ($this->host == $ip) {
                throw new \Exception("Cannot resolve $this->host");
            } else {
                $this->host = $ip;
            }
        }
        if ($this->helper->getEol()) {
            $this->eol = $this->helper->getEol();
        }
        if ($this->helper->getPrompt()) {
            $this->prompt = $this->helper->getPrompt();
        }
        $this->enableMagicControl = $this->helper->isEnableMagicControl();
        // attempt connection - suppress warnings
        $this->socket = @fsockopen($this->host, $this->port, $this->errno, $this->errstr, $this->timeout);
        if (!$this->socket) {
            throw new \Exception("Cannot connect to $this->host on port $this->port");
        }
        if ($sizes = $this->helper->getWindowSize()) {
            $this->setWindowSize($sizes[0], $sizes[1]);
        }

        return $this;
    }

    /**
     * Closes IP socket
     *
     * @return $this
     * @throws \Exception
     */
    public function disconnect()
    {
        if ($this->socket) {
            $this->runBeforeLogountCommands();
            if (!fclose($this->socket)) {
                throw new \Exception("Error while closing telnet socket");
            }
            $this->socket = null;
        }
        return $this;
    }

    /**
     * @param $wide
     * @param $high
     * @return $this
     * @throws \Exception
     */
    public function setWindowSize($wide = 80, $high = 40)
    {
        fwrite($this->socket, $this->IAC . $this->WILL . $this->NAWS);
        $c = $this->getc();
        if ($c != $this->IAC) {
            throw new \Exception('Error: unknown control character ' . ord($c));
        }
        $c = $this->getc();
        if ($c == $this->DONT || $c == $this->WONT) {
            throw new \Exception("Error: server refuses to use NAWS");
        } elseif ($c != $this->DO && $c != $this->WILL) {
            throw  new \Exception('Error: unknown control character ' . ord($c));
        }
        // PATCHED: the window size has to go out as two 16-bit BIG-ENDIAN
        // values (RFC 1073: IAC SB NAWS <w-hi> <w-lo> <h-hi> <h-lo> IAC SE).
        // This used to concatenate the PHP integers directly — ". 0 . $wide
        // . 0 . $high ." — which builds the ASCII TEXT "0512050" instead of
        // the four bytes 0x02 0x00 0x00 0x32. The device could not parse
        // that subnegotiation, silently ignored it, and stayed at its
        // default 80 columns.
        //
        // Confirmed live why that matters: at 80 columns the OLT wraps any
        // long command and injects a cursor-reposition escape into its echo
        // mid-word — captured in a real registration transcript as
        // 'ont add 0 1 sn-auth "..." om \x1b[1D ci ...', wrapping at exactly
        // column 80 counting the 39-char prompt. That escape breaks the
        // end-of-line prompt regex, so the longest lines in a macro (the
        // service-port ones) never matched a prompt and died on a stream
        // timeout, leaving an ONT added but with no service-ports.
        $escape = function ($byte) {
            // RFC 854: a 255 byte inside subnegotiation data must be doubled
            // so it isn't mistaken for another IAC.
            return $byte === 0xFF ? "\xFF\xFF" : chr($byte);
        };
        $payload = $escape(($wide >> 8) & 0xFF) . $escape($wide & 0xFF)
            . $escape(($high >> 8) & 0xFF) . $escape($high & 0xFF);
        fwrite($this->socket, $this->IAC . $this->SB . $this->NAWS . $payload . $this->IAC . $this->SE);
        return $this;
    }

    /**
     * Attempts login to remote host.
     * This method is a wrapper for lower level private methods and should be
     * modified to reflect telnet implementation details like login/password
     * and line prompts. Defaults to standard unix non-root prompts
     *
     * @param string $username Username
     * @param string $password Password
     * @param string $host_type Type of destination host
     * @return $this
     * @throws \Exception
     */
    public function login($username, $password)
    {
        try {
            // username
            if (!empty($username)) {
                $this->setRegexPrompt($this->helper->getUserPrompt());
                $this->waitPrompt();
                $this->write(trim($username));
            }

            // password
            $this->setRegexPrompt($this->helper->getPasswordPrompt());
            $this->waitPrompt();
            $this->write(trim($password));

            $this->setRegexPrompt($this->helper->getPrompt());
            $this->waitPrompt();
            if ($this->helper->isDoubleLoginPrompt()) {
                try {
                    $this->waitPrompt('', 1);
                } catch (\Exception $e) {
                }
            }
        } catch (\Exception $e) {
            throw new \Exception("Login failed. {$e->getMessage()}");
        }
        $this->runAfterLoginCommands($password);

        return $this;
    }

    /**
     * Gets character from the socket
     *
     * @return string $c character string
     */
    protected function getc($timeoutSec = null)
    {
        if(!$timeoutSec) {
            $timeoutSec = $this->stream_timeout_sec;
        }
        stream_set_timeout($this->socket, $timeoutSec);
        $c = fgetc($this->socket);
        try {
            $this->global_buffer->fwrite($c);
        } catch (\Throwable $e) {
        }
        return $c;
    }

    /**
     * Reads characters from the socket and adds them to command buffer.
     * Handles telnet control characters. Stops when prompt is ecountered.
     *
     */
    protected function readTo($prompt, $timeoutSec = null)
    {
        if (!$this->socket) {
            throw new \Exception("Telnet connection closed");
        }

        // clear the buffer
        $this->clearBuffer();
        $until_t = time() + $this->timeout;
        $eofDetected = 0;
        do {
            // time's up (loop can be exited at end or through continue!)
            if (time() > $until_t) {
                throw new \Exception("Couldn't find the requested : '$prompt' within {$this->timeout} seconds");
            }

            $c = $this->getc($timeoutSec);
            if ($c === false) {
                if (empty($prompt)) {
                    return $this;
                }
                $info = stream_get_meta_data($this->socket);
                if ($info['timed_out']) {
                    throw new \Exception("Stream timeout {$this->stream_timeout_sec}sec is reached :-(");
                }
                if ($this->helper->isIgnoreEOF()) {
                    usleep(1000);
                    $eofDetected++;
                    if ($eofDetected > 50000) {
                        throw new \Exception("Host {$this->host} send EOF within send all data");
                    }
                    continue;
                } else {
                    throw new \Exception("Couldn't find the requested : '" . $prompt . "', it was not in the data returned from server: " . $this->buffer);
                }
            }

            // Interpreted As Command
            if ($c == $this->IAC) {
                if ($this->negotiateTelnetOptions()) {
                    continue;
                }
            }

            // append current char to global buffer
            $this->buffer .= $c;
            $latestBytes = $this->removeNotASCIISymbols(substr($this->buffer, -70));
            if ($this->helper->getPaginationDetect()) {
                if (preg_match($this->helper->getPaginationDetect(), $latestBytes)) {
                    $this->buffer = preg_replace($this->helper->getPaginationDetect(), "\n", trim($this->buffer));
                    if (!fwrite($this->socket, $this->eol) < 0) {
                        throw new \Exception("Error writing to socket");
                    }
                    continue;
                }
            }

            // we've encountered the prompt. Break out of the loop
            if (!empty($prompt) && preg_match("/{$prompt}/m", trim($latestBytes))) {
                return $this;
            }

        } while ($c != $this->NULL || $c != $this->DC1);
    }

    /**
     * @param $buffer
     * @param $add_newline
     * @return $this
     * @throws \Exception
     */
    public function write($buffer, $add_newline = true)
    {
        if ($this->socket === null) {
            throw new \Exception("Telnet connection closed! Check you call method connect() before any calling");
        }

        // PATCHED: pacing delay before writing, for device types that need
        // breathing room between reading a prompt and receiving the next
        // command (see HuaweiMA::$preWriteDelayUs for the confirmed-live
        // case). 0 for every helper that doesn't set one, so this is a
        // no-op for every device type not specifically opted in.
        if ($this->helper && ($delay = $this->helper->getPreWriteDelayUs())) {
            usleep($delay);
        }

        // clear buffer from last command
        $this->clearBuffer();

        if ($add_newline == true) {
            $buffer .= $this->eol;
        }

        try {
            $this->global_buffer->fwrite($buffer);
        } catch (\Throwable $e) {
        }

        // PATCHED: write the WHOLE buffer, and actually detect failure.
        //
        // This was `if (!fwrite($this->socket, $buffer) < 0)`, which has two
        // separate bugs. First, precedence: !fwrite(...) evaluates to a bool
        // and `bool < 0` is always false, so the error branch could never
        // fire — every write failure was silently swallowed. Second, and the
        // damaging one, fwrite() is not guaranteed to write everything it is
        // given; a short write silently discarded the remainder.
        //
        // When the discarded remainder is the trailing EOL, the device
        // receives the command text but never the Enter that runs it, so it
        // sits holding the line open. Confirmed live: "display ont info
        // 0 0 1" echoed in full and then produced nothing until the read
        // timed out, and the NEXT command was appended onto that same
        // unterminated input line — the device saw "display ont info 0 0 1
        // displayservice-portport0/0/0" as one command and answered
        // "% Parameter error". That is the same failure that left a
        // registration stranded after "quit" with its service-port lines
        // never applied.
        $total = strlen($buffer);
        $written = 0;
        $stalled = 0;
        while ($written < $total) {
            $n = @fwrite($this->socket, substr($buffer, $written));
            if ($n === false) {
                throw new \Exception("Error writing to socket");
            }
            if ($n === 0) {
                // Nothing accepted this round — allow a few retries for a
                // momentarily full send buffer, but never spin forever.
                if (++$stalled > 100) {
                    throw new \Exception("Error writing to socket: only {$written} of {$total} bytes written");
                }
                usleep(10000);
                continue;
            }
            $stalled = 0;
            $written += $n;
        }

        return $this;
    }

    /**
     * Telnet control character magic
     *
     * @return bool
     * @throws \Exception
     * @internal param string $command Character to check
     */
    protected function negotiateTelnetOptions()
    {
        if (!$this->enableMagicControl) return true;

        $c = $this->getc();
        if ($c != $this->IAC) {
            if (($c == $this->DO) || ($c == $this->DONT)) {
                $opt = $this->getc();
                fwrite($this->socket, $this->IAC . $this->WONT . $opt);
            } else if (($c == $this->WILL) || ($c == $this->WONT)) {
                $opt = $this->getc();
                fwrite($this->socket, $this->IAC . $this->DONT . $opt);
            } else {
                throw new \Exception('ErrorNegotiate: unknown control character ' . ord($c));
            }
        } else {
            throw new \Exception('ErrorNegotiate: Something Wicked Happened');
        }

        return true;
    }

}