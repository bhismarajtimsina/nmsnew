<?php

namespace WCAA\OpenApi\Schemas\Devices;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="DeviceModel",
 *   type="object",
 *   description="Device model structure based on WCAA\\Models\\Devices\\DeviceModel",
 *   @OA\Property(property="id", type="integer", example=12),
 *   @OA\Property(property="name", type="string", example="Cisco C9300"),
 *   @OA\Property(property="key", type="string", example="cisco_c9300"),
 *   @OA\Property(
 *     property="params",
 *     type="object",
 *     nullable=true,
 *     additionalProperties=true,
 *     description="Model parameters"
 *   ),
 *   @OA\Property(property="vendor", type="string", example="Cisco"),
 *   @OA\Property(property="model", type="string", example="C9300"),
 *   @OA\Property(property="type", type="string", example="switch"),
 *   @OA\Property(property="controller", type="string", nullable=true, example="iosxe"),
 *   @OA\Property(
 *     property="pollers",
 *     type="array",
 *     nullable=true,
 *     @OA\Items(type="string")
 *   ),
 *   @OA\Property(
 *     property="icon",
 *     type="string",
 *     nullable=true,
 *     description="Base64-encoded icon"
 *   )
 * )
 */
final class DeviceModelSchema {}
