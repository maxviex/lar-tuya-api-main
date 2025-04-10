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
    protected $headers = [];
    
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
     * Ngambil access token, kalo belum ada atau expired, minta yang baru
     */
    public function getAccessToken()
    {
        // Untuk debugging, kita bisa clear cache
        if ($this->debug) {
            Cache::forget($this->tokenCacheKey);
        }
        
        // Cek dulu kalo token-nya masih ada di cache
        if (Cache::has($this->tokenCacheKey)) {
            $this->debugLog('Using cached token');
            return Cache::get($this->tokenCacheKey);
        }

        $this->debugLog('Requesting new token');
        
        // Persiapkan parameter untuk request token
        // Referensi implementasi: https://github.com/ground-creative/tuyapiphp/blob/master/src/Token.php
        $timestamp = $this->getTimestamp();
        $nonce = Str::random(16);
        
        // Sorting parameters by alphabetical order (like what the reference does)
        $params = [
            'grant_type' => 1
        ];
        
        // Create URL
        $url = $this->apiHost . '/v1.0/token';
        $fullUrl = $url . '?' . http_build_query($params);
        
        // Sign string - using method similar to reference
        $stringToSign = $this->buildStringToSign('GET', $url, $params, '');
        $sign = $this->calcSign($stringToSign);
        
        $this->debugLog('Token request details', [
            'url' => $fullUrl,
            'stringToSign' => $stringToSign,
            'timestamp' => $timestamp
        ]);
        
        // Set headers
        $headers = [
            'client_id' => $this->accessId,
            'sign' => $sign,
            't' => $timestamp,
            'sign_method' => 'HMAC-SHA256',
            'nonce' => $nonce,
            'Content-Type' => 'application/json'
        ];
        
        // Make request
        $response = Http::withHeaders($headers)->get($fullUrl);
        
        $this->debugLog('Token response', [
            'status' => $response->status(),
            'body' => $response->body()
        ]);
        
        // Check response
        if (!$response->successful()) {
            throw new \Exception('API error: ' . $response->body());
        }
        
        $data = $response->json();
        
        // Validate response format, similar to reference
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
        
        // Simpen token ke cache - using expiration time from response if available
        $expireTime = isset($data['result']['expire_time']) ? $data['result']['expire_time'] / 1000 - time() - 60 : $this->tokenCacheTime;
        Cache::put($this->tokenCacheKey, $token, $expireTime);
        
        return $token;
    }
    
    /**
     * Build string to sign (like in reference implementation)
     */
    protected function buildStringToSign($method, $url, $params = [], $body = '')
    {
        $urlParts = parse_url($url);
        $path = isset($urlParts['path']) ? $urlParts['path'] : '';
        
        // Handle headers - blank for now as we don't use them in this implementation
        $headers = '';
        
        // Get query params from URL if any
        $urlQuery = isset($urlParts['query']) ? $urlParts['query'] : '';
        
        // Combine with method params
        $allParams = [];
        parse_str($urlQuery, $urlParams);
        $allParams = array_merge($urlParams, $params);
        
        // Sort params similar to reference
        ksort($allParams);
        $queryString = http_build_query($allParams);
        
        // Prepare body hash
        $bodyHash = '';
        if (!empty($body)) {
            if (is_array($body)) {
                $body = json_encode($body);
            }
            $bodyHash = hash('sha256', $body);
        } else {
            $bodyHash = hash('sha256', '');
        }
        
        // Assemble string to sign
        $stringToSign = $method . "\n" . 
                        $bodyHash . "\n" .
                        $headers . "\n" .
                        $path;
        
        // Add query params if exist
        if (!empty($queryString)) {
            $stringToSign .= '?' . $queryString;
        }
        
        return $stringToSign;
    }

    /**
     * Bikin signature buat request
     */
    protected function calcSign(string $stringToSign, ?string $accessToken = null)
    {
        $key = $accessToken ? $this->accessSecret . $accessToken : $this->accessSecret;
        return hash_hmac('sha256', $stringToSign, $key);
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
        
        // Ensure path starts with /
        if (substr($path, 0, 1) !== '/') {
            $path = '/' . $path;
        }
        
        $url = $this->apiHost . $path;
        
        // Sort params alphabetically as the reference does
        ksort($params);
        
        // Create full URL with query params
        $fullUrl = $url;
        if (!empty($params)) {
            $fullUrl .= '?' . http_build_query($params);
        }
        
        // Create body content
        $bodyContent = '';
        if (!empty($body)) {
            $bodyContent = json_encode($body);
        }
        
        // Build string to sign
        $stringToSign = $this->buildStringToSign(strtoupper($method), $url, $params, $bodyContent);
        
        // Sign with token
        $sign = $this->calcSign($stringToSign, $token);
        
        $this->debugLog('Making API request', [
            'method' => $method,
            'url' => $fullUrl,
            'stringToSign' => $stringToSign
        ]);
        
        // Set headers
        $headers = [
            'client_id' => $this->accessId,
            'access_token' => $token,
            'sign' => $sign,
            't' => $timestamp,
            'sign_method' => 'HMAC-SHA256',
            'nonce' => $nonce,
            'Content-Type' => 'application/json'
        ];
        
        // Store headers for debug
        $this->headers = $headers;
        
        // Create HTTP client
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
        
        // Validate response success similar to reference
        if (isset($data['success']) && $data['success'] === false) {
            $errorMsg = isset($data['msg']) ? $data['msg'] : 'Unknown error';
            $errorCode = isset($data['code']) ? $data['code'] : 'unknown';
            throw new \Exception("API error: {$errorMsg} (Code: {$errorCode})");
        }
        
        return $data;
    }

    /**
     * Get the last request headers for debugging
     */
    public function getLastHeaders()
    {
        return $this->headers;
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