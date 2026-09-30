<?php

namespace WCAA\OpenApi\Schemas\Devices;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="DeviceInterface",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=5001),
 *   @OA\Property(property="bind_key", type="string", example="1/0/1"),
 *   @OA\Property(property="name", type="string", example="Gi1/0/1"),
 *   @OA\Property(property="type", type="string", example="ETH"),
 *   @OA\Property(property="status", type="string", nullable=true, example="up"),
 *   @OA\Property(property="status_changed", type="string", nullable=true, example="2026-02-25 11:40:00"),
 *   @OA\Property(property="poll_enabled", type="boolean", example=true),
 *   @OA\Property(property="parent_bind_key", type="string", nullable=true, example=null),
 *   @OA\Property(property="billing_link", type="string", nullable=true),
 *   @OA\Property(property="ip", type="string", nullable=true),
 *   @OA\Property(property="agreement", type="string", nullable=true),
 *   @OA\Property(property="description", type="string", nullable=true),
 *   @OA\Property(property="comment", type="string", nullable=true),
 *   @OA\Property(property="coordinates", type="string", nullable=true),
 *   @OA\Property(property="params", type="object", nullable=true, additionalProperties=true),
 *   @OA\Property(property="created_at", type="string", example="2026-02-25 10:30:00"),
 *   @OA\Property(property="updated_at", type="string", example="2026-02-25 11:50:00"),
 *   @OA\Property(property="device", ref="#/components/schemas/DeviceShort")
 * )
 */
final class DeviceInterfaceSchema {}
