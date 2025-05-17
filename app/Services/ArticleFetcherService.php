<?php

// PhpWechatAggregator/app/Services/ArticleFetcherService.php

namespace App\Services;

use App\Helpers\HttpClient;

class ArticleFetcherService
{
    private HttpClient $httpClient;
    private WechatAuthService $authService;
    private array $appConfig;
    private string $apiBaseUrl = 'https://mp.weixin.qq.com';

    public function __construct(HttpClient $httpClient, WechatAuthService $authService, array $appConfig)
    {
        $this->httpClient = $httpClient;
        $this->authService = $authService;
        $this->appConfig = $appConfig;
        $this->httpClient->setDefaultHeaders([
            'User-Agent' => $this->appConfig['user_agent'] ?? 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/86.0.4240.198 Safari/537.36'
        ]);
    }

    private function logError(string $message): void
    {
        error_log($message);
        fwrite(STDERR, "[SERVICE_ERROR] " . $message . PHP_EOL);
    }

    /**
     * 从指定公众号拉取最新文章。
     *
     * @param string $fakeId 公众号的 fakeid
     * @param string $accountName 公众号名称 (用于日志或数据存储)
     * @return array|null 成功则返回文章数组，失败返回 null
     */
    public function fetchArticles(string $fakeId, string $accountName): ?array
    {
        if (!$this->authService->areCredentialsValid()) {
            $this->logError("Cannot fetch articles for {$accountName} ({$fakeId}): Invalid or missing credentials.");
            return null;
        }

        $token = $this->authService->getToken();
        $cookie = $this->authService->getCookie();
        $count = $this->appConfig['fetch_options']['count'] ?? 10;

        $params = [
            'sub' => 'list',
            'search_field' => 'null',
            'begin' => 0,
            'count' => $count,
            'query' => '',
            'fakeid' => $fakeId,
            'type' => '101_1', // 基于对 Python 项目的分析
            'free_publish_type' => 1,
            'sub_action' => 'list_ex',
            'token' => $token,
            'lang' => 'zh_CN',
            'f' => 'json',
            'ajax' => 1,
        ];

        $response = $this->httpClient->get('/cgi-bin/appmsgpublish', $params, ['Cookie' => $cookie]);

        if ($response === null) {
            // HttpClient already logs cURL/JSON errors, but we add context here
            $this->logError("Failed to fetch articles for {$accountName} ({$fakeId}): HTTP request failed or non-JSON/null response from HttpClient.");
            return null;
        }

        if (isset($response['base_resp']['err_msg']) && strtolower($response['base_resp']['err_msg']) !== 'ok') {
            $errorMsg = $response['base_resp']['err_msg'];
            $this->logError("WeChat API error for {$accountName} ({$fakeId}): " . $errorMsg);
            if (in_array(strtolower($errorMsg), ['invalid session', 'invalid csrf token', 'not login'])) {
                $this->logError("Credentials may have expired or are invalid. Please re-run trigger_login.php.");
            }
            return null;
        }

        if (!isset($response['publish_page'])) {
            $this->logError("WeChat API error for {$accountName} ({$fakeId}): 'publish_page' not found in response. Full response: " . json_encode($response));
            return null;
        }

        $publishPageData = json_decode($response['publish_page'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($publishPageData['publish_list'])) {
            $this->logError("Failed to decode 'publish_page' or 'publish_list' not found for {$accountName} ({$fakeId}). Publish page content: " . $response['publish_page']);
            return null;
        }

        $articles = [];
        foreach ($publishPageData['publish_list'] as $item) {
            if (!isset($item['publish_info'])) continue;
            $publishInfo = json_decode($item['publish_info'], true);
            if (json_last_error() !== JSON_ERROR_NONE || !isset($publishInfo['appmsgex'])) continue;

            foreach ($publishInfo['appmsgex'] as $articleData) {
                if (empty($articleData['link']) || empty($articleData['title'])) {
                    continue;
                }

                // 时间戳转换 (关键点!)
                // 假设 $articleData['create_time'] 是标准的Unix时间戳 (秒)
                // 如果它是Python项目中那种特殊的 "jstime"，这里的转换逻辑需要调整
                // $publishTimestamp = isset($articleData['create_time']) ? (int)$articleData['create_time'] : time();
                // TODO: 确认 create_time 的确切格式和转换逻辑 - 已确认为Unix时间戳，需转换为DATETIME格式字符串
                $unixTimestamp = isset($articleData['create_time']) ? (int)$articleData['create_time'] : time();
                $publishTimestamp = date('Y-m-d H:i:s', $unixTimestamp);

                $articles[] = [
                    'account_name' => $accountName, // 从参数传入，方便记录
                    // 'source_fakeid' => $fakeId, // Not in the current DB schema
                    'title' => $articleData['title'],
                    'article_url' => $articleData['link'],
                    // 'cover_image_url' => $articleData['cover'] ?? null, // Not in the current DB schema / User decided to ignore for now
                    'publish_timestamp' => $publishTimestamp, 
                    // 'raw_create_time' => $articleData['create_time'] ?? null, // 可选：存储原始时间值以供调试
                ];
            }
        }

        // 按发布时间戳倒序排序 (确保最旧的在后面，最新的在前面，如果API不能保证的话)
        // 微信返回的顺序通常是 publish_list 最新的推送在前，appmsgex 最新的文章也在前
        // 如果需要强制排序：
        // usort($articles, function($a, $b) {
        // return $b['publish_timestamp'] <=> $a['publish_timestamp'];
        // });

        $maxArticlesToKeep = $this->appConfig['fetch_options']['max_articles_per_fetch'] ?? 5; // 从配置读取或默认5

        if (count($articles) > $maxArticlesToKeep) {
            $articles = array_slice($articles, 0, $maxArticlesToKeep);
            // 使用 error_log 或自定义的 logInfo 方法，避免直接输出到 STDERR 除非确实是错误
            error_log("[ArticleFetcherService] Info: Article list for {$accountName} truncated to the latest {$maxArticlesToKeep} articles.");
        }

        return $articles;
    }
} 