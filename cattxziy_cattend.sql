-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Sep 13, 2026 at 07:02 PM
-- Server version: 11.4.13-MariaDB-cll-lve
-- PHP Version: 8.4.24

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `cattxziy_cattend`
--
-- Merge mode: this file does not drop, truncate, or delete existing data.
-- Existing rows that conflict with a primary or unique key are skipped.

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE IF NOT EXISTS `admins` (
  `id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','sub_admin') NOT NULL DEFAULT 'admin',
  `assigned_class` enum('A','B') DEFAULT NULL,
  `is_setup_complete` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `admins`
--

INSERT IGNORE INTO `admins` (`id`, `full_name`, `email`, `phone`, `password_hash`, `role`, `is_setup_complete`, `created_at`) VALUES
(2, 'Abekah Robert', 'qwabenahsergeant@gmail.com', '0501892582', '$argon2id$v=19$m=65536,t=4,p=1$NUV3T1hnYWpvY2FPbHk4VA$CgkxAtZJDbROvhNNkiMen+GJAjMdDf3PlGOP/pHyLnY', 'admin', 1, '2026-09-13 16:13:47');

-- --------------------------------------------------------

--
-- Table structure for table `attendance_records`
--

CREATE TABLE IF NOT EXISTS `attendance_records` (
  `id` int(11) NOT NULL,
  `session_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `marked_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `marked_by` enum('self','admin') DEFAULT 'self',
  `approval_status` enum('approved','pending','rejected') NOT NULL DEFAULT 'approved',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `submitted_by_admin_id` int(11) DEFAULT NULL,
  `distance_meters` decimal(8,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_sessions`
--

CREATE TABLE IF NOT EXISTS `attendance_sessions` (
  `id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `target_class` enum('A','B','both') NOT NULL DEFAULT 'both',
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `radius_meters` int(11) DEFAULT 300,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `status` enum('active','ended') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `portal_only_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `course_id` int(11) DEFAULT NULL,
  `course_code` varchar(20) NOT NULL,
  `course_title` varchar(150) NOT NULL,
  `created_by_admin_id` int(11) NOT NULL,
  `target_class` enum('A','B','both') NOT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `radius_meters` int(11) NOT NULL DEFAULT 300,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `pdf_data` longblob DEFAULT NULL,
  `pdf_generated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_portal_only_sessions_active` (`is_active`,`start_time`,`end_time`),
  KEY `idx_portal_only_sessions_expiry` (`end_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `portal_only_attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `index_number` varchar(30) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `marked_at` datetime NOT NULL,
  `distance_meters` decimal(8,2) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_portal_only_session_student` (`session_id`,`student_id`),
  KEY `idx_portal_only_attendance_session` (`session_id`),
  CONSTRAINT `portal_only_attendance_session_fk` FOREIGN KEY (`session_id`) REFERENCES `portal_only_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `courses`
--

CREATE TABLE IF NOT EXISTS `courses` (
  `id` int(11) NOT NULL,
  `course_code` varchar(20) NOT NULL,
  `course_title` varchar(150) NOT NULL,
  `semester` varchar(20) NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `courses`
--

INSERT IGNORE INTO `courses` (`id`, `course_code`, `course_title`, `semester`, `academic_year`, `created_at`, `is_active`) VALUES
(3, 'NAP 201', 'COMMUNITY HEALTH NURSING II', '1', '2026', '2026-09-13 18:43:16', 1),
(4, 'ACA 203', 'INTRODUCTORY TO MENTAL HEALTH NURSING', '1', '2026/2026', '2026-09-13 18:44:29', 1),
(5, 'ACA 201', 'BASIC NUTRITION', '1', '2026/2026', '2026-09-13 18:45:26', 1),
(6, 'NAP 203', 'PRINCIPLES OF DISEASE MANAGEMENT AND CONTROL I', '1', '2026/2026', '2026-09-13 18:47:25', 1),
(7, 'ACA 205', 'INTRODUCTORY TO OBSTETRIC NURSING', '1', '2026/2026', '2026-09-13 18:48:57', 1),
(8, 'ACA 207', 'FUNDAMENTALS OF CHILD HEALTH', '1', '2026/2026', '2026-09-13 18:50:13', 1);

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int(11) NOT NULL,
  `index_number` varchar(30) NOT NULL,
  `token` varchar(6) NOT NULL,
  `expires_at` datetime NOT NULL,
  `is_used` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `password_resets`
--

INSERT IGNORE INTO `password_resets` (`id`, `index_number`, `token`, `expires_at`, `is_used`, `created_at`) VALUES
(2, 'CCNWBNAP250123', '174744', '2026-09-13 17:39:42', 0, '2026-09-13 17:24:42'),
(3, 'CCNWBNAP250052', '890521', '2026-09-13 17:41:30', 1, '2026-09-13 17:26:30'),
(4, 'CCNWBNAP250052', '685539', '2026-09-13 17:44:32', 1, '2026-09-13 17:29:32'),
(5, 'CCNWBNAP250052', '784350', '2026-09-13 17:45:04', 1, '2026-09-13 17:30:04'),
(6, 'CCNWBNAP250052', '937369', '2026-09-13 17:47:36', 1, '2026-09-13 17:32:36'),
(7, 'CCNWBNAP250052', '672363', '2026-09-13 17:50:55', 1, '2026-09-13 17:35:55'),
(8, 'CCNWBNAP250145', '364886', '2026-09-13 18:38:17', 1, '2026-09-13 18:23:17');

-- --------------------------------------------------------

--
-- Table structure for table `portal_alerts`
--

CREATE TABLE IF NOT EXISTS `portal_alerts` (
  `id` int(11) NOT NULL,
  `severity` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `source` varchar(40) NOT NULL DEFAULT 'portal',
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `portal_alerts`
--

INSERT IGNORE INTO `portal_alerts` (`id`, `severity`, `source`, `title`, `message`, `details`, `created_at`) VALUES
(1, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250082', 'Authentication failure', '2026-09-13 16:47:36'),
(2, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250082', 'Authentication failure', '2026-09-13 16:47:54'),
(3, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250082', 'Authentication failure', '2026-09-13 16:48:33'),
(4, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250082', 'Authentication failure', '2026-09-13 16:49:08'),
(5, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250082', 'Authentication failure', '2026-09-13 16:49:18'),
(6, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250082', 'Authentication failure', '2026-09-13 16:49:26'),
(7, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250116', 'Authentication failure', '2026-09-13 16:49:44'),
(8, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250017', 'Authentication failure', '2026-09-13 16:54:13'),
(9, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number 0534511501', 'Authentication failure', '2026-09-13 16:54:39'),
(10, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250055', 'Authentication failure', '2026-09-13 16:55:07'),
(11, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250082', 'Authentication failure', '2026-09-13 16:55:12'),
(12, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number BUNYAMIN', 'Authentication failure', '2026-09-13 16:55:25'),
(13, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNSP250084', 'Authentication failure', '2026-09-13 16:55:29'),
(14, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number BUNYAMIN', 'Authentication failure', '2026-09-13 16:55:32'),
(15, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250123', 'Authentication failure', '2026-09-13 16:55:48'),
(16, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNSP250084', 'Authentication failure', '2026-09-13 16:55:49'),
(17, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250082', 'Authentication failure', '2026-09-13 16:55:55'),
(18, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250082', 'Authentication failure', '2026-09-13 16:55:55'),
(19, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250073', 'Authentication failure', '2026-09-13 16:56:17'),
(20, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250017', 'Authentication failure', '2026-09-13 16:56:24'),
(21, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250055', 'Authentication failure', '2026-09-13 16:56:33'),
(22, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250069', 'Authentication failure', '2026-09-13 16:56:37'),
(23, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250072', 'Authentication failure', '2026-09-13 16:56:41'),
(24, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250017', 'Authentication failure', '2026-09-13 16:56:44'),
(25, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250073', 'Authentication failure', '2026-09-13 16:56:45'),
(26, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250046', 'Authentication failure', '2026-09-13 16:57:44'),
(27, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250107', 'Authentication failure', '2026-09-13 16:57:46'),
(28, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250107', 'Authentication failure', '2026-09-13 16:57:47'),
(29, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250073', 'Authentication failure', '2026-09-13 16:57:49'),
(30, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250107', 'Authentication failure', '2026-09-13 16:57:52'),
(31, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250017', 'Authentication failure', '2026-09-13 16:57:53'),
(32, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250107', 'Authentication failure', '2026-09-13 16:57:56'),
(33, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250107', 'Authentication failure', '2026-09-13 16:57:59'),
(34, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250107', 'Authentication failure', '2026-09-13 16:58:03'),
(35, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250073', 'Authentication failure', '2026-09-13 16:58:07'),
(36, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250107', 'Authentication failure', '2026-09-13 16:58:13'),
(37, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250107', 'Authentication failure', '2026-09-13 16:58:23'),
(38, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250107', 'Authentication failure', '2026-09-13 16:58:25'),
(39, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250149', 'Authentication failure', '2026-09-13 16:58:38'),
(40, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250107', 'Authentication failure', '2026-09-13 16:58:49'),
(41, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250017', 'Authentication failure', '2026-09-13 16:59:53'),
(42, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250149', 'Authentication failure', '2026-09-13 16:59:58'),
(43, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250066', 'Authentication failure', '2026-09-13 17:01:09'),
(44, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250123', 'Authentication failure', '2026-09-13 17:01:19'),
(45, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250096', 'Authentication failure', '2026-09-13 17:01:21'),
(46, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250066', 'Authentication failure', '2026-09-13 17:01:21'),
(47, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250066', 'Authentication failure', '2026-09-13 17:01:22'),
(48, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250043', 'Authentication failure', '2026-09-13 17:01:24'),
(49, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250046', 'Authentication failure', '2026-09-13 17:01:44'),
(50, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250066', 'Authentication failure', '2026-09-13 17:01:44'),
(51, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250066', 'Authentication failure', '2026-09-13 17:01:44'),
(52, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250017', 'Authentication failure', '2026-09-13 17:02:18'),
(53, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250066', 'Authentication failure', '2026-09-13 17:02:20'),
(54, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250066', 'Authentication failure', '2026-09-13 17:02:20'),
(55, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250082', 'Authentication failure', '2026-09-13 17:02:38'),
(56, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250082', 'Authentication failure', '2026-09-13 17:02:39'),
(57, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250017', 'Authentication failure', '2026-09-13 17:02:45'),
(58, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250066', 'Authentication failure', '2026-09-13 17:02:53'),
(59, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250132', 'Authentication failure', '2026-09-13 17:02:58'),
(60, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250066', 'Authentication failure', '2026-09-13 17:03:05'),
(61, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250071', 'Authentication failure', '2026-09-13 17:03:18'),
(62, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250123', 'Authentication failure', '2026-09-13 17:03:49'),
(63, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250071', 'Authentication failure', '2026-09-13 17:04:15'),
(64, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250052', 'Authentication failure', '2026-09-13 17:04:23'),
(65, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250082', 'Authentication failure', '2026-09-13 17:04:28'),
(66, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250052', 'Authentication failure', '2026-09-13 17:04:28'),
(67, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number 0543747728', 'Authentication failure', '2026-09-13 17:04:32'),
(68, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250123', 'Authentication failure', '2026-09-13 17:04:34'),
(69, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250066', 'Authentication failure', '2026-09-13 17:04:35'),
(70, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250123', 'Authentication failure', '2026-09-13 17:04:40'),
(71, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250100', 'Authentication failure', '2026-09-13 17:05:18'),
(72, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250123', 'Authentication failure', '2026-09-13 17:05:53'),
(73, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250097', 'Authentication failure', '2026-09-13 17:05:54'),
(74, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250117', 'Authentication failure', '2026-09-13 17:06:17'),
(75, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250142', 'Authentication failure', '2026-09-13 17:06:17'),
(76, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250066', 'Authentication failure', '2026-09-13 17:06:51'),
(77, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250142', 'Authentication failure', '2026-09-13 17:06:51'),
(78, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250142', 'Authentication failure', '2026-09-13 17:07:02'),
(79, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number 0593300748', 'Authentication failure', '2026-09-13 17:07:08'),
(80, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250123', 'Authentication failure', '2026-09-13 17:07:09'),
(81, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250117', 'Authentication failure', '2026-09-13 17:07:19'),
(82, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250142', 'Authentication failure', '2026-09-13 17:07:39'),
(83, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250117', 'Authentication failure', '2026-09-13 17:07:59'),
(84, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWNAP250066', 'Authentication failure', '2026-09-13 17:08:39'),
(85, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250067', 'Authentication failure', '2026-09-13 17:08:40'),
(86, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250066', 'Authentication failure', '2026-09-13 17:08:44'),
(87, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250117', 'Authentication failure', '2026-09-13 17:09:01'),
(88, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250117', 'Authentication failure', '2026-09-13 17:09:01'),
(89, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250082', 'Authentication failure', '2026-09-13 17:09:07'),
(90, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250067', 'Authentication failure', '2026-09-13 17:09:15'),
(91, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250067', 'Authentication failure', '2026-09-13 17:10:00'),
(92, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBAP250067', 'Authentication failure', '2026-09-13 17:10:23'),
(93, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250067', 'Authentication failure', '2026-09-13 17:10:55'),
(94, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250067', 'Authentication failure', '2026-09-13 17:11:18'),
(95, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250067', 'Authentication failure', '2026-09-13 17:11:27'),
(96, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250052', 'Authentication failure', '2026-09-13 17:11:27'),
(97, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250052', 'Authentication failure', '2026-09-13 17:11:31'),
(98, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250052', 'Authentication failure', '2026-09-13 17:11:57'),
(99, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250067', 'Authentication failure', '2026-09-13 17:12:02'),
(100, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250052', 'Authentication failure', '2026-09-13 17:12:08'),
(101, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250052', 'Authentication failure', '2026-09-13 17:12:10'),
(102, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250016', 'Authentication failure', '2026-09-13 17:13:29'),
(103, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250052', 'Authentication failure', '2026-09-13 17:14:01'),
(104, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250052', 'Authentication failure', '2026-09-13 17:14:06'),
(105, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number ABDULLAIHAWA', 'Authentication failure', '2026-09-13 17:14:25'),
(106, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250052', 'Authentication failure', '2026-09-13 17:14:30'),
(107, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250081', 'Authentication failure', '2026-09-13 17:14:52'),
(108, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250105', 'Authentication failure', '2026-09-13 17:14:54'),
(109, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250105', 'Authentication failure', '2026-09-13 17:15:56'),
(110, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250105', 'Authentication failure', '2026-09-13 17:15:57'),
(111, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250105', 'Authentication failure', '2026-09-13 17:16:56'),
(112, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250105', 'Authentication failure', '2026-09-13 17:18:02'),
(113, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250154', 'Authentication failure', '2026-09-13 17:18:19'),
(114, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250154', 'Authentication failure', '2026-09-13 17:19:43'),
(115, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250047', 'Authentication failure', '2026-09-13 17:22:24'),
(116, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250086', 'Authentication failure', '2026-09-13 17:23:59'),
(117, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250086', 'Authentication failure', '2026-09-13 17:24:10'),
(118, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250105', 'Authentication failure', '2026-09-13 17:24:25'),
(119, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250086', 'Authentication failure', '2026-09-13 17:25:12'),
(120, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number SCHOOLATTENDANCE', 'Authentication failure', '2026-09-13 17:25:40'),
(121, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250098', 'Authentication failure', '2026-09-13 17:26:15'),
(122, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250143', 'Authentication failure', '2026-09-13 17:26:20'),
(123, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250143', 'Authentication failure', '2026-09-13 17:26:20'),
(124, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250143', 'Authentication failure', '2026-09-13 17:26:40'),
(125, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250098', 'Authentication failure', '2026-09-13 17:26:52'),
(126, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250127', 'Authentication failure', '2026-09-13 17:27:13'),
(127, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250020', 'Authentication failure', '2026-09-13 17:28:00'),
(128, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250052', 'Authentication failure', '2026-09-13 17:28:41'),
(129, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250052', 'Authentication failure', '2026-09-13 17:28:45'),
(130, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250105', 'Authentication failure', '2026-09-13 17:28:59'),
(131, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250052', 'Authentication failure', '2026-09-13 17:29:46'),
(132, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250135', 'Authentication failure', '2026-09-13 17:29:58'),
(133, 'medium', 'student', 'Reset token rejected', 'An invalid or expired password reset token was submitted for index number CCNWBNAP250052', 'Password reset verification failed', '2026-09-13 17:31:25'),
(134, 'medium', 'student', 'Reset token rejected', 'An invalid or expired password reset token was submitted for index number CCNWBNAP250052', 'Password reset verification failed', '2026-09-13 17:31:45'),
(135, 'medium', 'student', 'Reset token rejected', 'An invalid or expired password reset token was submitted for index number ', 'Password reset verification failed', '2026-09-13 17:34:31'),
(136, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250090', 'Authentication failure', '2026-09-13 17:36:08'),
(137, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250090', 'Authentication failure', '2026-09-13 17:36:36'),
(138, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250090', 'Authentication failure', '2026-09-13 17:37:11'),
(139, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250052', 'Authentication failure', '2026-09-13 17:37:21'),
(140, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250090', 'Authentication failure', '2026-09-13 17:37:23'),
(141, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250090', 'Authentication failure', '2026-09-13 17:40:45'),
(142, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250035', 'Authentication failure', '2026-09-13 17:40:53'),
(143, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250090', 'Authentication failure', '2026-09-13 17:40:56'),
(144, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250090', 'Authentication failure', '2026-09-13 17:42:10'),
(145, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250035', 'Authentication failure', '2026-09-13 17:42:18'),
(146, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250090', 'Authentication failure', '2026-09-13 17:42:27'),
(147, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250090', 'Authentication failure', '2026-09-13 17:42:45'),
(148, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250145', 'Authentication failure', '2026-09-13 17:44:25'),
(149, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250145', 'Authentication failure', '2026-09-13 17:44:32'),
(150, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250090', 'Authentication failure', '2026-09-13 17:44:41'),
(151, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250090', 'Authentication failure', '2026-09-13 17:45:02'),
(152, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250090', 'Authentication failure', '2026-09-13 17:45:18'),
(153, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNSP250084', 'Authentication failure', '2026-09-13 17:46:10'),
(154, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250084', 'Authentication failure', '2026-09-13 17:47:06'),
(155, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250155', 'Authentication failure', '2026-09-13 17:47:12'),
(156, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNSP250084', 'Authentication failure', '2026-09-13 17:47:26'),
(157, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250155', 'Authentication failure', '2026-09-13 17:47:33'),
(158, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250035', 'Authentication failure', '2026-09-13 17:47:42'),
(159, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250035', 'Authentication failure', '2026-09-13 17:47:43'),
(160, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250035', 'Authentication failure', '2026-09-13 17:48:30'),
(161, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250012', 'Authentication failure', '2026-09-13 17:48:45'),
(162, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250035', 'Authentication failure', '2026-09-13 17:49:00'),
(163, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250012', 'Authentication failure', '2026-09-13 17:49:16'),
(164, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250035', 'Authentication failure', '2026-09-13 17:49:56'),
(165, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250126', 'Authentication failure', '2026-09-13 17:49:58'),
(166, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250126', 'Authentication failure', '2026-09-13 17:50:01'),
(167, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250035', 'Authentication failure', '2026-09-13 17:50:16'),
(168, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250035', 'Authentication failure', '2026-09-13 17:50:19'),
(169, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250049', 'Authentication failure', '2026-09-13 17:50:30'),
(170, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250049', 'Authentication failure', '2026-09-13 17:51:41'),
(171, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250103', 'Authentication failure', '2026-09-13 17:51:55'),
(172, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250103', 'Authentication failure', '2026-09-13 17:52:02'),
(173, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250103', 'Authentication failure', '2026-09-13 17:52:03'),
(174, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250103', 'Authentication failure', '2026-09-13 17:52:11'),
(175, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250103', 'Authentication failure', '2026-09-13 17:52:21'),
(176, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250103', 'Authentication failure', '2026-09-13 17:52:26'),
(177, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number 0530463851', 'Authentication failure', '2026-09-13 17:53:05'),
(178, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250049', 'Authentication failure', '2026-09-13 17:53:39'),
(179, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250049', 'Authentication failure', '2026-09-13 17:53:51'),
(180, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250049', 'Authentication failure', '2026-09-13 17:53:56'),
(181, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250049', 'Authentication failure', '2026-09-13 17:54:01'),
(182, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250029', 'Authentication failure', '2026-09-13 17:54:15'),
(183, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250029', 'Authentication failure', '2026-09-13 17:54:16'),
(184, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250029', 'Authentication failure', '2026-09-13 17:54:29'),
(185, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250049', 'Authentication failure', '2026-09-13 17:55:35'),
(186, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250049', 'Authentication failure', '2026-09-13 17:56:48'),
(187, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250049', 'Authentication failure', '2026-09-13 17:57:14'),
(188, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250049', 'Authentication failure', '2026-09-13 17:57:27'),
(189, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250049', 'Authentication failure', '2026-09-13 17:57:50'),
(190, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250049', 'Authentication failure', '2026-09-13 17:58:58'),
(191, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250035', 'Authentication failure', '2026-09-13 17:59:05'),
(192, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250035', 'Authentication failure', '2026-09-13 17:59:42'),
(193, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250035', 'Authentication failure', '2026-09-13 17:59:43'),
(194, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250035', 'Authentication failure', '2026-09-13 18:00:30'),
(195, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250145', 'Authentication failure', '2026-09-13 18:04:40'),
(196, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250145', 'Authentication failure', '2026-09-13 18:04:49'),
(197, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250145', 'Authentication failure', '2026-09-13 18:06:24'),
(198, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250145', 'Authentication failure', '2026-09-13 18:16:03'),
(199, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250110', 'Authentication failure', '2026-09-13 18:23:17'),
(200, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250110', 'Authentication failure', '2026-09-13 18:23:37'),
(201, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number 364886', 'Authentication failure', '2026-09-13 18:24:29'),
(202, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number 364886', 'Authentication failure', '2026-09-13 18:24:40'),
(203, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250079', 'Authentication failure', '2026-09-13 18:26:03'),
(204, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250079', 'Authentication failure', '2026-09-13 18:26:09'),
(205, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250079', 'Authentication failure', '2026-09-13 18:26:13'),
(206, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250079', 'Authentication failure', '2026-09-13 18:26:16'),
(207, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250079', 'Authentication failure', '2026-09-13 18:26:59'),
(208, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250110', 'Authentication failure', '2026-09-13 18:29:16'),
(209, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250009', 'Authentication failure', '2026-09-13 18:40:24'),
(210, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250009', 'Authentication failure', '2026-09-13 18:41:36'),
(211, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number 0547359032', 'Authentication failure', '2026-09-13 18:51:08'),
(212, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number 0547359032', 'Authentication failure', '2026-09-13 18:51:48'),
(213, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number 0547359032', 'Authentication failure', '2026-09-13 18:51:55'),
(214, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number 0547359032', 'Authentication failure', '2026-09-13 18:52:04'),
(215, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number 0547359032', 'Authentication failure', '2026-09-13 18:52:12'),
(216, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number 0547359032', 'Authentication failure', '2026-09-13 18:52:21'),
(217, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number 0547359032', 'Authentication failure', '2026-09-13 18:52:39'),
(218, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250049', 'Authentication failure', '2026-09-13 18:52:44'),
(219, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number 0547359032', 'Authentication failure', '2026-09-13 18:53:00'),
(220, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250049', 'Authentication failure', '2026-09-13 18:53:17'),
(221, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250049', 'Authentication failure', '2026-09-13 18:53:17'),
(222, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250084', 'Authentication failure', '2026-09-13 18:53:49'),
(223, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250049', 'Authentication failure', '2026-09-13 18:55:10'),
(224, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250124', 'Authentication failure', '2026-09-13 18:55:59'),
(225, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number 0500822436', 'Authentication failure', '2026-09-13 18:56:03'),
(226, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250151', 'Authentication failure', '2026-09-13 18:56:25'),
(227, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number 0547359032', 'Authentication failure', '2026-09-13 18:56:30'),
(228, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number 0547359032', 'Authentication failure', '2026-09-13 18:56:33'),
(229, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250124', 'Authentication failure', '2026-09-13 18:56:39'),
(230, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250151', 'Authentication failure', '2026-09-13 18:57:02'),
(231, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250025', 'Authentication failure', '2026-09-13 18:57:29'),
(232, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250025', 'Authentication failure', '2026-09-13 18:57:30'),
(233, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250025', 'Authentication failure', '2026-09-13 18:58:13'),
(234, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number C_STUDY', 'Authentication failure', '2026-09-13 19:03:42'),
(235, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number C_STUDY', 'Authentication failure', '2026-09-13 19:03:49'),
(236, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250094', 'Authentication failure', '2026-09-13 19:11:55'),
(237, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250094', 'Authentication failure', '2026-09-13 19:12:13'),
(238, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250156', 'Authentication failure', '2026-09-13 19:14:32'),
(239, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250156', 'Authentication failure', '2026-09-13 19:15:30'),
(240, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250088', 'Authentication failure', '2026-09-13 19:19:12'),
(241, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250068', 'Authentication failure', '2026-09-13 19:19:18'),
(242, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250088', 'Authentication failure', '2026-09-13 19:19:20'),
(243, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250088', 'Authentication failure', '2026-09-13 19:19:22'),
(244, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250086', 'Authentication failure', '2026-09-13 19:19:33'),
(245, 'high', 'admin', 'Sub-admin creation failed', 'Main admin could not create sub-admin qwabenahsergeant@gmail.com', 'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \'qwabenahsergeant@gmail.com\' for key \'email\'', '2026-09-13 19:20:12'),
(246, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250068', 'Authentication failure', '2026-09-13 19:20:29'),
(247, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250088', 'Authentication failure', '2026-09-13 19:20:54'),
(248, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250039', 'Authentication failure', '2026-09-13 19:21:21'),
(249, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number 0549130453', 'Authentication failure', '2026-09-13 19:21:27'),
(250, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number 0549130453', 'Authentication failure', '2026-09-13 19:21:30'),
(251, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250039', 'Authentication failure', '2026-09-13 19:21:37'),
(252, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250039', 'Authentication failure', '2026-09-13 19:21:46'),
(253, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number 0549130453', 'Authentication failure', '2026-09-13 19:21:52'),
(254, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250039', 'Authentication failure', '2026-09-13 19:24:09'),
(255, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250039', 'Authentication failure', '2026-09-13 19:24:21'),
(256, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250121', 'Authentication failure', '2026-09-13 19:24:29'),
(257, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250121', 'Authentication failure', '2026-09-13 19:24:35'),
(258, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250039', 'Authentication failure', '2026-09-13 19:25:00'),
(259, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250039', 'Authentication failure', '2026-09-13 19:26:34'),
(260, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250039', 'Authentication failure', '2026-09-13 19:26:38'),
(261, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250039', 'Authentication failure', '2026-09-13 19:26:45'),
(262, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250156', 'Authentication failure', '2026-09-13 19:29:19'),
(263, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250156', 'Authentication failure', '2026-09-13 19:30:39'),
(264, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250032', 'Authentication failure', '2026-09-13 19:32:08'),
(265, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250032', 'Authentication failure', '2026-09-13 19:32:42'),
(266, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250015', 'Authentication failure', '2026-09-13 19:34:59'),
(267, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP25”0', 'Authentication failure', '2026-09-13 19:35:59'),
(268, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250014', 'Authentication failure', '2026-09-13 19:44:14'),
(269, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CNWBNAP250014', 'Authentication failure', '2026-09-13 19:47:47'),
(270, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number 0558509281', 'Authentication failure', '2026-09-13 19:51:26'),
(271, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250080', 'Authentication failure', '2026-09-13 20:20:48'),
(272, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250080', 'Authentication failure', '2026-09-13 20:21:06'),
(273, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250080', 'Authentication failure', '2026-09-13 20:21:09'),
(274, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250080', 'Authentication failure', '2026-09-13 20:21:12'),
(275, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250080', 'Authentication failure', '2026-09-13 20:21:13'),
(276, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250080', 'Authentication failure', '2026-09-13 20:21:14'),
(277, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250080', 'Authentication failure', '2026-09-13 20:21:15'),
(278, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250080', 'Authentication failure', '2026-09-13 20:21:16'),
(279, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250080', 'Authentication failure', '2026-09-13 20:21:17'),
(280, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250080', 'Authentication failure', '2026-09-13 20:21:39'),
(281, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250080', 'Authentication failure', '2026-09-13 20:22:07'),
(282, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250080', 'Authentication failure', '2026-09-13 20:22:12'),
(283, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250080', 'Authentication failure', '2026-09-13 20:22:46'),
(284, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250033', 'Authentication failure', '2026-09-13 20:33:46'),
(285, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250056', 'Authentication failure', '2026-09-13 20:59:04'),
(286, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250056', 'Authentication failure', '2026-09-13 20:59:21'),
(287, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250056', 'Authentication failure', '2026-09-13 20:59:30'),
(288, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250056', 'Authentication failure', '2026-09-13 21:00:14'),
(289, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250056', 'Authentication failure', '2026-09-13 21:02:00'),
(290, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number OWIREDU@22', 'Authentication failure', '2026-09-13 21:18:15'),
(291, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250009', 'Authentication failure', '2026-09-13 21:21:05'),
(292, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number 0547359032', 'Authentication failure', '2026-09-13 21:28:25'),
(293, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number 0547359032', 'Authentication failure', '2026-09-13 21:28:48'),
(294, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250058', 'Authentication failure', '2026-09-13 21:35:51'),
(295, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:37:44'),
(296, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:38:29'),
(297, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:38:31'),
(298, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:38:34'),
(299, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:38:35'),
(300, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:38:35'),
(301, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:38:35'),
(302, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:38:35'),
(303, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:38:36'),
(304, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:38:56'),
(305, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:38:56'),
(306, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:38:56'),
(307, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:38:57'),
(308, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:38:57'),
(309, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:38:57'),
(310, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:38:57'),
(311, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:38:57'),
(312, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:38:57'),
(313, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:39:34'),
(314, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:39:34'),
(315, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:39:57'),
(316, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250058', 'Authentication failure', '2026-09-13 21:40:02'),
(317, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:41:01'),
(318, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:41:35'),
(319, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250058', 'Authentication failure', '2026-09-13 21:42:31');
INSERT IGNORE INTO `portal_alerts` (`id`, `severity`, `source`, `title`, `message`, `details`, `created_at`) VALUES
(320, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:42:46'),
(321, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250152', 'Authentication failure', '2026-09-13 21:43:34'),
(322, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:43:51'),
(323, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:43:55'),
(324, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:44:00'),
(325, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250152', 'Authentication failure', '2026-09-13 21:44:22'),
(326, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:44:29'),
(327, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:44:30'),
(328, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:44:31'),
(329, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:44:31'),
(330, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:44:31'),
(331, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:44:32'),
(332, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:44:32'),
(333, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:44:32'),
(334, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:44:32'),
(335, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:44:32'),
(336, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:44:33'),
(337, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:44:33'),
(338, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:44:33'),
(339, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:44:33'),
(340, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:44:33'),
(341, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:44:33'),
(342, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:44:33'),
(343, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:44:34'),
(344, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250152', 'Authentication failure', '2026-09-13 21:45:31'),
(345, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250058', 'Authentication failure', '2026-09-13 21:46:29'),
(346, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250152', 'Authentication failure', '2026-09-13 21:47:37'),
(347, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number 0554396579', 'Authentication failure', '2026-09-13 21:47:40'),
(348, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCWBNAP250152', 'Authentication failure', '2026-09-13 21:48:09'),
(349, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:49:07'),
(350, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:49:18'),
(351, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:49:51'),
(352, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:49:59'),
(353, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:50:04'),
(354, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:50:07'),
(355, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:53:01'),
(356, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250129', 'Authentication failure', '2026-09-13 21:56:49'),
(357, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250042', 'Authentication failure', '2026-09-13 22:15:03'),
(358, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250005', 'Authentication failure', '2026-09-13 22:17:10'),
(359, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP250005', 'Authentication failure', '2026-09-13 22:17:35'),
(360, 'medium', 'student', 'Failed student login', 'Invalid login attempt for index number CCNWBNAP240053', 'Authentication failure', '2026-09-13 22:57:35');

-- --------------------------------------------------------

--
-- Table structure for table `student_device_bindings`
--

CREATE TABLE IF NOT EXISTS `student_device_bindings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `device_token_hash` char(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_used_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `device_token_hash` (`device_token_hash`),
  KEY `idx_student_device_bindings_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_auth_events`
--

CREATE TABLE IF NOT EXISTS `student_auth_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `event_type` enum('login','logout') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_student_auth_events_created_at` (`created_at`),
  KEY `idx_student_auth_events_type_created` (`event_type`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE IF NOT EXISTS `students` (
  `id` int(11) NOT NULL,
  `index_number` varchar(30) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `class_name` enum('A','B') NOT NULL DEFAULT 'A',
  `password_hash` varchar(255) NOT NULL,
  `privacy_policy_accepted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `students`
--

INSERT IGNORE INTO `students` (`id`, `index_number`, `full_name`, `phone`, `class_name`, `password_hash`, `privacy_policy_accepted_at`, `created_at`) VALUES
(3, 'CCNWBNAP250138', 'ABEKAH ROBERT', '0501892582', 'B', '$argon2id$v=19$m=65536,t=4,p=1$SC8vQUlxN1c4bWR1TDVkUw$derFVL1FJswgcjGgocOP7seRhS1pC26p+J3fG0qcOM0', '2026-09-13 16:18:06', '2026-09-13 16:18:06'),
(4, 'CCNWBNAP250095', 'Happy Torkponu', '0209596565', 'B', '$argon2id$v=19$m=65536,t=4,p=1$di9RQ3JGMGpIU0VZcElDSQ$7l3pflt78U8WomOBiOD/MbIRDcMY7TulewL6jyIY+VI', '2026-09-13 16:49:06', '2026-09-13 16:49:06'),
(5, 'CCNWBNAP250084', 'Henrietta Naadei Addison', '0534511501', 'B', '$argon2id$v=19$m=65536,t=4,p=1$LmZGQThrTFdRbVRLZ0t0dg$sdbN6DN4JuaRZTGk3NTIE9h/dvFLVD6OJh+sFV7riZY', '2026-09-13 16:49:24', '2026-09-13 16:49:24'),
(6, 'CCNWBNAP250096', 'BROCKE-AGBO GEORGINA NAA-AMELEY', '0257952866', 'B', '$argon2id$v=19$m=65536,t=4,p=1$THBXOXk1M2tOcTRlNGQzWg$Cpj3hUVdV87SDvgDeyV76kEMdRvDJcACQj7c0jVMDgo', '2026-09-13 16:49:25', '2026-09-13 16:49:25'),
(7, 'CCNWBNAP250089', 'Kumi Jerrilyn', '0557331512', 'B', '$argon2id$v=19$m=65536,t=4,p=1$OVlCM1B4MUF4bHpQMjVGMg$i/JBI5PSbMtnMwAfd4rkvXQFCb+UDNPKdAHh+DwK84s', '2026-09-13 16:49:57', '2026-09-13 16:49:57'),
(8, 'CCNWBNAP250115', 'Theodora Agyei', '0532398720', 'B', '$argon2id$v=19$m=65536,t=4,p=1$Mkw4OE1GeERPWGlQNXIxZg$LSGPoe2pJX1WFcq6XfKakyekdMbw9CJkhEUSbZVAWqw', '2026-09-13 16:52:08', '2026-09-13 16:52:08'),
(9, 'CCNWBNAP250136', 'Esther Smith', '0547318090', 'B', '$argon2id$v=19$m=65536,t=4,p=1$aUd5d2FuNnR4a3o3QzBXcg$WLmQVEZG+haVCMFc5zTZvcVUTIp18rx7v60LFwem4ik', '2026-09-13 16:53:50', '2026-09-13 16:53:50'),
(10, 'CCNWBNAP250091', 'OSEI CHRISTABEL BONSU', '0559017099', 'B', '$argon2id$v=19$m=65536,t=4,p=1$d1BiNkh1VVhFMkJYei5xSg$pvAN1pcPCDcWkMJCC75nOqjry5SHO06wEH6Irunnz+I', '2026-09-13 16:54:25', '2026-09-13 16:54:25'),
(11, 'CCNWBNAP250116', 'Rejoice Asare', '0241895208', 'B', '$argon2id$v=19$m=65536,t=4,p=1$bXV0b3lYeFFZbS9Xei9iRg$6iJhnanudRSZSDVhziwRpvozO8gsn484st/q6EKLhWc', '2026-09-13 16:54:42', '2026-09-13 16:54:42'),
(12, 'CCNWBNAP250031', 'IBRAHIM BUNYAMIN', '0543295976', 'A', '$argon2id$v=19$m=65536,t=4,p=1$WmM0R1BzZEJtMGNYcU1OWQ$+KL6AWYwd42+XJTEqrlg1F09B7PLDYgb6SaQETzbYYE', '2026-09-13 16:55:17', '2026-09-13 16:55:17'),
(13, 'CCNWBNAP250037', 'Freda Ama Serwaa Asare', '0273074189', 'A', '$argon2id$v=19$m=65536,t=4,p=1$SHhtdWpBaTNjRXJKdzNoQQ$gz0cH5CYT9oQasPiovoVwHtuOeMpYAqpbwkB6vzRh7A', '2026-09-13 16:56:47', '2026-09-13 16:56:47'),
(14, 'CCNWBNAP250101', 'HENRIETA KOOMSON', '0594189489', 'B', '$argon2id$v=19$m=65536,t=4,p=1$VDE1SFYvb1BGbTg5dDN3Ug$bJ8Dsyw3/cqh6Dy5aHX5qo/wHAVXY07kb2UOYnqJzIs', '2026-09-13 16:56:52', '2026-09-13 16:56:52'),
(15, 'CCNWBNAP250104', 'ABIGAIL POBI', '0256710027', 'B', '$argon2id$v=19$m=65536,t=4,p=1$T2UzY3dHVjZ2SEV0U2svdA$q2L7I5SX2zpMnFIFGEDxw++W7kiIzxOR8PQENed7QO8', '2026-09-13 16:57:32', '2026-09-13 16:57:32'),
(17, 'CCNWBNAP250045', 'Portia Afful', '0556259571', 'A', '$argon2id$v=19$m=65536,t=4,p=1$blFrMHdJaHUvTTZwS1llYw$wAop/UzjnJJCTov00rzx2ycHnULfmUHvPqjXkuJ4sgA', '2026-09-13 16:58:35', '2026-09-13 16:58:35'),
(18, 'CCNWBNAP250072', 'ELLEN KWAKYEWAA AGYEKUM', '0540983027', 'A', '$argon2id$v=19$m=65536,t=4,p=1$Qy5sdG1aNzY3NXVPcnYyTA$KfL7BScoZj9fMX6MPjPffIygkbV7e4AADOGsM2hH5hI', '2026-09-13 16:58:52', '2026-09-13 16:58:52'),
(19, 'CCNWBNAP250073', 'Celestine Kwakyewaa Ahenkorah', '0506206897', 'A', '$argon2id$v=19$m=65536,t=4,p=1$V3FLeVNQcVRwNzF0ckl3Qw$Zs6FPXtnVyGuPCnoy0jUyi/RReaRW1G4MhfK7mvtiqc', '2026-09-13 16:58:54', '2026-09-13 16:58:54'),
(20, 'CCNWBNAP250055', 'PHILOMENA TERKIE ABABIO', '0597086241', 'A', '$argon2id$v=19$m=65536,t=4,p=1$ZGh6Q3BEdXRkS25iTk1PUA$5q8jL3WjLQ0JMYkFHYbMCIDuxZueoFUqYBc0Mk5HJ98', '2026-09-13 16:59:08', '2026-09-13 16:59:08'),
(21, 'CCNWBNAP250069', 'ERNESTINA AGBEVE', '0504977617', 'A', '$argon2id$v=19$m=65536,t=4,p=1$Ly5BaVBJVWJFdi9RQXZucQ$RiHmQ+MM9grn0sEGYYlLdZuVmPKBpzL2YE2oBeCvXBQ', '2026-09-13 16:59:16', '2026-09-13 16:59:16'),
(23, 'CCNWBNAP250034', 'AKAFO ERNESTINA KORKOR', '0530236581', 'A', '$argon2id$v=19$m=65536,t=4,p=1$bUp0TEp6cFA1S1Rvemx6bQ$yzUYLgxAEsx9tE2R//KwgFL4+1V1opioK5jYG5/7JbQ', '2026-09-13 16:59:33', '2026-09-13 16:59:33'),
(24, 'CCNWBNAP250107', 'MAGDALENE BONNEY', '0245358586', 'B', '$argon2id$v=19$m=65536,t=4,p=1$ZDFpWVIuZnRLR0dvWXdwWA$pEX3rDYkWc8Fp5s7pQZWKmrEgnnuIqb59w/EwW0QSFw', '2026-09-13 17:00:15', '2026-09-13 17:00:15'),
(26, 'CCNWBNAP250149', 'Edna Nyarko Odoom', '0530611002', 'B', '$argon2id$v=19$m=65536,t=4,p=1$SHdmSUxENGhHSGt2QllPcQ$cJv9oYLz/2S5btGSy6PFgZBxf4WccWLaCqFmk/lbcCI', '2026-09-13 17:01:30', '2026-09-13 17:01:30'),
(27, 'CCNWBNAP250043', 'Nana Ama Mfum -Mensah', '+233 245248217', 'A', '$argon2id$v=19$m=65536,t=4,p=1$U3JyUFdzTlJvcWdOeVlGcQ$jzJCvNFKgDD8USn5uLdjxof9ISgqxzs9DxJEz+TWXgI', '2026-09-13 17:02:52', '2026-09-13 17:02:52'),
(28, 'CCNWBNAP250060', 'Solomon Nartey', '0257619817', 'A', '$argon2id$v=19$m=65536,t=4,p=1$NTRHQk5rU1JwQXBwUzdtbg$HIAF8+Dl49p/9C1nelIgiH4Jn6Q/jjT/iLJiR6bRtvg', '2026-09-13 17:03:00', '2026-09-13 17:03:00'),
(29, 'CCNWBNAP250052', 'Bukari vanessa', '0543747728', 'A', '$argon2id$v=19$m=65536,t=4,p=1$WVdVdTlzcFhzemd0Lk9rWg$15kbjGRRajZsyEtsrzkfgYEmcNFGjx2h6DTDMmTxkfo', '2026-09-13 17:03:48', '2026-09-13 17:03:48'),
(30, 'CCNWBNAP250132', 'AMADU-BABA HAMDIYA', '0256998609', 'B', '$argon2id$v=19$m=65536,t=4,p=1$Qmt5eXhWUld3dC9wSTh2Vg$EpVpg2OE39q4wy6W5+xXWrF7iQZfQs4u8gKcz8umdrk', '2026-09-13 17:04:00', '2026-09-13 17:04:00'),
(31, 'CCNWBNAP250046', 'Sandra Asantewaa Boateng', '0241213447', 'A', '$argon2id$v=19$m=65536,t=4,p=1$Q044b3MuZ05VNHkuS1NrQw$8B1Hl2jANr2+YVPun2oAUPlXUn/UcoWjTzZenACZfPI', '2026-09-13 17:04:13', '2026-09-13 17:04:13'),
(32, 'CCNWBNAP250066', 'SARAH KORANTENG', '0247003629', 'A', '$argon2id$v=19$m=65536,t=4,p=1$b2hKUnBxYS41Rk1qUzNReg$Hjb2Zt9qTtudqnWCFi6QDMfI5SjPtEUDwQPDw+zAHvo', '2026-09-13 17:04:19', '2026-09-13 17:04:19'),
(33, 'CCNWBNAP250063', 'ELLEN ENNIN ASSANE', '0540629850', 'A', '$argon2id$v=19$m=65536,t=4,p=1$cTJQM1VqVTFsZURNSlp1cQ$FiZwcvmx9q3ss+fc46YIKwRrDr4QgWCwnYPdyKJGaoY', '2026-09-13 17:04:44', '2026-09-13 17:04:44'),
(34, 'CCNWBNAP250141', 'Akese Ruth', '0532867486', 'B', '$argon2id$v=19$m=65536,t=4,p=1$MzFEN1hXTGFzL1NVUGdnMw$DaSfdq2iQMFIuG2AZQdC+dSNU54LRCzQR+GXhNyWPXs', '2026-09-13 17:04:47', '2026-09-13 17:04:47'),
(36, 'CCNWBNAP250071', 'SANDRA ENTSIWAH', '0546172265', 'A', '$argon2id$v=19$m=65536,t=4,p=1$QzZZQ2lxdmJ1cmxkZDNlbA$/B20K7K/c03yGTVTGu72i76+AhiXVJ1aG9mlIh417EM', '2026-09-13 17:05:45', '2026-09-13 17:05:45'),
(37, 'CCNWBNAP250100', 'Rachael Acheampong', '0543068744', 'B', '$argon2id$v=19$m=65536,t=4,p=1$cW9DUURKcnJYQUFTSGFWUA$tXK0uCyV6eioKnNqYdCRnOeMwfqyqkbliTvlEaA1hG4', '2026-09-13 17:06:34', '2026-09-13 17:06:34'),
(38, 'CCNWBNAP250097', 'Akyeabea Holali Felicia', '0595540560', 'B', '$argon2id$v=19$m=65536,t=4,p=1$V3kzZW91ZXM5aGFqRFB2Mg$5JVUC3RnV9c8GgXQrHdvn5i286MDc9U+65iQLug8lMM', '2026-09-13 17:07:27', '2026-09-13 17:07:27'),
(39, 'CCNWBNAP250146', 'Edonua Nyarko', '0593300748', 'B', '$argon2id$v=19$m=65536,t=4,p=1$eFhSSXhIVi5CcXJ5MmxxNg$xdkzXF3ncQg6SaMSlz2hs0zGwN8GfThJPypW1WqlWbc', '2026-09-13 17:07:44', '2026-09-13 17:07:44'),
(41, 'CCNWBNAP250142', 'ALHASSAN SUWAIBA', '0530365637', 'A', '$argon2id$v=19$m=65536,t=4,p=1$VUVFUHhRWUtFb2R0RkpydQ$Dm/MgB+DMENIq/qTU07qi7dJfEExcd4SS/nVlLGeP2g', '2026-09-13 17:09:44', '2026-09-13 17:09:44'),
(42, 'CCNWBNAP250125', 'Vondey Rejoice', '0548323889', 'B', '$argon2id$v=19$m=65536,t=4,p=1$clVnLlBkL3BHdklqcHVKWQ$706xvEjLDrWj6vVu7dEkZhEE8YK/LfDdmQ74DBt1O7I', '2026-09-13 17:11:19', '2026-09-13 17:11:19'),
(43, 'CCNWBNAP250017', 'Ghansah Esi Sarah', '0559136972', 'A', '$argon2id$v=19$m=65536,t=4,p=1$RnU3ZlcvYWJRUTNWUElabg$Kt4U9s/bVrh2RSqZAa5LACxCsReiDXAD4hcYEa2Qmgo', '2026-09-13 17:11:22', '2026-09-13 17:11:22'),
(44, 'CCNWBNAP250082', 'Priscilla Ekua Ahema Banson', '0599150600', 'B', '$argon2id$v=19$m=65536,t=4,p=1$bUN4TFpRQnhqQi9hT215SA$Rs5CRZUtX+NxiFvAijEdlFdLKcJMdT8q995PSheaZq0', '2026-09-13 17:11:35', '2026-09-13 17:11:35'),
(45, 'CCNWBNAP250083', 'Enocklina Atombo', '0534987070', 'B', '$argon2id$v=19$m=65536,t=4,p=1$SEl1cldML1JIL0tiZWhrZA$OE8yqZsqWJGG2h8KQeSRuJSOoC83Ewui5OF64hoQPIM', '2026-09-13 17:12:26', '2026-09-13 17:12:26'),
(46, 'CCNWBNAP250102', 'Abdullai  Hawa', '0593894343', 'B', '$argon2id$v=19$m=65536,t=4,p=1$M3VSS09ORFBucGh0aGRKVA$r3bIL6YSGfNQWi2p6XgRl6OuB4BwXQzeVxLe1VEasN4', '2026-09-13 17:13:46', '2026-09-13 17:13:46'),
(47, 'CCNWBNAP250067', 'KONGO PEARL MAWUNYO', '0555566478', 'A', '$argon2id$v=19$m=65536,t=4,p=1$WDVrSGpYMkhHRmNUNS5UdQ$u03KPps90LlWCVnKTKSs9/mIog5GuuJh3elgu9/2F/U', '2026-09-13 17:14:25', '2026-09-13 17:14:25'),
(48, 'CCNWBNAP250016', 'Theresah Sagoe', '0539656989', 'A', '$argon2id$v=19$m=65536,t=4,p=1$ZHM1U3NjMmt4eVhEM29saQ$nh4hvAmk3XBRxE5+rUG0pIzOHppHXEJ5h+sO3xDgg9k', '2026-09-13 17:15:13', '2026-09-13 17:15:13'),
(49, 'CCNWBNAP250109', 'Firdaus Muhammed Sani', '0539649244', 'B', '$argon2id$v=19$m=65536,t=4,p=1$eG1xTmtqZDFFbE1ORUFhTA$JTdzV6JR8HRXHeMB3YqZKU4VyOPmp47UDucJi9OlqDA', '2026-09-13 17:15:44', '2026-09-13 17:15:44'),
(50, 'CCNWBNAP250081', 'Angela Asare Bediako', '0532606481', 'B', '$argon2id$v=19$m=65536,t=4,p=1$WkZjZG5iYW9yREFFRXRQMw$Zsnj4k0Aev2gb+Y+ODLtSiRv670zwlJGu0+XjIwSW2c', '2026-09-13 17:15:59', '2026-09-13 17:15:59'),
(51, 'CCNWBNAP250013', 'ACQUAH ERNESTINA', '0556694093', 'A', '$argon2id$v=19$m=65536,t=4,p=1$TlYudDdZWnJlMGZOMUdUWA$slpO/heNE6EhzN4f1uIqCj5LoLDiIL5CdW1RwF5Cnio', '2026-09-13 17:18:34', '2026-09-13 17:18:34'),
(53, 'CCNWBNAP250154', 'Cynthia Buabeng', '0535634628', 'B', '$argon2id$v=19$m=65536,t=4,p=1$UzRJcHB3OVR3MUJSemFLNQ$1jL7gQRP2mVpw6G+5xC9q3jdKqbEhXvgA1gdUgq7Asg', '2026-09-13 17:23:23', '2026-09-13 17:23:23'),
(54, 'CCNWBNAP250048', 'Jessica Yaa Afankwa', '0240923352', 'A', '$argon2id$v=19$m=65536,t=4,p=1$TjJ2TkpWd3NNSTJsQUMwRg$6pApJdpg8Zy/Ar0flJhRtrdHM8lOQcpm4qeTVwYy7WI', '2026-09-13 17:24:58', '2026-09-13 17:24:58'),
(55, 'CCNWBNAP250047', 'Abubakar Nihad', '0530654803', 'A', '$argon2id$v=19$m=65536,t=4,p=1$TGlVZUtma0ZIOXhQSUNqTw$AsgQFXZccjQN8gOz4g0nve9HThr9gscc2A/DhS9V52s', '2026-09-13 17:25:53', '2026-09-13 17:25:53'),
(56, 'CCNWBNAP250127', 'JOYCELYN ADOLEY ADDO', '0508786515', 'B', '$argon2id$v=19$m=65536,t=4,p=1$Z09aeDJrQkEzSEF0aEl6Vw$SGxgHuHWmpv8Q/Vdp4li/XEMpoPacvgPfJtss1dNmmQ', '2026-09-13 17:27:57', '2026-09-13 17:27:57'),
(57, 'CCNWBNAP250143', 'DORCAS AGBEKO', '0539249089', 'B', '$argon2id$v=19$m=65536,t=4,p=1$dzR0UkdpaHZOMEpHZXpIRw$szIgS7kQEch7LiY9dFdffGrHrRhY+FenGn9vwOherkk', '2026-09-13 17:28:04', '2026-09-13 17:28:04'),
(58, 'CCNWBNAP250098', 'Felicia Aduko', '0243277587', 'B', '$argon2id$v=19$m=65536,t=4,p=1$YW1qVTdhN3RhZGl4VHhQLg$PKyhv42zrqLhbNlJ8jDWa/uUgmqGH5+3j/zLIh001GI', '2026-09-13 17:28:46', '2026-09-13 17:28:46'),
(59, 'CCNWBNAP250020', 'Naomi Yeboah', '0245605464', 'A', '$argon2id$v=19$m=65536,t=4,p=1$RFUzNlZVSFJKNkpSRWVXeQ$J9S6ee6aC0IzUZIZrnhbUr+VXvGuXi7gvXhMxA+XNOU', '2026-09-13 17:28:59', '2026-09-13 17:28:59'),
(60, 'CCNWBNAP250105', 'Jennifer Dede Quarshie', '0550394949', 'A', '$argon2id$v=19$m=65536,t=4,p=1$QXhoUm1BdWhJUkhTZFRUQg$lcIBa7/per7qafkfM0Mja4jziWKAkMRAzHWZaqan+vE', '2026-09-13 17:31:33', '2026-09-13 17:31:33'),
(61, 'CCNWBNAP250114', 'PERFECT AKPAGLO', '0592921342', 'B', '$argon2id$v=19$m=65536,t=4,p=1$QXBsemdLYlhkSnNqUnNGUQ$zvhEeRr7DGUfeo/SiYTEOxFt7L9A3HtMg3sxG5ImGxs', '2026-09-13 17:31:57', '2026-09-13 17:31:57'),
(62, 'CCNWBNAP250135', 'ALICE GLADYS ASSAN', '0208476156', 'A', '$argon2id$v=19$m=65536,t=4,p=1$ZnBqTVY2UkkxYTBpa3ljLw$8hlpJhXzrECKybP/9mx6IDH0/A1eSLie4wWks5ER4MI', '2026-09-13 17:31:58', '2026-09-13 17:31:58'),
(63, 'CCNWBNAP250144', 'Sule Mariama', '0559602127', 'B', '$argon2id$v=19$m=65536,t=4,p=1$UVhGaTVveDE1TlVlNHYwcg$g2wJYJOVhnm1+gDSgBKHFogsiWc49YI2Htlcfc/84oE', '2026-09-13 17:38:40', '2026-09-13 17:38:40'),
(64, 'CCNWBNAP250051', 'NANA ADWOA ADUBEA DARKO', '0538243971', 'A', '$argon2id$v=19$m=65536,t=4,p=1$UE93Nm1xaGE1amtPUDhGMQ$ZEDCKpbUx7k4U/r4lyla8vqVjBxinH+iqWsD4txt3JY', '2026-09-13 17:39:02', '2026-09-13 17:39:02'),
(65, 'CCNWBNAP250064', 'SAAH NOADIAH KRAMPAH', '0556852631', 'A', '$argon2id$v=19$m=65536,t=4,p=1$MVN3VW9JNGpWWmNjYVRZeQ$CkwXikc3yajJgdN0tpxPnO5z3JrEvZOcimQCuMAHmGQ', '2026-09-13 17:41:18', '2026-09-13 17:41:18'),
(66, 'CCNWBNAP250035', 'Nkekeshie Bernice yayra yawo', '0533154938', 'A', '$argon2id$v=19$m=65536,t=4,p=1$Z3VBNVIzblhQZVVFQXFMZg$9wFwVH1RR8Og3mEhU0IRvNh7PItuYyEqZMJeF2Z46fg', '2026-09-13 17:47:14', '2026-09-13 17:47:14'),
(67, 'CCNWBNAP250133', 'Emmanuella Naa Koshie Kunim Lamptey', '0257949645', 'B', '$argon2id$v=19$m=65536,t=4,p=1$V3lTZmo4RVM4cmw5Y0Yucw$t+BsF1RVxnSrymWUK/lSsfxm69oXRh7jULRb8KHKvqI', '2026-09-13 17:50:39', '2026-09-13 17:50:39'),
(68, 'CCNWBNAP250012', 'Elizabeth Selase Adoboe', '0531888678', 'A', '$argon2id$v=19$m=65536,t=4,p=1$bExXbmtjc1JQMzQ3NnkuWQ$ma2wxAzFQTRS0iTzcFwFlJ7dx0K6sS3ZDb7uMX9OTIU', '2026-09-13 17:50:43', '2026-09-13 17:50:43'),
(69, 'CCNWBNAP250118', 'SEMEFA ATIDEPE', '0502534238', 'B', '$argon2id$v=19$m=65536,t=4,p=1$Uy5Sc0VxV2ZyTVpzbVdXZg$/4n2VlDSOfBj4Rhf2V8RNOtMy2EMIelKF7tFJOP0vK8', '2026-09-13 17:50:56', '2026-09-13 17:50:56'),
(70, 'CCNWBNAP250126', 'Asmau Abdulla', '0538141571', 'B', '$argon2id$v=19$m=65536,t=4,p=1$T2Z3NVBQZ2hQeC5qTXc5dQ$VfSPSfzRk1pBFYJ+Kwt9VY36ujAvy5mtBPEWbrxNjYw', '2026-09-13 17:51:10', '2026-09-13 17:51:10'),
(71, 'CCNWBNAP250030', 'EMMANUELLA FENYI GYAN', '0595542215', 'A', '$argon2id$v=19$m=65536,t=4,p=1$ODFCZ21FYmczaXJqek9QQQ$YDTGbSyiAvcBb4b8WqWj+PI/fccJG4GT5IfKUnkeq18', '2026-09-13 17:52:04', '2026-09-13 17:52:04'),
(72, 'CCNWBNAP250145', 'Dorcas Miriam Teikutey', '0537676376', 'B', '$argon2id$v=19$m=65536,t=4,p=1$QS53cEFFa3ZFVzFtS0hmRA$vdJpBsaCgnDit4OI53K54xPTT03d8JJAbGndYjbSAuo', '2026-09-13 17:52:35', '2026-09-13 17:52:35'),
(73, 'CCNWBNAP250036', 'Florence Acquah', '0530463851', 'A', '$argon2id$v=19$m=65536,t=4,p=1$UGYxUFBLRWN2OXd1REQ1Uw$SF2tCCboZUwJBWuQlq9CpY6jRUeiY6CAf2UdiKQPgN4', '2026-09-13 17:52:50', '2026-09-13 17:52:50'),
(74, 'CCNWBNAP250103', 'MICHEALLA DUODU', '0551297375', 'B', '$argon2id$v=19$m=65536,t=4,p=1$eFBwRHpPbGlhRE9pdVJyaA$QjKhFRhIQ5B1OrJs1crXqMBUSaDWKy+1R335l4uJEpc', '2026-09-13 17:53:53', '2026-09-13 17:53:53'),
(75, 'CCNWBNAP250029', 'ARTHUR SAKYIWAAH MARY', '0599405051', 'A', '$argon2id$v=19$m=65536,t=4,p=1$SVdqQ2JGRG5STG4uNHlTcA$z4nPJZUdHVgXr83eynSnT0AYKGjo0+F1GLSsQH7sJL4', '2026-09-13 17:55:11', '2026-09-13 17:55:11'),
(76, 'CCNWBNAP250090', 'Amartey Jewellyna Armah', '0508740888', 'B', '$argon2id$v=19$m=65536,t=4,p=1$YlRSTG5hQWh6eGNIaXRtRg$jg1RhWK33sW0Xvd5rIYRaLSjxfIqJlxegYqR56lcFYY', '2026-09-13 18:04:33', '2026-09-13 18:04:33'),
(77, 'CCNWBNAP250021', 'DEDE RACHAEL', '0537612136', 'A', '$argon2id$v=19$m=65536,t=4,p=1$YnlBOWtyZ3VMRk56TTVOMA$uzHU51Qk5cWMbvDQEsklc76PDHrHp2Km3pu4TvVryOA', '2026-09-13 18:19:34', '2026-09-13 18:19:34'),
(78, 'CCNWBNAP250079', 'Francisca Ayensu', '0245012781', 'B', '$argon2id$v=19$m=65536,t=4,p=1$ejB3QnJlNEpSdjdxTS8xRw$vWJIoDz17eg7VG4pIt+A1GS2B8o7pDuzFdklgTJ7P5w', '2026-09-13 18:28:35', '2026-09-13 18:28:35'),
(79, 'CCNWBNAP250110', 'Sekinatu Abdul -Razak', '0592654838', 'B', '$argon2id$v=19$m=65536,t=4,p=1$OFp5LlMuU01nR1NweE9LaA$fwxGEzagH6ADJDqw9YCPg9OZ1W22bw+fP1jh4AA4OCI', '2026-09-13 18:33:04', '2026-09-13 18:33:04'),
(80, 'CCNWBNAP250027', 'DOKU CAROLINE', '0534703715', 'A', '$argon2id$v=19$m=65536,t=4,p=1$M3hvcXJmZ0piOHV3WXRWLg$Acft2/zNIxyte/pucxDevFfCFgvcXmvhj98Gds7B4M0', '2026-09-13 18:41:00', '2026-09-13 18:41:00'),
(81, 'CCNWBNAP250124', 'Linda Aidoo', '0500822436', 'B', '$argon2id$v=19$m=65536,t=4,p=1$bWxHRS5aTVBvdjd5cTZUdQ$BKXIc6FY/C1HeNFpfZE02A3cf1N0eE7cUPcKrGmKuD8', '2026-09-13 18:58:15', '2026-09-13 18:58:15'),
(82, 'CCNWBNAP250049', 'BARBARA KPOBI', '0508013705', 'A', '$argon2id$v=19$m=65536,t=4,p=1$QWNLaURSaGsxbXlOWmpSYw$4z6SPVZYuSoKxj87edmr0RLLRWecXefeOi6LCvjHKkA', '2026-09-13 18:58:49', '2026-09-13 18:58:49'),
(83, 'CCNWBNAP250085', 'Elizabeth Kwofie', '0593957468', 'B', '$argon2id$v=19$m=65536,t=4,p=1$UkNxcGFMSTg1UGJOYnQ5ZA$ULhDFhTIjJzt9H9KuCBH5PXBPrMvqwBj2AKDVVrMy+M', '2026-09-13 19:00:15', '2026-09-13 19:00:15'),
(84, 'CCNWBNAP250025', 'Emmanuella Afrakomah Agyapong', '0550630395', 'A', '$argon2id$v=19$m=65536,t=4,p=1$N3ZpMTFPOEpFTnJveFh1aw$5MiryVUDN26RH5skesGTT1bXyPgQiL2ErysPX2WrQTI', '2026-09-13 19:03:37', '2026-09-13 19:03:37'),
(85, 'CCNWBNAP250094', 'Eugenia Frimpomaa Obeng', '0535794087', 'B', '$argon2id$v=19$m=65536,t=4,p=1$UFppOU5EMHBMOTVaTHgvcQ$ro+15KTuCDhJKzGqhtYo8RLl9u6S3qKuGbOqhf8T5A4', '2026-09-13 19:13:44', '2026-09-13 19:13:44'),
(86, 'CCNWBNAP250041', 'Adu Frimpong Priscilla', '0555803044', 'A', '$argon2id$v=19$m=65536,t=4,p=1$aExSdWpEanQ4aENnaDdGMw$SKPCqc1tfSzD/17HgOBIsllrbtbsz4L6hWnpY/N1Utk', '2026-09-13 19:15:28', '2026-09-13 19:15:28'),
(87, 'CCNWBNAP250068', 'BLESSING ABOR', '0559856664', 'A', '$argon2id$v=19$m=65536,t=4,p=1$aG5PeDRTWWdJYi53L2RSRA$Xb6gMTFFrq3AsHicFvANzZuXbRYMaFtjDegilpogI9A', '2026-09-13 19:22:00', '2026-09-13 19:22:00'),
(88, 'CCNWBNAP250024', 'DINAH OFORIWAA AGYEI', '0531236352', 'A', '$argon2id$v=19$m=65536,t=4,p=1$WVQ5U1ZCTjVNLjZ4MHdhNg$1b3KsIe8CdvNDPSCizIWBdndlfICmrKj33cMUNeirEs', '2026-09-13 19:22:15', '2026-09-13 19:22:15'),
(89, 'CCNWBNAP250088', 'APPIAH MUNIRA', '0597741390', 'A', '$argon2id$v=19$m=65536,t=4,p=1$RmxsNzMuYmNyUWFEYWVLTw$5cRc//oPZVYNABDXTCEq1YBQKV6yNO7ZF9eBteMsONE', '2026-09-13 19:22:26', '2026-09-13 19:22:26'),
(90, 'CCNWBNAP250086', 'Alima ketu', '0598742500', 'A', '$argon2id$v=19$m=65536,t=4,p=1$MTNkQlJ4Rlp1VmZoQmxhRA$Q7+dS7gRAw59XEM7pye6gUQunD9S3gipt+k6hAWcYKs', '2026-09-13 19:24:36', '2026-09-13 19:24:36'),
(91, 'CCNWBNAP250121', 'Fati Ayariga', '0596328067', 'B', '$argon2id$v=19$m=65536,t=4,p=1$RDlvNXoxUlpyV2t6VkdMSw$TU40JqYh4waqtf2lp2f8EtyQ8uvEaorkZ4jEtfYlYCw', '2026-09-13 19:26:07', '2026-09-13 19:26:07'),
(92, 'CCNWBNAP250006', 'Philipa Amoakoh', '0542825466', 'A', '$argon2id$v=19$m=65536,t=4,p=1$bGJKZW4xcXR3eUhSOWx0Wg$jU7Ri0VQXBVwRk+8XODzBgZUq9DoEaiXTZt62v8pbDk', '2026-09-13 19:27:49', '2026-09-13 19:27:49'),
(93, 'CCNWBNAP250156', 'Segu Naomi', '0538574826', 'A', '$argon2id$v=19$m=65536,t=4,p=1$VUFmMTVVTG9Ra2V5cUpyRw$KC9fq6cT1OUvfIyC/Ft8BcUfrZFv1/naosQtb9H0r1Y', '2026-09-13 19:31:53', '2026-09-13 19:31:53'),
(94, 'CCNWBNAP250122', 'JESSICA GYAN', '0533459355', 'B', '$argon2id$v=19$m=65536,t=4,p=1$OUw2UVBuSXp6UHFKV0tNMg$Q6VqTTuHe53CVVg/czUqVamy+GHeUSf0XUtGidaLD+8', '2026-09-13 19:32:14', '2026-09-13 19:32:14'),
(95, 'CCNWBNAP250032', 'Irene Bragor Boateng', '0531923390', 'A', '$argon2id$v=19$m=65536,t=4,p=1$enZqcU1nRmJCNkd5NlBwNQ$E8zryRGG30Kc/bFwDXHs3pd6MLi9D2+2saUv7R1AaP0', '2026-09-13 19:34:34', '2026-09-13 19:34:34'),
(96, 'CCNWBNAP250015', 'AUGUSTINA ADDO', '0257457185', 'A', '$argon2id$v=19$m=65536,t=4,p=1$c2pEckNMZ3hzbEpOWDlOYQ$oNn8GDsF2lG3g1pzWwtyi3GiRpF9Yb83qT3XTmbr62o', '2026-09-13 19:36:27', '2026-09-13 19:36:27'),
(97, 'CCNWBNAP250008', 'MENSAH EVANS', '0530275843', 'A', '$argon2id$v=19$m=65536,t=4,p=1$MWVQYVlDYkZMOWxWV2pvMw$0GPvwsHsXDnuAfTb2X4BV29wYTS6XvaNCvub/GMusOQ', '2026-09-13 19:42:44', '2026-09-13 19:42:44'),
(98, 'CCNWBNAP250059', 'Agyekum Mercy', '0544192111', 'A', '$argon2id$v=19$m=65536,t=4,p=1$aFlBUC44eEVZczVyaDZBZA$ZRzGFHbCOAk/PZDJoyAdkYegpzlKyniukJEjCre1jNo', '2026-09-13 19:43:33', '2026-09-13 19:43:33'),
(99, 'CCNWBNAP250014', 'BENEDICTA MENSAH', '0595476598', 'A', '$argon2id$v=19$m=65536,t=4,p=1$bFkwMG9Ocm1yd25XQ3hJOQ$mi/fR641tD6PIVsTnbspgaSv+OjrLrzzm3bW+ydCts4', '2026-09-13 19:47:22', '2026-09-13 19:47:22'),
(100, 'CCNWBNAP250040', 'BEKOE MARIAM', '0558509281', 'A', '$argon2id$v=19$m=65536,t=4,p=1$VkxBbE1TeFo5T052YTNYVQ$ssCZPZ3/CUJ/gcPmaBBMlOVi9TcUXhpwwJCawjWvqZk', '2026-09-13 19:51:01', '2026-09-13 19:51:01'),
(101, 'CCNWBNAP250134', 'CHRISTABEL SERWAA ACHEAMPONG', '0505451921', 'A', '$argon2id$v=19$m=65536,t=4,p=1$UVYvOVh1MXpWL0FKLnR6Mg$4RexWDH2bxr/GxmQy6q+Fn4yx6mbfHYVFmPP715CJ8c', '2026-09-13 20:06:48', '2026-09-13 20:06:48'),
(102, 'CCNWBNAP250087', 'Faith Adebo', '0593495636', 'B', '$argon2id$v=19$m=65536,t=4,p=1$RGYwRm81OEd3WFlER202eA$c5JiF5Wopr0UDlJ/RsSQzCoNUWyXuwGPBZDgs0noitM', '2026-09-13 20:09:32', '2026-09-13 20:09:32'),
(103, 'CCNWBNAP250108', 'Queenster Opokua Barnor', '0553617468', 'B', '$argon2id$v=19$m=65536,t=4,p=1$RGJqZlpWcFVhYzZFdVZyTw$TH2VliPh5SBcnSHBZe80AeM2+UiEpZXJLtS25aHwj5M', '2026-09-13 20:20:33', '2026-09-13 20:20:33'),
(104, 'CCNWBNAP250007', 'HENRY ARTHUR', '0201121543', 'A', '$argon2id$v=19$m=65536,t=4,p=1$TkhUdmRGdXEwMVdyOUovdA$HbqTs1B9Xn3/rNy92vDaaueB/KQeBI253wDdv+r6BGA', '2026-09-13 20:21:04', '2026-09-13 20:21:04'),
(105, 'CCNWBNAP250080', 'Juliet Takyi-Bosu', '0544714016', 'B', '$argon2id$v=19$m=65536,t=4,p=1$SVRTR2RkWmQvNkYxc1I0eg$OXeipId41vNNkYI86b6+RWi6LInRzM/QO+fQNC22bv0', '2026-09-13 20:24:27', '2026-09-13 20:24:27'),
(106, 'CCNWBNAP250070', 'Emmanuel Amponsah', '0548085848', 'B', '$argon2id$v=19$m=65536,t=4,p=1$THNzZlhHL2haV255dlg4Lg$Okdm3odcVPCMTU6mHq3m2Sz+yt1FvqSB2C0k8DXoR5k', '2026-09-13 20:24:39', '2026-09-13 20:24:39'),
(107, 'CCNWBNAP250019', 'MARY ADJEI', '0552597828', 'A', '$argon2id$v=19$m=65536,t=4,p=1$b0Vkd0pON1B6MHdpVmRYeg$U+VfOGnX47Oqc/FvUBmWF9ktidZzZGVY6HVWCN4lVNU', '2026-09-13 20:32:05', '2026-09-13 20:32:05'),
(108, 'CCNWBNAP250033', 'Fiona Aku Bansah', '0533665711', 'A', '$argon2id$v=19$m=65536,t=4,p=1$UXh4NG1VODdKSXpRMzVVZw$W6qFlGp9715orH5wAeRVQq/C4mR0UWXAbprGqErMfNg', '2026-09-13 20:36:14', '2026-09-13 20:36:14'),
(109, 'CCNWBNAP250062', 'PRECIOUS EDU', '0537566199', 'A', '$argon2id$v=19$m=65536,t=4,p=1$SFlUWUVYRTA0NS5saXFmVg$OFAgRzXKCc8Up5hzrLlo192qEhC2r0IdfHETwkvZozs', '2026-09-13 20:47:00', '2026-09-13 20:47:00'),
(110, 'CCNWBNAP250050', 'MARY AWUNI', '0594626734', 'B', '$argon2id$v=19$m=65536,t=4,p=1$cTFidlV3OEYvLkt5RnZQRw$nlrzjkQTLYrXRxM4Fc2VAR0pcxOnU6GGHEPbY2zRDUc', '2026-09-13 20:48:29', '2026-09-13 20:48:29'),
(111, 'CCNWBNAP250056', 'MERCY ACQUAH', '0535186085', 'A', '$argon2id$v=19$m=65536,t=4,p=1$VmdhVGxQczE5SWV1QjdaRA$xT1bf6K0zPcr7+LuFKsweE6FqYp48M03wIaxRGx5i+U', '2026-09-13 21:04:06', '2026-09-13 21:04:06'),
(112, 'CCNWBNAP250028', 'ANNELLA OWIREDU', '0534709925', 'A', '$argon2id$v=19$m=65536,t=4,p=1$T21TZktwZ05UdkZDTm5Odg$xaZJDLhWKQ5kzttn94cp7J4q9SgtyUr0id4syKVpapY', '2026-09-13 21:16:28', '2026-09-13 21:16:28'),
(113, 'CCNWBNAP250009', 'Ruth Mensah', '0247742801', 'A', '$argon2id$v=19$m=65536,t=4,p=1$R2hCSHo4TnYvTEJQV2Vudg$U6/7JFfVlZsAlO0jg4Q+sRMJUOvsbvpBRiPhKbWUm70', '2026-09-13 21:22:43', '2026-09-13 21:22:43'),
(114, 'CCNWBNAP250151', 'Selina Okyere', '0547359032', 'A', '$argon2id$v=19$m=65536,t=4,p=1$aks0Z2Nkb0QvMTNRcFdhaQ$v9Xu0pUQk8OiPMASSYiZ3LxCzy0EF3HuF4kwjSkbXvw', '2026-09-13 21:28:18', '2026-09-13 21:28:18'),
(115, 'CCNWBNAP250152', 'EMMANUEL ABENYI', '0554396579', 'A', '$argon2id$v=19$m=65536,t=4,p=1$R2VrVkpaRVMwT2FOYlc2Zw$urOGhTh6Oj5cJWljDIUV/WMHWuzNB67NdWqrG0TZU+s', '2026-09-13 21:47:04', '2026-09-13 21:47:04'),
(116, 'CCNWBNAP250058', 'MARY ADDISON', '0559373637', 'A', '$argon2id$v=19$m=65536,t=4,p=1$cFBWNHRMRXRhY0dTd3pBbg$3tPeoDhMzfv0cI+cFDXzny7MV8JhQko5VPwNvnnVIhc', '2026-09-13 21:50:19', '2026-09-13 21:50:19'),
(117, 'CCNWBNAP250129', 'Selina Quayson', '0531820026', 'B', '$argon2id$v=19$m=65536,t=4,p=1$UFp3NHVYczRCWFRPZU1UMg$IK5deWZysZDXCMgrTnMo4E5Fbbr1bu4nJRfsxAtmDlo', '2026-09-13 21:56:44', '2026-09-13 21:56:44'),
(118, 'CCNWBNAP250011', 'Hannah Baffoe', '0552898108', 'A', '$argon2id$v=19$m=65536,t=4,p=1$b2UzRm0uSWpiUlAxWC9jWg$G2RItBOxaa4Jwx4O4aeW6feJ2hi5seFY8B92sSWYx/s', '2026-09-13 22:02:00', '2026-09-13 22:02:00'),
(119, 'CCNWBNAP250042', 'Ivy Lumor', '0549495462', 'A', '$argon2id$v=19$m=65536,t=4,p=1$NlFRa2p4WGNiRVFlVlMxaw$U37I3GSazL+p486DfzbSHoiejkFStkswHKhUBMI/lfk', '2026-09-13 22:15:44', '2026-09-13 22:15:44'),
(120, 'CCNWBNAP250005', 'JANE GHUNNEY', '0548816565', 'A', '$argon2id$v=19$m=65536,t=4,p=1$WUpDWWVvM256QS5zRlRCdA$O2ss1mF19sdGx2tcYvIE2nkfutF9mjrh4RnS76qaqzY', '2026-09-13 22:19:01', '2026-09-13 22:19:01');

/*
-- Indexes, auto-increment adjustments, and foreign keys are intentionally
-- omitted in merge mode because the target database already owns its schema.
-- This prevents duplicate-key and duplicate-constraint errors.

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `attendance_records`
--
ALTER TABLE `attendance_records`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_student_session` (`session_id`,`student_id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `attendance_sessions`
--
ALTER TABLE `attendance_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `courses`
--
ALTER TABLE `courses`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `index_number` (`index_number`),
  ADD KEY `token` (`token`);

--
-- Indexes for table `portal_alerts`
--
ALTER TABLE `portal_alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `source` (`source`),
  ADD KEY `severity` (`severity`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `index_number` (`index_number`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `attendance_records`
--
ALTER TABLE `attendance_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `attendance_sessions`
--
ALTER TABLE `attendance_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `courses`
--
ALTER TABLE `courses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `portal_alerts`
--
ALTER TABLE `portal_alerts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=361;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=121;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance_records`
--
ALTER TABLE `attendance_records`
  ADD CONSTRAINT `attendance_records_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `attendance_sessions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `attendance_records_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `attendance_sessions`
--
ALTER TABLE `attendance_sessions`
  ADD CONSTRAINT `attendance_sessions_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE;
*/
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
