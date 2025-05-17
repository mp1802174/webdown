<?php
// PhpWechatAggregator/public/trigger_login_page.php
$basePath = dirname(__DIR__);
$triggerLoginScriptPath = $basePath . '/trigger_login.php';
$configAppPath = $basePath . '/config/app.php';

$cliCommand = 'php ' . escapeshellarg($triggerLoginScriptPath);
$projectRoot = realpath($basePath);

$pageTitle = "更新凭据 (扫码登录)";

$statusMessage = '';
$statusType = ''; // success, error, info

// 简单的尝试: 检查凭据是否已存在 (这只是一个非常粗略的检查)
if (file_exists($configAppPath)) {
    $config = require $configAppPath;
    if (!empty($config['wechat_credentials']['token']) && !empty($config['wechat_credentials']['cookie'])) {
        $statusMessage = '系统中似乎已配置微信凭据。如果您遇到问题或需要刷新，请按以下步骤操作。';
        $statusType = 'info';
    }
}

?>
<!DOCTYPE html>
<html lang=\"zh-CN\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title><?php echo htmlspecialchars($pageTitle); ?> - 微信文章聚合器</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #f4f4f4; color: #333; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .container { background-color: #fff; padding: 30px 40px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); text-align: center; max-width: 600px; }
        h1 { color: #333; margin-bottom: 20px; }
        p { line-height: 1.6; margin-bottom: 15px; }
        .instructions { text-align: left; background-color: #f9f9f9; padding: 15px; border-radius: 5px; border: 1px solid #eee; margin-top: 20px; }
        .instructions strong { display: block; margin-bottom: 10px; font-size: 1.1em; }
        .instructions code { background-color: #e9e9e9; padding: 3px 6px; border-radius: 3px; font-family: monospace; }
        .button-container { margin-top: 30px; }
        .button-container a { padding: 12px 25px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 1em; }
        .button-container a:hover { background-color: #0056b3; }
        .status-message { margin-bottom: 20px; padding: 10px; border-radius: 4px; text-align: left;}
        .status-message.info { background-color: #e7f3fe; color: #0c5460; border: 1px solid #b8daff; }
        .status-message.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .status-message.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>
    <div class=\"container\">
        <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
        
        <?php if ($statusMessage): ?>
            <div class=\"status-message <?php echo htmlspecialchars($statusType); ?>\">
                <?php echo htmlspecialchars($statusMessage); ?>
            </div>
        <?php endif; ?>

        <p>为了更新用于抓取微信公众号文章的凭据（Token 和 Cookie），您需要通过扫描二维码的方式登录微信公众平台。</p>
        <p>此过程是通过一个命令行脚本完成的。</p>

        <div class=\"instructions\">
            <strong>请按照以下步骤操作：</strong>
            <ol>
                <li>打开您服务器的命令行界面（例如 SSH 终端，或者 Windows 上的 PowerShell/CMD）。</li>
                <li>导航到本项目的根目录。如果您不确定路径，它通常是：<br><code><?php echo htmlspecialchars($projectRoot); ?></code></li>
                <li>在项目根目录下，运行以下命令：<br><code><?php echo htmlspecialchars($cliCommand); ?></code></li>
                <li>脚本运行后，会生成一个二维码。请使用您的微信扫描此二维码并确认登录。</li>
                <li>成功登录后，脚本会自动将获取到的新凭据更新到 <code>config/app.php</code> 文件中。</li>
            </ol>
        </div>

        <div class=\"button-container\">
            <a href=\"index.php\">返回主页</a>
        </div>
    </div>
</body>
</html> 