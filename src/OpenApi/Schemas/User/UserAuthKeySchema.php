<?php

namespace WCAA\OpenApi\Schemas\User;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="UserAuthKey",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=11),
 *   @OA\Property(property="user", ref="#/components/schemas/UserShort"),
 *   @OA\Property(property="created_at", type="string", example="2026-02-25 10:30:00"),
 *   @OA\Property(property="expired_at", type="string", example="2026-02-26 10:30:00"),
 *   @OA\Property(property="key", type="string", example="0b53d498-b8fe-4be2-a24f-39ec9ef72e4a"),
 *   @OA\Property(property="user_agent", type="string", nullable=true),
 *   @OA\Property(property="remote_addr", type="string", nullable=true, example="10.0.0.5"),
 *   @OA\Property(property="status", type="string", example="ACTIVE"),
 *   @OA\Property(property="last_activity", type="string", nullable=true, example="2026-02-25 11:35:00"),
 *   @OA\Property(property="device", ref="#/components/schemas/UserAuthKeyDeviceInfo"),
 *   @OA\Property(property="is_manual", type="boolean", example=false),
 *   @OA\Property(property="description", type="string", nullable=true)
 * )
 */
final class UserAuthKeySchema {}
