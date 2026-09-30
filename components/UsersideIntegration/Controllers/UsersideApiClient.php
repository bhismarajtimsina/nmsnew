<?php

namespace WCC\UsersideIntegration\Controllers;

use GuzzleHttp\Client;

class UsersideApiClient
{


    /**
     * Дополнительные параметры:
     * action - подкатегория запроса (возможное значение: см.ниже)
     * ID типов объектов инфраструктуры:
     *
     * 0 - узел связи (до 3.14.21)
     * 1 - муфта
     * 2 - опора
     * 3 - колодец
     * 4 - узел связи (с 3.14.21)
     * */
    const NODE_TYPE_COMMUNICATION_UNIT = 4;
    const NODE_TYPE_SPLICE_CLOSURE = 1;
    const NODE_TYPE_POLE = 2;
    const NODE_TYPE_MANHOLE = 3;

    /**
     * @param int $objectType
     * @return mixed
     * @throws \Exception
     */
    function getNodes(int $objectType)
    {
        return $this->callApi('node', 'get', [
            'object_type' => $objectType,
        ]);
    }

    function getSplitters()
    {
        return $this->callApi('splitter', 'get');
    }



    /***
     * @param string $deviceType Типы -  [switch|onu|olt|radio|all]
     * @return void
     * @throws \Exception
     */
    function getDevices($deviceType = 'all')
    {
        $devices = $this->callApi('device', 'get_data', [
            'object_type' => $deviceType,
        ]);
        return $devices;
    }


    protected function callApi($catalog, $action, $params = [])
    {

        $apiURL = _env('USERSIDE_WEB_ADDRESS', null);
        if (!$apiURL) {
            throw new \Exception('Userside API address not set');
        }
        $apiKEY = _env('USERSIDE_API_KEY', null);
        if (!$apiKEY) {
            throw new \Exception('Userside API key not set');
        }

        $query = array_merge($params, [
            'key' => $apiKEY,  // ключ API
            'cat' => $catalog,  // Каталог с АПИ
            'action' => $action, // метод
        ]);

        $client = new Client();
        $res = $client->request('GET', $apiURL . "/api.php", [
            'query' => $query,
            'timeout' => 90.0,
            'connect_timeout' => 5.0,
        ]);
        $content = $res->getBody()->getContents();
        $data = json_decode($content, true);
        if(json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Incorrect JSON in userside API response: " . json_last_error_msg());
        }
        if ($data && $data['result'] != 'OK') {
            throw new \Exception("Error from userside API(StatusCode={$res->getStatusCode()}, Status={$res->getReasonPhrase()}) - " . $data['ErrorText']);
        }

        if ($res->getStatusCode() != 200) {
            throw new \Exception("Incorrect HTTP code from Userside API, must be 200, returned - {$res->getStatusCode()}");
        }
        return $data['data'];
    }
}