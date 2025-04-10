<?php

namespace maxviex\TuyaLaravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array getAccessToken()
 * @method static array request(string $method, string $path, array $params = [], array $body = [])
 * @method static array get(string $path, array $params = [])
 * @method static array post(string $path, array $body = [], array $params = [])
 * @method static array put(string $path, array $body = [], array $params = [])
 * @method static array delete(string $path, array $body = [], array $params = [])
 * @method static array getDevices()
 * @method static array getDevice(string $deviceId)
 * @method static array getDeviceStatus(string $deviceId)
 * @method static array controlDevice(string $deviceId, array $commands)
 * 
 * @see \maxviex\TuyaLaravel\TuyaClient
 */
class Tuya extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'tuya';
    }
}