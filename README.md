# PhpWechatAggregator (微信公众号文章聚合器)

一个PHP工具，用于聚合来自指定微信公众号的文章，并将它们存储到MySQL数据库中。
该项目结合使用了PHP和一个Python辅助脚本来实现其功能，包括通过自动化登录来获取凭据。

## 核心功能

1.  **自动化凭据管理**: 利用 `trigger_login.php` 和 `python_login_helper.py` 脚本自动化微信登录过程，获取必要的 `token` 和 `cookie`，并将其存储在 `config/app.php` 文件中。
2.  **文章聚合**: 使用获取到的凭据从配置的公众号列表中抓取最新的文章。
3.  **数据存储**: 将抓取的文章信息（如公众号名称、标题、文章链接、发布时间戳）存储到MySQL数据库中。基于文章的唯一URL来忽略重复的文章。

## 环境要求

*   PHP >= 7.4 (或与所用语法兼容的更高版本)
*   PHP 扩展:
    *   `curl` (用于HTTP请求)
    *   `json` (用于处理JSON数据)
    *   `pdo`
    *   `pdo_mysql` (用于MySQL数据库交互)
    *   `mbstring` (推荐用于字符串操作)
*   Python 3.x (用于运行 `python_login_helper.py`)
*   Python 库 (用于 `python_login_helper.py`):
    *   `requests`
    *   `pyzbar`
    *   `Pillow` (PIL)
    *   `qrcode`
    *   (通过 pip 安装: `pip install requests pyzbar Pillow qrcode`)
*   MySQL 服务器
*   Composer (用于PHP依赖管理和自动加载)

## 安装与配置

1.  **克隆仓库:**
    ```bash
    git clone <your-repository-url> PhpWechatAggregator
    cd PhpWechatAggregator
    ```
    将 `<your-repository-url>` 替换为你的实际仓库地址。

2.  **安装 PHP 依赖 (自动加载):**
    确保你已经安装了 Composer。然后，在 `PhpWechatAggregator` 目录下运行:
    ```bash
    composer install --no-dev --optimize-autoloader
    ```
    这将为 `App` 命名空间设置 PSR-4 自动加载。

3.  **安装 Python 依赖:**
    ```bash
    pip install requests pyzbar Pillow qrcode
    ```

4.  **准备配置文件:**
    *   **应用配置 (`config/app.php`):**
        如果存在 `config/app.sample.php`，请将其复制为 `config/app.php`。否则，请创建 `PhpWechatAggregator/config/app.php` 文件，内容结构如下。**最初，你可以将 `token` 和 `cookie` 留空或使用占位符值**，它们将由登录脚本自动更新。
        ```php
        <?php
        // PhpWechatAggregator/config/app.php
        return [
            'wechat_credentials' => [
                'token' => '', // 将由登录脚本自动填充
                'cookie' => '', // 将由登录脚本自动填充
                'appmsg_token' => '', // 可选: python助手脚本可能会用到
                'uin' => '', // 可选: python助手脚本可能会用到
                'key' => '', // 可选: python助手脚本可能会用到
            ],
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36', // 或你偏好的 User-Agent
            'official_accounts' => [
                [
                    'account_name' => '示例公众号名称', // 例如：央视新闻
                    'fakeid' => '你的公众号FAKEID',    // 替换为实际的 fakeid
                ],
                // 添加更多公众号...
            ],
            'fetch_options' => [
                'count' => 10, // 每次运行时为每个账户尝试抓取的文章数量
            ],
            'python_executable' => 'python', // 或者 'python3' 或 Python解释器的完整路径
        ];
        ```
        **关于 `fakeid`**: 你仍然需要找到你想要关注的公众号的 `fakeid`。这通常可以通过在微信公众号平台上查看某账户文章时检查网络请求，或通过其他第三方工具找到。

    *   **数据库配置 (`config/database.php`):**
        如果存在 `config/database.sample.php`，请将其复制为 `config/database.php`。否则，创建 `PhpWechatAggregator/config/database.php` 文件：
        ```php
        <?php
        // PhpWechatAggregator/config/database.php
        return [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'wechat_aggregator_db', // 你的数据库名称
            'username' => 'your_db_user',       // 你的数据库用户名
            'password' => 'your_db_password',   // 你的数据库密码
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        ];
        ```
        请将主机、数据库名称、用户名和密码更新为你的MySQL设置。

