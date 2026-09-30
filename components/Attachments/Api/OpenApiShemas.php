<?php

namespace WCC\Attachments\Api;

use OpenApi\Annotations as OA;
/**
 * @OA\Schema(
 *   schema="AttachmentUserRoleLite",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=2),
 *   @OA\Property(property="name", type="string", example="Operator")
 * )
 *
 * @OA\Schema(
 *   schema="AttachmentUserLite",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=12),
 *   @OA\Property(property="name", type="string", example="NOC Operator"),
 *   @OA\Property(property="login", type="string", example="operator"),
 *   @OA\Property(property="role", ref="#/components/schemas/AttachmentUserRoleLite"),
 *   @OA\Property(property="last_activity", type="string", nullable=true, example="2026-02-25 12:00:00")
 * )
 *
 * @OA\Schema(
 *   schema="AttachmentExtra",
 *   type="object",
 *   @OA\Property(property="extension", type="string", nullable=true, example="jpg"),
 *   @OA\Property(property="filename", type="string", nullable=true, example="photo.jpg"),
 *   @OA\Property(property="mimetype", type="string", nullable=true, example="image/jpeg"),
 *   @OA\Property(property="size", type="integer", nullable=true, example=182736),
 *   @OA\Property(property="exif", type="object", nullable=true, additionalProperties=true),
 *   @OA\Property(property="preview", type="object", nullable=true, additionalProperties=true)
 * )
 *
 * @OA\Schema(
 *   schema="Attachment",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=41),
 *   @OA\Property(property="user", ref="#/components/schemas/AttachmentUserLite"),
 *   @OA\Property(property="created_at", type="string", example="2026-02-25 12:15:00"),
 *   @OA\Property(property="object_type", type="string", example="device"),
 *   @OA\Property(property="object_id", type="integer", example=101),
 *   @OA\Property(property="uuid", type="string", example="1d2f7348-44b3-4f6f-955c-a53cc5a6ea85"),
 *   @OA\Property(property="extension", type="string", example="jpg"),
 *   @OA\Property(property="extra", ref="#/components/schemas/AttachmentExtra")
 * )
 *
 * @OA\Schema(
 *   schema="AttachmentListItem",
 *   allOf={
 *     @OA\Schema(ref="#/components/schemas/Attachment"),
 *     @OA\Schema(
 *       type="object",
 *       @OA\Property(property="url", type="string", example="/api/v1/component/attachments/object/1d2f7348-44b3-4f6f-955c-a53cc5a6ea85"),
 *       @OA\Property(property="preview", type="string", example="/api/v1/component/attachments/thumb/1d2f7348-44b3-4f6f-955c-a53cc5a6ea85")
 *     )
 *   }
 * )
 */

final class OpenApiShemas {}

