<?php

// PhpWechatAggregator/app/Helpers/HttpClient.php

namespace App\Helpers;

class HttpClient
{
    private string $baseUrl = '';
    private array $defaultHeaders = [];

    public function __construct(string $baseUrl = '')
    {
        $this->baseUrl = $baseUrl;
    }

    public function setDefaultHeaders(array $headers): void
    {
        $this->defaultHeaders = $headers;
    }

    public function get(string $endpoint, array $params = [], array $headers = []): ?array
    {
        $url = $this->baseUrl . $endpoint;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30秒超时

        $finalHeaders = array_merge($this->defaultHeaders, $headers);
        $httpHeaders = [];
        foreach ($finalHeaders as $key => $value) {
            $httpHeaders[] = "{$key}: {$value}";
        }
        if (!empty($httpHeaders)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);
        }
        
        // 在Windows环境下，PHP的cURL可能默认不使用系统证书，导致SSL验证失败
        // 为提高兼容性，可以考虑不校验SSL证书，但这会带来安全风险，仅建议测试环境或信任网络中使用
        // 在生产环境，应确保正确配置了CA证书路径. 请参考 README.md 中的 SSL/TLS 配置说明。
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // TEMPORARILY DISABLED for testing
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);    // TEMPORARILY DISABLED for testing
        // 如果遇到 SSL 证书问题, 请不要直接禁用这些选项。
        // 请参照项目 README.md 文件中关于配置 CA 证书的说明进行操作。

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrorNum = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlErrorNum !== 0) { // Check curl_errno() for any error
            $errorMessage = "cURL Error for {$url}: [{$curlErrorNum}] " . $curlError;
            error_log($errorMessage);
            fwrite(STDERR, "[HTTP_CLIENT_CURL_ERROR] " . $errorMessage . PHP_EOL);
            return null;
        }

        // Even if no cURL error, check HTTP status code if needed by application logic (though ArticleFetcherService checks API's base_resp)
        // For now, we only care if cURL itself had an issue.
        // if ($httpCode !== 200) { ... }
        
        $decodedResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $jsonErrorMessage = "JSON Decode Error for {$url}: " . json_last_error_msg() . ". Response (first 500 chars): " . substr($response, 0, 500);
            error_log($jsonErrorMessage);
            fwrite(STDERR, "[HTTP_CLIENT_JSON_ERROR] " . $jsonErrorMessage . PHP_EOL);
            return null; 
        }

        return $decodedResponse;
    }

    public function getRaw(string $fullUrl, array $headers = []): ?string
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); 
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5); 

        $finalHeaders = array_merge($this->defaultHeaders, $headers);
        $httpHeaders = [];
        foreach ($finalHeaders as $key => $value) {
            $httpHeaders[] = "{$key}: {$value}";
        }
        if (!empty($httpHeaders)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);
        }
        
        // SSL settings from existing get method
        // In Windows environment, PHP's cURL may not use system certificates by default, causing SSL verification to fail.
        // For improved compatibility, SSL certificate verification can be considered, but this poses security risks and is only recommended for testing environments or trusted networks.
        // In a production environment, ensure that the CA certificate path is correctly configured. Please refer to the SSL/TLS configuration instructions in README.md.
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // TEMPORARILY DISABLED for testing as per original HttpClient
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);    // TEMPORARILY DISABLED for testing as per original HttpClient
        // If you encounter SSL certificate issues, please do not disable these options directly.
        // Please follow the instructions for configuring CA certificates in the project's README.md file.

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrorNum = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlErrorNum !== 0) {
            $errorMessage = "cURL Error for {$fullUrl}: [{$curlErrorNum}] " . $curlError;
            error_log($errorMessage);
            // Consider not using STDERR here if this helper is used in a web context primarily
            // fwrite(STDERR, "[HTTP_CLIENT_CURL_ERROR] " . $errorMessage . PHP_EOL);
            return null;
        }

        if ($httpCode >= 400) { 
            error_log("HttpClient::getRaw HTTP Error {$httpCode} for URL: {$fullUrl}");
            return null; 
        }

        return $response; 
    }

    // 可以根据需要添加 post, put, delete 等方法
} 