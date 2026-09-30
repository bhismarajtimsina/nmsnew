<?php


namespace WCAA\Infrastructure;


use IPv4\SubnetCalculator;
use WCAA\App;

class TrustedIps
{
    protected $trustedIps = [];
    function __construct(array $trustedIps = [])
    {
        $this->setTrustedIps($trustedIps);
    }

    function setTrustedIps($trustedIps)
    {
        $this->trustedIps = $trustedIps;
        return $this;
    }

    function isWca(string $ip) {
        if($ip === '127.0.0.1') return true;
        //This grants system-user access, so anything not parseable as an IPv4
        //address is rejected rather than handed to the subnet calculator.
        if(!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        try {
            list($neth, $cidr) = explode("/", _env('LOCAL_SUBNET', '10.255.255.128/25'));
            $subnet = new SubnetCalculator($neth, $cidr);
            $minHost = $subnet->getMinHost();
            if($minHost === $ip) {
                return  false;
            }
            return (bool)$subnet->isIPAddressInSubnet($ip);
        } catch (\Throwable $e) {
            return false;
        }
    }

    function isAllowed(string $ip)
    {
        if (in_array($ip, $this->trustedIps)) {
            return true;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        foreach (array_filter($this->trustedIps, function ($e) {
            return strpos($e, "/");
        }) as $network) {
            try {
                list($neth, $cidr) = explode("/", $network);
                if ((new SubnetCalculator($neth, $cidr))->isIPAddressInSubnet($ip)) {
                    return true;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        return false;
    }
}
