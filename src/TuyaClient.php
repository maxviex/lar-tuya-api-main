<?php

namespace Maxviex\TuyaLaravel;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class TuyaClient
{
    protected $apiHost;
    protected $accessId;
    protected $accessSecret;
    protected $tokenCacheKey = 'tuya_access_token';
    protected $tokenCacheTime = 7200; // 2 jam, kurang dari masa berlaku token asli
    protected $debug = false;
    
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
     * Enable debug mode
     */
    public function enableDebug()
    {
        $this->debug = true;
        return $this;
    }
    
    /**
     * Debug log helper
     */
    protected function debugLog($message, $data = [])
    {
        if ($this->debug) {
            Log::info('TuyaClient Debug: ' . $message, $data);
        }
    }

    /**
     * Mendapatkan access token menggunakan metode terbaru
     * https://developer.tuya.com/en/docs/iot/authentication-method?id=Ka49gbaxjygox
     */
    public function getAccessToken()
    {
        // Force clear cache for debugging if needed
        if ($this->debug) {
            Cache::forget($this->tokenCacheKey);
        }
        
        // Cek dulu jika token sudah di cache
        if (Cache::has($this->tokenCacheKey)) {
            $this->debugLog('Using cached token');
            return Cache::get($this->tokenCacheKey);
        }

        $this->debugLog('Requesting new token');
        
        // Timestamp dalam milidetik
        $timestamp = round(microtime(true) * 1000);
        $nonce = Str::random(16);
        
        // Metode terbaru untuk signing token request (dari docs)
        $stringToSign = $this->accessId . $timestamp;
        $sign = strtoupper(hash_hmac('sha256', $stringToSign, $this->accessSecret));
        
        $this->debugLog('Token request details', [
            'url' => $this->apiHost . '/v1.0/token?grant_type=1',
            'stringToSign' => $stringToSign,
            'timestamp' => $timestamp
        ]);
        
        // Headers sesuai dokumentasi terbaru
        $headers = [
            'client_id' => $this->accessId,
            'sign' => $sign,
            't' => (string)$timestamp,
            'sign_method' => 'HMAC-SHA256',
            'nonce' => $nonce
        ];
        
        // Request token
        $response = Http::withHeaders($headers)
            ->get($this->apiHost . '/v1.0/token?grant_type=1');
        
        $this->debugLog('Token response', [
            'status' => $response->status(),
            'body' => $response->body()
        ]);
        
        // Check response
        if (!$response->successful()) {
            throw new \Exception('API error: ' . $response->body());
        }
        
        $data = $response->json();
        
        // Validate response format
        if (!isset($data['success']) || $data['success'] !== true) {
            $errorMsg = isset($data['msg']) ? $data['msg'] : 'Unknown error';
            $errorCode = isset($data['code']) ? $data['code'] : 'unknown';
            throw new \Exception("API error: {$errorMsg} (Code: {$errorCode})");
        }
        
        // Extract token from response
        if (!isset($data['result']) || !isset($data['result']['access_token'])) {
            throw new \Exception('Token not found in response: ' . json_encode($data));
        }
        
        $token = $data['result']['access_token'];
        $this->debugLog('Token obtained successfully');
        
        // Cache token
        $expireTime = isset($data['result']['expire_time']) ? ($data['result']['expire_time'] / 1000) - time() - 60 : $this->tokenCacheTime;
        Cache::put($this->tokenCacheKey, $token, $expireTime);
        
        return $token;
    }

    /**
     * Membuat signature untuk API request sesuai metode terbaru
     * https://developer.tuya.com/en/docs/iot/new-singnature?id=Kbw0q34cs2e5g
     */
    protected function calcSign(string $method, string $path, string $body = '', ?string $accessToken = null)
    {
        // Prepare content hash
        $contentHash = hash('sha256', $body);
        
        // Metode terbaru untuk signing API requests
        $stringToSign = $method . "\n" .
                        $contentHash . "\n" .
                        '' . "\n" . // Headers is empty for now
                        $path;
        
        $this->debugLog('Sign details', [
            'method' => $method,
            'path' => $path,
            'stringToSign' => $stringToSign
        ]);
        
        // Key untuk signing
        $key = $accessToken ? $this->accessSecret . $accessToken : $this->accessSecret;
        
        // Generate signature (uppercase sesuai docs terbaru)
        return strtoupper(hash_hmac('sha256', $stringToSign, $key));
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
        // Ensure path starts with /
        if (substr($path, 0, 1) !== '/') {
            $path = '/' . $path;
        }
        
        // Get token first
        $token = $this->getAccessToken();
        
        // Prepare request details
        $timestamp = $this->getTimestamp();
        $nonce = Str::random(16);
        $url = $this->apiHost . $path;
        $bodyContent = '';
        
        // Convert body to JSON if needed
        if (!empty($body)) {
            $bodyContent = json_encode($body);
        }
        
        // Add query parameters if needed
        $fullUrl = $url;
        if (!empty($params)) {
            $queryString = http_build_query($params);
            $fullUrl .= '?' . $queryString;
            $path .= '?' . $queryString; // Path with query for signing
        }
        
        // Sign request
        $sign = $this->calcSign(strtoupper($method), $path, $bodyContent, $token);
        
        $this->debugLog('API request details', [
            'method' => $method,
            'url' => $fullUrl,
            'body' => $bodyContent
        ]);
        
        // Set headers based on latest docs
        $headers = [
            'client_id' => $this->accessId,
            'access_token' => $token,
            'sign' => $sign,
            't' => (string)$timestamp,
            'sign_method' => 'HMAC-SHA256',
            'nonce' => $nonce
        ];
        
        if (!empty($body)) {
            $headers['Content-Type'] = 'application/json';
        }
        
        // Make the request
        $client = Http::withHeaders($headers);
        
        // Execute request based on method
        $response = null;
        switch (strtoupper($method)) {
            case 'GET':
                $response = $client->get($fullUrl);
                break;
            case 'POST':
                $response = $client->post($fullUrl, $body);
                break;
            case 'PUT':
                $response = $client->put($fullUrl, $body);
                break;
            case 'DELETE':
                $response = $client->delete($fullUrl, $body);
                break;
            default:
                throw new \Exception('Method gak didukung: ' . $method);
        }
        
        $this->debugLog('API response', [
            'status' => $response->status(),
            'body' => $response->body()
        ]);
        
        // Check response
        if (!$response->successful()) {
            throw new \Exception('API error: ' . $response->body());
        }
        
        // Parse response
        $data = $response->json();
        
        // Check for error
        if (isset($data['success']) && $data['success'] === false) {
            $errorMsg = isset($data['msg']) ? $data['msg'] : 'Unknown error';
            $errorCode = isset($data['code']) ? $data['code'] : 'unknown';
            throw new \Exception("API error: {$errorMsg} (Code: {$errorCode})");
        }
        
        return $data;
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