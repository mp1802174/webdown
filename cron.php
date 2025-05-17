<?php

// PhpWechatAggregator/cron.php

require __DIR__ . '/vendor/autoload.php'; // 假设 vendor 目录与 cron.php 同级或能被正确解析

use App\Services\WechatAuthService;
use App\Services\ArticleFetcherService;
use App\Services\DatabaseService;
use App\Helpers\HttpClient;

// --- 配置加载 ---
$basePath = __DIR__;
$configDir = $basePath . '/config';
$dataDir = $basePath . '/data'; // Define data directory
$appConfigPath = $configDir . '/app.php';
$dbConfigPath = $configDir . '/database.php';
$scheduleConfigFile = $configDir . '/schedule.json';
$statusFilePath = $dataDir . '/schedule_status.json'; // Path for the status file

function log_message($message) {
    global $dataDir; 

    $logFile = $dataDir . '/cron_execution.log';
    $formattedMessage = "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL;

    // 确保 data 目录存在
    if (!is_dir($dataDir)) {
        if (!mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
            echo "[" . date('Y-m-d H:i:s') . "] CRITICAL: Failed to create data directory for logs: " . $dataDir . PHP_EOL;
            // Fallback to echo if directory creation fails for the log itself
        }
    }

    // 使用 FILE_APPEND 来追加内容， LOCK_EX 来防止并发写入问题
    if (file_put_contents($logFile, $formattedMessage, FILE_APPEND | LOCK_EX) === false) {
        echo "[" . date('Y-m-d H:i:s') . "] CRITICAL: Failed to write to log file: " . $logFile . PHP_EOL;
    }
    
    // 仍然 echo 一份，方便直接从命令行执行时查看
    echo $formattedMessage;
}

// Helper function to load schedule status
function load_schedule_status($filePath) {
    if (!file_exists($filePath)) {
        return []; // Return empty array if file doesn't exist
    }
    $statusJson = file_get_contents($filePath);
    if ($statusJson === false) {
        log_message("Warning: Could not read status file: " . $filePath);
        return [];
    }
    $statusData = json_decode($statusJson, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        log_message("Warning: Could not parse JSON from status file (" . $filePath . "): " . json_last_error_msg());
        return []; // Return empty on decode error
    }
    return $statusData;
}

