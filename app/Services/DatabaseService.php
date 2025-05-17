<?php

// PhpWechatAggregator/app/Services/DatabaseService.php

namespace App\Services;

use PDO;
use PDOException;
use DateTime;
use DateTimeZone;

class DatabaseService
{
    private ?PDO $pdo = null;
    private array $dbConfig;
    private string $tableName = 'wechat_articles'; // 表名保持不变

    public function __construct(array $dbConfig)
    {
        $this->dbConfig = $dbConfig;
        $this->connect();
    }

    private function connect(): void
    {
        if ($this->pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $this->dbConfig['host'],
                $this->dbConfig['port'],
                $this->dbConfig['database'],      // 来自 database.php
                $this->dbConfig['charset']
            );

            try {
                $this->pdo = new PDO($dsn, $this->dbConfig['username'], $this->dbConfig['password'], $this->dbConfig['options']);
            } catch (PDOException $e) {
                error_log("Database connection failed: " . $e->getMessage());
                $this->pdo = null;
            }
        }
    }

    public function getConnection(): ?PDO
    {
        return $this->pdo;
    }

    public function getArticleByUrl(string $articleUrl): ?array
    {
        if ($this->pdo === null) {
            error_log("Cannot get article: No database connection.");
            return null;
        }

        try {
            $stmt = $this->pdo->prepare("SELECT * FROM `{$this->tableName}` WHERE `article_url` = :article_url LIMIT 1");
            $stmt->bindParam(':article_url', $articleUrl);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result === false ? null : $result; // 确保在 fetch 失败或未找到时返回 null
        } catch (PDOException $e) {
            error_log("Error fetching article: " . $e->getMessage());
            return null;
        }
    }

    public function insertArticle(array $articleData, string $sourceType = 'wechat'): bool
    {
        if (empty($articleData['article_url'])) {
            error_log("DatabaseService::insertArticle Error: article_url is empty.");
            return false;
        }

        $existing = $this->getArticleByUrl($articleData['article_url']);
        if ($existing) {
            error_log("DatabaseService::insertArticle Info: Article already exists with URL: " . $articleData['article_url']);
            return false; // Or handle update
        }

        // Ensure fetched_at uses the database default by not including it in the explicit columns list
        $sql = "INSERT INTO wechat_articles (account_name, title, article_url, publish_timestamp, source_type) 
                VALUES (:account_name, :title, :article_url, :publish_timestamp, :source_type)";

        try {
            $stmt = $this->pdo->prepare($sql);

            $accountName = $articleData['account_name'] ?? 'Unknown Account';
            $title = $articleData['title'] ?? 'Untitled';
            // publish_timestamp should be handled correctly by MySQL if it's a valid DATETIME string or compatible integer/timestamp
            // Given previous confirmation that it works, we assume direct binding is fine for existing WeChat logic.
            // For external articles, it will be a DATETIME string or NULL (if column allows).
            $publishTimestamp = $articleData['publish_timestamp']; // Keep as is if it works.

            $stmt->bindParam(':account_name', $accountName);
            $stmt->bindParam(':title', $title);
            $stmt->bindParam(':article_url', $articleData['article_url']);
            $stmt->bindParam(':publish_timestamp', $publishTimestamp);
            $stmt->bindParam(':source_type', $sourceType); 

            return $stmt->execute();
        } catch (\PDOException $e) {
            error_log("DatabaseService::insertArticle PDOException: " . $e->getMessage() . " for URL: " . ($articleData['article_url'] ?? 'N/A'));
            return false;
        }
    }

    public static function getCreateTableSql(): string
    {
        return <<<SQL
CREATE TABLE IF NOT EXISTS `wechat_articles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `account_name` VARCHAR(255) NOT NULL,
    `title` VARCHAR(512) NOT NULL,
    `article_url` VARCHAR(1024) NOT NULL,
    `publish_timestamp` DATETIME NOT NULL,
    `fetched_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `source_type` VARCHAR(255),
    UNIQUE KEY `article_url_UNIQUE` (`article_url`(767))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Aggregated WeChat Articles';
SQL;
    }

    /**
     * 获取最近的文章列表
     * @param int $limit 获取的文章数量
     * @return array 文章列表, 失败则返回空数组
     */
    public function getRecentArticles(int $limit = 20): array
    {
        if ($this->pdo === null) {
            error_log("Cannot get articles: No database connection.");
            return [];
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT `account_name`, `title`, `article_url`, `publish_timestamp` 
                 FROM `{$this->tableName}` 
                 ORDER BY `publish_timestamp` DESC 
                 LIMIT :limitValue"
            );
            $stmt->bindParam(':limitValue', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching recent articles: " . $e->getMessage());
            return [];
        }
    }
} 