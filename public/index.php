<?php
// PhpWechatAggregator/public/index.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/../vendor/autoload.php';

use App\Services\DatabaseService;
use App\Helpers\ConfigHelper; // 假设我们创建一个ConfigHelper来加载配置

// --- 配置加载 ---
// 为了避免在多个Web入口文件重复加载配置的逻辑，可以考虑创建一个简单的ConfigHelper
// 但为了快速开始，我们直接在这里加载
$basePath = dirname(__DIR__); // PhpWechatAggregator 目录

$configDir = $basePath . '/config';
$appConfigPath = $configDir . '/app.php';
$dbConfigPath = $configDir . '/database.php';

if (!file_exists($appConfigPath) || !file_exists($dbConfigPath)) {
    die("错误: 配置文件未找到。请确保 config/app.php 和 config/database.php 存在。");
}

$appConfig = require $appConfigPath;
$dbConfig = require $dbConfigPath;

// --- 服务初始化 ---
$dbService = null;
try {
    $dbService = new DatabaseService($dbConfig);
    if ($dbService->getConnection() === null) {
        throw new Exception("数据库连接失败，请检查配置。");
    }
} catch (\PDOException $e) {
    die("数据库连接错误: " . $e->getMessage());
} catch (\Exception $e) {
    die("初始化错误: " . $e->getMessage());
}

