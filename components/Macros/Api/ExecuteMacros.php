<?php

namespace WCC\Macros\Api;

use OpenApi\Annotations as OA;
use DI\Annotation\Inject;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Macros\Controllers\MacrosGateway;
use WCC\Macros\Models\Macros;
use WCC\Macros\Storage\MacrosStorage;
/**
 * @return Response
 *
 * @OA\Post(
 *   path="/component/macros/execute",
 *   description="Execute macros on device",
 *   tags={"macros"},
 *   security={{"XAuthKey":{}}},
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="device", type="object", @OA\Property(property="id", type="integer", description="Device ID from database")),
 *       @OA\Property(property="macros", type="object", @OA\Property(property="id", type="integer")),
 *       @OA\Property(property="interface", type="object", @OA\Property(property="id", type="integer"), @OA\Property(property="bind_key", type="string")),
 *       @OA\Property(property="params", type="object", nullable=true, additionalProperties={}),
 *       @OA\Property(property="from", type="string", enum={"device","cache","store"}, description="Data source: device — read from device; cache — read from cache, if stale then query device; store — read from cache, if missing then error."),
 *       @OA\Property(property="preview", type="boolean", description="If true, return template preview instead of execution")
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="OK",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="data", additionalProperties={}),
 *       @OA\Property(property="meta", type="object", nullable=true, additionalProperties={})
 *     )
 *   )
 * )
 */

class ExecuteMacros extends PrivateAction
{

    protected $forbiddenInDemo = true;

    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaceStorage;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var MacrosStorage
     */
    protected $macrosStorage;

    /**
     * @Inject
     * @var MacrosGateway
     */
    protected $gateway;

    /**
     * @Inject
     * @var \WCAA\Interfaces\CacheInterface
     */
    protected $cache;

    /**
     * Writes an early-stage checkpoint to the same cache key the progress
     * poller reads (see GetExecutionProgress). The per-command progress
     * inside multiRawConsoleCommandRun() only starts once execution is
     * genuinely underway — but confirmed live that a delete can stall
     * BEFORE that point entirely (generateVariables() below can query the
     * device when its cache is stale), which published nothing at all and
     * left the UI frozen on a static "Connecting to device…" with no way
     * to tell which phase was actually stuck. These checkpoints make each
     * phase visible. Best-effort only: any cache failure is swallowed, so
     * this can never break an execution that would otherwise succeed.
     */
    /**
     * Drop this device's cached ONT reads after a macro actually ran
     * commands on it.
     *
     * The ONTs tree and the Unregistered-ONTs list both read from the
     * switcher-core module cache (SWC:{device}:{module}:...). Running a
     * macro changes the device but leaves that cache untouched, so the UI
     * kept serving pre-change data until it expired or a poller cycle
     * happened — which is exactly the "I registered/deleted it but the list
     * still shows the old state" delay.
     *
     * The real-time path itself was already fine: this action records a
     * system action, which pushes event:sys_action:added over the
     * WebSocket, and both tabs already react to it by re-reading
     * immediately. They were just re-reading a stale cache. Clearing it
     * here (before the action is recorded, so the push can't beat the
     * invalidation) makes that existing instant refresh return fresh data.
     *
     * Mirrors what the native dereg/clear-pon endpoints have always done —
     * see OltsControl\Api\DeregOnuAction — which the macro-based delete
     * replaced without carrying this part across.
     */
    private function invalidateOntCaches(\WCAA\Models\Devices\Device $device): void
    {
        try {
            $this->cache->deleteByRegex(
                '/^SWC:' . preg_quote((string)$device->getId(), '/') . ':(pon_onts_|unregistered_onts)/'
            );
        } catch (\Throwable $e) {
            // Best-effort: a cache miss is self-healing, and failing to
            // clear it must never fail an execution that already succeeded
            // on the device.
        }
    }

    private function checkpoint(?string $executionId, string $stage, string $detail): void
    {
        if (!$executionId) {
            return;
        }
        try {
            $this->cache->set("macro_progress:{$executionId}", [
                'commands' => [['command' => $stage, 'output' => $detail, 'success' => true]],
                'done' => 0,
                'total' => 0,
            ], 300);
        } catch (\Throwable $e) {
            // best-effort only
        }
    }

