<?php

namespace WCC\Links\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Models\Devices\DeviceInterface;
use WCC\Links\Models\Link;
/**
 * @OA\Put(
 *   path="/component/links/{id}",
 *   tags={"links"},
 *   security={{"XAuthKey": {}}},
 *   summary="Update link by ID",
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", example=12)),
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(ref="#/components/schemas/LinkWritePayload")
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Updated link",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/LinkLite")
 *     )
 *   ),
 *   @OA\Response(response=400, description="Bad Request"),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=404, description="Not Found")
 * )
 */

class UpdateAction extends BaseApiAction
{
    protected $forbiddenInDemo = true;

    protected function action(): Response
    {
        $form = $this->getFormData();
        $linkId = $this->request->getAttribute('id');
        $link = $this->controller->getLinkStorage()->getById($linkId);

        if (isset($form['src_device']['id'])) {
            $link->setSrcDevice($this->controller->getDeviceStorage()->getById($form['src_device']['id']));
        }

        if (isset($form['dest_device']['id'])) {
            $link->setDestDevice($this->controller->getDeviceStorage()->getById($form['dest_device']['id']));
        }

        if (isset($form['src_iface']['id']) && $form['src_iface']['id']) {
            $link->setSrcIface($this->controller->getDeviceInterfaceStorage()->getById($form['src_iface']['id']));
        } elseif ($form['src_iface'] == null) {
            $link->setSrcIface(null)->setSrcIfaceId(null);

        }

        if (isset($form['dest_iface']['id']) && $form['dest_iface']['id']) {
            $link->setDestIface($this->controller->getDeviceInterfaceStorage()->getById($form['dest_iface']['id']));
        } elseif ( $form['dest_iface'] == null) {
            $link->setDestIface(null)->setDestIfaceId(null);
        }

        if (isset($form['params'])) {
            $link->setParams($form['params']);
        }
        $link = $this->controller->updateLink($link);
        $this->actionLog->success(
            'link:updated',
            "Success updated link by user {$this->user->getName()}",
            $link->getAsArray()
        );
        return $this->respondWithData($link->getAsArrayLite());
    }
}
