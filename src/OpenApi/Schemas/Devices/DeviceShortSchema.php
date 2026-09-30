<?php

namespace WCAA\OpenApi\Schemas\Devices;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="DeviceShort",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=101),
 *   @OA\Property(property="ip", type="string", example="192.168.1.10"),
 *   @OA\Property(property="name", type="string", example="Core-Switch-01")
 * )
 */
final class DeviceShortSchema {}
