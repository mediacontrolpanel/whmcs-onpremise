<?php

if (!function_exists('logModuleCall')) {
    function logModuleCall()
    {
    }
}

if (!function_exists('encrypt')) {
    function encrypt($value)
    {
        return $value;
    }
}

if (!function_exists('decrypt')) {
    function decrypt($value)
    {
        return $value;
    }
}

require_once __DIR__ . '/../modules/servers/mediacp/mediacp.php';
