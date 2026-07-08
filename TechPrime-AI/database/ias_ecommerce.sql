-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 07, 2026 at 06:06 PM
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
(91, NULL, 'mfa_generated', 'MFA Code: 994626', '::1', '2026-05-08 17:14:24'),
(92, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 17:14:50'),
(93, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-08 17:14:50'),
(94, NULL, 'block_user', 'Admin blocked user ID 8', '::1', '2026-05-08 17:15:07'),
(95, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-08 17:15:21'),
(96, NULL, 'unblock_user', 'Admin unblocked user ID 8', '::1', '2026-05-08 17:21:13'),
(97, NULL, 'block_user', 'Admin blocked user ID 8', '::1', '2026-05-08 17:21:21'),
(98, NULL, 'logout', 'User logged out', '::1', '2026-05-08 17:21:29'),
(99, 6, 'mfa_generated', 'MFA Code: 379335', '::1', '2026-05-08 17:21:37'),
(100, 6, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 17:21:55'),
(101, NULL, 'mfa_generated', 'MFA Code: 304367', '::1', '2026-05-08 17:22:31'),
(102, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 17:23:15'),
(103, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-08 17:23:15'),
(104, NULL, 'block_user', 'Admin blocked user ID 6', '::1', '2026-05-08 17:23:22'),
(105, 7, 'mfa_generated', 'MFA Code: 353460', '::1', '2026-05-08 17:29:18'),
(106, 7, 'mfa_generated', 'MFA Code: 451091', '::1', '2026-05-08 17:29:47'),
(107, 7, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 17:30:00'),
(108, 7, 'mfa_generated', 'MFA Code: 102012', '::1', '2026-05-08 17:56:32'),
(109, 7, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 17:57:08'),
(110, NULL, 'mfa_generated', 'MFA Code: 396840', '::1', '2026-05-08 18:24:20'),
(111, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-08 18:24:35'),
(112, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-08 18:24:35'),
(113, NULL, 'unblock_user', 'Admin unblocked user ID 8', '::1', '2026-05-08 18:24:39'),
(114, NULL, 'unblock_user', 'Admin unblocked user ID 6', '::1', '2026-05-08 18:24:42'),
(115, NULL, 'logout', 'User logged out', '::1', '2026-05-08 18:24:48'),
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
(159, NULL, 'mfa_generated', 'MFA Code: 237558', '::1', '2026-05-09 00:18:50'),
(160, NULL, 'login_success', 'User logged in via MFA', '::1', '2026-05-09 00:20:43'),
(161, NULL, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-09 00:20:43'),
(162, NULL, 'block_user', 'Admin blocked user ID 6', '::1', '2026-05-09 00:23:25'),
(163, NULL, 'logout', 'User logged out', '::1', '2026-05-09 00:23:30'),
(164, 7, 'mfa_generated', 'MFA Code: 632535', '::1', '2026-05-09 00:24:24'),
(165, 7, 'login_success', 'User logged in via MFA', '::1', '2026-05-09 00:26:43'),
(166, 7, 'update_cart', 'Cart quantities updated', '::1', '2026-05-09 00:27:10'),
(167, 25, 'mfa_generated', 'MFA Code: 470931', '::1', '2026-05-09 00:28:23'),
(168, 25, 'login_success', 'User logged in via MFA', '::1', '2026-05-09 00:28:35'),
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
(193, 7, 'mfa_generated', 'MFA Code: 171886', '::1', '2026-05-12 07:10:57'),
(194, 7, 'login_success', 'User logged in via MFA', '::1', '2026-05-12 07:11:09'),
(195, 7, 'update_cart', 'Cart quantities updated', '::1', '2026-05-12 07:12:47'),
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
(208, 7, 'mfa_generated', 'MFA Code: 673819', '::1', '2026-05-19 13:11:40'),
(209, 7, 'login_success', 'User logged in via MFA', '::1', '2026-05-19 13:11:53'),
(210, 7, 'mfa_generated', 'MFA Code: 153543', '::1', '2026-05-21 05:53:24'),
(211, 7, 'login_success', 'User logged in via MFA', '::1', '2026-05-21 05:53:39'),
(212, 7, 'mfa_generated', 'MFA Code: 514572', '::1', '2026-05-21 05:55:46'),
(213, 7, 'login_success', 'User logged in via MFA', '::1', '2026-05-21 05:55:58'),
(214, 7, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-21 05:55:58'),
(215, 7, 'unblock_user', 'Admin unblocked user ID 6', '::1', '2026-05-21 05:56:08'),
(216, 7, 'logout', 'User logged out', '::1', '2026-05-21 06:04:34'),
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
(229, 7, 'totp_setup_initiated', 'First login — TOTP setup started', '::1', '2026-05-21 07:23:12'),
(230, 7, 'totp_setup_initiated', 'First login — TOTP setup started', '::1', '2026-05-21 07:23:22'),
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
(301, 34, 'totp_setup_initiated', 'First login — TOTP setup started', '::1', '2026-05-23 00:31:46'),
(302, 34, 'totp_setup_complete', 'Google Authenticator configured', '::1', '2026-05-23 00:32:00'),
(303, 34, 'login_success', 'User logged in after TOTP setup', '::1', '2026-05-23 00:32:00'),
(304, 34, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-23 00:32:00'),
(305, 34, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-23 00:34:17'),
(306, 35, 'totp_setup_initiated', 'First login — TOTP setup started', '::1', '2026-05-23 00:38:59'),
(307, 35, 'totp_setup_complete', 'Google Authenticator configured', '::1', '2026-05-23 00:40:24'),
(308, 35, 'login_success', 'User logged in after TOTP setup', '::1', '2026-05-23 00:40:24'),
(309, 34, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-23 00:49:03'),
(310, 34, 'login_success', 'User logged in via Google Authenticator TOTP', '::1', '2026-05-23 00:49:35'),
(311, 34, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-23 00:49:35'),
(312, 35, 'logout', 'User logged out', '::1', '2026-05-23 00:51:01'),
(313, 34, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-23 01:00:54'),
(314, 34, 'unlock_user', 'Admin unlocked user ID 31', '::1', '2026-05-23 01:05:44'),
(315, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-23 01:05:59'),
(316, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-23 01:06:17'),
(317, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-23 01:06:42'),
(318, 34, 'settings_update', 'Admin updated security settings (max_attempts=2)', '::1', '2026-05-23 01:06:59'),
(319, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-23 01:07:19'),
(320, 34, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-23 01:10:16'),
(321, 34, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-23 01:11:53'),
(322, 34, 'logout', 'User logged out', '::1', '2026-05-23 01:27:18'),
(323, 25, 'totp_setup_initiated', 'First login — TOTP setup started', '::1', '2026-05-23 01:27:58'),
(324, 35, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-23 01:29:07'),
(325, 35, 'login_success', 'User logged in via Google Authenticator TOTP', '::1', '2026-05-23 01:29:22'),
(326, 34, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-23 01:34:35'),
(327, 34, 'login_success', 'User logged in via Google Authenticator TOTP', '::1', '2026-05-23 01:34:45'),
(328, 34, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-23 01:34:45'),
(329, 34, 'unlock_user', 'Admin unlocked user ID 31', '::1', '2026-05-23 01:34:53'),
(330, 34, 'logout', 'User logged out', '::1', '2026-05-23 01:34:58'),
(331, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-23 01:35:07'),
(332, NULL, 'totp_failed', 'Invalid TOTP code entered', '::1', '2026-05-23 01:35:25'),
(333, NULL, 'totp_failed', 'Invalid TOTP code entered', '::1', '2026-05-23 01:35:37'),
(334, NULL, 'totp_failed', 'Invalid TOTP code entered', '::1', '2026-05-23 01:35:48'),
(335, 35, 'logout', 'User logged out', '::1', '2026-05-23 01:37:52'),
(336, 34, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-05-23 01:38:00'),
(337, 34, 'login_success', 'User logged in via Google Authenticator TOTP', '::1', '2026-05-23 01:38:15'),
(338, 34, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-23 01:38:15'),
(339, 34, 'view_dashboard', 'Admin viewed dashboard', '::1', '2026-05-23 01:43:09'),
(340, NULL, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-07-06 16:10:26'),
(341, NULL, 'totp_failed', 'Invalid TOTP code entered', '::1', '2026-07-06 16:11:13'),
(342, NULL, 'totp_failed', 'Invalid TOTP code entered', '::1', '2026-07-06 16:11:22'),
(343, NULL, 'totp_failed', 'Invalid TOTP code entered', '::1', '2026-07-06 16:11:27'),
(344, 36, 'totp_setup_initiated', 'First login — TOTP setup started', '::1', '2026-07-06 16:14:47'),
(345, 36, 'totp_setup_complete', 'Google Authenticator configured', '::1', '2026-07-06 16:15:14'),
(346, 36, 'login_success', 'User logged in after TOTP setup', '::1', '2026-07-06 16:15:14'),
(347, 36, 'login_attempt', 'Credentials verified, awaiting TOTP', '::1', '2026-07-07 04:23:11'),
(348, 36, 'login_success', 'User logged in via Google Authenticator TOTP', '::1', '2026-07-07 04:23:25');

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
(7, 7, 'tweetret', '09683363700', 90000.00, '', '2026-05-09 00:27:19'),
(8, 31, 'Here, There, Everywhere', '09922668844', 79500.00, '', '2026-05-22 08:00:18');

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
(6, 7, 6, 4, 22500.00),
(7, 8, 4, 2, 28500.00),
(8, 8, 6, 1, 22500.00);

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
(4, 'CloudPink Slimbook 14 (Ryzen 5 Edition)', 'The ultimate aesthetic laptop for Binsoy Shop! ✨ Features a gorgeous matte Sakura Pink finish and a white backlit keyboard. Perfectly lightweight for coffee shop study dates or WFH vibes.', '', 28500.00, 93, NULL, NULL, 25, '2026-05-08 21:45:40'),
(5, 'Nitro-G: Pink Power Gaming Laptop', 'Who says gaming has to be boring? 🌸 High-performance gaming meets the pink aesthetic. Play Valorant, Genshin, or edit videos with zero lag. Features Customizable RGB (Pink/Purple) lighting!\\r\\n\\r\\nSpecs: RTX 4050 Graphics / 16GB RAM / 144Hz Smooth Display\\r\\n\\r\\nFreebie: Pink Mechanical Keyboard & Mousepad 🎮', '', 48500.00, 96, NULL, NULL, 25, '2026-05-08 21:46:17'),
(6, 'EliteBook - Rose Gold Edition', 'The ultimate aesthetic laptop for Binsoy Shop! ✨ Features a gorgeous matte Sakura Pink finish and a white backlit keyboard. Perfectly lightweight for coffee shop study dates or WFH vibes.\\r\\n\\r\\nSpecs: 16GB RAM / 512GB SSD / 14-inch Full HD\\r\\n\\r\\nFreebie: Matching Pink Wireless Mouse & Velvet Sleeve 🎀', '', 22500.00, 995, NULL, NULL, 25, '2026-05-08 21:46:45'),
(7, 'Keyboard ni vinny malou', 'Limited Edition Keyboard', 'https://i1-e.pinimg.com/webp/1200x/11/8f/4a/118f4a4aa1dfbc78a9754fde4cca1057.webp', 85000.00, 50, NULL, NULL, 33, '2026-05-22 09:14:03');

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
(1, 'pw_min_length', '8', 34, '2026-05-23 01:06:59'),
(2, 'pw_require_upper', '1', 34, '2026-05-23 01:06:59'),
(3, 'pw_require_lower', '1', 34, '2026-05-23 01:06:59'),
(4, 'pw_require_number', '1', 34, '2026-05-23 01:06:59'),
(5, 'pw_require_special', '1', 34, '2026-05-23 01:06:59'),
(16, 'max_failed_attempts', '2', 34, '2026-05-23 01:06:59');

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
  `activation_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `shop_description` text DEFAULT NULL,
  `totp_secret` varchar(64) DEFAULT NULL,
  `totp_enabled` tinyint(4) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `surname`, `age`, `address`, `email`, `password`, `role`, `is_verified`, `is_locked`, `failed_attempts`, `activation_token`, `created_at`, `shop_description`, `totp_secret`, `totp_enabled`) VALUES
(6, 'Rona Shop', 'Dantic', 20, 'Pasig City', 'danticronamae01@gmail.com', '$2y$10$6cX/uszJILiYFcCO1Q7kMushQMwNhAFaSaJuPYwJComxbC5fVTlRO', 'seller', 1, 0, 0, NULL, '2026-05-08 11:18:03', 'Turning your phone into your favorite accessory.\\r\\nDrop-tested protection 💖 Dreamcore aesthetic.\\r\\n✨ MagSafe Compatible & Eco-Friendly.\\r\\nShop the new Pink Cloud collection! 👇', NULL, 0),
(7, 'Rona', 'Dantic', 20, 'Pasig City', 'synehiraya@gmail.com', '$2y$10$904S9WLbD21vxgLEPSo53uqcdlb2p883944PKP8gm903ZmMTsikEe', 'admin', 1, 0, 0, NULL, '2026-05-08 16:32:03', NULL, NULL, 0),
(25, 'Binsoy Shop', 'Lasala', 30, 'Pasig City', 'ronagorrgiii@gmail.com', '$2y$10$9sMTvtCH2Vo4TaTLKlDvV.B4jARc/3Dy1v6nlJZf3aGOPfDCvpfye', 'seller', 1, 0, 0, NULL, '2026-05-08 21:43:02', 'The ultimate aesthetic laptop for Binsoy Shop! ✨ Features a gorgeous matte Sakura Pink finish and a white backlit keyboard. Perfectly lightweight for coffee shop study dates or WFH vibes.', NULL, 0),
(34, 'sam', 'admin', 20, 'Pasig City', 'jumaoas_samantha@plpasig.edu.ph', '$2y$10$6OUwdJolnFcRPUDS6pSfFuyyHhbCoNCcML/hnTJ4uxRIVmpTKD3ta', 'admin', 1, 0, 0, NULL, '2026-05-23 00:30:32', NULL, 'WAP7POMRWKDU6FGU', 1),
(35, 'sam', 'seller', 20, 'Pasig City', 'jumaoas.samantha05@gmail.com', '$2y$10$Zko5aALDUgqX4De0c5wr7Oql3saA/8kLZ8rDJF2.F53O4iTkY3PiW', 'seller', 1, 1, 0, NULL, '2026-05-23 00:36:20', NULL, 'SVEVUOQ6ZJW3OKVV', 1),
(36, 'Samantha', 'Jumao-as', 18, '786 Blk 4 Lot 5 Strawberry Street, Damayan Park Homes, Maybunga', 'samj28005@gmail.com', '$2y$10$Il5ybbL3LiJ3HD1x1AyIFu3jM324PTyILxcpCQMMlbsu19rcb/vZm', 'client', 1, 0, 0, NULL, '2026-07-06 16:12:34', NULL, 'H735J7VAUOF2DUJY', 1);

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `locked_accounts`
--
ALTER TABLE `locked_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `logs`
--
ALTER TABLE `logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=349;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

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
