<?php

// PhpWechatAggregator/config/database.php

return [
    'driver' => 'mysql',
    'host' => '140.238.201.162',
    'port' => '3306',
    'database' => 'cj',
    'username' => 'cj',
    'password' => '760516',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'options' => [
        // PDO 连接选项
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
]; 