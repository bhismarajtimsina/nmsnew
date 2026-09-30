<?php

namespace WCAA\OpenApi\Schemas\User;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="PermissionRule",
 *   type="object",
 *   @OA\Property(property="key", type="string", example="user_management"),
 *   @OA\Property(property="description", type="string", nullable=true, example="Manage users"),
 *   @OA\Property(property="logic_group", type="string", nullable=true, example="users")
 * )
 */
final class PermissionRuleSchema {}
