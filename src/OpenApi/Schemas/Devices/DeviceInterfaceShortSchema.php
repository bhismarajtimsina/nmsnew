<?php

namespace WCAA\OpenApi\Schemas\Devices;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="DeviceInterfaceShort",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=5001),
 *   @OA\Property(property="bind_key", type="string", example="1/0/1"),
 *   @OA\Property(property="name", type="string", example="Gi1/0/1"),
 *   @OA\Property(property="type", type="string", example="ETH")
 * )
 */
final class DeviceInterfaceShortSchema {}
