-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 13, 2026 at 12:21 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

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

--
-- Dumping data for table `cart`
--

INSERT INTO `cart` (`id`, `user_id`, `product_id`, `quantity`) VALUES
(7, 7, 2, 1);

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
(40, NULL, 'mfa_generated', 'MFA Code: 163844', '::1', '2026-05-08 11:18:24'),
(41, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 11:19:03'),
(42, NULL, 'mfa_generated', 'MFA Code: 398744', '::1', '2026-05-08 11:22:22'),
(43, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 11:22:43'),
(44, NULL, 'mfa_generated', 'MFA Code: 995768', '::1', '2026-05-08 11:40:05'),
(45, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 11:40:23'),
(46, NULL, 'mfa_generated', 'MFA Code: 129851', '::1', '2026-05-08 12:02:07'),
(47, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 12:02:33'),
(48, NULL, 'mfa_generated', 'MFA Code: 883858', '::1', '2026-05-08 12:04:01'),
(49, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 12:04:13'),
(50, NULL, 'mfa_generated', 'MFA Code: 100405', '::1', '2026-05-08 12:07:15'),
(51, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 12:07:27'),
(52, NULL, 'mfa_generated', 'MFA Code: 455033', '::1', '2026-05-08 12:07:54'),
(53, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 12:08:05'),
(54, NULL, 'mfa_generated', 'MFA Code: 911927', '::1', '2026-05-08 12:27:53'),
(55, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 12:28:13'),
(56, NULL, 'mfa_generated', 'MFA Code: 649947', '::1', '2026-05-08 12:32:23'),
(57, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 12:32:40'),
(58, NULL, 'logout', 'User logged out', '::1', '2026-05-08 12:32:45'),
(59, NULL, 'mfa_generated', 'MFA Code: 396898', '::1', '2026-05-08 12:32:49'),
(60, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 12:35:07'),
(61, NULL, 'logout', 'User logged out', '::1', '2026-05-08 12:59:22'),
(62, NULL, 'mfa_generated', 'MFA Code: 700283', '::1', '2026-05-08 12:59:26'),
(63, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 12:59:39'),
(64, NULL, 'mfa_generated', 'MFA Code: 004583', '::1', '2026-05-08 13:02:41'),
(65, NULL, 'logout', 'User logged out', '::1', '2026-05-08 14:47:28'),
(66, NULL, 'mfa_generated', 'MFA Code: 277267', '::1', '2026-05-08 14:48:02'),
(67, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 14:48:18'),
(68, NULL, 'mfa_generated', 'MFA Code: 050262', '::1', '2026-05-08 14:50:21'),
(69, NULL, 'mfa_generated', 'MFA Code: 446301', '::1', '2026-05-08 14:50:50'),
(70, NULL, 'mfa_generated', 'MFA Code: 655087', '::1', '2026-05-08 14:51:10'),
(71, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 14:51:33'),
(72, NULL, 'mfa_generated', 'MFA Code: 437068', '::1', '2026-05-08 16:12:10'),
(73, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 16:12:28'),
(74, NULL, 'logout', 'User logged out', '::1', '2026-05-08 16:13:49'),
(75, NULL, 'mfa_generated', 'MFA Code: 639141', '::1', '2026-05-08 16:13:54'),
(76, NULL, 'mfa_generated', 'MFA Code: 658722', '::1', '2026-05-08 16:29:59'),
(77, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 16:31:09'),
(78, NULL, 'logout', 'User logged out', '::1', '2026-05-08 16:31:32'),
(79, NULL, 'mfa_generated', 'MFA Code: 505539', '::1', '2026-05-08 16:32:20'),
(80, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 16:32:32'),
(81, NULL, 'mfa_generated', 'MFA Code: 746634', '::1', '2026-05-08 16:37:42'),
(82, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 16:37:59'),
(83, NULL, 'mfa_generated', 'MFA Code: 062088', '::1', '2026-05-08 17:05:17'),
(84, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 17:06:23'),
(85, NULL, 'mfa_generated', 'MFA Code: 707541', '::1', '2026-05-08 17:06:50'),
(86, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 17:07:06'),
(87, NULL, 'mfa_generated', 'MFA Code: 686312', '::1', '2026-05-08 17:08:06'),
(88, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 17:08:22'),
(89, NULL, 'mfa_generated', 'MFA Code: 111830', '::1', '2026-05-08 17:11:13'),
(90, NULL, 'mfa_generated', 'MFA Code: 638365', '::1', '2026-05-08 17:12:10'),
(91, NULL, 'mfa_generated', 'MFA Code: 994626', '::1', '2026-05-08 17:14:24'),
(92, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 17:14:50'),
(93, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-08 17:14:50'),
(94, NULL, 'block_user', 'Admin blocked user ID 8', '::1', '2026-05-08 17:15:07'),
(95, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-08 17:15:21'),
(96, NULL, 'unblock_user', 'Admin unblocked user ID 8', '::1', '2026-05-08 17:21:13'),
(97, NULL, 'block_user', 'Admin blocked user ID 8', '::1', '2026-05-08 17:21:21'),
(98, NULL, 'logout', 'User logged out', '::1', '2026-05-08 17:21:29'),
(99, NULL, 'mfa_generated', 'MFA Code: 379335', '::1', '2026-05-08 17:21:37'),
(100, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 17:21:55'),
(101, NULL, 'mfa_generated', 'MFA Code: 304367', '::1', '2026-05-08 17:22:31'),
(102, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 17:23:15'),
(103, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-08 17:23:15'),
(104, NULL, 'block_user', 'Admin blocked user ID 6', '::1', '2026-05-08 17:23:22'),
(105, NULL, 'mfa_generated', 'MFA Code: 353460', '::1', '2026-05-08 17:29:18'),
(106, NULL, 'mfa_generated', 'MFA Code: 451091', '::1', '2026-05-08 17:29:47'),
(107, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 17:30:00'),
(108, NULL, 'mfa_generated', 'MFA Code: 102012', '::1', '2026-05-08 17:56:32'),
(109, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 17:57:08'),
(110, NULL, 'mfa_generated', 'MFA Code: 396840', '::1', '2026-05-08 18:24:20'),
(111, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 18:24:35'),
(112, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-08 18:24:35'),
(113, NULL, 'unblock_user', 'Admin unblocked user ID 8', '::1', '2026-05-08 18:24:39'),
(114, NULL, 'unblock_user', 'Admin unblocked user ID 6', '::1', '2026-05-08 18:24:42'),
(115, NULL, 'logout', 'User logged out', '::1', '2026-05-08 18:24:48'),
(116, NULL, 'mfa_generated', 'MFA Code: 466345', '::1', '2026-05-08 18:25:49'),
(117, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 18:26:09'),
(118, NULL, 'mfa_generated', 'MFA Code: 750606', '::1', '2026-05-08 19:23:37'),
(119, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 19:23:53'),
(120, NULL, 'logout', 'User logged out', '::1', '2026-05-08 19:47:48'),
(121, NULL, 'mfa_generated', 'MFA Code: 817478', '::1', '2026-05-08 19:48:04'),
(122, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 19:48:15'),
(123, NULL, 'mfa_generated', 'MFA Code: 501158', '::1', '2026-05-08 19:48:23'),
(124, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 19:48:44'),
(125, NULL, 'add_to_cart', 'Product ID 1 added to cart', '::1', '2026-05-08 19:49:11'),
(126, NULL, 'mfa_generated', 'MFA Code: 396305', '::1', '2026-05-08 19:51:16'),
(127, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 19:51:26'),
(128, NULL, 'logout', 'User logged out', '::1', '2026-05-08 20:03:53'),
(129, NULL, 'mfa_generated', 'MFA Code: 451297', '::1', '2026-05-08 20:10:47'),
(130, NULL, 'mfa_generated', 'MFA Code: 877449', '::1', '2026-05-08 20:11:08'),
(131, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 20:11:23'),
(132, NULL, 'mfa_generated', 'MFA Code: 957608', '::1', '2026-05-08 20:19:47'),
(133, NULL, 'mfa_generated', 'MFA Code: 379906', '::1', '2026-05-08 20:54:28'),
(134, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 20:55:10'),
(135, NULL, 'mfa_generated', 'MFA Code: 619487', '::1', '2026-05-08 21:42:27'),
(136, NULL, 'mfa_generated', 'MFA Code: 648706', '::1', '2026-05-08 21:43:04'),
(137, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 21:43:17'),
(138, NULL, 'logout', 'User logged out', '::1', '2026-05-08 21:47:10'),
(139, NULL, 'mfa_generated', 'MFA Code: 453378', '::1', '2026-05-08 21:47:32'),
(140, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 21:47:52'),
(141, NULL, 'add_to_cart', 'Product ID 1 added to cart', '::1', '2026-05-08 21:47:56'),
(142, NULL, 'add_to_cart', 'Product ID 4 added to cart', '::1', '2026-05-08 21:48:28'),
(143, NULL, 'add_to_cart', 'Product ID 5 added to cart', '::1', '2026-05-08 21:49:09'),
(144, NULL, 'update_cart', 'Cart quantities updated', '::1', '2026-05-08 21:51:19'),
(145, NULL, 'update_cart', 'Cart quantities updated', '::1', '2026-05-08 21:51:29'),
(146, NULL, 'add_to_cart', 'Product ID 5 added to cart', '::1', '2026-05-08 21:55:49'),
(147, NULL, 'add_to_cart', 'Product ID 1 added to cart', '::1', '2026-05-08 21:56:19'),
(148, NULL, 'mfa_generated', 'MFA Code: 478635', '::1', '2026-05-08 22:18:28'),
(149, NULL, 'mfa_generated', 'MFA Code: 518340', '::1', '2026-05-08 22:19:34'),
(150, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 22:19:51'),
(151, NULL, 'mfa_generated', 'MFA Code: 746894', '::1', '2026-05-09 00:12:10'),
(152, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-09 00:12:45'),
(153, NULL, 'update_cart', 'Cart quantities updated', '::1', '2026-05-09 00:13:13'),
(154, NULL, 'mfa_generated', 'MFA Code: 502941', '::1', '2026-05-09 00:14:30'),
(155, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-09 00:14:50'),
(156, NULL, 'logout', 'User logged out', '::1', '2026-05-09 00:15:41'),
(157, NULL, 'mfa_generated', 'MFA Code: 014984', '::1', '2026-05-09 00:16:48'),
(158, NULL, 'mfa_generated', 'MFA Code: 818755', '::1', '2026-05-09 00:17:49'),
(159, NULL, 'mfa_generated', 'MFA Code: 237558', '::1', '2026-05-09 00:18:50'),
(160, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-09 00:20:43'),
(161, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-09 00:20:43'),
(162, NULL, 'block_user', 'Admin blocked user ID 6', '::1', '2026-05-09 00:23:25'),
(163, NULL, 'logout', 'User logged out', '::1', '2026-05-09 00:23:30'),
(164, NULL, 'mfa_generated', 'MFA Code: 632535', '::1', '2026-05-09 00:24:24'),
(165, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-09 00:26:43'),
(166, NULL, 'update_cart', 'Cart quantities updated', '::1', '2026-05-09 00:27:10'),
(167, NULL, 'mfa_generated', 'MFA Code: 470931', '::1', '2026-05-09 00:28:23'),
(168, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-09 00:28:35'),
(169, NULL, 'mfa_generated', 'MFA Code: 517514', '::1', '2026-05-11 12:03:36'),
(170, NULL, 'mfa_generated', 'MFA Code: 769053', '::1', '2026-05-12 06:11:10'),
(171, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-12 06:11:48'),
(172, NULL, 'mfa_generated', 'MFA Code: 640581', '::1', '2026-05-12 06:12:30'),
(173, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-12 06:12:38'),
(174, NULL, 'mfa_generated', 'MFA Code: 939133', '::1', '2026-05-12 06:53:04'),
(175, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-12 06:53:15'),
(176, NULL, 'view_dashboard', 'Courier viewed dashboard', '::1', '2026-05-12 06:53:15'),
(177, NULL, 'view_history', 'Courier viewed delivery history', '::1', '2026-05-12 06:53:40'),
(178, NULL, 'logout', 'User logged out', '::1', '2026-05-12 06:53:43'),
(179, NULL, 'mfa_generated', 'MFA Code: 662018', '::1', '2026-05-12 06:53:45'),
(180, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-12 06:53:57'),
(181, NULL, 'view_dashboard', 'Courier viewed dashboard', '::1', '2026-05-12 06:53:57'),
(182, NULL, 'view_dashboard', 'Courier viewed dashboard', '::1', '2026-05-12 06:54:01'),
(183, NULL, 'view_history', 'Courier viewed delivery history', '::1', '2026-05-12 06:58:11'),
(184, NULL, 'logout', 'User logged out', '::1', '2026-05-12 06:58:12'),
(185, NULL, 'mfa_generated', 'MFA Code: 198992', '::1', '2026-05-12 06:58:14'),
(186, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-12 06:58:24'),
(187, NULL, 'mfa_generated', 'MFA Code: 309391', '::1', '2026-05-12 06:59:04'),
(188, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-12 06:59:13'),
(189, NULL, 'view_dashboard', 'Courier viewed dashboard', '::1', '2026-05-12 06:59:13'),
(190, NULL, 'view_history', 'Courier viewed delivery history', '::1', '2026-05-12 07:01:13'),
(191, NULL, 'view_dashboard', 'Courier viewed dashboard', '::1', '2026-05-12 07:01:15'),
(192, NULL, 'view_dashboard', 'Courier viewed dashboard', '::1', '2026-05-12 07:01:19'),
(193, NULL, 'mfa_generated', 'MFA Code: 171886', '::1', '2026-05-12 07:10:57'),
(194, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-12 07:11:09'),
(195, NULL, 'update_cart', 'Cart quantities updated', '::1', '2026-05-12 07:12:47'),
(196, NULL, 'registration', 'User registered. Email sent: yes', '::1', '2026-05-16 06:29:16'),
(197, NULL, 'registration', 'User registered. Email sent: yes', '::1', '2026-05-18 12:22:56'),
(198, NULL, 'mfa_generated', 'MFA Code: 828414', '::1', '2026-05-19 13:02:30'),
(199, NULL, 'mfa_generated', 'MFA Code: 205186', '::1', '2026-05-19 13:08:25'),
(200, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-19 13:08:46'),
(201, NULL, 'mfa_generated', 'MFA Code: 862159', '::1', '2026-05-19 13:09:29'),
(202, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-19 13:09:38'),
(203, NULL, 'mfa_generated', 'MFA Code: 826566', '::1', '2026-05-19 13:10:18'),
(204, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-19 13:10:27'),
(205, NULL, 'view_dashboard', 'Courier viewed dashboard', '::1', '2026-05-19 13:10:27'),
(206, NULL, 'view_history', 'Courier viewed delivery history', '::1', '2026-05-19 13:10:39'),
(207, NULL, 'logout', 'User logged out', '::1', '2026-05-19 13:10:39'),
(208, NULL, 'mfa_generated', 'MFA Code: 673819', '::1', '2026-05-19 13:11:40'),
(209, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-19 13:11:53'),
(210, NULL, 'mfa_generated', 'MFA Code: 153543', '::1', '2026-05-21 05:53:24'),
(211, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-21 05:53:39'),
(212, NULL, 'mfa_generated', 'MFA Code: 514572', '::1', '2026-05-21 05:55:46'),
(213, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-21 05:55:58'),
(214, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-21 05:55:58'),
(215, NULL, 'unblock_user', 'Admin unblocked user ID 6', '::1', '2026-05-21 05:56:08'),
(216, NULL, 'logout', 'User logged out', '::1', '2026-05-21 06:04:34'),
(217, NULL, 'totp_setup_initiated', 'First login — TOTP setup started', '::1', '2026-05-21 06:41:54'),
(218, NULL, 'totp_setup_complete', 'Google Authenticator configured', '::1', '2026-05-21 06:43:54'),
(219, NULL, 'login_success', 'User logged in after TOTP setup', '::1', '2026-05-21 06:43:54'),
(220, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-21 06:50:16'),
(221, NULL, 'login_success', 'User logged in via Google Authenticator TOTP', '::1', '2026-05-21 06:50:28'),
(222, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-21 06:50:51'),
(223, NULL, 'totp_failed', 'Invalid TOTP code entered', '::1', '2026-05-21 06:50:58'),
(224, NULL, 'login_success', 'User logged in via Google Authenticator TOTP', '::1', '2026-05-21 06:51:03'),
(225, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-21 07:20:59'),
(226, NULL, 'totp_failed', 'Invalid TOTP code entered', '::1', '2026-05-21 07:21:38'),
(227, NULL, 'login_success', 'User logged in via Google Authenticator TOTP', '::1', '2026-05-21 07:21:42'),
(228, NULL, 'logout', 'User logged out', '::1', '2026-05-21 07:22:37'),
(229, NULL, 'totp_setup_initiated', 'First login — TOTP setup started', '::1', '2026-05-21 07:23:12'),
(230, NULL, 'totp_setup_initiated', 'First login — TOTP setup started', '::1', '2026-05-21 07:23:22'),
(231, NULL, 'totp_setup_initiated', 'First login — TOTP setup started', '::1', '2026-05-22 07:20:26'),
(232, NULL, 'totp_setup_complete', 'Google Authenticator configured', '::1', '2026-05-22 07:21:44'),
(233, NULL, 'login_success', 'User logged in after TOTP setup', '::1', '2026-05-22 07:21:44'),
(234, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-22 07:21:44'),
(235, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-22 07:25:00'),
(236, NULL, 'login_success', 'User logged in via Google Authenticator TOTP', '::1', '2026-05-22 07:25:12'),
(237, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-22 07:25:12'),
(238, NULL, 'block_user', 'Admin blocked user ID 31', '::1', '2026-05-22 07:30:34'),
(239, NULL, 'logout', 'User logged out', '::1', '2026-05-22 07:37:34'),
(240, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-22 07:37:44'),
(241, NULL, 'login_success', 'User logged in via Google Authenticator TOTP', '::1', '2026-05-22 07:38:02'),
(242, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-22 07:38:02'),
(243, NULL, 'unblock_user', 'Admin unblocked user ID 31', '::1', '2026-05-22 07:38:07'),
(244, NULL, 'logout', 'User logged out', '::1', '2026-05-22 07:44:19'),
(245, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-22 07:44:21'),
(246, NULL, 'login_success', 'User logged in via Google Authenticator TOTP', '::1', '2026-05-22 07:44:40'),
(247, NULL, 'update_cart', 'Cart quantities updated', '::1', '2026-05-22 07:45:45'),
(248, NULL, 'update_cart', 'Cart quantities updated', '::1', '2026-05-22 07:45:49'),
(249, NULL, 'logout', 'User logged out', '::1', '2026-05-22 08:00:43'),
(250, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-22 08:36:11'),
(251, NULL, 'login_success', 'User logged in via Google Authenticator TOTP', '::1', '2026-05-22 08:36:32'),
(252, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-22 08:47:08'),
(253, NULL, 'totp_failed', 'Invalid TOTP code entered', '::1', '2026-05-22 08:47:28'),
(254, NULL, 'totp_failed', 'Invalid TOTP code entered', '::1', '2026-05-22 08:47:33'),
(255, NULL, 'login_success', 'User logged in via Google Authenticator TOTP', '::1', '2026-05-22 08:47:59'),
(256, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-22 08:47:59'),
(257, NULL, 'logout', 'User logged out', '::1', '2026-05-22 08:48:02'),
(258, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-22 08:48:09'),
(259, NULL, 'login_success', 'User logged in via Google Authenticator TOTP', '::1', '2026-05-22 08:48:29'),
(260, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-22 08:48:29'),
(261, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-22 08:48:51'),
(262, NULL, 'logout', 'User logged out', '::1', '2026-05-22 09:01:22'),
(263, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-22 09:01:38'),
(264, NULL, 'totp_failed', 'Invalid TOTP code entered', '::1', '2026-05-22 09:02:14'),
(265, NULL, 'login_success', 'User logged in via Google Authenticator TOTP', '::1', '2026-05-22 09:02:43'),
(266, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-22 09:02:43'),
(267, NULL, 'profile_update', 'Admin updated their profile info', '::1', '2026-05-22 09:06:47'),
(268, NULL, 'logout', 'User logged out', '::1', '2026-05-22 09:06:55'),
(269, NULL, 'totp_setup_initiated', 'First login — TOTP setup started', '::1', '2026-05-22 09:11:08'),
(270, NULL, 'totp_setup_complete', 'Google Authenticator configured', '::1', '2026-05-22 09:11:49'),
(271, NULL, 'login_success', 'User logged in after TOTP setup', '::1', '2026-05-22 09:11:49'),
(272, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-22 09:14:35'),
(273, NULL, 'login_success', 'User logged in via Google Authenticator TOTP', '::1', '2026-05-22 09:15:30'),
(274, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-22 09:25:26'),
(275, NULL, 'login_success', 'User logged in via Google Authenticator TOTP', '::1', '2026-05-22 09:26:30'),
(276, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-22 09:26:30'),
(277, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-22 09:27:35'),
(278, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-22 09:28:01'),
(279, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-22 10:22:48'),
(280, NULL, 'login_success', 'User logged in via Google Authenticator TOTP', '::1', '2026-05-22 10:23:16'),
(281, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-22 10:23:16'),
(282, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-22 11:45:42'),
(283, NULL, 'login_success', 'User logged in via Google Authenticator TOTP', '::1', '2026-05-22 11:46:05'),
(284, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-22 11:46:05'),
(285, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-22 12:00:42'),
(286, NULL, 'login_success', 'User logged in via Google Authenticator TOTP', '::1', '2026-05-22 12:00:47'),
(287, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-22 12:00:47'),
(288, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-22 12:00:50'),
(289, NULL, 'block_user', 'Admin blocked user ID 31', '::1', '2026-05-22 12:00:56'),
(290, NULL, 'logout', 'User logged out', '::1', '2026-05-22 12:01:14'),
(291, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-22 12:01:56'),
(292, NULL, 'login_success', 'User logged in via Google Authenticator TOTP', '::1', '2026-05-22 12:02:03'),
(293, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-22 12:02:03'),
(294, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-22 23:50:59'),
(295, NULL, 'totp_failed', 'Invalid TOTP code entered', '::1', '2026-05-22 23:51:23'),
(296, NULL, 'totp_failed', 'Invalid TOTP code entered', '::1', '2026-05-22 23:51:33'),
(297, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-22 23:51:43'),
(298, NULL, 'totp_failed', 'Invalid TOTP code entered', '::1', '2026-05-22 23:51:50'),
(299, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-23 00:28:45'),
(300, NULL, 'totp_failed', 'Invalid TOTP code entered', '::1', '2026-05-23 00:29:35'),
(301, NULL, 'totp_setup_initiated', 'First login — TOTP setup started', '::1', '2026-05-23 00:31:46'),
(302, NULL, 'totp_setup_complete', 'Google Authenticator configured', '::1', '2026-05-23 00:32:00'),
(303, NULL, 'login_success', 'User logged in after TOTP setup', '::1', '2026-05-23 00:32:00'),
(304, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-23 00:32:00'),
(305, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-23 00:34:17'),
(306, NULL, 'totp_setup_initiated', 'First login — TOTP setup started', '::1', '2026-05-23 00:38:59'),
(307, NULL, 'totp_setup_complete', 'Google Authenticator configured', '::1', '2026-05-23 00:40:24'),
(308, NULL, 'login_success', 'User logged in after TOTP setup', '::1', '2026-05-23 00:40:24'),
(309, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-23 00:49:03'),
(310, NULL, 'login_success', 'User logged in via Google Authenticator TOTP', '::1', '2026-05-23 00:49:35'),
(311, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-23 00:49:35'),
(312, NULL, 'logout', 'User logged out', '::1', '2026-05-23 00:51:01'),
(313, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-23 01:00:54'),
(314, NULL, 'unlock_user', 'Admin unlocked user ID 31', '::1', '2026-05-23 01:05:44'),
(315, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-23 01:05:59'),
(316, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-23 01:06:17'),
(317, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-23 01:06:42'),
(318, NULL, 'settings_update', 'Admin updated security settings (max_attempts=2)', '::1', '2026-05-23 01:06:59'),
(319, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-23 01:07:19'),
(320, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-23 01:10:16'),
(321, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-23 01:11:53'),
(322, NULL, 'logout', 'User logged out', '::1', '2026-05-23 01:27:18'),
(323, NULL, 'totp_setup_initiated', 'First login — TOTP setup started', '::1', '2026-05-23 01:27:58'),
(324, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-23 01:29:07'),
(325, NULL, 'login_success', 'User logged in via Google Authenticator TOTP', '::1', '2026-05-23 01:29:22'),
(326, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-23 01:34:35'),
(327, NULL, 'login_success', 'User logged in via Google Authenticator TOTP', '::1', '2026-05-23 01:34:45'),
(328, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-23 01:34:45'),
(329, NULL, 'unlock_user', 'Admin unlocked user ID 31', '::1', '2026-05-23 01:34:53'),
(330, NULL, 'logout', 'User logged out', '::1', '2026-05-23 01:34:58'),
(331, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-23 01:35:07'),
(332, NULL, 'totp_failed', 'Invalid TOTP code entered', '::1', '2026-05-23 01:35:25'),
(333, NULL, 'totp_failed', 'Invalid TOTP code entered', '::1', '2026-05-23 01:35:37'),
(334, NULL, 'totp_failed', 'Invalid TOTP code entered', '::1', '2026-05-23 01:35:48'),
(335, NULL, 'logout', 'User logged out', '::1', '2026-05-23 01:37:52'),
(336, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-23 01:38:00'),
(337, NULL, 'login_success', 'User logged in via Google Authenticator TOTP', '::1', '2026-05-23 01:38:15'),
(338, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-23 01:38:15'),
(339, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-23 01:43:09'),
(340, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-07-06 16:10:26'),
(341, NULL, 'totp_failed', 'Invalid TOTP code entered', '::1', '2026-07-06 16:11:13'),
(342, NULL, 'totp_failed', 'Invalid TOTP code entered', '::1', '2026-07-06 16:11:22'),
(343, NULL, 'totp_failed', 'Invalid TOTP code entered', '::1', '2026-07-06 16:11:27'),
(344, NULL, 'totp_setup_initiated', 'First login — TOTP setup started', '::1', '2026-07-06 16:14:47'),
(345, NULL, 'totp_setup_complete', 'Google Authenticator configured', '::1', '2026-07-06 16:15:14'),
(346, NULL, 'login_success', 'User logged in after TOTP setup', '::1', '2026-07-06 16:15:14'),
(347, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-07-07 04:23:11'),
(348, NULL, 'login_success', 'User logged in via Google Authenticator TOTP', '::1', '2026-07-07 04:23:25'),
(349, NULL, 'account_locked', 'Account locked after 3 failed attempts', '::1', '2026-07-08 17:55:35'),
(350, NULL, 'totp_setup_initiated', 'First login — TOTP setup started', '::1', '2026-07-08 17:57:09'),
(351, NULL, 'totp_setup_initiated', 'First login — TOTP setup started', '::1', '2026-07-09 07:57:38'),
(352, NULL, 'login_success', 'User logged in', '::1', '2026-07-09 07:58:06'),
(353, NULL, 'logout', 'User logged out', '::1', '2026-07-09 08:00:19'),
(354, 40, 'login_success', 'User logged in', '::1', '2026-07-09 08:02:57'),
(355, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-09 08:02:57'),
(356, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-09 08:03:52'),
(357, 40, 'logout', 'User logged out', '::1', '2026-07-09 08:03:54'),
(358, NULL, 'login_success', 'User logged in', '::1', '2026-07-09 08:17:59'),
(359, 40, 'login_success', 'User logged in', '::1', '2026-07-09 09:30:02'),
(360, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-09 09:30:03'),
(361, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-09 09:30:19'),
(362, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-09 09:30:28'),
(363, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-09 09:36:47'),
(364, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-09 09:42:16'),
(365, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-09 09:42:31'),
(366, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-09 09:53:55'),
(367, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-09 09:54:09'),
(368, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-09 09:55:47'),
(369, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-09 09:57:26'),
(370, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-09 09:58:01'),
(371, 40, 'login_success', 'User logged in', '::1', '2026-07-09 10:49:54'),
(372, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-09 10:49:54'),
(373, NULL, 'login_success', 'User logged in', '::1', '2026-07-09 16:42:38'),
(374, NULL, 'login_success', 'User logged in', '::1', '2026-07-09 18:50:28'),
(375, NULL, 'logout', 'User logged out', '::1', '2026-07-09 18:52:45'),
(376, 40, 'login_success', 'User logged in', '::1', '2026-07-09 18:53:03'),
(377, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-09 18:53:04'),
(378, 40, 'login_success', 'User logged in', '::1', '2026-07-10 07:14:50'),
(379, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-10 07:14:50'),
(380, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-10 07:14:58'),
(381, 40, 'logout', 'User logged out', '::1', '2026-07-10 07:15:36'),
(382, 40, 'login_success', 'User logged in', '::1', '2026-07-10 07:23:25'),
(383, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-10 07:23:25'),
(384, 40, 'logout', 'User logged out', '::1', '2026-07-10 07:23:31'),
(385, NULL, 'login_success', 'User logged in', '::1', '2026-07-10 07:23:56'),
(386, NULL, 'logout', 'User logged out', '::1', '2026-07-10 07:25:18'),
(387, 40, 'login_success', 'User logged in', '::1', '2026-07-10 07:27:59'),
(388, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-10 07:27:59'),
(389, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-10 07:29:05'),
(390, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-10 07:29:12'),
(391, 40, 'logout', 'User logged out', '::1', '2026-07-10 07:29:17'),
(392, 40, 'login_success', 'User logged in', '::1', '2026-07-11 06:56:24'),
(393, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-11 06:56:24'),
(394, 40, 'logout', 'User logged out', '::1', '2026-07-11 06:56:41'),
(395, 47, 'login_success', 'User logged in', '::1', '2026-07-11 06:58:31'),
(396, 47, 'login_success', 'User logged in', '::1', '2026-07-11 07:24:30'),
(397, 47, 'logout', 'User logged out', '::1', '2026-07-11 07:25:12'),
(398, NULL, 'login_success', 'User logged in', '::1', '2026-07-11 07:32:54'),
(399, NULL, 'add_product', 'Added product: Wireless Keyboard', '::1', '2026-07-11 07:37:44'),
(400, NULL, 'logout', 'User logged out', '::1', '2026-07-11 07:37:50'),
(401, 47, 'login_success', 'User logged in', '::1', '2026-07-11 07:37:57'),
(402, 40, 'login_success', 'User logged in', '::1', '2026-07-11 08:53:22'),
(403, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-11 08:53:22'),
(404, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-11 08:53:34'),
(405, 40, 'logout', 'User logged out', '::1', '2026-07-11 08:53:57'),
(406, 47, 'login_success', 'User logged in', '::1', '2026-07-11 11:29:41'),
(407, NULL, 'login_success', 'User logged in', '::1', '2026-07-11 16:22:18'),
(408, NULL, 'login_success', 'User logged in', '::1', '2026-07-11 16:25:42'),
(409, NULL, 'view_dashboard', 'Retail Officer viewed dashboard', '::1', '2026-07-11 16:25:42'),
(410, NULL, 'logout', 'User logged out', '::1', '2026-07-11 16:34:10'),
(411, 47, 'login_success', 'User logged in', '::1', '2026-07-11 17:03:16'),
(412, 47, 'logout', 'User logged out', '::1', '2026-07-11 17:04:36'),
(413, NULL, 'login_success', 'User logged in', '::1', '2026-07-11 17:10:14'),
(414, NULL, 'add_product', 'Added product: Jabilee', '::1', '2026-07-11 17:16:38'),
(415, NULL, 'logout', 'User logged out', '::1', '2026-07-11 17:17:02'),
(416, 47, 'login_success', 'User logged in', '::1', '2026-07-11 17:17:14'),
(417, 47, 'update_cart', 'Cart quantities updated', '::1', '2026-07-11 17:18:14'),
(418, 47, 'logout', 'User logged out', '::1', '2026-07-11 17:19:22'),
(419, 40, 'login_success', 'User logged in', '::1', '2026-07-11 17:19:50'),
(420, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-11 17:19:50'),
(421, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-11 17:24:05'),
(422, 40, 'login_success', 'User logged in', '::1', '2026-07-12 00:13:24'),
(423, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-12 00:13:24'),
(424, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-12 00:13:27'),
(425, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-12 00:13:28'),
(426, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-12 00:18:30'),
(427, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-12 00:23:25'),
(428, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-12 00:23:48'),
(429, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-12 00:32:29'),
(430, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-12 00:32:57'),
(431, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-12 00:33:06'),
(432, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-12 00:34:06'),
(433, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-12 00:34:30'),
(434, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-12 00:47:13'),
(435, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-12 00:47:25'),
(436, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-12 00:47:59'),
(437, 40, 'logout', 'User logged out', '::1', '2026-07-12 00:49:59'),
(438, NULL, 'login_success', 'User logged in', '::1', '2026-07-12 00:50:20'),
(439, NULL, 'view_dashboard', 'Retail Officer viewed dashboard', '::1', '2026-07-12 00:50:20'),
(440, NULL, 'view_dashboard', 'Retail Officer viewed dashboard', '::1', '2026-07-12 00:50:29'),
(441, NULL, 'logout', 'User logged out', '::1', '2026-07-12 00:51:23'),
(442, NULL, 'login_success', 'User logged in', '::1', '2026-07-12 00:55:04'),
(443, NULL, 'view_dashboard', 'Technician viewed dashboard', '::1', '2026-07-12 00:55:04'),
(444, NULL, 'profile_update', 'Technician updated their profile info', '::1', '2026-07-12 00:55:17'),
(445, NULL, 'view_dashboard', 'Technician viewed dashboard', '::1', '2026-07-12 00:55:20'),
(446, NULL, 'logout', 'User logged out', '::1', '2026-07-12 00:55:28'),
(447, 40, 'login_success', 'User logged in', '::1', '2026-07-12 00:57:01'),
(448, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-12 00:57:01'),
(449, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-12 00:57:35'),
(450, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-12 00:57:44'),
(451, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-12 00:58:21'),
(452, 40, 'block_user', 'Admin blocked user ID 50', '::1', '2026-07-12 01:00:10'),
(453, 40, 'unblock_user', 'Admin unblocked user ID 50', '::1', '2026-07-12 01:00:16'),
(454, 40, 'login_success', 'User logged in', '::1', '2026-07-12 04:07:40'),
(455, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-12 04:07:40'),
(456, 40, 'create_staff_account', 'Admin created a Inventory Custodian account for samj28005@gmail.com', '::1', '2026-07-12 04:09:17'),
(457, 40, 'logout', 'User logged out', '::1', '2026-07-12 04:09:24'),
(458, 40, 'login_success', 'User logged in', '::1', '2026-07-12 04:09:51'),
(459, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-12 04:09:51'),
(460, 40, 'logout', 'User logged out', '::1', '2026-07-12 04:11:43'),
(461, NULL, 'login_success', 'User logged in', '::1', '2026-07-12 04:11:50'),
(462, NULL, 'view_dashboard', 'Retail Officer viewed dashboard', '::1', '2026-07-12 04:11:50'),
(463, NULL, 'view_dashboard', 'Retail Officer viewed dashboard', '::1', '2026-07-12 04:11:58'),
(464, NULL, 'logout', 'User logged out', '::1', '2026-07-12 04:12:01'),
(465, 47, 'login_success', 'User logged in', '::1', '2026-07-12 04:12:10'),
(466, 47, 'logout', 'User logged out', '::1', '2026-07-12 04:13:53'),
(467, 40, 'login_success', 'User logged in', '::1', '2026-07-12 04:13:59'),
(468, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-12 04:13:59'),
(469, 40, 'create_staff_account', 'Admin created a Retail Officer account for samj28005@gmail.com', '::1', '2026-07-12 04:14:43'),
(470, 40, 'logout', 'User logged out', '::1', '2026-07-12 04:14:55'),
(471, NULL, 'login_success', 'User logged in', '::1', '2026-07-12 04:15:02'),
(472, NULL, 'view_dashboard', 'Retail Officer viewed dashboard', '::1', '2026-07-12 04:15:02'),
(473, NULL, 'logout', 'User logged out', '::1', '2026-07-12 04:15:15'),
(474, 40, 'login_success', 'User logged in', '::1', '2026-07-12 06:23:06'),
(475, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-12 06:23:06'),
(476, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-12 06:28:41'),
(477, 40, 'logout', 'User logged out', '::1', '2026-07-12 06:28:48'),
(478, NULL, 'login_success', 'User logged in', '::1', '2026-07-12 06:29:23'),
(479, NULL, 'login_success', 'User logged in', '::1', '2026-07-12 16:31:13'),
(480, NULL, 'view_dashboard', 'Retail Officer viewed dashboard', '::1', '2026-07-12 16:31:14'),
(481, NULL, 'view_dashboard', 'Retail Officer viewed dashboard', '::1', '2026-07-12 16:31:21'),
(482, NULL, 'view_dashboard', 'Retail Officer viewed dashboard', '::1', '2026-07-12 16:44:33'),
(483, NULL, 'login_success', 'User logged in', '::1', '2026-07-13 01:43:35'),
(484, NULL, 'logout', 'User logged out', '::1', '2026-07-13 01:48:27'),
(485, NULL, 'login_success', 'User logged in', '::1', '2026-07-13 01:51:48'),
(486, NULL, 'logout', 'User logged out', '::1', '2026-07-13 01:51:58'),
(487, NULL, 'login_success', 'User logged in', '::1', '2026-07-13 01:53:37'),
(488, NULL, 'logout', 'User logged out', '::1', '2026-07-13 01:53:50'),
(489, 40, 'login_success', 'User logged in', '::1', '2026-07-13 01:54:10'),
(490, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-13 01:54:10'),
(491, 40, 'settings_update', 'Admin updated security settings (max_attempts=3)', '::1', '2026-07-13 01:54:55'),
(492, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-13 01:55:06'),
(493, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-13 01:55:16'),
(494, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-13 01:55:20'),
(495, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-13 01:55:25'),
(496, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-13 01:56:25'),
(497, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-13 01:56:45'),
(498, 40, 'create_staff_account', 'Admin created a Technician account for samj28005@gmail.com', '::1', '2026-07-13 01:57:53'),
(499, 40, 'create_staff_account', 'Admin created a Inventory Custodian account for jumaoas.samantha05@gmail.com', '::1', '2026-07-13 01:59:12'),
(500, 40, 'logout', 'User logged out', '::1', '2026-07-13 01:59:15'),
(501, 56, 'login_success', 'User logged in', '::1', '2026-07-13 01:59:26'),
(502, 56, 'view_dashboard', 'Technician viewed dashboard', '::1', '2026-07-13 01:59:26'),
(503, 56, 'view_dashboard', 'Technician viewed dashboard', '::1', '2026-07-13 01:59:30'),
(504, 56, 'view_dashboard', 'Technician viewed dashboard', '::1', '2026-07-13 02:01:02'),
(505, 56, 'logout', 'User logged out', '::1', '2026-07-13 02:01:05'),
(506, 57, 'login_success', 'User logged in', '::1', '2026-07-13 02:01:17'),
(507, 57, 'view_dashboard', 'Inventory Custodian viewed dashboard', '::1', '2026-07-13 02:01:17'),
(508, 57, 'logout', 'User logged out', '::1', '2026-07-13 02:01:35'),
(509, 56, 'login_success', 'User logged in', '::1', '2026-07-13 02:01:47'),
(510, 56, 'view_dashboard', 'Technician viewed dashboard', '::1', '2026-07-13 02:01:47'),
(511, 56, 'login_success', 'User logged in', '::1', '2026-07-13 05:12:56'),
(512, 56, 'view_dashboard', 'Associate viewed dashboard', '::1', '2026-07-13 05:12:56'),
(513, 56, 'view_dashboard', 'Associate viewed dashboard', '::1', '2026-07-13 05:16:07'),
(514, 56, 'logout', 'User logged out', '::1', '2026-07-13 05:16:15'),
(515, 57, 'login_success', 'User logged in', '::1', '2026-07-13 05:16:24'),
(516, 57, 'view_dashboard', 'Associate viewed dashboard', '::1', '2026-07-13 05:16:24'),
(517, 57, 'view_dashboard', 'Associate viewed dashboard', '::1', '2026-07-13 05:16:34'),
(518, 57, 'logout', 'User logged out', '::1', '2026-07-13 05:16:36'),
(519, NULL, 'login_success', 'User logged in', '::1', '2026-07-13 05:44:56'),
(520, NULL, 'logout', 'User logged out', '::1', '2026-07-13 05:45:08'),
(521, 40, 'login_success', 'User logged in', '::1', '2026-07-13 05:45:24'),
(522, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-13 05:45:24'),
(523, 40, 'password_change', 'Admin changed their password', '::1', '2026-07-13 05:46:13'),
(524, 40, 'create_staff_account', 'Admin created a Retail Officer account for 136740100226@ncr2.deped.gov.ph', '::1', '2026-07-13 05:47:25'),
(525, 40, 'settings_update', 'Admin updated security settings (max_attempts=4)', '::1', '2026-07-13 05:47:52'),
(526, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-13 05:48:05'),
(527, 40, 'logout', 'User logged out', '::1', '2026-07-13 05:48:11'),
(528, 59, 'login_success', 'User logged in', '::1', '2026-07-13 05:49:10'),
(529, 59, 'login_success', 'User logged in', '::1', '2026-07-13 06:16:06'),
(530, 59, 'logout', 'User logged out', '::1', '2026-07-13 06:16:27'),
(531, 56, 'login_success', 'User logged in', '::1', '2026-07-13 06:16:30'),
(532, 56, 'view_dashboard', 'Associate viewed dashboard', '::1', '2026-07-13 06:16:30'),
(533, 56, 'view_history', 'Associate viewed delivery history', '::1', '2026-07-13 06:16:36'),
(534, 56, 'logout', 'User logged out', '::1', '2026-07-13 06:19:00'),
(535, 59, 'login_success', 'User logged in', '::1', '2026-07-13 06:19:10'),
(536, 59, 'logout', 'User logged out', '::1', '2026-07-13 06:19:23'),
(537, 40, 'login_success', 'User logged in', '::1', '2026-07-13 06:19:29'),
(538, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-13 06:19:29'),
(539, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-13 06:20:24'),
(540, 40, 'logout', 'User logged out', '::1', '2026-07-13 06:23:08'),
(541, 47, 'login_success', 'User logged in', '::1', '2026-07-13 06:23:17'),
(542, 47, 'login_success', 'User logged in', '::1', '2026-07-13 10:14:42'),
(543, 47, 'logout', 'User logged out', '::1', '2026-07-13 10:14:56'),
(544, 40, 'login_success', 'User logged in', '::1', '2026-07-13 10:15:28'),
(545, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-13 10:15:28'),
(546, 40, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-07-13 10:15:40'),
(547, 40, 'logout', 'User logged out', '::1', '2026-07-13 10:15:41'),
(548, 56, 'login_success', 'User logged in', '::1', '2026-07-13 10:15:46'),
(549, 56, 'view_dashboard', 'Associate viewed dashboard', '::1', '2026-07-13 10:15:46'),
(550, 56, 'view_history', 'Associate viewed delivery history', '::1', '2026-07-13 10:16:09'),
(551, 56, 'view_dashboard', 'Associate viewed dashboard', '::1', '2026-07-13 10:16:15'),
(552, 56, 'view_history', 'Associate viewed delivery history', '::1', '2026-07-13 10:16:18'),
(553, 56, 'view_history', 'Associate viewed delivery history', '::1', '2026-07-13 10:16:20'),
(554, 56, 'logout', 'User logged out', '::1', '2026-07-13 10:16:25'),
(555, 59, 'login_success', 'User logged in', '::1', '2026-07-13 10:16:33'),
(556, 59, 'logout', 'User logged out', '::1', '2026-07-13 10:19:20');

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
-- Table structure for table `site_settings`
--

CREATE TABLE `site_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `site_settings`
--

INSERT INTO `site_settings` (`id`, `setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES
(1, 'pw_min_length', '8', 40, '2026-07-13 01:54:54'),
(2, 'pw_require_upper', '0', 40, '2026-07-13 05:47:52'),
(3, 'pw_require_lower', '1', 40, '2026-07-13 01:54:55'),
(4, 'pw_require_number', '1', 40, '2026-07-13 01:54:55'),
(5, 'pw_require_special', '1', 40, '2026-07-13 01:54:55'),
(16, 'max_failed_attempts', '4', 40, '2026-07-13 05:47:52');

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
  `role` enum('admin','seller','client','courier','retail_officer','technician','inventory_custodian') DEFAULT 'client',
  `is_verified` tinyint(4) DEFAULT 0,
  `is_locked` tinyint(4) DEFAULT 0,
  `failed_attempts` int(11) DEFAULT 0,
  `activation_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `totp_secret` varchar(64) DEFAULT NULL,
  `totp_enabled` tinyint(4) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `surname`, `age`, `address`, `email`, `password`, `role`, `is_verified`, `is_locked`, `failed_attempts`, `activation_token`, `created_at`, `totp_secret`, `totp_enabled`) VALUES
(40, 'Sam', 'jazz', 21, 'somewhere', 'jumaoas_samantha@plpasig.edu.ph', '$2y$10$5zlCTEJlq42OngE6cax/vuEM77PylNswy6pSAKLrI.msqtypyALWy', 'admin', 1, 0, 0, NULL, '2026-07-09 08:02:14', NULL, 0),
(47, 'Samantha', 'Jumao-as', 21, '786 Blk 4 Lot 5 Strawberry Street, Damayan Park Homes, Maybunga', 'samanthajumaoas@gmail.com', '$2y$10$mSoXmmVSGTn1AhhaxliIB.C8tJ.P2gkyXR9gg07LIs7dIkerrT4pm', 'client', 1, 0, 0, NULL, '2026-07-11 06:57:29', NULL, 0),
(56, 'yow', 'kabado', 20, 'Pasig City', 'samj28005@gmail.com', '$2y$10$zdCwjHJWnfPX74DfEGopvuu43bMpLKLD3kmfcTyDfrYyDAe2j1gEi', 'technician', 1, 0, 0, NULL, '2026-07-13 01:57:53', NULL, 0),
(57, 'hahahahahaha', 'hooligan', 21, 'Pasig City', 'jumaoas.samantha05@gmail.com', '$2y$10$6fzIzmW1ffr7xC70qVrUr.hDl7.vo5OITzHPwoXMox0qznaZ0jOZa', 'inventory_custodian', 1, 0, 0, NULL, '2026-07-13 01:59:12', NULL, 0),
(59, 'samsam', 'oj', 16, 'Pasig City', '136740100226@ncr2.deped.gov.ph', '$2y$10$0t.3k9oDdKSEiTb3qQPexO9G26.0R7MkJcCgsaRCbuk3bJpQGdG9S', 'retail_officer', 1, 0, 0, NULL, '2026-07-13 05:47:25', NULL, 0);

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
-- Indexes for table `site_settings`
--
ALTER TABLE `site_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `locked_accounts`
--
ALTER TABLE `locked_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `logs`
--
ALTER TABLE `logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=557;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

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
-- AUTO_INCREMENT for table `site_settings`
--
ALTER TABLE `site_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=65;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=60;

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