// Helper function to save schedule status
function save_schedule_status($filePath, $statusData) {
    global $dataDir;
    if (!is_dir($dataDir)) {
        if (!mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
            log_message("Error: Failed to create data directory: " . $dataDir . ". Check permissions.");
            return false;
        }
    }
    $statusJson = json_encode($statusData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (file_put_contents($filePath, $statusJson) === false) {
        log_message("Error: Could not write to status file: " . $filePath . ". Check permissions.");
        return false;
    }
    return true;
}

if (!file_exists($appConfigPath) || !file_exists($dbConfigPath)) {
    log_message("Error: Core configuration files not found. Ensure config/app.php and config/database.php exist.");
    exit(1);
}

$appConfig = require $appConfigPath;
$dbConfig = require $dbConfigPath;

// --- 定期抓取逻辑检查 ---
$scheduleSettings = null;
if (file_exists($scheduleConfigFile)) {
    $scheduleJson = file_get_contents($scheduleConfigFile);
    $scheduleSettings = json_decode($scheduleJson, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        log_message("Warning: Could not parse schedule.json: " . json_last_error_msg() . ". Running as if not scheduled.");
        $scheduleSettings = null; // Treat as not scheduled if config is broken
    }
} else {
    log_message("Info: schedule.json not found. Running script without schedule checks (always run if called).");
    // If no schedule file, assume direct execution is intended, so we proceed.
}

$shouldExecuteBasedOnSchedule = false;
$triggeredBySchedule = false; // Flag to indicate if execution is due to schedule settings

if ($scheduleSettings !== null) {
    $triggeredBySchedule = true; // If schedule settings are present, we are evaluating based on them
    if (!isset($scheduleSettings['enabled']) || $scheduleSettings['enabled'] !== true) {
        log_message("Info: Scheduled fetching is disabled in schedule.json. Exiting.");
        exit(0);
    }

    // Timezone should be consistent with what the user expects when setting the time
    // PHP default timezone can be set in php.ini or here if needed.
    // date_default_timezone_set('Asia/Shanghai'); 
    $currentTime = new DateTime();
    $scheduledTimeStr = $scheduleSettings['time'] ?? '03:00'; // HH:MM
    
    try {
        list($scheduledHour, $scheduledMinute) = explode(':', $scheduledTimeStr);
        $executeToday = false;

        $currentHour = (int)$currentTime->format('H');
        $currentMinute = (int)$currentTime->format('i');
        $scheduledHour = (int)$scheduledHour;
        $scheduledMinute = (int)$scheduledMinute;

        // Check if current time is around scheduled time (e.g., within a 5-minute window after scheduled time)
        $timeMatches = false;
        if ($currentHour === $scheduledHour && $currentMinute >= $scheduledMinute && $currentMinute < ($scheduledMinute + 5)) {
             // Allow a small window, e.g. cron runs at 03:00, script runs at 03:00:xx to 03:04:xx
            $timeMatches = true;
        }

        if (!$timeMatches) {
            log_message("Info: Current time (" . $currentTime->format('H:i') . ") does not match scheduled time window (" . $scheduledTimeStr . " - " . sprintf('%02d:%02d', $scheduledHour, $scheduledMinute + 4) . "). Exiting.");
            exit(0);
        }
        
        // Proceed with period check only if time matches
        $period = $scheduleSettings['period'] ?? 'daily';

        if ($period === 'daily') {
            $executeToday = true;
        } elseif ($period === 'weekly') {
            $scheduledDayOfWeek = $scheduleSettings['day_of_week'] ?? '1'; // 1 (Mon) - 7 (Sun)
            $currentDayOfWeek = $currentTime->format('N'); // N gives 1 (Mon) to 7 (Sun)
            if ($currentDayOfWeek === $scheduledDayOfWeek) {
                $executeToday = true;
            }
        } elseif ($period === 'monthly') {
            $scheduledMonthDay = (int)($scheduleSettings['month_day'] ?? 1);
            $currentMonthDay = (int)$currentTime->format('j');
            if ($currentMonthDay === $scheduledMonthDay) {
                $executeToday = true;
            }
        } elseif ($period === 'n_days') {
            $nValue = (int)($scheduleSettings['n_value'] ?? 1);
            if ($nValue <= 0) $nValue = 1; // Ensure n_value is positive

            $statusData = load_schedule_status($statusFilePath);
            $lastRunDateStr = $statusData['n_days_last_run'] ?? null;
            
            log_message("Info: N-Days check (every {$nValue} days) - Last run: " . ($lastRunDateStr ?: 'Never'));

            if (!$lastRunDateStr) {
                $executeToday = true; // First run
                log_message("Info: N-Days - No last run date found, executing for the first time.");
            } else {
                try {
                    $lastRunDate = new DateTime($lastRunDateStr);
                    $interval = $currentTime->diff($lastRunDate);
                    $daysDifference = $interval->days;
                    log_message("Info: N-Days - Days since last run: {$daysDifference}.");
                    if ($daysDifference >= $nValue) {
                        $executeToday = true;
                    }
                } catch (Exception $e) {
                    log_message("Warning: N-Days - Error parsing last run date '{$lastRunDateStr}': " . $e->getMessage() . ". Assuming first run.");
                    $executeToday = true; // If date is invalid, allow execution
                }
            }
        }

        if ($executeToday) {
            // Basic lock mechanism to prevent multiple executions in the same minute/window
            $lockFilePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'phwechat_cron.lock';
            $lockFileDate = @file_get_contents($lockFilePath);
            $currentRunSignature = $currentTime->format('Y-m-d_H'); // Lock per hour for a given schedule
            if ($scheduleSettings['period'] === 'daily') {
                 $currentRunSignature = $currentTime->format('Y-m-d');
            } elseif ($scheduleSettings['period'] === 'weekly'){
                 $currentRunSignature = $currentTime->format('Y-W'); // Lock per week number for weekly schedule
            }
            // For every_x_days, the day check is the primary guard.
            // The time window check already narrows execution significantly.
            // A more robust lock would consider the specific schedule (period, time, day_of_week)
            
            $logIdentifier = $period . '@' . $scheduledTimeStr;
            if ($period === 'weekly' && isset($scheduleSettings['day_of_week'])) {
                $logIdentifier .= '_day' . $scheduleSettings['day_of_week'];
            } elseif ($period === 'monthly' && isset($scheduleSettings['month_day'])) {
                $logIdentifier .= '_date' . $scheduleSettings['month_day'];
            }
            $lastRunLogFile = $dataDir . '/last_run_' . preg_replace('/[^a-z0-9_]/i', '_', $logIdentifier) . '.log';

            if (file_exists($lastRunLogFile)) {
                $lastRunTimestamp = file_get_contents($lastRunLogFile);
                // Check if already run today (for daily) or this week (for weekly) within the same scheduled hour slot
                $todayDate = $currentTime->format('Y-m-d');
                $lastRunDate = date('Y-m-d', (int)$lastRunTimestamp);
                $currentHourVal = (int)$currentTime->format('H');
                $lastRunHourVal = (int)date('H', (int)$lastRunTimestamp);

                if ($lastRunDate === $todayDate && $lastRunHourVal === $currentHourVal) {
                    log_message("Info: Scheduled task for '{$logIdentifier}' already ran today within this hour slot ({$currentHourVal}:00-{$currentHourVal}:59). Exiting to prevent re-run.");
                    exit(0);
                }
            }

            // Create/Update log file before execution
            if (!is_dir($dataDir)) mkdir($dataDir, 0755, true);
            file_put_contents($lastRunLogFile, time());
            log_message("Info: Conditions met for period '{$period}' at scheduled time. Proceeding with article fetching.");
            $shouldExecuteBasedOnSchedule = true;

        } else {
            log_message("Info: Conditions not met for period '{$period}' or it's not the scheduled day. Exiting.");
            exit(0);
        }

    } catch (\Exception $e) {
        log_message("Error during schedule time parsing or check: " . $e->getMessage() . ". Exiting.");
        exit(1);
    }
} else {
    log_message("Info: No valid schedule settings found in schedule.json or file missing. Script will run now (assuming direct execution).");
}

if (!$shouldExecuteBasedOnSchedule && $triggeredBySchedule) {
    // This case should ideally not be reached if logic above is correct and exits, but as a safeguard:
    log_message("Info: Script execution was not warranted by schedule settings. Exiting.");
    exit(0);
}

// --- 服务初始化 ---
log_message("Initializing services...");
$authService = new WechatAuthService($appConfig);
$httpClient = new HttpClient('https://mp.weixin.qq.com'); // 设置API基础URL
$articleFetcher = new ArticleFetcherService($httpClient, $authService, $appConfig);
$dbService = new DatabaseService($dbConfig);

// 检查数据库连接
if ($dbService->getConnection() === null) {
    log_message("Error: Failed to connect to the database. Check your config/database.php and MySQL server.");
    exit(1);
}
log_message("Database connection successful.");

// --- 凭证检查 ---
log_message("Checking WeChat credentials...");
if (!$authService->areCredentialsProvided()) {
    log_message("Error: WeChat token or cookie is not configured in config/app.php.");
    exit(1);
}
// MVP阶段，areCredentialsValid 仅检查是否配置，实际的API调用错误会在拉取时体现
if (!$authService->areCredentialsValid($httpClient)) { // 传递httpClient用于将来实际的API验证
    log_message("Error: WeChat credentials configured but seem to be invalid (actual validation pending API call).");
    // In a cron, we might not want to die here, but let ArticleFetcherService handle it and log API errors.
    // exit(1); // Or proceed and let fetch fail
}
log_message("WeChat credentials seem okay (basic check passed).");

// --- 拉取和存储文章 ---
$officialAccounts = $appConfig['official_accounts'] ?? [];
if (empty($officialAccounts)) {
    log_message("No official accounts configured in config/app.php. Exiting.");
    exit(0);
}

log_message("Starting article fetching process for " . count($officialAccounts) . " account(s)...");
$totalNewArticles = 0;
$accountsProcessed = 0;
$errorsInFetch = [];
$allFetchesSuccessful = true; // Assume success initially

foreach ($officialAccounts as $account) {
    $accountName = $account['account_name'] ?? 'Unknown Account';
    $fakeId = $account['fakeid'] ?? null;

    if (!$fakeId) {
        log_message("Skipping account '{$accountName}': fakeid is missing.");
        $errorsInFetch[] = "Account '{$accountName}' missing fakeid.";
        $allFetchesSuccessful = false; // Mark as not entirely successful
        continue;
    }
    $currentAccountProcessed = false; // Flag for current account

    log_message("Fetching articles for: {$accountName} (FakeID: {$fakeId})...");
    $articles = $articleFetcher->fetchArticles($fakeId, $accountName);

    if ($articles === null) {
        log_message("Failed to fetch articles for {$accountName} or no new articles found.");
        $errorsInFetch[] = "Failed to fetch articles for {$accountName}.";
        // Detailed error should have been logged by ArticleFetcherService to STDERR
        // $accountsProcessed++; // Do not count as successfully processed if fetch failed before returning articles
        $allFetchesSuccessful = false; 
        continue;
    }

    if (empty($articles)) {
        log_message("No new articles found for {$accountName}.");
        $accountsProcessed++; // Count as processed if API call was successful but no new articles
        $currentAccountProcessed = true;
        continue;
    }

    log_message("Fetched " . count($articles) . " potential articles for {$accountName}. Storing to database...");
    $newlyInsertedCount = 0;
    foreach ($articles as $articleData) {
        if ($dbService->insertArticle($articleData)) {
            $newlyInsertedCount++;
            $totalNewArticles++;
        }
    }
    log_message("Stored {$newlyInsertedCount} new articles for {$accountName}.");
    if ($newlyInsertedCount > 0 || $currentAccountProcessed) { // If articles were inserted OR if fetch was successful but no new articles
        $accountsProcessed++;
    }
}

log_message("--------------------------------------------------");
log_message("Article fetching process completed.");
log_message("Processed {$accountsProcessed} account(s).");
log_message("Total new articles stored: {$totalNewArticles}.");
if (!empty($errorsInFetch)) {
    log_message("Errors during fetch: " . implode("; ", $errorsInFetch));
}

// 可以考虑输出 DatabaseService::getCreateTableSql() 如果是首次运行或需要检查表结构
// echo "\nReminder: Ensure your 'articles' table exists. SQL to create:\n";
// echo DatabaseService::getCreateTableSql() . "\n";

// Update n_days_last_run if it was an n_days schedule and ran successfully
if ($triggeredBySchedule && isset($scheduleSettings['period']) && $scheduleSettings['period'] === 'n_days' && $shouldExecuteBasedOnSchedule) {
    // We should only update if the main fetch logic was at least attempted for all configured accounts
    // A more robust check would be if $accountsProcessed > 0 or $allFetchesSuccessful is true
    // For now, if it was scheduled as n_days and passed all checks to run, we update the date.
    if ($allFetchesSuccessful || $accountsProcessed > 0) { // Only update if something was actually processed or no errors for all.
        $statusData = load_schedule_status($statusFilePath);
        $statusData['n_days_last_run'] = $currentTime->format('Y-m-d'); // $currentTime was defined in schedule check block
        if (save_schedule_status($statusFilePath, $statusData)) {
            log_message("Info: N-Days - Successfully updated last run date to " . $currentTime->format('Y-m-d'));
        } else {
            log_message("Warning: N-Days - Failed to update last run date in status file.");
        }
    } else {
        log_message("Info: N-Days - Fetching process had errors or processed no accounts, not updating last run date to ensure re-try.");
    }
}

exit(0);
?> 