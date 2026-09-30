<?php

namespace WCC\Links\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Models\Devices\DeviceInterface;
use WCC\Links\Models\Link;
/**
 * @OA\Post(
 *   path="/component/links",
 *   tags={"links"},
 *   security={{"XAuthKey": {}}},
 *   summary="Create link",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(ref="#/components/schemas/LinkWritePayload")
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Created link",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/LinkLite")
 *     )
 *   ),
 *   @OA\Response(response=400, description="Bad Request"),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */

class AddAction extends BaseApiAction
{

    protected $forbiddenInDemo = true;

    protected function action(): Response
    {
        $form = $this->getFormData();
        $link = (new Link())
            ->setSource('manual')
            ->setSrcDevice($this->controller->getDeviceStorage()->getById($form['src_device']['id']))
            ->setDestDevice($this->controller->getDeviceStorage()->getById($form['dest_device']['id']));
        if(isset($form['src_iface']['id'])) {
            $link->setSrcIface($this->controller->getDeviceInterfaceStorage()->getById($form['src_iface']['id']));
        }
        if(isset($form['dest_iface']['id'])) {
            $link->setDestIface($this->controller->getDeviceInterfaceStorage()->getById($form['dest_iface']['id']));
        }

        if (isset($form['params'])) {
            $link->setParams($form['params']);
        }
        $link = $this->controller->addLink($link);
        $this->actionLog->success(
            'link:added',
            "Success added link by user {$this->user->getName()}",
            $link->getAsArray()
        );
        $lnk = $link->getAsArrayLite();
        return  $this->respondWithData($lnk);
    }
}