5.  **创建数据表 (`wechat_articles`):**
    连接到你的MySQL服务器并执行以下SQL语句。`DatabaseService.php` 使用此表结构。
    ```sql
    CREATE TABLE IF NOT EXISTS `wechat_articles` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `account_name` VARCHAR(255) NOT NULL COMMENT '公众号名称',
        `title` VARCHAR(512) NOT NULL COMMENT '文章标题',
        `article_url` VARCHAR(1024) NOT NULL COMMENT '文章链接',
        `publish_timestamp` DATETIME NOT NULL COMMENT '文章发布时间',
        `fetched_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '抓取入库时间',
        UNIQUE KEY `article_url_UNIQUE` (`article_url`(767)) -- 对于使用utf8mb4的旧版MySQL，索引长度可能需要设置
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='聚合的微信文章表';
    ```
    *关于 `UNIQUE KEY` 的说明: 对 `article_url` 设置长度 `(767)` 是为了兼容旧版MySQL和`utf8mb4`字符集（当`innodb_large_prefix`关闭时）。现代MySQL版本可能不需要此设置。*

## 自动化凭据设置 (推荐)

本项目包含脚本以帮助自动化登录过程并获取API调用所需的 `token` 和 `cookie`。

1.  **确保 `python_login_helper.py` 可执行** (如果你的操作系统需要)。
2.  **从命令行运行 `trigger_login.php` (在 `PhpWechatAggregator` 目录下):**
    ```bash
    php trigger_login.php
    ```
3.  此脚本将会:
    *   调用 `python_login_helper.py`。
    *   Python脚本将生成一个用于登录的二维码。请使用你的微信手机应用扫描此二维码。
    *   成功登录后，Python脚本将输出 `token`、`cookie` 及其他相关参数。
    *   `trigger_login.php` 将捕获此输出并使用这些新凭据更新你的 `config/app.php` 文件。
4.  验证 `config/app.php` 文件现在是否包含获取到的 `token` 和 `cookie`。

**手动凭据获取 (如果自动化失败):**
如果自动化过程遇到问题，你仍然可以手动获取 `token` 和 `cookie`:
    1. 打开浏览器 (例如 Chrome, Firefox) 并访问 `https://mp.weixin.qq.com/`。
    2. 登录微信公众号平台。
    3. 成功登录后，打开浏览器的开发者工具 (通常按 F12)。
    4. **Token**: 查看浏览器地址栏中的URL。它应包含 `token=YOUR_TOKEN_VALUE`。复制这个值。
    5. **Cookie**: 在开发者工具中，转到"网络(Network)"选项卡。刷新页面或在平台内导航。找到一个发往 `mp.weixin.qq.com` 的请求。检查其请求头(Request Headers)并找到 `Cookie` 头。复制其完整值。
    6. 将这些值粘贴到 `config/app.php` 的 `wechat_credentials` 部分。

## SSL/TLS 证书配置 (重要!)

`HttpClient.php` 现在强制执行SSL证书验证 (`CURLOPT_SSL_VERIFYPEER` 为 `true` 且 `CURLOPT_SSL_VERIFYHOST` 为 `2`)。这对于安全通信至关重要。如果 `cron.php` 因SSL错误而失败 (例如，"SSL certificate problem: unable to get local issuer certificate")，这意味着PHP的cURL扩展无法找到或验证 `mp.weixin.qq.com` 的SSL证书。

**请勿在生产环境中禁用代码中的SSL验证。**

要解决此问题，你需要配置PHP以使用有效的CA (证书颁发机构) 证书包：

