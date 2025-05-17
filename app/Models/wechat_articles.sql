-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- 主机： localhost
-- 生成日期： 2025-05-16 15:52:38
-- 服务器版本： 5.7.41-log
-- PHP 版本： 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- 数据库： `cj`
--

-- --------------------------------------------------------

--
-- 表的结构 `wechat_articles`
--

CREATE TABLE `wechat_articles` (
  `id` int(11) NOT NULL,
  `account_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '公众号名称',
  `title` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '文章标题',
  `article_url` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '文章链接',
  `publish_timestamp` datetime NOT NULL COMMENT '文章发布时间',
  `source_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'wechat' COMMENT '来源类型: wechat, external_link等',
  `fetched_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '抓取入库时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='聚合的微信文章表';

--
-- 转储表的索引
--

--
-- 表的索引 `wechat_articles`
--
ALTER TABLE `wechat_articles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `article_url_UNIQUE` (`article_url`(767));

--
-- 在导出的表使用AUTO_INCREMENT
--

--
-- 使用表AUTO_INCREMENT `wechat_articles`
--
ALTER TABLE `wechat_articles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARA