-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jul 14, 2025 at 02:05 PM
-- Server version: 10.6.19-MariaDB
-- PHP Version: 8.1.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `jorabini_3xui`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `userid` bigint(20) NOT NULL,
  `rule` enum('admin','superadmin') NOT NULL DEFAULT 'admin',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bot_messages`
--

CREATE TABLE `bot_messages` (
  `id` int(11) NOT NULL,
  `chat_id` bigint(20) NOT NULL,
  `message_id` int(11) NOT NULL,
  `message_text` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `configs`
--

CREATE TABLE `configs` (
  `id` int(11) NOT NULL,
  `config_name` varchar(255) NOT NULL,
  `config_settings` text NOT NULL,
  `port_type` enum('single_port','multi_port') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `config_logs`
--

CREATE TABLE `config_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `config_email` varchar(255) NOT NULL,
  `action` enum('create','delete','renew') NOT NULL,
  `server_id` int(11) NOT NULL,
  `config_details` text DEFAULT NULL,
  `status` enum('success','failed') NOT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `config_servers`
--

CREATE TABLE `config_servers` (
  `config_id` int(11) NOT NULL,
  `server_id` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `panel_admins`
--

CREATE TABLE `panel_admins` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `processed_callbacks`
--

CREATE TABLE `processed_callbacks` (
  `id` int(6) UNSIGNED NOT NULL,
  `callback_id` varchar(255) NOT NULL,
  `processed_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `config_ids` text NOT NULL,
  `price` decimal(10,2) DEFAULT 0.00,
  `volume_gb` int(11) DEFAULT 0,
  `days_count` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `servers`
--

CREATE TABLE `servers` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `url` varchar(255) NOT NULL,
  `cookies` text NOT NULL,
  `tunnel_ip` varchar(255) DEFAULT NULL,
  `capacity` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `test_accounts`
--

CREATE TABLE `test_accounts` (
  `id` int(6) UNSIGNED NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `email` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` int(6) UNSIGNED NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `amount` int(6) NOT NULL,
  `type` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(6) UNSIGNED NOT NULL,
  `userid` bigint(20) NOT NULL,
  `username` varchar(255) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `configcount` int(6) DEFAULT 0,
  `balance` int(6) DEFAULT 0
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `userid`, `username`, `name`, `configcount`, `balance`) VALUES
(1, 1706227769, 'nullfill', 'Hamed', 71, 1507000),
(2, 1049477667, 'Gt12n010', 'A', 8, 405000),
(3, 1660344142, 'siavash_j64', 'آقای جودی', 62, 123500),
(4, 627085821, 'Saeron2516', 'Sajjad_', 44, 60000),
(5, 69557978, 'RezaM066', 'rEzA', 8, 75000),
(6, 613270927, '', 'Sina', 0, 143000),
(7, 7077973100, 'Zelatanebrahim_333', '.', 0, 0),
(8, 121657197, 'shanlycloop', 'Amir', 6, 240000),
(9, 134528974, 'mohammadakbari_ir', 'محمد', 6, 40000),
(10, 5547818153, 'pouya_frshi', 'pouya', 17, 78000),
(11, 1093707066, 'swgrii', '🧚🏻‍♀️', 0, 0),
(12, 5535892327, '', '.', 3, 14500),
(13, 667798241, 'rezafarahmand33', 'reza', 9, 63000),
(14, 163370566, 'hamidrezatorkashvand', 'hrt', 2, 3000),
(15, 7084307318, 'Maedeabbasiii', 'Maede', 0, 500),
(16, 812507944, '', 'S', 0, 0),
(17, 5930496307, 'mrbullir', 'Mr Bull', 0, 11000),
(18, 5182503001, '', 're', 2, 180000),
(19, 60257856, 'LnazNemati', 'Lnaz', 0, 0),
(20, 1921900816, '', 'Amir', 0, 0),
(21, 267151803, 'Pouya856112', '......', 1, 205000),
(22, 5935576651, 'Ykilmadim', 'Ꭷ Ꮇ Ꭵ Ꮄ-', 24, 149000),
(23, 7982724499, '', 'reza', 0, 0),
(24, 6668167240, '', 'Amir', 0, 0),
(25, 7164389911, 'levoTrader', 'Levo Trader', 2, 0),
(26, 7368281183, '', 'علی اصغر حسین', 1, 180000),
(27, 5532931836, 'zeynab_soonaz', '𝐙𝐞𝐲𝐧𝐚𝐛', 1, 102500),
(28, 5871642207, 'amirsadat1', 'Amir', 0, 0),
(29, 6719077961, 'jdhanko', '🦴Tamo🦴', 0, 0),
(30, 775902108, 'SaraAv2002', '𝓢𝓪𝓻𝓪. 𝓐𝓿𝔂', 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `usersconfig`
--

CREATE TABLE `usersconfig` (
  `id` int(11) NOT NULL,
  `userid_c` bigint(20) NOT NULL,
  `config_c` text NOT NULL,
  `configtunnel_c` text DEFAULT NULL,
  `name_config` varchar(255) NOT NULL,
  `email_config` varchar(255) NOT NULL,
  `config_id` int(11) NOT NULL,
  `server_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_states`
--

CREATE TABLE `user_states` (
  `id` int(11) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `state` varchar(50) NOT NULL,
  `temp_data` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wallet_recharge_requests`
--

CREATE TABLE `wallet_recharge_requests` (
  `id` int(6) UNSIGNED NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `amount` int(6) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `receipt_image` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approved_by` bigint(20) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `userid` (`userid`);

--
-- Indexes for table `bot_messages`
--
ALTER TABLE `bot_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_chat_id` (`chat_id`);

--
-- Indexes for table `configs`
--
ALTER TABLE `configs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `config_logs`
--
ALTER TABLE `config_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `config_email` (`config_email`),
  ADD KEY `server_id` (`server_id`);

--
-- Indexes for table `config_servers`
--
ALTER TABLE `config_servers`
  ADD PRIMARY KEY (`config_id`,`server_id`),
  ADD KEY `server_id` (`server_id`);

--
-- Indexes for table `panel_admins`
--
ALTER TABLE `panel_admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `processed_callbacks`
--
ALTER TABLE `processed_callbacks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `callback_id` (`callback_id`) USING HASH,
  ADD KEY `processed_at` (`processed_at`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `servers`
--
ALTER TABLE `servers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `test_accounts`
--
ALTER TABLE `test_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `userid` (`userid`);

--
-- Indexes for table `usersconfig`
--
ALTER TABLE `usersconfig`
  ADD PRIMARY KEY (`id`),
  ADD KEY `server_id` (`server_id`),
  ADD KEY `idx_userid_c` (`userid_c`),
  ADD KEY `idx_email_config` (`email_config`(250));

--
-- Indexes for table `user_states`
--
ALTER TABLE `user_states`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `wallet_recharge_requests`
--
ALTER TABLE `wallet_recharge_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `status` (`status`),
  ADD KEY `created_at` (`created_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bot_messages`
--
ALTER TABLE `bot_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `configs`
--
ALTER TABLE `configs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `config_logs`
--
ALTER TABLE `config_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `panel_admins`
--
ALTER TABLE `panel_admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `processed_callbacks`
--
ALTER TABLE `processed_callbacks`
  MODIFY `id` int(6) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `servers`
--
ALTER TABLE `servers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `test_accounts`
--
ALTER TABLE `test_accounts`
  MODIFY `id` int(6) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(6) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(6) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `usersconfig`
--
ALTER TABLE `usersconfig`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_states`
--
ALTER TABLE `user_states`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wallet_recharge_requests`
--
ALTER TABLE `wallet_recharge_requests`
  MODIFY `id` int(6) UNSIGNED NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