        protected function action(): Response
    {
        $this->gateway->setUser($this->user);
        $data = $this->getFormData();

        if (!isset($data['device']['id'])) {
            throw new \Exception("Choose device is required");
        }
        if (!isset($data['macros'])) {
            throw new HttpBadRequestException($this->request, "macros is required");
        }

        $macros = $this->macrosStorage->fill(new Macros($data['macros']['id']));
        if (!array_filter($macros->getAllowedRoles(), function ($role) {
            return $role->getId() == $this->user->getRole()->getId();
        })) {
            throw new HttpForbiddenException($this->request, "Insufficient permissions to execute. Please, contact your administrator");
        }

        $iface = null;
        $parameters = [];
        $from = 'cache';
        $preview = false;

        $device = $this->deviceStorage->getById($data['device']['id']);
        if (isset($data['preview']) && $data['preview']) {
            $preview = true;
        }
        if (isset($data['interface']['id'])) {
            $iface = $this->deviceInterfaceStorage->getById($data['interface']['id']);
        } elseif (isset($data['interface']['bind_key'])) {
            $iface = $this->deviceInterfaceStorage->getByDeviceAndKey($device, $data['interface']['bind_key']);
        }
        if (isset($data['params'])) {
            $parameters = $data['params'];
        }
        if (isset($data['from'])) {
            $from = $data['from'];
        }
        // Caller-generated (a UUID from the frontend, typically) — lets it
        // start polling the progress endpoint before this call even
        // returns. Optional; execution behaves identically either way.
        // Lightly sanitized since it flows straight into a cache key.
        $executionId = null;
        if (isset($data['execution_id']) && is_string($data['execution_id']) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $data['execution_id'])) {
            $executionId = $data['execution_id'];
        }
        foreach ($parameters as $name => $value) {
            if (is_string($value) || is_numeric($value)) {
                $parameters[$name] = str_replace(["\n", "\r", "\t", "|", "{", "}", "#"], "", $value);
            }
        }

        // Checkpoint ladder — whichever stage is the last one visible in the
        // progress poller is exactly where an execution is stuck.
        $this->checkpoint($executionId, '(preparing)', "Reading device data (from={$from})…");
        // Template passed so only the data it actually references gets
        // gathered — see MacrosGateway::generateVariables(). A preview
        // renders the same template, so it benefits identically.
        $variables = $this->gateway->generateVariables($device, $iface, $parameters, $from, $macros->getTemplate());
        $this->checkpoint($executionId, '(building)', 'Device data ready — building command list…');
        $template = $this->gateway->buildTemplate($macros->getTemplate(), $variables);
        $this->checkpoint($executionId, '(starting)', 'Commands ready — connecting to device…');

        try {
            if ($preview) {
                $this->addActionSuccess("macros:preview", "User {$this->user->getName()} preview macros with name {$macros->getName()} on device {$device->getName()} ({$device->getIp()})",
                    [
                        'device' => $device->getAsArray(),
                        'iface' => $iface ? $iface->getAsArray() : null,
                        'user' => $this->user->getAsArray(),
                        'params' => $parameters,
                        'variables' => $variables,
                        'commands' => $template,
                    ]
                );
                return $this->respondWithData($template, [
                    "action_type" => 'preview'
                ]);
            } else {
                $response = $this->gateway->execute($device, $macros, $variables, $executionId);
                $this->invalidateOntCaches($device);
                $this->addActionSuccess("macros:execute", "User {$this->user->getName()} execute macros with name {$macros->getName()} on device {$device->getName()} ({$device->getIp()})",
                    [
                        'device' => $device->getAsArray(),
                        'iface' => $iface ? $iface->getAsArray() : null,
                        'user' => $this->user->getAsArray(),
                        'params' => $parameters,
                        'variables' => $variables,
                        'template' => $template,
                        'commands' => $response,
                    ]
                );
                return $this->respondWithData($response, ['action_type' => 'execute']);
            }
        } catch (\Throwable $e) {
            // See the success path: a macro that fails partway has still
            // changed the device up to the failing command, so the cached
            // ONT reads are stale either way.
            $this->invalidateOntCaches($device);
            $this->addActionFailed("macros:execute", "User {$this->user->getName()} execute macros with name {$macros->getName()} on device {$device->getName()} ({$device->getIp()})",
                $e,
                [
                    'device' => $device->getAsArray(),
                    'iface' => $iface ? $iface->getAsArray() : null,
                    'user' => $this->user->getAsArray(),
                    'params' => $parameters,
                    'variables' => $variables,
                    'commands' => $template,
                ]
            );
            throw $e;
        }
    }

}
