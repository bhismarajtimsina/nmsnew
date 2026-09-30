<?php

namespace WCAA\OpenApi\Schemas\User;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="UserRole",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=2),
 *   @OA\Property(property="name", type="string", example="Operator"),
 *   @OA\Property(property="display", type="boolean", example=true),
 *   @OA\Property(property="description", type="string", nullable=true, example="NOC operator role"),
 *   @OA\Property(property="permissions", type="array", @OA\Items(type="string")),
 *   @OA\Property(property="params", type="object", nullable=true, additionalProperties=true)
 * )
 */
final class UserRoleSchema {}
