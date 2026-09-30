<?php
namespace WCAA\SwitcherCore\CacheSystem;


use WCAA\Models\Devices\Device;
use WCAA\Models\User\User;
use WCAA\SwitcherCore\Request;
use WCAA\SwitcherCore\Response;

interface CacheSystemInterface
{
    /**
     * @param Request  $req
     * @return Response
     */
    function get(Request  $req);

    /**
     * @param Request  $req
     * @return Response
     */
    function getWithoutTimeout(Request $req);

    /**
     * @param Response $data
     * @return string
     */
    function write(Response $data);
}