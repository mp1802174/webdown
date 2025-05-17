<?php
// PhpWechatAggregator/public/action_handler.php

ini_set('display_errors', 0); // Suppress normal output for JSON response
// For development, you might want to enable error display to browser:
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

header('Content-Type: application/json');

$basePath = dirname(__DIR__);
$configDir = $basePath . '/config';
$scheduleConfigFile = $configDir . '/schedule.json';

$response = ['success' => false, 'message' => '未知操作或无效请求'];

if (isset($_GET['do'])) {
    $action = $_GET['do'];

    if ($action === 'immediate_fetch') {
        ob_start();
        $message = '';
        $success = false;
        try {
            require_once $basePath . '/vendor/autoload.php';
            $appConfigPath = $configDir . '/app.php';
            $dbConfigPath = $configDir . '/database.php';

            if (!file_exists($appConfigPath) || !file_exists($dbConfigPath)) {
                throw new Exception("核心配置文件未找到。");
            }
            $appConfig = require $appConfigPath;
            $dbConfig = require $dbConfigPath;

            $authService = new \App\Services\WechatAuthService($appConfig);
            $httpClient = new \App\Helpers\HttpClient('https://mp.weixin.qq.com');
            $articleFetcher = new \App\Services\ArticleFetcherService($httpClient, $authService, $appConfig);
            $dbService = new \App\Services\DatabaseService($dbConfig);

            if ($dbService->getConnection() === null) throw new Exception("数据库连接失败。");
            if (!$authService->areCredentialsProvided()) throw new Exception("微信凭据未配置。");
            if (!$authService->areCredentialsValid()) throw new Exception("微信凭据无效 (基本检查失败)。");

            $officialAccounts = $appConfig['official_accounts'] ?? [];
            if (empty($officialAccounts)) {
                $message = "没有配置公众号进行抓取。";
                $success = true; 
            } else {
                $totalNewArticles = 0;
                $accountsProcessed = 0;
                $errorsOccurred = false;
                $errorMessages = [];
                foreach ($officialAccounts as $account) {
                    $accountName = $account['account_name'] ?? '未知账户';
                    $fakeId = $account['fakeid'] ?? null;
                    if (!$fakeId) {
                        $errorMessages[] = "账户 '{$accountName}' 缺少 fakeid。";
                        $errorsOccurred = true; continue;
                    }
                    $articles = $articleFetcher->fetchArticles($fakeId, $accountName);
                    if ($articles === null) {
                        $errorMessages[] = "为 {$accountName} 获取文章失败或未找到新文章。";
                        $errorsOccurred = true;
                    }
                    if (!empty($articles)) {
                        $newlyInsertedCount = 0;
                        foreach ($articles as $articleData) {
                            if ($dbService->insertArticle($articleData)) $newlyInsertedCount++;
                        }
                        $totalNewArticles += $newlyInsertedCount;
                    }
                    $accountsProcessed++;
                }
                if ($errorsOccurred) {
                    $errMsg = implode(" ", $errorMessages);
                    $message = ($totalNewArticles > 0) ? "部分账户处理失败: {$errMsg} 但成功存储了 {$totalNewArticles} 篇新文章。" : "所有账户处理失败: {$errMsg}";
                    $success = $totalNewArticles > 0;
                } else {
                    $message = "成功处理了 {$accountsProcessed} 个账户，共存储了 {$totalNewArticles} 篇新文章。";
                    $success = true;
                }
            }
        } catch (\PDOException $e) {
            $message = "数据库错误: " . $e->getMessage(); 
            $success = false;
        } catch (\Exception $e) {
            $message = "执行抓取时发生错误: " . $e->getMessage(); 
            $success = false;
        }
        ob_end_clean(); 
        $response = ['success' => $success, 'message' => $message];

    } elseif ($action === 'get_schedule_settings') {
        if (file_exists($scheduleConfigFile)) {
            $settingsJson = file_get_contents($scheduleConfigFile);
            $settings = json_decode($settingsJson, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $response = ['success' => true, 'settings' => $settings, 'message' => '设置已加载。'];
            } else {
                error_log("Error decoding schedule.json: " . json_last_error_msg());
                $response = ['success' => false, 'message' => '无法解析计划配置文件 (schedule.json)。文件可能已损坏。'];
            }
        } else {
            $defaultSettings = [
                'enabled' => false,
                'period' => 'daily',
                'day_of_week' => '1',
                'time' => '03:00'
            ];
            $response = ['success' => true, 'settings' => $defaultSettings, 'message' => '未找到现有设置文件，已返回默认值。'];
        }

    } elseif ($action === 'save_schedule_settings') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $inputJSON = file_get_contents('php://input');
            $input = json_decode($inputJSON, TRUE);

            if (json_last_error() === JSON_ERROR_NONE && $input !== null) {
                $settings = [
                    'enabled' => isset($input['enabled']) ? (bool)$input['enabled'] : false,
                    'period' => isset($input['period']) && in_array($input['period'], ['daily', 'every_two_days', 'every_three_days', 'weekly']) ? $input['period'] : 'daily',
                    'day_of_week' => isset($input['day_of_week']) && in_array($input['day_of_week'], ['1','2','3','4','5','6','7']) ? $input['day_of_week'] : '1',
                    'time' => isset($input['time']) && preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $input['time']) ? $input['time'] : '03:00',
                ];
                if ($settings['period'] !== 'weekly') {
                    $settings['day_of_week'] = null;
                }

                try {
                    if (!is_dir($configDir)) {
                        if (!mkdir($configDir, 0755, true) && !is_dir($configDir)) {
                            throw new \RuntimeException(sprintf('Directory "%s" was not created', $configDir));
                        }
                    }
                    if (file_put_contents($scheduleConfigFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
                        $response = ['success' => true, 'message' => '定期抓取设置已成功保存。'];
                    } else {
                        $response = ['success' => false, 'message' => '无法写入计划配置文件。请检查目录 (' . htmlspecialchars($configDir) . ') 的写入权限。'];
                    }
                } catch (\Exception $e) {
                    error_log("Error saving schedule settings: " . $e->getMessage());
                    $response = ['success' => false, 'message' => '保存设置时发生服务器错误。'];
                }
            } else {
                $response = ['success' => false, 'message' => '无效的输入数据或JSON格式错误。'];
            }
        } else {
            $response = ['success' => false, 'message' => '仅允许POST请求保存设置。'];
        }

    } elseif ($action === 'trigger_login_cli') {
        $triggerLoginScriptPath = $basePath . '/trigger_login.php';
        if (file_exists($triggerLoginScriptPath)) {
            $response = [
                'success' => true, 
                'message' => '请打开服务器的命令行/终端，导航到项目目录 (' . realpath($basePath) . ') 并运行以下命令来更新凭据: php trigger_login.php',
                'instructions' => 'php ' . escapeshellarg($triggerLoginScriptPath),
                'cwd' => realpath($basePath)
            ];
        } else {
            $response = ['success' => false, 'message' => '登录触发脚本 trigger_login.php 未找到。'];
        }
    } elseif ($action === 'get_server_time') {
        try {
            // 获取服务器当前时间，格式为 HH:MM
            $currentTime = date('H:i'); 
            // 获取服务器当前时区
            $timezone = date_default_timezone_get();
            $response = ['success' => true, 'currentTime' => $currentTime, 'timezone' => $timezone];
        } catch (\Exception $e) {
            // 虽然 date() 和 date_default_timezone_get() 不太可能抛出异常，但为了以防万一
            error_log("Error in get_server_time action: " . $e->getMessage());
            $response = ['success' => false, 'message' => '无法获取服务器时间: ' . $e->getMessage()];
        }
    }
    // else: $response remains ['success' => false, 'message' => '未知操作或无效请求']
}

echo json_encode($response);
?> 