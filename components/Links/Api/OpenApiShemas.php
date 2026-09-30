<?php

namespace WCC\Links\Api;

use OpenApi\Annotations as OA;
/**
 * @OA\Schema(
 *   schema="LinkDeviceLite",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=101),
 *   @OA\Property(property="name", type="string", example="core-sw-1"),
 *   @OA\Property(property="ip", type="string", example="10.0.0.1")
 * )
 *
 * @OA\Schema(
 *   schema="LinkInterfaceLite",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=2435),
 *   @OA\Property(property="name", type="string", example="ge-0/0/1")
 * )
 *
 * @OA\Schema(
 *   schema="LinkLite",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=12),
 *   @OA\Property(property="source", type="string", example="manual"),
 *   @OA\Property(property="src_device", ref="#/components/schemas/LinkDeviceLite"),
 *   @OA\Property(property="dest_device", ref="#/components/schemas/LinkDeviceLite"),
 *   @OA\Property(property="src_iface", ref="#/components/schemas/LinkInterfaceLite", nullable=true),
 *   @OA\Property(property="dest_iface", ref="#/components/schemas/LinkInterfaceLite", nullable=true),
 *   @OA\Property(property="params", type="object", nullable=true, additionalProperties=true),
 *   @OA\Property(property="utilization", type="number", format="float", nullable=true)
 * )
 *
 * @OA\Schema(
 *   schema="LinkNodeRefPayload",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=101)
 * )
 *
 * @OA\Schema(
 *   schema="LinkWritePayload",
 *   type="object",
 *   @OA\Property(property="src_device", ref="#/components/schemas/LinkNodeRefPayload"),
 *   @OA\Property(property="dest_device", ref="#/components/schemas/LinkNodeRefPayload"),
 *   @OA\Property(property="src_iface", ref="#/components/schemas/LinkNodeRefPayload", nullable=true),
 *   @OA\Property(property="dest_iface", ref="#/components/schemas/LinkNodeRefPayload", nullable=true),
 *   @OA\Property(property="params", type="object", nullable=true, additionalProperties=true)
 * )
 *
 * @OA\Schema(
 *   schema="LinksOptionDevice",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=101),
 *   @OA\Property(property="name", type="string", example="core-sw-1"),
 *   @OA\Property(property="ip", type="string", example="10.0.0.1")
 * )
 *
 * @OA\Schema(
 *   schema="LinksOptionInterface",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=2435),
 *   @OA\Property(property="name", type="string", example="ge-0/0/1")
 * )
 *
 * @OA\Schema(
 *   schema="LinksOptionConfiguration",
 *   type="object",
 *   @OA\Property(property="max_util_for_alert", type="integer", example=85),
 *   @OA\Property(property="calc_util_period", type="string", example="15m"),
 *   @OA\Property(property="periods", type="array", @OA\Items(type="string", example="15m"))
 * )
 *
 * @OA\Schema(
 *   schema="ListMeta",
 *   type="object",
 *   @OA\Property(property="total", type="integer", example=124),
 *   @OA\Property(property="limit", type="integer", example=50),
 *   @OA\Property(property="offset", type="integer", example=0),
 *   @OA\Property(property="count", type="integer", example=50)
 * )
 *
 * @OA\Schema(
 *   schema="LinksTopology",
 *   type="object",
 *   @OA\Property(property="devices", type="array", @OA\Items(type="object", additionalProperties=true)),
 *   @OA\Property(property="links", type="array", @OA\Items(type="object", additionalProperties=true))
 * )
 *
 * @OA\Schema(
 *   schema="UpwardTopologyItem",
 *   type="object",
 *   @OA\Property(property="device", type="object", additionalProperties=true),
 *   @OA\Property(property="uplink_interface", type="object", nullable=true, additionalProperties=true),
 *   @OA\Property(property="downlink_interface", type="object", nullable=true, additionalProperties=true),
 *   @OA\Property(property="depth", type="integer", example=1),
 *   @OA\Property(property="link", type="object", additionalProperties=true)
 * )
 */

final class OpenApiShemas {}
