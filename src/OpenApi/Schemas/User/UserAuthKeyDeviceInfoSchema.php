<?php

namespace WCAA\OpenApi\Schemas\User;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="UserAuthKeyDeviceInfo",
 *   type="object",
 *   @OA\Property(property="bot", type="object", nullable=true, additionalProperties=true),
 *   @OA\Property(property="client", type="object", nullable=true, additionalProperties=true),
 *   @OA\Property(property="os_info", type="object", nullable=true, additionalProperties=true),
 *   @OA\Property(property="device", type="string", nullable=true, example="desktop"),
 *   @OA\Property(property="brand", type="string", nullable=true, example="Apple"),
 *   @OA\Property(property="model", type="string", nullable=true, example="Mac")
 * )
 */
final class UserAuthKeyDeviceInfoSchema {}
