<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

use App\Helpers\HttpClient;
use App\Services\DatabaseService;

// --- 配置加载 ---
$configDir = $basePath . '/config';
$dbConfigPath = $configDir . '/database.php';

if (!file_exists($dbConfigPath)) {
    die("错误: 数据库配置文件 (config/database.php) 未找到。"); 
}
$dbConfig = require $dbConfigPath;

// Basic message handling (will be improved)
$message = '';
$message_type = ''; // 'success', 'error', or 'info'

// Retain form values
$submitted_url = $_POST['article_url'] ?? '';
$submitted_cookies = $_POST['cookies'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $article_url = trim($submitted_url);
    $cookies = trim($submitted_cookies);

    if (empty($article_url) || !filter_var($article_url, FILTER_VALIDATE_URL)) {
        $message = '错误：请输入一个有效的文章URL。';
        $message_type = 'error';
    } else {
        try {
            $dbService = new DatabaseService($dbConfig);
            if ($dbService->getConnection() === null) {
                throw new Exception("数据库连接失败，请检查配置 (config/database.php)。");
            }

            $httpClient = new HttpClient();
            
            $requestHeaders = [];
            if (!empty($cookies)) {
                $requestHeaders['Cookie'] = $cookies;
            }
            $requestHeaders['User-Agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';

            error_log("[Manual Add] Attempting to fetch: {$article_url} with cookies: " . (!empty($cookies) ? 'Yes' : 'No'));

            $htmlContent = $httpClient->getRaw($article_url, $requestHeaders);

            if ($htmlContent === null || empty(trim($htmlContent))) {
                error_log("[Manual Add] Failed to fetch content for: {$article_url}. Check cURL errors in log if any were produced by HttpClient.");
                throw new Exception("无法获取文章内容。请检查URL是否正确，Cookie是否有效（如果需要登录），或目标服务器是否阻止了请求。查看应用错误日志获取更多cURL详情。");
            }

            $doc = new DOMDocument();
            libxml_use_internal_errors(true);
            if (!$doc->loadHTML('<?xml encoding="utf-8" ?>' . $htmlContent)) {
                libxml_clear_errors();
                error_log("[Manual Add] Successfully fetched content but failed to parse HTML for URL: {$article_url}. Page structure might be invalid or very malformed.");
                throw new Exception("成功获取内容，但解析HTML时失败。页面结构可能无效。");
            }
            libxml_clear_errors();

            $xpath = new DOMXPath($doc);

            $title = '';
            $titleQueries = [
                '//title/text()',
                '//meta[@property="og:title"]/@content',
                '//meta[@name="twitter:title"]/@content',
                '//h1[1]/text()',
                '(//h1)[1]/text()', // More robust way to get first h1
                '//h1[contains(@class, "title") or contains(@id, "title")]/text()'
            ];
            foreach ($titleQueries as $query) {
                $node = $xpath->query($query)->item(0);
                if ($node) {
                    $title = trim($node->nodeValue);
                    if (!empty($title)) break;
                }
            }
            if (empty($title)) {
                $title = '未知标题 - ' . strtok(basename($article_url), '?');
            }

            $publish_timestamp_str = date('Y-m-d H:i:s');
            $dateQueries = [
                '//meta[@property="article:published_time"]/@content',
                '//meta[@name="pubdate"]/@content',
                '//time/@datetime',
                '//meta[@name="DC.date.issued"]/@content', // Dublin Core date
                '//span[contains(@class, "date") or contains(@class, "time") or contains(@id, "date") or contains(@id, "time")]/text()',
                '//p[contains(@class, "date") or contains(@class, "time") or contains(@id, "date") or contains(@id, "time")]/text()'

            ];
            foreach ($dateQueries as $query) {
                $node = $xpath->query($query)->item(0);
                if ($node) {
                    $dateString = trim($node->nodeValue);
                    if (!empty($dateString)) {
                        $parsedDate = strtotime($dateString);
                        if ($parsedDate) {
                            $publish_timestamp_str = date('Y-m-d H:i:s', $parsedDate);
                            break;
                        }
                    }
                }
            }
            
            $parsedUrl = parse_url($article_url);
            $account_name = $parsedUrl['host'] ?? '外部来源';
            if (strpos(strtolower($account_name), 'www.') === 0) {
                $account_name = substr($account_name, 4);
            }
            
            $articleData = [
                'account_name'      => $account_name,
                'title'             => $title,
                'article_url'       => $article_url,
                'publish_timestamp' => $publish_timestamp_str,
            ];

            if ($dbService->insertArticle($articleData, 'forum_manual')) {
                $message = '文章 "'. htmlspecialchars($title) . '" 已成功添加！';
                $message_type = 'success';
                $submitted_url = ''; // Clear form on success
                $submitted_cookies = '';
            } else {
                if ($dbService->getArticleByUrl($article_url)) {
                     $message = '信息：文章 "' . htmlspecialchars($title) . '" 已存在于数据库中 (基于URL)。';
                     $message_type = 'info';
                } else {
                    error_log("[Manual Add] insertArticle returned false for {$article_url} but not found by getArticleByUrl. This might indicate a DB-level constraint violation not caught, or other PDO error during execute that wasn't an exception.");
                    throw new Exception("无法将文章插入数据库。可能已存在但未通过URL检查捕获，或发生其他数据库错误。请检查应用错误日志。");
                }
            }

        } catch (\PDOException $e) {
            error_log("[Manual Add] PDOException: " . $e->getMessage() . " for URL: " . $article_url . " SQLState: " . $e->getCode());
            $message = "数据库操作失败。详情请查看应用错误日志。";
            $message_type = 'error';
        } catch (\Exception $e) {
            error_log("[Manual Add] Exception: " . $e->getMessage() . " for URL: " . $article_url);
            $message = "发生错误: " . $e->getMessage();
            $message_type = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>添加外部文章</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; margin: 20px; background-color: #f8f9fa; color: #212529; line-height: 1.6; }
        .container { background-color: #ffffff; padding: 25px 30px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); max-width: 750px; margin: 30px auto; }
        h1 { color: #343a40; text-align: center; margin-bottom: 25px; font-weight: 500; }
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #495057; }
        input[type="url"], textarea {
            width: 100%;
            padding: 12px 15px;
            margin-bottom: 20px;
            border: 1px solid #ced4da;
            border-radius: 5px;
            box-sizing: border-box;
            font-size: 1rem;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }
        input[type="url"]:focus, textarea:focus {
            border-color: #80bdff;
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }
        textarea { min-height: 120px; resize: vertical; }
        button[type="submit"] {
            background-color: #007bff;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1.05rem;
            transition: background-color 0.15s ease-in-out;
            display: block;
            width: 100%;
        }
        button[type="submit"]:hover { background-color: #0056b3; }
        .message {
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: 5px;
            font-size: 0.95rem;
            border: 1px solid transparent;
        }
        .message.success { background-color: #d1e7dd; color: #0f5132; border-color: #badbcc; }
        .message.error { background-color: #f8d7da; color: #842029; border-color: #f5c2c7; }
        .message.info { background-color: #cff4fc; color: #055160; border-color: #b6effb; }
        .instructions { margin-bottom:25px; padding: 15px; background-color: #e9ecef; border-left: 4px solid #007bff; font-size: 0.9rem; color: #495057;}
        .instructions strong { color: #343a40; }
        .instructions ol { padding-left: 20px; margin-top: 10px;}
        .instructions code { background-color: #e0e0e0; padding: 2px 4px; border-radius:3px; font-family: SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;}
    </style>
</head>
<body>
    <div class="container">
        <h1>添加外部论坛文章</h1>

        <?php if ($message): ?>
            <div class="message <?php echo htmlspecialchars($message_type); ?>">
                <?php echo nl2br(htmlspecialchars($message)); ?>
            </div>
        <?php endif; ?>

        <div class="instructions">
            <p>请粘贴文章的完整URL以及该论坛对应的Cookie信息 (如果需要登录才能访问)。</p>
            <p><strong>如何获取Cookie (以Chrome为例):</strong></p>
            <ol>
                <li>在您的浏览器中登录目标论坛。</li>
                <li>打开开发者工具 (通常按 F12)。</li>
                <li>转到 "网络 (Network)" 标签页。</li>
                <li>刷新文章页面或导航至您想抓取的文章。</li>
                <li>在网络请求列表中，找到主文档请求 (通常是第一个，类型为 "document")。</li>
                <li>点击该请求，在右侧面板中选择 "标头 (Headers)" 标签页。</li>
                <li>向下滚动到 "请求标头 (Request Headers)" 部分。</li>
                <li>找到名为 <code>Cookie:</code> 的行，复制其完整的字符串值 (<code>Cookie:</code> 后面的所有内容)。</li>
            </ol>
        </div>

        <form action="add_manual_article.php" method="POST">
            <div>
                <label for="article_url">文章URL:</label>
                <input type="url" id="article_url" name="article_url" value="<?php echo htmlspecialchars($submitted_url); ?>" required placeholder="例如：https://forum.example.com/thread/12345">
            </div>
            <div>
                <label for="cookies">Cookie (可选):</label>
                <textarea id="cookies" name="cookies" placeholder="如果论坛需要登录才能访问，请粘贴Cookie字符串"><?php echo htmlspecialchars($submitted_cookies); ?></textarea>
            </div>
            <div>
                <button type="submit">提交文章</button>
            </div>
        </form>
    </div>
</body>
</html> 