<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Tuya Access Credentials
    |--------------------------------------------------------------------------
    |
    | Kredensial yang lo dapetin dari Cloud Development Platform Tuya.
    | Buka https://iot.tuya.com/ dan bikin project baru buat dapetin 
    | access_id dan access_secret.
    |
    */
    'access_id' => env('TUYA_ACCESS_ID', ''),
    'access_secret' => env('TUYA_ACCESS_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Tuya API Host
    |--------------------------------------------------------------------------
    |
    | Host API Tuya tergantung region lo. Pilih yang sesuai:
    | - China: https://openapi.tuyacn.com
    | - US: https://openapi.tuyaus.com
    | - Europe: https://openapi.tuyaeu.com
    | - India: https://openapi.tuyain.com
    |
    */
    'api_host' => env('TUYA_API_HOST', 'https://openapi.tuyaus.com'),

    /*
    |--------------------------------------------------------------------------
    | Token Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Setting buat caching token. Default-nya 2 jam (7200 detik).
    | Jangan lewatin 8 jam soalnya token Tuya expired setelah itu.
    |
    */
    'token_cache_time' => env('TUYA_TOKEN_CACHE_TIME', 7200),
];