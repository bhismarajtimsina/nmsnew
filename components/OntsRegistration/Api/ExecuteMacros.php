<?php

namespace WCC\OntsRegistration\Api;

use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\OntsRegistration\Controllers\MacrosGateway;
use WCC\OntsRegistration\Models\UnregisteredOntMacro;
use WCC\OntsRegistration\Storage\UnregisteredOntMacroStorage;
/**
 * @OA\Post(
 *   path="/component/onts_registration/execute",
 *   tags={"onts-registration"},
 *   security={{"XAuthKey": {}}},
 *   summary="Execute ONT registration macro",
 *
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"device", "ont"},
 *
 *       @OA\Property(
 *         property="device",
 *         type="object",
 *         required={"id"},
 *         @OA\Property(
 *           property="id",
 *           type="integer",
 *           example=123,
 *           description="Device ID"
 *         )
 *       ),
 *
 *       @OA\Property(
 *         property="ont",
 *         type="object",
 *         additionalProperties=true,
 *         description="Unregistered ONT data"
 *       ),
 *
 *       @OA\Property(
 *         property="preview",
 *         type="boolean",
 *         example=false,
 *         description="If true, return rendered template only"
 *       ),
 *
 *       @OA\Property(
 *         property="params",
 *         type="object",
 *         additionalProperties=true,
 *         description="Additional parameters for macro execution"
 *       ),
 *
 *       @OA\Property(
 *         property="from",
 *         type="string",
 *         example="cache",
 *         description="Source of device data"
 *       )
 *     )
 *   ),
 *
 *   @OA\Response(
 *     response=200,
 *     description="Execution result",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="data", type="object", additionalProperties=true),
 *       @OA\Property(property="meta", type="object", additionalProperties=true)
 *     )
 *   ),
 *
 *   @OA\Response(
 *     response=400,
 *     description="Bad request",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=400),
 *       @OA\Property(
 *         property="error",
 *         type="object",
 *         @OA\Property(property="description", type="string", example="ont is required")
 *       )
 *     )
 *   ),
 *
 *   @OA\Response(
 *     response=403,
 *     description="Forbidden",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=403),
 *       @OA\Property(
 *         property="error",
 *         type="object",
 *         @OA\Property(property="description", type="string", example="Register ont not allowed")
 *       )
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
     * @var UnregisteredOntMacroStorage
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
     * Drop this device's cached ONT reads after a registration actually ran
     * on the device.
     *
     * Registering an ONT changes it from "unregistered" to a real ONT, but
     * both the Unregistered-ONTs list and the ONTs tree read from the
     * switcher-core module cache (SWC:{device}:{module}:...), which the
     * registration itself never touches. The result is the reported
     * symptom: the ONT still shows as unregistered and doesn't appear in
     * the ONTs list until that cache expires or a poller cycle runs.
     *
     * The WebSocket path was already correct — this action records a system
     * action, which pushes event:sys_action:added, and both tabs re-read on
     * it straight away. They were just re-reading stale data. Clearing the
     * cache first makes that existing instant refresh show the real
     * post-registration state.
     *
     * Same invalidation the native dereg/clear-pon endpoints do — see
     * OltsControl\Api\DeregOnuAction.
     */
    private function invalidateOntCaches(\WCAA\Models\Devices\Device $device): void
    {
        try {
            $this->cache->deleteByRegex(
                '/^SWC:' . preg_quote((string)$device->getId(), '/') . ':(pon_onts_|unregistered_onts)/'
            );
        } catch (\Throwable $e) {
            // Best-effort — never fail a registration that already
            // succeeded on the device because of a cache problem.
        }
    }

    /**
     * @return Response
     */
    protected function action(): Response
    {
        $this->gateway->setUser($this->user);
        $data = $this->getFormData();

        if (!isset($data['device']['id'])) {
            throw new \Exception("Choose device is required");
        }
        if (!isset($data['ont'])) {
            throw new HttpBadRequestException($this->request, "ont is required");
        }

        $preview = false;
        if (isset($data['preview']) && $data['preview']) {
            $preview = true;
        }
        if($preview && !$this->user->isRulePermitted('unregistered_onts_preview')) {
            throw new HttpForbiddenException($this->request, "Preview not allowed");
        } elseif (!$preview && !$this->user->isRulePermitted('unregistered_onts')) {
            throw new HttpForbiddenException($this->request, "Register ont not allowed");
        }

        $device = $this->deviceStorage->getById($data['device']['id']);
        $macros = $this->macrosStorage->getByDeviceModel($device->getModel());

        $parameters = [];
        $from = 'cache';

        if (isset($data['params'])) {
            $parameters = $data['params'];
            foreach ($parameters as $name => $value) {
                if (is_string($value) || is_numeric($value)) {
                    $parameters[$name] = str_replace(["\n", "\r", "\t", "|", "{", "}", "#"], "", $value);
                }
            }
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

        $variables = $this->gateway->generateVariables($device, $data['ont'], $parameters, $from);
        $template = $this->gateway->buildTemplate($macros->getTemplate(), $variables);

        try {
            if ($preview) {
                $this->addActionSuccess("unregistered_onts:preview", "User {$this->user->getName()} preview macros with name {$macros->getName()} on device {$device->getName()} ({$device->getIp()})",
                    [
                        'device' => $device->getAsArray(),
                        'user' => $this->user->getAsArray(),
                        'ont_ident' => $data['ont'],
                        'params' => $parameters,
                        'variables' => $variables,
                        'template' => $template,
                    ]
                );
                return $this->respondWithData($template, [
                    "action_type" => 'preview'
                ]);
            } else {
                $response = $this->gateway->execute($device, $macros, $variables, $executionId);
                $response['ont_ident'] = $data['ont'];
                $this->invalidateOntCaches($device);
                $this->addActionSuccess("unregistered_onts:execute", "User {$this->user->getName()} execute macros with name {$macros->getName()} on device {$device->getName()} ({$device->getIp()})",
                    [
                        'device' => $device->getAsArray(),
                        'ont_ident' => $data['ont'],
                        'user' => $this->user->getAsArray(),
                        'params' => $parameters,
                        'variables' => $variables,
                        'template' => $template,
                        'response' => $response,
                    ]
                );
                return $this->respondWithData($response, ['action_type' => 'execute']);
            }
        } catch (\Throwable $e) {
            // Invalidate on failure too: a macro that dies partway has
            // still changed the device up to that point. Confirmed live —
            // a registration whose service-port lines failed had already
            // created the ONT, so leaving the cache intact meant the UI
            // kept showing it as unregistered even though it now exists.
            $this->invalidateOntCaches($device);
            $this->addActionFailed("unregistered_onts:execute", "User {$this->user->getName()} execute macros with name {$macros->getName()} on device {$device->getName()} ({$device->getIp()})",
                $e,
                [
                    'device' => $device->getAsArray(),
                    'ont' => $data['ont'],
                    'user' => $this->user->getAsArray(),
                    'params' => $parameters,
                    'variables' => $variables,
                    'template' => $template,
                ]
            );
            throw $e;
        }
    }

}
