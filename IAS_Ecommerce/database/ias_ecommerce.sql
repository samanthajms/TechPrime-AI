-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 09, 2026 at 02:34 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ias_ecommerce`
--

-- --------------------------------------------------------

--
-- Table structure for table `cart`
--

CREATE TABLE `cart` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `quantity` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `locked_accounts`
--

CREATE TABLE `locked_accounts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `locked_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `locked_accounts`
--

INSERT INTO `locked_accounts` (`id`, `user_id`, `reason`, `locked_at`) VALUES
(5, 6, 'Admin manual block', '2026-05-09 00:23:25');

-- --------------------------------------------------------

--
-- Table structure for table `logs`
--

CREATE TABLE `logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(255) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `logs`
--

INSERT INTO `logs` (`id`, `user_id`, `action`, `details`, `ip_address`, `created_at`) VALUES
(1, NULL, 'mfa_generated', 'MFA Code: 763726', '::1', '2026-05-06 18:56:27'),
(2, NULL, 'mfa_generated', 'MFA Code: 492352', '::1', '2026-05-06 19:01:12'),
(3, NULL, 'mfa_generated', 'MFA Code: 419012', '::1', '2026-05-06 19:01:44'),
(4, NULL, 'mfa_generated', 'MFA Code: 881241', '::1', '2026-05-06 19:07:05'),
(5, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-06 19:07:28'),
(6, NULL, 'mfa_generated', 'MFA Code: 732350', '::1', '2026-05-06 19:14:19'),
(7, NULL, 'mfa_generated', 'MFA Code: 270027', '::1', '2026-05-06 19:14:28'),
(8, NULL, 'mfa_generated', 'MFA Code: 166973', '::1', '2026-05-06 19:14:45'),
(9, NULL, 'mfa_generated', 'MFA Code: 181216', '::1', '2026-05-06 19:15:01'),
(10, NULL, 'mfa_generated', 'MFA Code: 168164', '::1', '2026-05-06 19:15:28'),
(11, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-06 19:18:10'),
(12, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-06 19:18:10'),
(13, NULL, 'logout', 'User logged out', '::1', '2026-05-06 19:18:21'),
(14, NULL, 'mfa_generated', 'MFA Code: 434325', '::1', '2026-05-06 19:18:25'),
(15, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-06 19:19:34'),
(16, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-06 19:19:34'),
(17, NULL, 'logout', 'User logged out', '::1', '2026-05-06 19:19:40'),
(18, NULL, 'mfa_generated', 'MFA Code: 982096', '::1', '2026-05-06 19:19:46'),
(19, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-06 19:20:08'),
(20, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-06 19:20:08'),
(21, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-06 19:24:18'),
(22, NULL, 'mfa_generated', 'MFA Code: 145049', '::1', '2026-05-06 19:24:19'),
(23, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-06 19:24:31'),
(24, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-06 19:24:31'),
(25, NULL, 'block_user', 'Admin blocked user ID 1', '::1', '2026-05-06 19:25:34'),
(26, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-06 19:29:23'),
(27, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-06 19:29:27'),
(28, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-06 19:29:28'),
(29, NULL, 'logout', 'User logged out', '::1', '2026-05-06 19:32:56'),
(30, NULL, 'mfa_generated', 'MFA Code: 434416', '::1', '2026-05-06 19:38:11'),
(31, NULL, 'mfa_generated', 'MFA Code: 925805', '::1', '2026-05-06 19:40:27'),
(32, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-06 19:40:46'),
(33, NULL, 'mfa_generated', 'MFA Code: 511375', '::1', '2026-05-08 08:08:38'),
(34, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 08:09:07'),
(35, NULL, 'mfa_generated', 'MFA Code: 919634', '::1', '2026-05-08 08:34:16'),
(36, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 08:34:35'),
(37, NULL, 'mfa_generated', 'MFA Code: 136468', '::1', '2026-05-08 08:59:45'),
(38, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 09:00:04'),
(39, NULL, 'logout', 'User logged out', '::1', '2026-05-08 09:00:17'),
(40, 6, 'mfa_generated', 'MFA Code: 163844', '::1', '2026-05-08 11:18:24'),
(41, 6, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 11:19:03'),
(42, 6, 'mfa_generated', 'MFA Code: 398744', '::1', '2026-05-08 11:22:22'),
(43, 6, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 11:22:43'),
(44, 6, 'mfa_generated', 'MFA Code: 995768', '::1', '2026-05-08 11:40:05'),
(45, 6, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 11:40:23'),
(46, 6, 'mfa_generated', 'MFA Code: 129851', '::1', '2026-05-08 12:02:07'),
(47, 6, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 12:02:33'),
(48, 6, 'mfa_generated', 'MFA Code: 883858', '::1', '2026-05-08 12:04:01'),
(49, 6, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 12:04:13'),
(50, 6, 'mfa_generated', 'MFA Code: 100405', '::1', '2026-05-08 12:07:15'),
(51, 6, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 12:07:27'),
(52, 6, 'mfa_generated', 'MFA Code: 455033', '::1', '2026-05-08 12:07:54'),
(53, 6, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 12:08:05'),
(54, 6, 'mfa_generated', 'MFA Code: 911927', '::1', '2026-05-08 12:27:53'),
(55, 6, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 12:28:13'),
(56, 6, 'mfa_generated', 'MFA Code: 649947', '::1', '2026-05-08 12:32:23'),
(57, 6, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 12:32:40'),
(58, 6, 'logout', 'User logged out', '::1', '2026-05-08 12:32:45'),
(59, 6, 'mfa_generated', 'MFA Code: 396898', '::1', '2026-05-08 12:32:49'),
(60, 6, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 12:35:07'),
(61, 6, 'logout', 'User logged out', '::1', '2026-05-08 12:59:22'),
(62, 6, 'mfa_generated', 'MFA Code: 700283', '::1', '2026-05-08 12:59:26'),
(63, 6, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 12:59:39'),
(64, 6, 'mfa_generated', 'MFA Code: 004583', '::1', '2026-05-08 13:02:41'),
(65, 6, 'logout', 'User logged out', '::1', '2026-05-08 14:47:28'),
(66, 6, 'mfa_generated', 'MFA Code: 277267', '::1', '2026-05-08 14:48:02'),
(67, 6, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 14:48:18'),
(68, 6, 'mfa_generated', 'MFA Code: 050262', '::1', '2026-05-08 14:50:21'),
(69, 6, 'mfa_generated', 'MFA Code: 446301', '::1', '2026-05-08 14:50:50'),
(70, 6, 'mfa_generated', 'MFA Code: 655087', '::1', '2026-05-08 14:51:10'),
(71, 6, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 14:51:33'),
(72, 6, 'mfa_generated', 'MFA Code: 437068', '::1', '2026-05-08 16:12:10'),
(73, 6, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 16:12:28'),
(74, 6, 'logout', 'User logged out', '::1', '2026-05-08 16:13:49'),
(75, 6, 'mfa_generated', 'MFA Code: 639141', '::1', '2026-05-08 16:13:54'),
(76, 6, 'mfa_generated', 'MFA Code: 658722', '::1', '2026-05-08 16:29:59'),
(77, 6, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 16:31:09'),
(78, 6, 'logout', 'User logged out', '::1', '2026-05-08 16:31:32'),
(79, 7, 'mfa_generated', 'MFA Code: 505539', '::1', '2026-05-08 16:32:20'),
(80, 7, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 16:32:32'),
(81, 7, 'mfa_generated', 'MFA Code: 746634', '::1', '2026-05-08 16:37:42'),
(82, 7, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 16:37:59'),
(83, 7, 'mfa_generated', 'MFA Code: 062088', '::1', '2026-05-08 17:05:17'),
(84, 7, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 17:06:23'),
(85, 7, 'mfa_generated', 'MFA Code: 707541', '::1', '2026-05-08 17:06:50'),
(86, 7, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 17:07:06'),
(87, 6, 'mfa_generated', 'MFA Code: 686312', '::1', '2026-05-08 17:08:06'),
(88, 6, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 17:08:22'),
(89, 6, 'mfa_generated', 'MFA Code: 111830', '::1', '2026-05-08 17:11:13'),
(90, 6, 'mfa_generated', 'MFA Code: 638365', '::1', '2026-05-08 17:12:10'),
(91, 9, 'mfa_generated', 'MFA Code: 994626', '::1', '2026-05-08 17:14:24'),
(92, 9, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 17:14:50'),
(93, 9, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-08 17:14:50'),
(94, 9, 'block_user', 'Admin blocked user ID 8', '::1', '2026-05-08 17:15:07'),
(95, 9, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-08 17:15:21'),
(96, 9, 'unblock_user', 'Admin unblocked user ID 8', '::1', '2026-05-08 17:21:13'),
(97, 9, 'block_user', 'Admin blocked user ID 8', '::1', '2026-05-08 17:21:21'),
(98, 9, 'logout', 'User logged out', '::1', '2026-05-08 17:21:29'),
(99, 6, 'mfa_generated', 'MFA Code: 379335', '::1', '2026-05-08 17:21:37'),
(100, 6, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 17:21:55'),
(101, 9, 'mfa_generated', 'MFA Code: 304367', '::1', '2026-05-08 17:22:31'),
(102, 9, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 17:23:15'),
(103, 9, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-08 17:23:15'),
(104, 9, 'block_user', 'Admin blocked user ID 6', '::1', '2026-05-08 17:23:22'),
(105, 7, 'mfa_generated', 'MFA Code: 353460', '::1', '2026-05-08 17:29:18'),
(106, 7, 'mfa_generated', 'MFA Code: 451091', '::1', '2026-05-08 17:29:47'),
(107, 7, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 17:30:00'),
(108, 7, 'mfa_generated', 'MFA Code: 102012', '::1', '2026-05-08 17:56:32'),
(109, 7, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 17:57:08'),
(110, 9, 'mfa_generated', 'MFA Code: 396840', '::1', '2026-05-08 18:24:20'),
(111, 9, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 18:24:35'),
(112, 9, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-08 18:24:35'),
(113, 9, 'unblock_user', 'Admin unblocked user ID 8', '::1', '2026-05-08 18:24:39'),
(114, 9, 'unblock_user', 'Admin unblocked user ID 6', '::1', '2026-05-08 18:24:42'),
(115, 9, 'logout', 'User logged out', '::1', '2026-05-08 18:24:48'),
(116, 6, 'mfa_generated', 'MFA Code: 466345', '::1', '2026-05-08 18:25:49'),
(117, 6, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 18:26:09'),
(118, 6, 'mfa_generated', 'MFA Code: 750606', '::1', '2026-05-08 19:23:37'),
(119, 6, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 19:23:53'),
(120, 6, 'logout', 'User logged out', '::1', '2026-05-08 19:47:48'),
(121, 7, 'mfa_generated', 'MFA Code: 817478', '::1', '2026-05-08 19:48:04'),
(122, 7, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 19:48:15'),
(123, 7, 'mfa_generated', 'MFA Code: 501158', '::1', '2026-05-08 19:48:23'),
(124, 7, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 19:48:44'),
(125, 7, 'add_to_cart', 'Product ID 1 added to cart', '::1', '2026-05-08 19:49:11'),
(126, 6, 'mfa_generated', 'MFA Code: 396305', '::1', '2026-05-08 19:51:16'),
(127, 6, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 19:51:26'),
(128, 6, 'logout', 'User logged out', '::1', '2026-05-08 20:03:53'),
(129, 6, 'mfa_generated', 'MFA Code: 451297', '::1', '2026-05-08 20:10:47'),
(130, 6, 'mfa_generated', 'MFA Code: 877449', '::1', '2026-05-08 20:11:08'),
(131, 6, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 20:11:23'),
(132, 6, 'mfa_generated', 'MFA Code: 957608', '::1', '2026-05-08 20:19:47'),
(133, 6, 'mfa_generated', 'MFA Code: 379906', '::1', '2026-05-08 20:54:28'),
(134, 6, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 20:55:10'),
(135, NULL, 'mfa_generated', 'MFA Code: 619487', '::1', '2026-05-08 21:42:27'),
(136, 25, 'mfa_generated', 'MFA Code: 648706', '::1', '2026-05-08 21:43:04'),
(137, 25, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 21:43:17'),
(138, 25, 'logout', 'User logged out', '::1', '2026-05-08 21:47:10'),
(139, 7, 'mfa_generated', 'MFA Code: 453378', '::1', '2026-05-08 21:47:32'),
(140, 7, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 21:47:52'),
(141, 7, 'add_to_cart', 'Product ID 1 added to cart', '::1', '2026-05-08 21:47:56'),
(142, 7, 'add_to_cart', 'Product ID 4 added to cart', '::1', '2026-05-08 21:48:28'),
(143, 7, 'add_to_cart', 'Product ID 5 added to cart', '::1', '2026-05-08 21:49:09'),
(144, 7, 'update_cart', 'Cart quantities updated', '::1', '2026-05-08 21:51:19'),
(145, 7, 'update_cart', 'Cart quantities updated', '::1', '2026-05-08 21:51:29'),
(146, 7, 'add_to_cart', 'Product ID 5 added to cart', '::1', '2026-05-08 21:55:49'),
(147, 7, 'add_to_cart', 'Product ID 1 added to cart', '::1', '2026-05-08 21:56:19'),
(148, 7, 'mfa_generated', 'MFA Code: 478635', '::1', '2026-05-08 22:18:28'),
(149, 7, 'mfa_generated', 'MFA Code: 518340', '::1', '2026-05-08 22:19:34'),
(150, 7, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 22:19:51'),
(151, 7, 'mfa_generated', 'MFA Code: 746894', '::1', '2026-05-09 00:12:10'),
(152, 7, 'login_success', 'User logged in via MFA', '::1', '2026-05-09 00:12:45'),
(153, 7, 'update_cart', 'Cart quantities updated', '::1', '2026-05-09 00:13:13'),
(154, 6, 'mfa_generated', 'MFA Code: 502941', '::1', '2026-05-09 00:14:30'),
(155, 6, 'login_success', 'User logged in via MFA', '::1', '2026-05-09 00:14:50'),
(156, 6, 'logout', 'User logged out', '::1', '2026-05-09 00:15:41'),
(157, 6, 'mfa_generated', 'MFA Code: 014984', '::1', '2026-05-09 00:16:48'),
(158, 6, 'mfa_generated', 'MFA Code: 818755', '::1', '2026-05-09 00:17:49'),
(159, 9, 'mfa_generated', 'MFA Code: 237558', '::1', '2026-05-09 00:18:50'),
(160, 9, 'login_success', 'User logged in via MFA', '::1', '2026-05-09 00:20:43'),
(161, 9, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-09 00:20:43'),
(162, 9, 'block_user', 'Admin blocked user ID 6', '::1', '2026-05-09 00:23:25'),
(163, 9, 'logout', 'User logged out', '::1', '2026-05-09 00:23:30'),
(164, 7, 'mfa_generated', 'MFA Code: 632535', '::1', '2026-05-09 00:24:24'),
(165, 7, 'login_success', 'User logged in via MFA', '::1', '2026-05-09 00:26:43'),
(166, 7, 'update_cart', 'Cart quantities updated', '::1', '2026-05-09 00:27:10'),
(167, 25, 'mfa_generated', 'MFA Code: 470931', '::1', '2026-05-09 00:28:23'),
(168, 25, 'login_success', 'User logged in via MFA', '::1', '2026-05-09 00:28:35');

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `shipping_address` text DEFAULT NULL,
  `customer_phone` varchar(50) DEFAULT NULL,
  `total` decimal(10,2) DEFAULT NULL,
  `status` enum('to_pay','to_ship','to_receive','to_review') DEFAULT 'to_pay',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `user_id`, `shipping_address`, `customer_phone`, `total`, `status`, `created_at`) VALUES
(1, 7, 'wotwptipwpett', '09683363700', 339600.00, '', '2026-05-08 22:03:43'),
(2, 7, 'wotwptipwpett', '09683363700', 339600.00, '', '2026-05-08 22:04:45'),
(3, 7, 'wotwptipwpett', '09683363700', 339600.00, '', '2026-05-08 22:04:53'),
(4, 7, 'wotwptipwpett', '09683363700', 339600.00, '', '2026-05-08 22:05:02'),
(5, 7, 'wotwptipwpett', '09683363700', 339600.00, '', '2026-05-08 22:07:11'),
(6, 7, 'WFFW', '090909009', 6000.00, '', '2026-05-09 00:13:26'),
(7, 7, 'tweetret', '09683363700', 90000.00, '', '2026-05-09 00:27:19');

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `quantity` int(11) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `quantity`, `price`) VALUES
(1, 5, 1, 2, 950.00),
(2, 5, 2, 1, 1200.00),
(3, 5, 4, 5, 28500.00),
(4, 5, 5, 4, 48500.00),
(5, 6, 2, 5, 1200.00),
(6, 7, 6, 4, 22500.00);

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `image_url` text DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `stock` int(11) DEFAULT 0,
  `image` varchar(255) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `seller_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `name`, `description`, `image_url`, `price`, `stock`, `image`, `category`, `seller_id`, `created_at`) VALUES
(1, 'Soft Blush Petal Case', 'Ultra-slim, translucent matte finish in a delicate baby pink. Anti-fingerprint coating with a velvet-soft feel.', 'https://pin.it/4xbE02GPH', 950.00, 98, NULL, NULL, 6, '2026-05-08 19:37:46'),
(2, 'Pink Dream Charm & Case Bundle', 'Get the full aesthetic. Includes our signature pink ribbon case plus a handmade pearl and ribbon phone strap.', 'https://pin.it/5ACANsWJs', 1200.00, 14, NULL, NULL, 6, '2026-05-08 19:45:46'),
(3, 'Sakura Sparkle', 'A dreamcore-inspired hot pink glitter case featuring military-grade drop protection and a built-in MagSafe magnet. Perfect for the pink-obsessed.', 'https://pin.it/7hdOyfg0a', 850.00, 50, NULL, NULL, 6, '2026-05-08 19:46:51'),
(4, 'CloudPink Slimbook 14 (Ryzen 5 Edition)', 'The ultimate aesthetic laptop for Binsoy Shop! ✨ Features a gorgeous matte Sakura Pink finish and a white backlit keyboard. Perfectly lightweight for coffee shop study dates or WFH vibes.', '', 28500.00, 95, NULL, NULL, 25, '2026-05-08 21:45:40'),
(5, 'Nitro-G: Pink Power Gaming Laptop', 'Who says gaming has to be boring? 🌸 High-performance gaming meets the pink aesthetic. Play Valorant, Genshin, or edit videos with zero lag. Features Customizable RGB (Pink/Purple) lighting!\\r\\n\\r\\nSpecs: RTX 4050 Graphics / 16GB RAM / 144Hz Smooth Display\\r\\n\\r\\nFreebie: Pink Mechanical Keyboard & Mousepad 🎮', '', 48500.00, 96, NULL, NULL, 25, '2026-05-08 21:46:17'),
(6, 'EliteBook - Rose Gold Edition', 'The ultimate aesthetic laptop for Binsoy Shop! ✨ Features a gorgeous matte Sakura Pink finish and a white backlit keyboard. Perfectly lightweight for coffee shop study dates or WFH vibes.\\r\\n\\r\\nSpecs: 16GB RAM / 512GB SSD / 14-inch Full HD\\r\\n\\r\\nFreebie: Matching Pink Wireless Mouse & Velvet Sleeve 🎀', '', 22500.00, 996, NULL, NULL, 25, '2026-05-08 21:46:45');

-- --------------------------------------------------------

--
-- Table structure for table `reviews`
--

CREATE TABLE `reviews` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `rating` int(11) NOT NULL CHECK (`rating` >= 1 and `rating` <= 5),
  `comment` text DEFAULT NULL,
  `seller_reply` text DEFAULT NULL,
  `replied_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shipments`
--

CREATE TABLE `shipments` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `courier_id` int(11) DEFAULT NULL,
  `carrier` enum('JNT','LBC','NinjaVan','FlashExpress') DEFAULT 'JNT',
  `shipment_status` enum('pending','processing','shipped','out_for_delivery','delivered') DEFAULT 'pending',
  `tracking_number` varchar(120) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `surname` varchar(100) DEFAULT NULL,
  `age` int(11) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` enum('admin','seller','client','courier') DEFAULT 'client',
  `is_verified` tinyint(4) DEFAULT 0,
  `is_locked` tinyint(4) DEFAULT 0,
  `failed_attempts` int(11) DEFAULT 0,
  `mfa_code` varchar(10) DEFAULT NULL,
  `mfa_expiry` datetime DEFAULT NULL,
  `activation_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `shop_description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `surname`, `age`, `address`, `email`, `password`, `role`, `is_verified`, `is_locked`, `failed_attempts`, `mfa_code`, `mfa_expiry`, `activation_token`, `created_at`, `shop_description`) VALUES
(6, 'Rona Shop', 'Dantic', 20, 'Pasig City', 'danticronamae01@gmail.com', '$2y$10$6cX/uszJILiYFcCO1Q7kMushQMwNhAFaSaJuPYwJComxbC5fVTlRO', 'seller', 1, 1, 0, '818755', '2026-05-09 08:27:49', NULL, '2026-05-08 11:18:03', 'Turning your phone into your favorite accessory.\\r\\nDrop-tested protection 💖 Dreamcore aesthetic.\\r\\n✨ MagSafe Compatible & Eco-Friendly.\\r\\nShop the new Pink Cloud collection! 👇'),
(7, 'Rona', 'Dantic', 20, 'Pasig City', 'synehiraya@gmail.com', '$2y$10$904S9WLbD21vxgLEPSo53uqcdlb2p883944PKP8gm903ZmMTsikEe', 'client', 1, 0, 0, '632535', '2026-05-09 08:34:24', NULL, '2026-05-08 16:32:03', NULL),
(9, 'Rona Mae', 'Dantic', 20, 'Pasig City', 'secremaria08@gmail.com', '$2y$10$j6hOlDWha2EPv.QSgVHPaegWWQ82spKK1EItN2KYkej/UnyXm0BBq', 'admin', 1, 0, 0, '237558', '2026-05-09 08:28:50', NULL, '2026-05-08 17:14:09', NULL),
(25, 'Binsoy Shop', 'Lasala', 30, 'Pasig City', 'ronagorrgiii@gmail.com', '$2y$10$9sMTvtCH2Vo4TaTLKlDvV.B4jARc/3Dy1v6nlJZf3aGOPfDCvpfye', 'seller', 1, 0, 0, '470931', '2026-05-09 08:38:23', NULL, '2026-05-08 21:43:02', 'The ultimate aesthetic laptop for Binsoy Shop! ✨ Features a gorgeous matte Sakura Pink finish and a white backlit keyboard. Perfectly lightweight for coffee shop study dates or WFH vibes.');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `cart`
--
ALTER TABLE `cart`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `locked_accounts`
--
ALTER TABLE `locked_accounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `logs`
--
ALTER TABLE `logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `reviews`
--
ALTER TABLE `reviews`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `shipments`
--
ALTER TABLE `shipments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_shipments_courier` (`courier_id`),
  ADD KEY `idx_shipments_order` (`order_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `cart`
--
ALTER TABLE `cart`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `locked_accounts`
--
ALTER TABLE `locked_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `logs`
--
ALTER TABLE `logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=169;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `reviews`
--
ALTER TABLE `reviews`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shipments`
--
ALTER TABLE `shipments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `locked_accounts`
--
ALTER TABLE `locked_accounts`
  ADD CONSTRAINT `locked_accounts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `logs`
--
ALTER TABLE `logs`
  ADD CONSTRAINT `logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `reviews`
--
ALTER TABLE `reviews`
  ADD CONSTRAINT `reviews_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reviews_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `shipments`
--
ALTER TABLE `shipments`
  ADD CONSTRAINT `shipments_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `shipments_ibfk_2` FOREIGN KEY (`courier_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
