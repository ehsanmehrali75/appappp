-- Maarksho Flow Database Schema
-- Database: magtaned_flow

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- Tables structure

CREATE TABLE IF NOT EXISTS `super_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `super_users` (`id`, `username`, `password`) VALUES
(1, 'admin', '1375');

CREATE TABLE IF NOT EXISTS `projects` (
  `id` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `active_from` date NOT NULL,
  `active_to` date NOT NULL,
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`settings`)),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `project_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` varchar(50) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('project_admin','editor','viewer') NOT NULL DEFAULT 'editor',
  `permissions` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_id` (`project_id`),
  UNIQUE KEY `proj_user` (`project_id`, `username`),
  CONSTRAINT `fk_project_user` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `products` (
  `id` varchar(50) NOT NULL,
  `project_id` varchar(50) NOT NULL,
  `sku` varchar(50) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `image` text DEFAULT NULL,
  `gallery` longtext DEFAULT NULL,
  `link` text NOT NULL,
  `price` varchar(50) DEFAULT NULL,
  `extracted_at` int(11) NOT NULL,
  `manual_edit` tinyint(1) DEFAULT 0,
  `likes` int(11) DEFAULT 0,
  `dislikes` int(11) DEFAULT 0,
  `user_votes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`user_votes`)),
  `user_feedback` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`user_feedback`)),
  `platforms_sent` text DEFAULT NULL,
  `status` varchar(20) DEFAULT 'active',
  `color` varchar(50) DEFAULT NULL,
  `stock_online` varchar(50) DEFAULT NULL,
  `priority` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `project_id` (`project_id`),
  CONSTRAINT `fk_project_product` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` varchar(50) NOT NULL,
  `at` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `platform` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_id` (`project_id`),
  CONSTRAINT `fk_project_log` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `schedules` (
  `id` varchar(50) NOT NULL,
  `project_id` varchar(50) NOT NULL,
  `product_id` varchar(50) NOT NULL,
  `platform` varchar(50) NOT NULL,
  `caption` text DEFAULT NULL,
  `scheduled_at` datetime NOT NULL,
  `status` enum('pending','completed','failed','orphaned') DEFAULT 'pending',
  PRIMARY KEY (`id`),
  KEY `project_id` (`project_id`),
  CONSTRAINT `fk_project_schedule` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `scraping_jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` varchar(50) NOT NULL,
  `url` text NOT NULL,
  `type` enum('discovery','product_sync') NOT NULL DEFAULT 'discovery',
  `status` enum('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
  `priority` int(11) DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `worker_id` varchar(100) DEFAULT NULL,
  `result_summary` text DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_id` (`project_id`),
  CONSTRAINT `fk_project_job` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