1.  **下载CA证书包:**
    cURL项目本身提供了一个常用的CA证书包。你可以从以下地址下载：[https://curl.se/ca/cacert.pem](https://curl.se/ca/cacert.pem)
    将此 `cacert.pem` 文件保存到服务器上的一个已知位置 (例如，Windows上的 `C:\php\extras\ssl\cacert.pem`，或Linux上的 `/etc/ssl/certs/cacert.pem`)。

2.  **配置 `php.ini`:**
    找到你的PHP安装目录下的 `php.ini` 文件。(你可以通过在终端运行 `php --ini` 来找到其路径)。
    打开 `php.ini` 文件，找到 `[curl]` 部分。设置或取消注释 `curl.cainfo` 指令，使其指向你下载的 `cacert.pem` 文件：
    ```ini
    [curl]
    ; CURLOPT_CAINFO 选项的默认值。这必须是一个绝对路径。
    curl.cainfo = "/path/to/your/cacert.pem"
    ```
    将 `"/path/to/your/cacert.pem"` 替换为你保存文件的实际路径。
    Windows 示例: `curl.cainfo = "C:\php\extras\ssl\cacert.pem"`
    Linux 示例: `curl.cainfo = "/etc/ssl/certs/cacert.pem"`

3.  **重启你的 Web 服务器/PHP-FPM:**
    如果你通过Web服务器 (Apache, Nginx with PHP-FPM) 运行PHP，请重启它以使 `php.ini` 的更改生效。对于像 `cron.php` 这样的CLI脚本，更改应在下次运行时应用。

4.  **替代方案 (不太推荐 - 针对单个脚本或项目的CA信息):**
    如果你无法修改 `php.ini`，你*可以*在 `HttpClient.php` 中直接使用 `curl_setopt($ch, CURLOPT_CAINFO, "/path/to/your/cacert.pem");` 来指定CA证书包路径。然而，在 `php.ini` 中配置是更健壮和全局的解决方案。为鼓励使用 `php.ini` 方法，当前 `HttpClient.php` 中未实现此方法。

## 运行文章聚合器

配置完成并且凭据设置好之后 (最好是通过 `trigger_login.php` 完成)，运行 `cron.php` 来抓取文章:

```bash
php PhpWechatAggregator/cron.php
```
或者，如果你已经在 `PhpWechatAggregator` 目录下:
```bash
php cron.php
```

设置一个定时任务 (Linux/macOS上的cron job或Windows上的计划任务) 来定期运行此脚本。
示例 (每天凌晨2点运行，并记录输出):
```cron
0 2 * * * /usr/bin/php /path/to/PhpWechatAggregator/cron.php >> /path/to/logs/wechat_aggregator.log 2>&1
```

## 项目结构 (关键文件)

*   `cron.php`: 触发文章抓取和存储的主脚本。
*   `trigger_login.php`: 通过Python辅助脚本启动登录过程的PHP脚本。
*   `python_login_helper.py`: 处理微信二维码登录和凭据提取的Python脚本。
*   `config/`: 配置文件目录。
    *   `app.php`: 应用设置、微信公众号账户、API凭据。
    *   `database.php`: 数据库连接详情。
*   `app/`: 核心应用逻辑 (通过Composer进行PSR-4自动加载)。
    *   `Services/`: 包含服务类。
        *   `WechatAuthService.php`: 管理微信认证凭据。
        *   `ArticleFetcherService.php`: 从微信抓取文章。
        *   `DatabaseService.php`: 处理数据库交互。
    *   `Helpers/`: 辅助工具类。
        *   `HttpClient.php`: 用于执行HTTP请求的cURL封装。
*   `vendor/`: Composer 依赖项。

## 问题排查

*   **重新启用验证后出现SSL错误**: 请参阅上面的"SSL/TLS证书配置"部分。这是最常见的问题。
*   **`python_login_helper.py` 运行失败**:
    *   确保已安装Python 3.x并已将其添加到系统的PATH中，或者 `config/app.php` 中的 `python_executable`路径正确。
    *   验证所有Python依赖 (`requests`, `pyzbar`, `Pillow`, `qrcode`) 是否已在所使用的Python环境中安装。
    *   检查Python脚本打印的错误信息。
*   **"Failed to fetch articles for ... Invalid or missing credentials" (为...获取文章失败：凭据无效或丢失)**: 再次运行 `php trigger_login.php` 以刷新凭据。
*   **"WeChat API error ... invalid session/token" (微信API错误...无效的会话/令牌)**: 凭据可能已过期。请运行 `php trigger_login.php`。
*   **未抓取到任何文章**:
    *   检查 `config/app.php` 中的 `fakeid` 值是否正确。
    *   该公众号可能自上次抓取以来没有发布新文章。
    *   如果你将输出重定向到日志文件，请查看STDERR或日志文件中的错误。

## 未来增强 (TODO)

*   更强大的错误处理和专用的日志库 (例如 Monolog)。
*   用户界面 (Web) 用于管理账户、查看文章和日志。
*   如果可能，增加发现 `fakeid` 的选项 (尽管通常很难)。
*   支持抓取完整的文章内容，而不仅仅是元数据。
*   新文章或错误的通知功能。 