// --- 获取文章 ---
$articles = [];
$fetchError = '';
try {
    // 简单获取最近20篇文章，按发布时间倒序
    // 注意: DatabaseService 中尚未有直接获取多篇文章的方法，我们需要添加或临时实现
    $stmt = $dbService->getConnection()->query("SELECT account_name, title, article_url, publish_timestamp FROM wechat_articles ORDER BY publish_timestamp DESC LIMIT 20");
    if ($stmt) {
        $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $fetchError = "无法从数据库获取文章。";
    }
} catch (\PDOException $e) {
    $fetchError = "数据库查询错误: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>微信文章聚合器</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #f4f4f4; color: #333; }
        .container { background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1, h2 { color: #333; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; border: 1px solid #ddd; text-align: left; }
        th { background-color: #e9e9e9; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        tr:hover { background-color: #f1f1f1; }
        a { color: #007bff; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .error { color: red; font-weight: bold; }
        .empty-message { font-style: italic; color: #777; }
        .actions { margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .actions button, .dropdown button { padding: 10px 15px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; }
        .actions button:hover, .dropdown button:hover { background-color: #0056b3; }
        
        /* Dropdown styles */
        .dropdown { position: relative; display: inline-block; }
        .dropdown-content { display: none; position: absolute; background-color: #f9f9f9; min-width: 160px; box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2); z-index: 1; border-radius: 4px; }
        .dropdown-content a, .dropdown-content button { color: black; padding: 12px 16px; text-decoration: none; display: block; width: 100%; text-align: left; border: none; background: none; cursor: pointer; }
        .dropdown-content a:hover, .dropdown-content button:hover { background-color: #f1f1f1; }
        .dropdown:hover .dropdown-content { display: block; }
        .dropdown button.dropdown-toggle { display: flex; align-items: center; }
        .dropdown button.dropdown-toggle::after { content: ' ▼'; font-size: 0.7em; margin-left: 5px; }

        #status-message { margin-top: 15px; padding: 10px; border-radius: 4px; display: none; }
        #status-message.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        #status-message.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        #status-message.info { background-color: #e7f3fe; color: #0c5460; border: 1px solid #b8daff; }

        /* Modal Styles */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4); }
        .modal-content { background-color: #fefefe; margin: 10% auto; padding: 25px; border: 1px solid #888; width: 80%; max-width: 500px; border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); }
        .modal-header { padding-bottom: 15px; border-bottom: 1px solid #eee; margin-bottom: 20px;}
        .modal-header h3 { margin: 0; font-size: 1.5em;}
        .modal-body { margin-bottom: 20px; }
        .modal-footer { padding-top: 15px; border-top: 1px solid #eee; text-align: right; }
        .modal-footer button { padding: 10px 18px; margin-left: 10px; border-radius: 4px; cursor: pointer; }
        .modal-footer button.primary { background-color: #007bff; color: white; border: 1px solid #007bff; }
        .modal-footer button.primary:hover { background-color: #0056b3; }
        .modal-footer button.secondary { background-color: #6c757d; color: white; border: 1px solid #6c757d; }
        .modal-footer button.secondary:hover { background-color: #5a6268; }
        .close-btn { color: #aaa; float: right; font-size: 28px; font-weight: bold; line-height: 1; }
        .close-btn:hover, .close-btn:focus { color: black; text-decoration: none; cursor: pointer; }
        
        .form-group { margin-bottom: 15px; text-align: left; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input[type=\"checkbox\"], .form-group input[type=\"time\"], .form-group select { width: calc(100% - 22px); padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        .form-group input[type=\"checkbox\"] { width: auto; vertical-align: middle; margin-right: 5px; }
        .form-group .inline-label { font-weight: normal; }
    </style>
</head>
<body>
    <div class="container">
        <h1>微信文章聚合器</h1>

        <div class="actions">
            <h2>操作</h2>
            <div class="dropdown">
                <button class="dropdown-toggle" id="fetch-settings-btn">抓取设置</button>
                <div class="dropdown-content" id="fetch-settings-dropdown">
                    <button id="trigger-immediate-fetch">立即抓取</button>
                    <button id="open-schedule-modal-btn">定期抓取</button>
                </div>
            </div>
            <button onclick="triggerLogin()">更新凭据 (扫码登录)</button>
            <button onclick="location.href='add_account.php'">添加公众号</button>
        </div>
        <div id="status-message"></div>

        <!-- Modal for Schedule Settings -->
        <div id="schedule-modal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <span class="close-btn" id="close-schedule-modal-btn">&times;</span>
                    <h3>定期抓取设置</h3>
                </div>
                <div class="modal-body">
                    <form id="schedule-form">
                        <div class="form-group">
                            <input type="checkbox" id="schedule-enabled" name="enabled">
                            <label for="schedule-enabled" class="inline-label">启用定期抓取</label>
                        </div>
                        <div class="form-group">
                            <label for="schedule-period">抓取周期:</label>
                            <select id="schedule-period" name="period">
                                <option value="daily">每天</option>
                                <option value="n_days">N天</option>
                                <option value="weekly">每周</option>
                                <option value="monthly">每月</option>
                            </select>
                        </div>
                        <div class="form-group" id="schedule-n-value-group" style="display: none;">
                            <label for="schedule-n-value">每隔 (N) 天:</label>
                            <input type="number" id="schedule-n-value" name="n_value" min="1" value="1">
                        </div>
                        <div class="form-group" id="schedule-day-of-week-group" style="display: none;">
                            <label for="schedule-day-of-week">选择星期几:</label>
                            <select id="schedule-day-of-week" name="day_of_week">
                                <option value="1">星期一</option>
                                <option value="2">星期二</option>
                                <option value="3">星期三</option>
                                <option value="4">星期四</option>
                                <option value="5">星期五</option>
                                <option value="6">星期六</option>
                                <option value="7">星期日</option>
                            </select>
                        </div>
                        <div class="form-group" id="schedule-month-day-group" style="display: none;">
                            <label for="schedule-month-day">选择每月几号:</label>
                            <input type="number" id="schedule-month-day" name="month_day" min="1" value="1">
                        </div>
                        <div class="form-group">
                            <label for="schedule-time">抓取时间 (小时:分钟): <span style="font-weight:normal; font-size:0.9em;">(服务器当前: <span id="server-time-display">--:--</span>)</span></label>
                            <input type="time" id="schedule-time" name="time" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="secondary" id="cancel-schedule-btn">取消</button>
                    <button type="button" class="primary" id="save-schedule-btn">保存设置</button>
                </div>
            </div>
        </div>

        <h2>已聚合文章</h2>
        <?php if ($fetchError): ?>
            <p class="error"><?php echo htmlspecialchars($fetchError); ?></p>
        <?php endif; ?>

        <?php if (empty($articles) && !$fetchError): ?>
            <p class="empty-message">数据库中暂无文章。</p>
        <?php elseif (!empty($articles)): ?>
            <table>
                <thead>
                    <tr>
                        <th>公众号名称</th>
                        <th>文章标题</th>
                        <th>发布时间</th>
                        <th>原文链接</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($articles as $article): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($article['account_name']); ?></td>
                            <td><?php echo htmlspecialchars($article['title']); ?></td>
                            <td><?php echo htmlspecialchars($article['publish_timestamp']); ?></td>
                            <td><a href="<?php echo htmlspecialchars($article['article_url']); ?>" target="_blank">阅读原文</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <script>
        let statusMessageTimeoutId = null; 

        function showStatus(message, type = 'info', duration = 5000) {
            const statusElement = document.getElementById('status-message');
            
            if (statusMessageTimeoutId) {
                clearTimeout(statusMessageTimeoutId);
                statusMessageTimeoutId = null;
            }

            statusElement.textContent = message;
            statusElement.className = ''; 
            statusElement.classList.add(type); 
            statusElement.style.display = 'block';
            
            if (duration > 0) {
                statusMessageTimeoutId = setTimeout(() => {
                    statusElement.style.display = 'none';
                    statusElement.className = ''; 
                    statusMessageTimeoutId = null; 
                }, duration);
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const fetchSettingsBtn = document.getElementById('fetch-settings-btn');
            const fetchSettingsDropdown = document.getElementById('fetch-settings-dropdown');
            const immediateFetchButton = document.getElementById('trigger-immediate-fetch');
            
            const scheduleModal = document.getElementById('schedule-modal');
            const openScheduleModalBtn = document.getElementById('open-schedule-modal-btn');
            const closeScheduleModalBtn = document.getElementById('close-schedule-modal-btn');
            const cancelScheduleBtn = document.getElementById('cancel-schedule-btn');
            const saveScheduleBtn = document.getElementById('save-schedule-btn');
            const scheduleForm = document.getElementById('schedule-form');
            const schedulePeriodSelect = document.getElementById('schedule-period');
            const scheduleDayOfWeekGroup = document.getElementById('schedule-day-of-week-group');
            const scheduleNValueGroup = document.getElementById('schedule-n-value-group');
            const scheduleMonthDayGroup = document.getElementById('schedule-month-day-group');
            const statusElement = document.getElementById('status-message');

            // Function to fetch and display server time in the modal
            function displayServerTimeInModal() {
                const serverTimeElement = document.getElementById('server-time-display');
                if (!serverTimeElement) {
                    console.error("Element with ID 'server-time-display' not found.");
                    return;
                }
                serverTimeElement.textContent = '加载中...';

                fetch('action_handler.php?do=get_server_time')
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('网络响应错误，状态码: ' + response.status);
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success && data.currentTime) {
                            serverTimeElement.textContent = data.currentTime + (data.timezone ? ' (' + data.timezone + ')' : '');
                        } else {
                            serverTimeElement.textContent = '获取失败';
                            console.error('获取服务器时间失败:', data.message || '未知错误');
                        }
                    })
                    .catch(error => {
                        serverTimeElement.textContent = '获取错误';
                        console.error('请求服务器时间出错:', error);
                    });
            }

            // Dropdown logic
            fetchSettingsBtn.addEventListener('click', function(event) {
                event.stopPropagation();
                fetchSettingsDropdown.style.display = fetchSettingsDropdown.style.display === 'block' ? 'none' : 'block';
            });
            document.addEventListener('click', function(event) {
                if (!fetchSettingsBtn.contains(event.target) && !fetchSettingsDropdown.contains(event.target)) {
                    fetchSettingsDropdown.style.display = 'none';
                }
            });

            immediateFetchButton.addEventListener('click', function() {
                fetchSettingsDropdown.style.display = 'none';
                
                if (statusMessageTimeoutId) {
                    clearTimeout(statusMessageTimeoutId);
                    statusMessageTimeoutId = null;
                }
                statusElement.textContent = '正在触发抓取，请稍候...';
                statusElement.className = 'info';
                statusElement.style.display = 'block';

                fetch('action_handler.php?do=immediate_fetch', {
                    method: 'POST', 
                    headers: { 'Content-Type': 'application/json' }
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('网络响应错误: ' + response.statusText);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        showStatus(data.message || '抓取任务已成功完成。', 'success', 0);
                    } else {
                        showStatus('抓取失败: ' + (data.message || '未知错误'), 'error', 0);
                    }
                })
                .catch(error => {
                    showStatus('抓取请求失败: ' + error.message, 'error', 0);
                });
            });

            // Modal open/close logic
            openScheduleModalBtn.addEventListener('click', function() {
                fetchSettingsDropdown.style.display = 'none';
                loadScheduleSettings();
                displayServerTimeInModal(); // Fetch and display server time
                scheduleModal.style.display = 'block';
            });
            closeScheduleModalBtn.addEventListener('click', function() { scheduleModal.style.display = 'none'; });
            cancelScheduleBtn.addEventListener('click', function() { scheduleModal.style.display = 'none'; });
            window.addEventListener('click', function(event) {
                if (event.target == scheduleModal) { scheduleModal.style.display = 'none'; }
            });

            // Show/hide day_of_week based on period selection
            schedulePeriodSelect.addEventListener('change', function() {
                const period = this.value;
                scheduleDayOfWeekGroup.style.display = 'none';
                scheduleNValueGroup.style.display = 'none';
                scheduleMonthDayGroup.style.display = 'none';

                if (period === 'n_days') {
                    scheduleNValueGroup.style.display = 'block';
                } else if (period === 'weekly') {
                    scheduleDayOfWeekGroup.style.display = 'block';
                } else if (period === 'monthly') {
                    scheduleMonthDayGroup.style.display = 'block';
                }
            });

            // Load existing schedule settings into the modal form
            function loadScheduleSettings() {
                showStatus('正在加载当前定期抓取设置...', 'info', 5000);
                fetch('action_handler.php?do=get_schedule_settings')
                .then(response => {
                    if (!response.ok) { throw new Error('无法加载配置: ' + response.statusText); }
                    return response.json();
                })
                .then(data => {
                    if (data.success && data.settings) {
                        document.getElementById('schedule-enabled').checked = data.settings.enabled || false;
                        const period = data.settings.period || 'daily';
                        document.getElementById('schedule-period').value = period;
                        document.getElementById('schedule-time').value = data.settings.time || '03:00';
                        
                        // Trigger change to show correct extra fields
                        schedulePeriodSelect.dispatchEvent(new Event('change'));

                        if (period === 'n_days') {
                            document.getElementById('schedule-n-value').value = data.settings.n_value || 1;
                        } else if (period === 'weekly') {
                            document.getElementById('schedule-day-of-week').value = data.settings.day_of_week || '1';
                        } else if (period === 'monthly') {
                            document.getElementById('schedule-month-day').value = data.settings.month_day || 1;
                        }
                        showStatus('设置已加载。', 'success');
                    } else {
                        // Defaults if no settings found or error
                        document.getElementById('schedule-enabled').checked = false;
                        document.getElementById('schedule-period').value = 'daily';
                        document.getElementById('schedule-time').value = '03:00';
                        schedulePeriodSelect.dispatchEvent(new Event('change')); // Ensure correct fields shown for default
                        if(data.message) showStatus('加载设置失败: ' + data.message, 'error');
                        else showStatus('未找到现有设置，已载入默认值。', 'info');
                    }
                })
                .catch(error => {
                    console.error('Error loading schedule settings:', error);
                    showStatus('加载配置时出错: ' + error.message, 'error');
                    document.getElementById('schedule-enabled').checked = false;
                    document.getElementById('schedule-period').value = 'daily';
                    document.getElementById('schedule-time').value = '03:00';
                    schedulePeriodSelect.dispatchEvent(new Event('change')); // Ensure correct fields shown for default
                });
            }

            // Save schedule settings
            saveScheduleBtn.addEventListener('click', function() {
                const periodValue = document.getElementById('schedule-period').value;
                const settings = {
                    enabled: document.getElementById('schedule-enabled').checked,
                    period: periodValue,
                    n_value: null,
                    day_of_week: null,
                    month_day: null,
                    time: document.getElementById('schedule-time').value
                };

                if (periodValue === 'n_days') {
                    settings.n_value = parseInt(document.getElementById('schedule-n-value').value, 10) || 1;
                } else if (periodValue === 'weekly') {
                    settings.day_of_week = document.getElementById('schedule-day-of-week').value;
                } else if (periodValue === 'monthly') {
                    settings.month_day = parseInt(document.getElementById('schedule-month-day').value, 10) || 1;
                }

                showStatus('正在保存设置...', 'info');
                fetch('action_handler.php?do=save_schedule_settings', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(settings)
                })
                .then(response => {
                    if (!response.ok) { throw new Error('保存失败: ' + response.statusText); }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        showStatus(data.message || '设置已成功保存。', 'success');
                        scheduleModal.style.display = 'none';
                    } else {
                        showStatus('保存设置失败: ' + (data.message || '未知错误'), 'error');
                    }
                })
                .catch(error => {
                    console.error('Error saving schedule settings:', error);
                    showStatus('保存配置时出错: ' + error.message, 'error');
                });
            });

        });

        function triggerLogin() {
            showStatus('正在准备登录流程，请稍候...', 'info', 5000);
            window.location.href = 'trigger_login_page.php';
        }

    </script>
</body>
</html> 