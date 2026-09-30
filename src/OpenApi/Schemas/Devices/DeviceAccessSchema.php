<?php

namespace WCAA\OpenApi\Schemas\Devices;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="DeviceAccess",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=7),
 *   @OA\Property(property="name", type="string", example="Default SNMP"),
 *   @OA\Property(property="public_community", type="string", nullable=true, example="public"),
 *   @OA\Property(property="private_community", type="string", nullable=true, example="HIDDEN"),
 *   @OA\Property(property="login", type="string", nullable=true, example="HIDDEN"),
 *   @OA\Property(property="password", type="string", nullable=true, example="HIDDEN"),
 *   @OA\Property(property="params", type="object", nullable=true, additionalProperties=true)
 * )
 */
final class DeviceAccessSchema {}
