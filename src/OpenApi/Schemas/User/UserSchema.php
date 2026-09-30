<?php

namespace WCAA\OpenApi\Schemas\User;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="User",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=1),
 *   @OA\Property(property="name", type="string", example="Administrator"),
 *   @OA\Property(property="login", type="string", example="admin"),
 *   @OA\Property(property="status", type="string", example="ENABLED"),
 *   @OA\Property(property="language", type="string", example="en"),
 *   @OA\Property(property="is_twofa", type="boolean", example=true),
 *   @OA\Property(property="created_at", type="string", example="2026-02-25 10:30:00"),
 *   @OA\Property(property="updated_at", type="string", example="2026-02-25 11:00:00"),
 *   @OA\Property(property="role", ref="#/components/schemas/UserRole"),
 *   @OA\Property(property="device_groups", type="array", @OA\Items(ref="#/components/schemas/DeviceGroup")),
 *   @OA\Property(property="settings", type="object", additionalProperties=true),
 *   @OA\Property(property="last_activity", type="string", nullable=true, example="2026-02-25 11:35:00"),
 *   @OA\Property(property="active_sessions", type="array", @OA\Items(ref="#/components/schemas/UserSession"))
 * )
 */
final class UserSchema {}
