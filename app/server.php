<?php
require '/www/app/init.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker;
use WCAA\Api\Actions\ActionError;
use WCAA\Api\Actions\ActionPayload;

$slim = $app->buildSlimApp();
$factory = new Psr17Factory();
$worker = Worker::create();
$psr7 = new PSR7Worker($worker, $factory, $factory, $factory);

while ($request = $psr7->waitRequest()) {
    try {
        $psr7->respond($slim->handle($request));
    } catch (\Throwable $e) {
        error_log((string) $e);
        // This is the true last-resort catch-all for this app — anything
        // that gets past Slim's own error middleware (HttpErrorHandler,
        // wired separately in App::buildSlimApp()) lands here. This app
        // runs as a long-lived RoadRunner worker loop, not traditional
        // PHP-FPM, so a classic register_shutdown_function() handler
        // (there's a ShutdownHandler class in src/Api/Handlers/ built for
        // exactly that PHP-FPM model, confirmed unused/unregistered
        // anywhere) wouldn't even fire correctly here — a worker's
        // shutdown function only runs when the whole long-lived process
        // exits, not after each request. This is the real, correctly-
        // adapted equivalent. Kept deliberately simple (no DI container
        // calls) since this is the last line of defense — it must not be
        // able to fail itself. Same ActionError/ActionPayload JSON shape
        // every other error response already uses, instead of the bare
        // "Internal Server Error" text (no JSON, no description) that
        // used to leave the frontend with nothing to show.
        $error = new ActionError(ActionError::SERVER_ERROR, 'An internal error has occurred while processing your request.', null);
        $payload = new ActionPayload(500, null, null, $error);
        $body = json_encode($payload, JSON_PRETTY_PRINT) ?: '{"statusCode":500,"meta":null,"error":{"type":"SERVER_ERROR","description":"An internal error has occurred while processing your request."}}';
        $psr7->respond(new Response(500, ['Content-Type' => 'application/json'], $body));
    } finally {
        // Release any device console (telnet/SSH) session this request
        // opened, instead of letting this worker hold it for its entire
        // lifetime.
        //
        // Confirmed live, and the root cause of a long-running "Delete ONT
        // gets stuck on Connecting to device…" bug: CoreConnector caches a
        // Core — and its lazily-connected Console — per device IP in
        // $instances, and NOTHING in this app ever called its own
        // closeAllCoreInstances(). So each worker that ran any console
        // operation kept a telnet session to that OLT open indefinitely.
        // With RR_NUM_WORKERS=50 that's up to 50 concurrent sessions to a
        // single device; Huawei VRP defaults to `user-interface vty 0 4`,
        // i.e. FIVE. Observed exactly 5 stuck-open sessions to one OLT with
        // its VTY pool exhausted — new logins then get a TCP connection but
        // never a usable session, and hang in the telnet dialogue before
        // running a single command (which is also why no macro progress was
        // ever published: it never got that far).
        //
        // Only the console is dropped, NOT the whole Core — the cached Core
        // also holds SNMP model detection state that is expensive to
        // rebuild, and that part was never the problem. TelnetLazyConnect /
        // SshLazyConnect reset their isLogined flag on disconnect(), so the
        // next request transparently reconnects; disconnect() also runs the
        // helper's before-logout commands ("quit"), so the device frees the
        // VTY properly rather than waiting for an idle timeout.
        //
        // Best-effort and non-fatal by design: this runs after the response
        // is already sent, so a cleanup failure must never surface to the
        // user or kill the worker.
        try {
            $connector = $app->getContainer()->get(\SwitcherCore\Switcher\CoreConnector::class);
            foreach ($connector->getAllCoreInstances() as $core) {
                try {
                    $container = $core->getContainer();
                    if ($container && $container->has(\SwitcherCore\Switcher\Console\ConsoleInterface::class)) {
                        $container->get(\SwitcherCore\Switcher\Console\ConsoleInterface::class)->disconnect();
                    }
                } catch (\Throwable $inner) {
                    // one bad console must not stop the others being freed
                }
            }
        } catch (\Throwable $e) {
            error_log('console cleanup failed: ' . $e->getMessage());
        }
    }
}
