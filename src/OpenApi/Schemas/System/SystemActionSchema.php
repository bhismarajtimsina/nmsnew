<?php

namespace WCAA\OpenApi\Schemas\System;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="SystemAction",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=101),
 *   @OA\Property(property="created_at", type="string", example="2026-02-25 12:00:00"),
 *   @OA\Property(property="action", type="string", example="user:logged_in"),
 *   @OA\Property(property="status", type="string", example="SUCCESS"),
 *   @OA\Property(property="message", type="string", example="User logged in"),
 *   @OA\Property(property="meta", type="object", nullable=true, additionalProperties=true),
 *   @OA\Property(property="user", ref="#/components/schemas/UserShort"),
 *   @OA\Property(property="device", ref="#/components/schemas/DeviceShort")
 * )
 */
final class SystemActionSchema {}
