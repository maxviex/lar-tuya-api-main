<?php

namespace maxviex\TuyaLaravel;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class TuyaClient
{
    protected $apiHost;
    protected $accessId;
    protected $accessSecret;
    protected $tokenCacheKey = 'tuya_access_token';
    protected $tokenCacheTime = 7200; // 2 jam, kurang dari masa berlaku token asli

    /**
     * TuyaClient constructor.
     * 
     * @param string $accessId Client ID dari akun Tuya IoT
     * @param string $accessSecret Client Secret dari akun Tuya IoT
     * @param string $apiHost Host API Tuya (contoh: https://openapi.tuyaus.com)
     */
    public function __construct(string $accessId, string $accessSecret, string $apiHost)
    {
        $this->accessId = $accessId;
        $this->accessSecret = $accessSecret;
        $this->apiHost = rtrim($apiHost, '/');
    }

    /**
     * Ngambil access token, kalo belum ada atau expired, minta yang baru
     */
    public function getAccessToken()
    {
        // Cek dulu kalo token-nya masih ada di cache
        if (Cache::has($this->tokenCacheKey)) {
            return Cache::get($this->tokenCacheKey);
        }

        // Kalo gak ada, minta token baru
        $timestamp = $this->getTimestamp();
        $stringToSign = $this->accessId . $timestamp;
        $sign = $this->calcSign($stringToSign);

        $response = Http::withHeaders([
            'client_id' => $this->accessId,
            'sign' => $sign,
            't' => $timestamp,
            'sign_method' => 'HMAC-SHA256',
        ])->get($this->apiHost . '/v1.0/token?grant_type=1');

        if (!$response->successful()) {
            throw new \Exception('Gagal dapetin token: ' . $response->body());
        }

        $data = $response->json();
        $token = $data['result']['access_token'];

        // Simpen token ke cache
        Cache::put($this->tokenCacheKey, $token, $this->tokenCacheTime);

        return $token;
    }

    /**
     * Bikin signature buat request
     */
    protected function calcSign(string $stringToSign, ?string $accessToken = null)
    {
        $str = $accessToken ? $this->accessSecret . $accessToken : $this->accessSecret;
        return hash_hmac('sha256', $stringToSign, $str);
    }

    /**
     * Dapetin timestamp sekarang dalam milidetik
     */
    protected function getTimestamp()
    {
        return round(microtime(true) * 1000);
    }

    /**
     * Bikin request ke API Tuya
     */
    public function request(string $method, string $path, array $params = [], array $body = [])
    {
        $token = $this->getAccessToken();
        $timestamp = $this->getTimestamp();
        $nonce = Str::random(16);
        $url = $this->apiHost . $path;

        // Bikin query string kalo ada params
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        // Bikin string yang mau di-sign
        $contentToSign = '';
        if (!empty($body)) {
            $contentToSign = json_encode($body);
        }
        
        $stringToSign = strtoupper($method) . "\n" .
            hash('sha256', $contentToSign) . "\n" .
            '' . "\n" . // headers kosong
            $path;

        // Tambahkan query string ke string yang mau di-sign kalo ada
        if (!empty($params)) {
            $stringToSign .= '?' . http_build_query($params);
        }

        $sign = $this->calcSign($stringToSign, $token);

        // Bikin request
        $response = Http::withHeaders([
            'client_id' => $this->accessId,
            'access_token' => $token,
            'sign' => $sign,
            't' => $timestamp,
            'sign_method' => 'HMAC-SHA256',
            'nonce' => $nonce,
        ]);

        // Tambah body kalo ada
        if (!empty($body)) {
            $response = $response->withBody(json_encode($body), 'application/json');
        }

        // Kirim request sesuai method-nya
        switch (strtoupper($method)) {
            case 'GET':
                $response = $response->get($url);
                break;
            case 'POST':
                $response = $response->post($url, $body);
                break;
            case 'PUT':
                $response = $response->put($url, $body);
                break;
            case 'DELETE':
                $response = $response->delete($url, $body);
                break;
            default:
                throw new \Exception('Method gak didukung: ' . $method);
        }

        return $response->json();
    }

    /**
     * Helper buat ngirim request GET
     */
    public function get(string $path, array $params = [])
    {
        return $this->request('GET', $path, $params);
    }

    /**
     * Helper buat ngirim request POST
     */
    public function post(string $path, array $body = [], array $params = [])
    {
        return $this->request('POST', $path, $params, $body);
    }

    /**
     * Helper buat ngirim request PUT
     */
    public function put(string $path, array $body = [], array $params = [])
    {
        return $this->request('PUT', $path, $params, $body);
    }

    /**
     * Helper buat ngirim request DELETE
     */
    public function delete(string $path, array $body = [], array $params = [])
    {
        return $this->request('DELETE', $path, $params, $body);
    }

    /**
     * Dapetin semua device
     */
    public function getDevices()
    {
        return $this->get('/v1.0/devices');
    }

    /**
     * Dapetin detail device berdasarkan ID
     */
    public function getDevice(string $deviceId)
    {
        return $this->get('/v1.0/devices/' . $deviceId);
    }

    /**
     * Dapetin status device
     */
    public function getDeviceStatus(string $deviceId)
    {
        return $this->get('/v1.0/devices/' . $deviceId . '/status');
    }

    /**
     * Ngontrol device
     */
    public function controlDevice(string $deviceId, array $commands)
    {
        return $this->post('/v1.0/devices/' . $deviceId . '/commands', [
            'commands' => $commands
        ]);
    }
}