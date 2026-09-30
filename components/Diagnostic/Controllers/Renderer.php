<?php

namespace WCC\Diagnostic\Controllers;

use WCAA\Exceptions\SupportException;

class Renderer
{

    function renderError(\Exception $error)
    {
        $parameters = [];
        if ($error instanceof SupportException && $error->getType() == 'SWC_CACHE_NOT_FOUND') {
            $parameters['message'] = "OLT or SWITCH is offline and history information not found";
        } else {
            $parameters['message'] = $error->getMessage();
        }
        return $this->build($this->getTemplate('error'), $parameters);
    }

    function renderResultFromData($data)
    {
        $basicLink = _env('EXTERNAL_HTTP_ADDRESS', 'http://127.0.0.1:8088');
        $parameters = [
            'last_seen' => $this->_findFirstTimeFromMeta($data['diagnostic']['meta']),
            'has_errors' => $this->_hasDiagErrorsFromMeta($data['diagnostic']['meta']),
            'device' => [
                '_id' => $data['iface']['device']['id'],
                'ip' => $data['iface']['device']['ip'],
                'name' => $data['iface']['device']['name'],
                'type' => $data['iface']['device']['model']['type'],
                'model' => $data['iface']['device']['model']['name'],
                'is_online' => $data['device_status']['error'] === null,
            ],
            'iface' => [
                '_id' => $data['iface']['id'],
                '_bind_key' => $data['iface']['bind_key'],
                'name' => $data['iface']['name'],
                'type' => $data['iface']['type'],
            ],
            'diagnostic' => $data['diagnostic']['data'],
            'links' => [
                'device' => "{$basicLink}/devices/{$data['iface']['device']['id']}/",
                'interface' => "{$basicLink}/devices/{$data['iface']['device']['id']}/interface/{$data['iface']['bind_key']}",
            ]
        ];
        return $this->build($this->getTemplate('result'), $parameters);
    }

    protected function _findFirstTimeFromMeta($meta)
    {
        $first = time();
        foreach ($meta as $name => $data) {
            $tm = \DateTime::createFromFormat("Y-m-d H:i:s", $data['time'])->getTimestamp();
            if ($tm < $first) {
                $first = $tm;
            }
        }
        return \date("Y-m-d H:i:s", $first);
    }

    protected function _hasDiagErrorsFromMeta($meta): bool
    {
        foreach ($meta as $name => $data) {
            if ($data['error']) {
                return true;
            }
        }
        return false;
    }


    /**
     * @param string $template
     * @param $parameters
     * @return string
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    function build($template, $parameters = [])
    {
        $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader([
            'commands' => $template
        ]), [
            'strict_variables' => true,
        ]);
        return $twig->render(
            'commands',
            $parameters
        );
    }

    function getTemplate($name)
    {
        if (file_exists(__DIR__ . "/../tmpl/{$name}.twig")) {
            return file_get_contents(__DIR__ . "/../tmpl/{$name}.twig");
        }
        throw new SupportException("Unknown template name for loading");
    }
}