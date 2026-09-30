<?php

namespace WCAA\Api\Actions\Dashboard\Widgets;

use DI\Annotation\Inject;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\SwitcherCoreActionStorage;
class ErrorCallingByDevices extends PrivateAction
{

    /**
     * @Inject
     * @var SwitcherCoreActionStorage
     */
    protected $switcherCoreActionStorage;

    protected function action(): Response
    {
        $limit = 30;
        $response = [
           'errors_count' => 0,
           'not_responding_count' => 0,
           'data' => [],
           'limit' => $limit,
        ];
        $stat = $this->switcherCoreActionStorage->callingErrorsStatByDevice($this->user);
        $counter = 0;
        foreach ($stat as $s) {
            $counter++;
            $response['errors_count'] += $s['errors_count'];
            $response['not_responding_count'] += $s['not_responding_count'];
            if($counter < $limit) {
                $device = $s['device']->getAsArray();
                $response['data'][] = [
                    'device' => [
                        'id' => $device['id'],
                        'ip' => $device['ip'],
                        'name' => $device['name'],
                    ],
                    'errors_count' => $s['errors_count'],
                    'not_responding_count' => $s['not_responding_count'],
                ];
            }
        }
        return $this->respondWithData($response);
    }
}