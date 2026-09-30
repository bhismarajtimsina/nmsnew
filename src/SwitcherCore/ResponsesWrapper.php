<?php

namespace WCAA\SwitcherCore;

use WCAA\Models\Devices\Device;
use WCAA\Models\User\User;

class ResponsesWrapper
{
     protected $responses = [];

    /**
     * @param Response[] $responses
     */
     function __construct(array $responses = [])
     {
         array_map(function ($e) {
             if(!($e instanceof Response)) {
                 throw new \InvalidArgumentException("Init param for ResponseWrapper must be array of WCAA\SwitcherCore\Response");
             }
         }, $responses);
         $this->responses = $responses;
     }

     function addResponse($response) {
         $this->responses[] = $response;
         return $this;
     }
     function setResponses($responses) {
         $this->responses = $responses;
         return $this;
     }

     function filterByDevice(Device $device) {
         $responses = array_filter($this->responses, function ($el) use ($device) {
             return $el->getDevice()->getId() === $device->getId();
         });
         $wrap = clone $this;
         $wrap->responses = array_values($responses);
         return $wrap;
     }

     function filterByModule($module) {
         $responses = array_filter($this->responses, function ($el) use ($module) {
             return $el->getModule() === $module;
         });
         $wrap = clone $this;
         $wrap->responses = array_values($responses);
         return $wrap;
     }

     function getFirstByModule($module) {

         $responses = array_filter($this->responses, function ($el) use ($module) {
             return $el->getModule() === $module;
         });
         if(count($responses) <= 0) {
             throw new \InvalidArgumentException("Responses by module '{$module}' not found");
         }
         return array_values($responses)[0];
     }
     function getAllByModule($module) {
         $responses = array_filter($this->responses, function ($el) use ($module) {
             return $el->getModule() === $module;
         });
         if(count($responses) <= 0) {
             throw new \InvalidArgumentException("Responses by module '{$module}' not found");
         }
         return array_values($responses);
     }
     function getAllResponses() {
         return $this->responses;
     }

    function findByRequest(Request $req) {
         $responses = array_filter($this->responses, function ($el) use ($req) {
             return  $req->getHash() === $el->getHash();
         });
         if($responses) return array_values($responses)[0];
         throw new \Exception("Not found responses by request");
    }
}