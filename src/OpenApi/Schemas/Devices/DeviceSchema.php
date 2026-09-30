<?php

namespace WCAA\OpenApi\Schemas\Devices;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="Device",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=101),
 *   @OA\Property(property="ip", type="string", example="192.168.1.10"),
 *   @OA\Property(property="name", type="string", example="Core-Switch-01"),
 *   @OA\Property(property="description", type="string", nullable=true, example="Main switch"),
 *   @OA\Property(property="location", type="string", nullable=true, example="Rack A-12"),
 *   @OA\Property(property="coordinates", type="string", nullable=true, example="50.4501,30.5234"),
 *   @OA\Property(property="mac", type="string", nullable=true, example="AA:BB:CC:DD:EE:FF"),
 *   @OA\Property(property="serial", type="string", nullable=true, example="SN123456"),
 *   @OA\Property(property="enabled", type="boolean", example=true),
 *   @OA\Property(property="params", type="object", nullable=true, additionalProperties=true),
 *   @OA\Property(property="pollers", type="array", nullable=true, @OA\Items(type="string")),
 *   @OA\Property(property="created_at", type="string", example="2026-02-25 10:30:00"),
 *   @OA\Property(property="updated_at", type="string", example="2026-02-25 12:00:00"),
 *   @OA\Property(property="model", ref="#/components/schemas/DeviceModel"),
 *   @OA\Property(property="access", ref="#/components/schemas/DeviceAccess"),
 *   @OA\Property(property="group", ref="#/components/schemas/DeviceGroup")
 * )
 */
final class DeviceSchema {}
