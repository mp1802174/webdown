<?php

// PhpWechatAggregator/app/Services/WechatAuthService.php

namespace App\Services;

class WechatAuthService
{
    private array $config;
    private string $token;
    private string $cookie;

    public function __construct(array $appConfig)
    {
        $this->config = $appConfig;
        $this->loadCredentials();
    }

    private function loadCredentials(): void
    {
        $this->token = $this->config['wechat_credentials']['token'] ?? '';
        $this->cookie = $this->config['wechat_credentials']['cookie'] ?? '';
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getCookie(): string
    {
        return $this->cookie;
    }

    public function areCredentialsProvided(): bool
    {
        return !empty($this->token) && !empty($this->cookie);
    }

    /**
     * 检查凭证是否有效。
     * MVP阶段：简单检查token和cookie是否已配置。
     * 后续可以实现调用一个轻量级的微信API来实际验证。
     * @param HttpClient $httpClient (可选, 用于实际API验证)
     * @return bool
     */
    public function areCredentialsValid(/* HttpClient $httpClient = null */): bool
    {
        if (!$this->areCredentialsProvided()) {
            error_log("WeChat token or cookie is not configured.");
            return false;
        }

        // MVP: 简单返回true，假设配置了就是有效的，由后续API调用失败来间接反映问题。
        // TODO: 实现真实的API调用验证逻辑。
        // 例如，可以尝试获取公众号信息或少量文章，检查 base_resp.err_msg
        // $testParams = [
        //     'action' => 'get_account_info', // 假设有这样一个接口
        //     'token' => $this->token,
        //     'f' => 'json',
        //     'ajax' => 1,
        // ];
        // $response = $httpClient->get('ACTION_URL', $testParams, ['Cookie' => $this->cookie]);
        // if ($response && isset($response['base_resp']['err_msg']) && $response['base_resp']['err_msg'] === 'ok') {
        //     return true;
        // }
        // error_log("WeChat credential validation failed. Response: " . json_encode($response));
        // return false;

        return true; // MVP简化处理
    }
} 