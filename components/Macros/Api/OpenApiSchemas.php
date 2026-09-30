<?php

namespace WCC\Macros\Api;

use OpenApi\Annotations as OA;
/**
 * @OA\Schema(
 *   schema="MacrosModel",
 *   type="object",
 *   @OA\Property(property="id", type="integer"),
 *   @OA\Property(property="key", type="string"),
 *   @OA\Property(property="name", type="string"),
 *   @OA\Property(property="type", type="string"),
 *   @OA\Property(property="vendor", type="string", nullable=true),
 *   @OA\Property(property="params", type="object", nullable=true, additionalProperties={})
 * )
 *
 * @OA\Schema(
 *   schema="MacrosUserRole",
 *   type="object",
 *   @OA\Property(property="id", type="integer"),
 *   @OA\Property(property="name", type="string")
 * )
 *
 * @OA\Schema(
 *   schema="MacrosParameter",
 *   type="object",
 *   additionalProperties={}
 * )
 *
 * @OA\Schema(
 *   schema="Macros",
 *   type="object",
 *   @OA\Property(property="id", type="integer"),
 *   @OA\Property(property="name", type="string"),
 *   @OA\Property(property="description", type="string"),
 *   @OA\Property(property="display_for", type="array", @OA\Items(type="string", enum={"DEVICE","PORT","PON","ONU"})),
 *   @OA\Property(property="display_output", type="string", enum={"no","all","last"}),
 *   @OA\Property(property="models", type="array", @OA\Items(ref="#/components/schemas/MacrosModel")),
 *   @OA\Property(property="user_roles", type="array", @OA\Items(ref="#/components/schemas/MacrosUserRole")),
 *   @OA\Property(property="template", type="string"),
 *   @OA\Property(property="parameters", type="array", @OA\Items(ref="#/components/schemas/MacrosParameter"))
 * )
 *
 * @OA\Schema(
 *   schema="MacrosPublic",
 *   type="object",
 *   @OA\Property(property="id", type="integer"),
 *   @OA\Property(property="name", type="string"),
 *   @OA\Property(property="description", type="string"),
 *   @OA\Property(property="template", type="string"),
 *   @OA\Property(property="display_output", type="string", enum={"no","all","last"}),
 *   @OA\Property(property="parameters", type="array", @OA\Items(ref="#/components/schemas/MacrosParameter"))
 * )
 *
 * @OA\Schema(
 *   schema="MacrosListItem",
 *   type="object",
 *   @OA\Property(property="id", type="integer"),
 *   @OA\Property(property="name", type="string"),
 *   @OA\Property(property="description", type="string")
 * )
 *
 * @OA\Schema(
 *   schema="MacrosUpsert",
 *   type="object",
 *   @OA\Property(property="name", type="string"),
 *   @OA\Property(property="description", type="string"),
 *   @OA\Property(property="display_for", type="array", @OA\Items(type="string", enum={"DEVICE","PORT","PON","ONU"})),
 *   @OA\Property(property="display_output", type="string", enum={"no","all","last"}),
 *   @OA\Property(property="models", type="array", @OA\Items(type="object", @OA\Property(property="id", type="integer"), @OA\Property(property="key", type="string"))),
 *   @OA\Property(property="user_roles", type="array", @OA\Items(type="object", @OA\Property(property="id", type="integer"))),
 *   @OA\Property(property="template", type="string"),
 *   @OA\Property(property="parameters", type="array", @OA\Items(ref="#/components/schemas/MacrosParameter"))
 * )
 */
class OpenApiSchemas
{
}
