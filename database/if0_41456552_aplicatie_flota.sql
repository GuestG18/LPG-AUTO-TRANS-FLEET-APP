-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jun 16, 2026 at 01:21 PM
-- Server version: 8.0.45
-- PHP Version: 8.3.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `if0_41456552_aplicatie_flota`
--

-- --------------------------------------------------------

--
-- Table structure for table `alimentari`
--

CREATE TABLE `alimentari` (
  `id` int UNSIGNED NOT NULL,
  `vehicle_id` int UNSIGNED NOT NULL,
  `driver_id` int UNSIGNED DEFAULT NULL,
  `data_alimentare` date NOT NULL,
  `litri` decimal(8,2) NOT NULL,
  `cost_total` decimal(10,2) NOT NULL,
  `km_bord` int UNSIGNED NOT NULL,
  `km_alimentare` int UNSIGNED NOT NULL,
  `observatii` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `anvelope`
--

CREATE TABLE `anvelope` (
  `id` int UNSIGNED NOT NULL,
  `brand` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tire_size` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dot_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `serial_number` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_vehicle_type` enum('autovehicul','autoutilitara','camion','cap_tractor','semiremorca','semiremorca_primar','semiremorca_distributie','universal') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'universal',
  `target_axle_config` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `axle_type` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tire_type` enum('direction','traction','trailer','balloon','balloon_directional') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'trailer',
  `mount_date` date DEFAULT NULL,
  `km_initial` int UNSIGNED NOT NULL DEFAULT '0',
  `estimated_life_km` int UNSIGNED DEFAULT NULL,
  `tread_depth_mm` decimal(5,2) DEFAULT NULL,
  `min_tread_depth_mm` decimal(5,2) NOT NULL DEFAULT '2.00',
  `status` enum('active','spare','removed','damaged','retreaded') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'spare',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `mentenanta_id` int UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `anvelope`
--

INSERT INTO `anvelope` (`id`, `brand`, `model`, `tire_size`, `dot_code`, `serial_number`, `target_vehicle_type`, `mount_date`, `km_initial`, `estimated_life_km`, `tread_depth_mm`, `min_tread_depth_mm`, `status`, `notes`, `mentenanta_id`, `created_at`, `updated_at`) VALUES
(1, 'Michelin', 'Axinte1', '315/80', '3423', '53214151', 'universal', '2026-05-12', 323232, 5000, NULL, 2.00, 'active', NULL, 5, '2026-05-12 11:33:50', '2026-05-12 14:09:05'),
(2, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B605NET-20260512124328-001-5ADC', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 6, '2026-05-12 12:43:28', '2026-05-19 15:30:10'),
(3, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B605NET-20260512124328-002-9E40', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 7, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(4, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B605NET-20260512124328-003-FF7E', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 8, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(5, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B605NET-20260512124328-004-A5B5', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 9, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(6, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B605NET-20260512124328-005-F8CD', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 10, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(7, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B435NET-20260512124328-001-C421', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 11, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(8, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B435NET-20260512124328-002-7588', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 12, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(9, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B435NET-20260512124328-003-6857', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 13, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(10, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B435NET-20260512124328-004-3015', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 14, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(11, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B435NET-20260512124328-005-B5D6', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 15, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(12, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B435NET-20260512124328-006-4647', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 16, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(13, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B677NET-20260512124328-001-B508', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 17, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(14, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B677NET-20260512124328-002-A7F4', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 18, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(15, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B677NET-20260512124328-003-1E79', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 19, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(16, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B677NET-20260512124328-004-D01D', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 20, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(17, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B677NET-20260512124328-005-E4E0', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 21, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(18, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B677NET-20260512124328-006-F742', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 22, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(19, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B325NET-20260512124328-001-86AE', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 23, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(20, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B325NET-20260512124328-002-ECDF', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 24, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(21, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B325NET-20260512124328-003-4EB4', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 25, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(22, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B325NET-20260512124328-004-B28C', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 26, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(23, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B325NET-20260512124328-005-2B23', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 27, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(24, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B325NET-20260512124328-006-F9F7', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 28, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(25, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B275NET-20260512124328-001-5463', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 29, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(26, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B275NET-20260512124328-002-C3EB', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 30, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(27, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B275NET-20260512124328-003-5824', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 31, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(28, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B275NET-20260512124328-004-68D4', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 32, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(29, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B275NET-20260512124328-005-3BA4', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 33, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(30, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B275NET-20260512124328-006-2614', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 34, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(31, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B430NET-20260512124328-001-B89F', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 35, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(32, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B430NET-20260512124328-002-DD59', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 36, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(33, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B430NET-20260512124328-003-77AB', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 37, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(34, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B430NET-20260512124328-004-9879', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 38, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(35, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B430NET-20260512124328-005-58C4', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 39, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(36, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B430NET-20260512124328-006-65AB', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 40, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(37, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B311NET-20260512124328-001-232D', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 41, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(38, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B311NET-20260512124328-002-083E', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 42, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(39, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B311NET-20260512124328-003-07DF', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 43, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(40, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B311NET-20260512124328-004-697C', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 44, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(41, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B311NET-20260512124328-005-E284', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 45, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(42, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B311NET-20260512124328-006-078C', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 46, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(43, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B345NET-20260512124328-001-22D7', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 47, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(44, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B345NET-20260512124328-002-DB99', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 48, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(45, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B345NET-20260512124328-003-D1EF', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 49, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(46, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B345NET-20260512124328-004-E9F2', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 50, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(47, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B345NET-20260512124328-005-46DB', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 51, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(48, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B345NET-20260512124328-006-89C6', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 52, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(49, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B219NET-20260512124328-001-B5A5', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 53, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(50, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B219NET-20260512124328-002-955C', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 54, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(51, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B219NET-20260512124328-003-B5F7', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 55, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(52, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B219NET-20260512124328-004-C37D', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 56, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(53, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B219NET-20260512124328-005-67F7', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 57, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(54, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B219NET-20260512124328-006-FF14', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 58, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(55, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B437NET-20260512124328-001-D0AB', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 59, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(56, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B437NET-20260512124328-002-56D2', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 60, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(57, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B437NET-20260512124328-003-1B69', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 61, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(58, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B437NET-20260512124328-004-33DC', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 62, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(59, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B437NET-20260512124328-005-4C63', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 63, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(60, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B437NET-20260512124328-006-E6E7', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 64, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(61, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B385NET-20260512124328-001-DB8B', 'autovehicul', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 65, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(62, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B385NET-20260512124328-002-B030', 'autovehicul', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 66, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(63, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B385NET-20260512124328-003-925D', 'autovehicul', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 67, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(64, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B385NET-20260512124328-004-DB5E', 'autovehicul', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 68, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(65, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B375NET-20260512124329-001-8B2F', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 69, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(66, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B375NET-20260512124329-002-5930', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 70, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(67, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B375NET-20260512124329-003-0358', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 71, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(68, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B375NET-20260512124329-004-CF2C', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 72, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(69, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B375NET-20260512124329-005-9E6C', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 73, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(70, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B375NET-20260512124329-006-CDF2', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 74, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(71, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B285NET-20260512124329-001-A860', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 75, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(72, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B285NET-20260512124329-002-A8AF', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 76, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(73, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B285NET-20260512124329-003-E189', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 77, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(74, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B285NET-20260512124329-004-41EE', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 78, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(75, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B285NET-20260512124329-005-6AEE', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 79, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(76, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B285NET-20260512124329-006-DC3F', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 80, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(77, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B405NET-20260512124329-001-BDCC', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 81, '2026-05-12 12:43:28', '2026-06-12 14:41:43'),
(78, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B405NET-20260512124329-002-CE0D', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 82, '2026-05-12 12:43:28', '2026-06-12 14:46:38'),
(79, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B405NET-20260512124329-003-E91F', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 83, '2026-05-12 12:43:28', '2026-06-12 14:46:38'),
(80, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B405NET-20260512124329-004-1EA6', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 84, '2026-05-12 12:43:28', '2026-06-12 14:41:59'),
(81, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B405NET-20260512124329-005-1A06', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 85, '2026-05-12 12:43:28', '2026-06-12 14:41:59'),
(82, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B405NET-20260512124329-006-1310', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 86, '2026-05-12 12:43:28', '2026-06-12 14:46:38'),
(83, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B405NET-20260512124329-007-6E04', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 87, '2026-05-12 12:43:28', '2026-06-12 14:46:38'),
(84, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B405NET-20260512124329-008-C4CE', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 88, '2026-05-12 12:43:28', '2026-06-12 14:41:59'),
(85, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B405NET-20260512124329-009-4FD5', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 89, '2026-05-12 12:43:28', '2026-06-12 14:41:59'),
(86, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B405NET-20260512124329-010-C4DD', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 90, '2026-05-12 12:43:28', '2026-06-12 14:46:38'),
(87, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B405NET-20260512124329-011-44BE', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 91, '2026-05-12 12:43:28', '2026-06-12 14:46:38'),
(88, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B405NET-20260512124329-012-A134', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 92, '2026-05-12 12:43:28', '2026-06-12 14:41:59'),
(89, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B665NET-20260512124329-001-B698', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 93, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(90, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B665NET-20260512124329-002-118B', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 94, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(91, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B665NET-20260512124329-003-F71D', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 95, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(92, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B665NET-20260512124329-004-0937', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 96, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(93, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B665NET-20260512124329-005-C30D', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 97, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(94, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B665NET-20260512124329-006-2F65', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 98, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(95, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B218NET-20260512124329-001-29E7', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 99, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(96, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B218NET-20260512124329-002-473B', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 100, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(97, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B218NET-20260512124329-003-6EA2', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 101, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(98, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B218NET-20260512124329-004-6265', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 102, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(99, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B218NET-20260512124329-005-F518', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 103, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(100, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B218NET-20260512124329-006-66A6', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 104, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(101, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B218NET-20260512124329-007-0398', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 105, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(102, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B218NET-20260512124329-008-B420', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 106, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(103, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B218NET-20260512124329-009-F954', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 107, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(104, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B218NET-20260512124329-010-13F8', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 108, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(105, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B218NET-20260512124329-011-581D', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 109, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(106, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B218NET-20260512124329-012-0486', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 110, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(107, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B235NET-20260512124329-001-D8D0', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 111, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(108, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B235NET-20260512124329-002-0A54', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 112, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(109, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B235NET-20260512124329-003-6883', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 113, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(110, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B235NET-20260512124329-004-95B5', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 114, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(111, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B235NET-20260512124329-005-7E24', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 115, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(112, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B235NET-20260512124329-006-89D1', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 116, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(113, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B235NET-20260512124329-007-C269', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 117, '2026-05-12 12:43:28', '2026-06-15 09:03:33'),
(114, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B235NET-20260512124329-008-F413', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 118, '2026-05-12 12:43:28', '2026-06-15 09:03:33'),
(115, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B235NET-20260512124329-009-3AB7', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 119, '2026-05-12 12:43:28', '2026-06-15 09:03:33'),
(116, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B235NET-20260512124329-010-982A', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 120, '2026-05-12 12:43:28', '2026-06-15 09:03:33'),
(117, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-SEMIREMORCA-20260512125113-7AD63A-0001', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', NULL, '2026-05-12 12:51:13', '2026-05-20 10:15:30'),
(118, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-SEMIREMORCA-20260512125113-80FD9C-0002', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', NULL, '2026-05-12 12:51:13', '2026-05-20 10:15:30'),
(119, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-SEMIREMORCA-20260512125113-9B81D9-0003', 'semiremorca', '2026-05-19', 0, 180000, NULL, 2.00, 'active', 'Stoc sezon creat automat (2026-05-12)', 199, '2026-05-12 12:51:13', '2026-05-19 16:44:10'),
(120, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-SEMIREMORCA-20260512125113-9902DE-0004', 'semiremorca', '2026-05-19', 0, 180000, NULL, 2.00, 'active', 'Stoc sezon creat automat (2026-05-12)', 200, '2026-05-12 12:51:13', '2026-05-19 16:44:10'),
(121, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-CAMION-20260512125113-C14A0C-0001', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', NULL, '2026-05-12 12:51:13', '2026-05-20 10:15:30'),
(122, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-CAMION-20260512125113-A10D5C-0002', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', NULL, '2026-05-12 12:51:13', '2026-05-20 10:15:30'),
(123, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-CAMION-20260512125113-59A417-0003', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', NULL, '2026-05-12 12:51:13', '2026-05-20 10:15:30'),
(124, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-CAMION-20260512125113-39B304-0004', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', NULL, '2026-05-12 12:51:13', '2026-05-20 10:15:30'),
(125, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-CAMION-20260512125113-8F8D4C-0005', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', NULL, '2026-05-12 12:51:13', '2026-05-20 10:15:30'),
(126, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-CAMION-20260512125113-E73F4F-0006', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', NULL, '2026-05-12 12:51:13', '2026-05-20 10:15:30'),
(127, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-CAMION-20260512125113-250F38-0007', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', NULL, '2026-05-12 12:51:13', '2026-05-20 10:15:30'),
(128, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-CAMION-20260512125113-DFEA99-0008', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', NULL, '2026-05-12 12:51:13', '2026-05-20 10:15:30'),
(129, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-CAMION-20260512125113-BAA38D-0009', 'camion', '2026-05-26', 0, 180000, NULL, 2.00, 'active', 'Stoc sezon creat automat (2026-05-12)', 227, '2026-05-12 12:51:13', '2026-05-29 09:48:03'),
(130, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-CAMION-20260512125113-8B9117-0010', 'camion', '2026-05-26', 0, 180000, NULL, 2.00, 'active', 'Stoc sezon creat automat (2026-05-12)', 228, '2026-05-12 12:51:13', '2026-05-29 09:48:03'),
(131, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-CAPTRACTOR-20260512125113-0D5261-0001', 'cap_tractor', '2026-05-19', 0, 180000, NULL, 2.00, 'active', 'Stoc sezon creat automat (2026-05-12)', 175, '2026-05-12 12:51:13', '2026-05-19 12:42:09'),
(132, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-CAPTRACTOR-20260512125113-1491A1-0002', 'cap_tractor', '2026-05-19', 0, 180000, NULL, 2.00, 'active', 'Stoc sezon creat automat (2026-05-12)', 176, '2026-05-12 12:51:13', '2026-05-19 12:42:09'),
(133, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-CAPTRACTOR-20260512125113-36BE8C-0003', 'cap_tractor', '2026-05-19', 0, 180000, NULL, 2.00, 'active', 'Stoc sezon creat automat (2026-05-12)', 177, '2026-05-12 12:51:13', '2026-05-19 12:42:09'),
(134, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-CAPTRACTOR-20260512125113-46D2D2-0004', 'cap_tractor', '2026-05-19', 0, 180000, NULL, 2.00, 'active', 'Stoc sezon creat automat (2026-05-12)', 178, '2026-05-12 12:51:13', '2026-05-19 12:42:09'),
(135, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-AUTOVEHICUL-20260512125113-B73DBC-0001', 'autovehicul', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', NULL, '2026-05-12 12:51:13', '2026-05-20 10:15:30'),
(136, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-AUTOVEHICUL-20260512125113-066296-0002', 'autovehicul', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', NULL, '2026-05-12 12:51:13', '2026-05-20 10:15:30'),
(137, 'Michelin', 'Subaru', '315/80', '3423', 'STOC-20260520095417-9B1F50-0001', 'cap_tractor', '2026-05-20', 0, 180000, NULL, 2.00, 'spare', 'Vine de la mama mare', NULL, '2026-05-20 09:54:17', '2026-05-20 10:15:30'),
(138, 'Subaru', 'Subaru', '315/80', NULL, 'DEVARANENE-CAPTRACTOR-20260520095805-52FE82-0001', 'cap_tractor', '2026-05-20', 0, 180000, NULL, 2.00, 'spare', 'Generat bulk pentru Cap tractor | Lipsa curenta: 2 | Rezerve extra: 0 | lot venit de la nenicu', NULL, '2026-05-20 09:58:05', '2026-05-20 10:15:30'),
(139, 'Subaru', 'Subaru', '315/80', NULL, 'DEVARANENE-CAPTRACTOR-20260520095805-FDAA65-0002', 'cap_tractor', '2026-05-20', 0, 180000, NULL, 2.00, 'spare', 'Generat bulk pentru Cap tractor | Lipsa curenta: 2 | Rezerve extra: 0 | lot venit de la nenicu', NULL, '2026-05-20 09:58:05', '2026-05-20 10:15:30'),
(140, 'Subaru', 'Subaru', '315/80', NULL, 'DEVARANENE-SEMIREMORCA-20260520095805-FDD6A9-0001', 'semiremorca', '2026-05-20', 0, 180000, NULL, 2.00, 'spare', 'Generat bulk pentru Semi-remorca | Lipsa curenta: 10 | Rezerve extra: 0 | lot venit de la nenicu', NULL, '2026-05-20 09:58:05', '2026-05-20 10:15:30'),
(141, 'Subaru', 'Subaru', '315/80', NULL, 'DEVARANENE-SEMIREMORCA-20260520095805-325BFE-0002', 'semiremorca', '2026-05-20', 0, 180000, NULL, 2.00, 'spare', 'Generat bulk pentru Semi-remorca | Lipsa curenta: 10 | Rezerve extra: 0 | lot venit de la nenicu', NULL, '2026-05-20 09:58:05', '2026-05-20 10:15:30'),
(142, 'Subaru', 'Subaru', '315/80', NULL, 'DEVARANENE-SEMIREMORCA-20260520095805-68074E-0003', 'semiremorca', '2026-05-20', 0, 180000, NULL, 2.00, 'spare', 'Generat bulk pentru Semi-remorca | Lipsa curenta: 10 | Rezerve extra: 0 | lot venit de la nenicu', NULL, '2026-05-20 09:58:05', '2026-05-20 10:15:30'),
(143, 'Subaru', 'Subaru', '315/80', NULL, 'DEVARANENE-SEMIREMORCA-20260520095805-DBC65E-0004', 'semiremorca', '2026-05-20', 0, 180000, NULL, 2.00, 'spare', 'Generat bulk pentru Semi-remorca | Lipsa curenta: 10 | Rezerve extra: 0 | lot venit de la nenicu', NULL, '2026-05-20 09:58:05', '2026-05-20 10:15:30'),
(144, 'Subaru', 'Subaru', '315/80', NULL, 'DEVARANENE-SEMIREMORCA-20260520095805-1A0A9D-0005', 'semiremorca', '2026-05-20', 0, 180000, NULL, 2.00, 'spare', 'Generat bulk pentru Semi-remorca | Lipsa curenta: 10 | Rezerve extra: 0 | lot venit de la nenicu', NULL, '2026-05-20 09:58:05', '2026-05-20 10:15:30'),
(145, 'Subaru', 'Subaru', '315/80', NULL, 'DEVARANENE-SEMIREMORCA-20260520095805-8DF4B6-0006', 'semiremorca', '2026-05-20', 0, 180000, NULL, 2.00, 'spare', 'Generat bulk pentru Semi-remorca | Lipsa curenta: 10 | Rezerve extra: 0 | lot venit de la nenicu', NULL, '2026-05-20 09:58:05', '2026-05-20 10:15:30'),
(146, 'Subaru', 'Subaru', '315/80', NULL, 'DEVARANENE-SEMIREMORCA-20260520095805-048DB2-0007', 'semiremorca', '2026-05-20', 0, 180000, NULL, 2.00, 'spare', 'Generat bulk pentru Semi-remorca | Lipsa curenta: 10 | Rezerve extra: 0 | lot venit de la nenicu', NULL, '2026-05-20 09:58:05', '2026-05-20 10:15:30'),
(147, 'Subaru', 'Subaru', '315/80', NULL, 'DEVARANENE-SEMIREMORCA-20260520095805-3D50FE-0008', 'semiremorca', '2026-05-20', 0, 180000, NULL, 2.00, 'spare', 'Generat bulk pentru Semi-remorca | Lipsa curenta: 10 | Rezerve extra: 0 | lot venit de la nenicu', NULL, '2026-05-20 09:58:05', '2026-05-20 10:15:30'),
(148, 'Subaru', 'Subaru', '315/80', NULL, 'DEVARANENE-SEMIREMORCA-20260520095805-EB1C3F-0009', 'semiremorca', '2026-05-20', 0, 180000, NULL, 2.00, 'spare', 'Generat bulk pentru Semi-remorca | Lipsa curenta: 10 | Rezerve extra: 0 | lot venit de la nenicu', NULL, '2026-05-20 09:58:05', '2026-05-20 10:15:30'),
(149, 'Subaru', 'Subaru', '315/80', NULL, 'DEVARANENE-SEMIREMORCA-20260520095805-62AD12-0010', 'semiremorca', '2026-05-20', 0, 180000, NULL, 2.00, 'spare', 'Generat bulk pentru Semi-remorca | Lipsa curenta: 10 | Rezerve extra: 0 | lot venit de la nenicu', NULL, '2026-05-20 09:58:05', '2026-05-20 10:15:30'),
(150, 'Subaru', 'Subaru', '315/80', NULL, 'DEVARANENE-CAMION-20260520095805-595D9D-0001', 'camion', '2026-05-20', 0, 180000, NULL, 2.00, 'spare', 'Generat bulk pentru Camion | Lipsa curenta: 1 | Rezerve extra: 0 | lot venit de la nenicu', NULL, '2026-05-20 09:58:05', '2026-05-20 10:15:30');

-- --------------------------------------------------------

--
-- Table structure for table `anvelope_alocari`
--

CREATE TABLE `anvelope_alocari` (
  `id` int UNSIGNED NOT NULL,
  `tire_id` int UNSIGNED NOT NULL,
  `vehicle_id` int UNSIGNED NOT NULL,
  `position_id` int UNSIGNED NOT NULL,
  `data_start` date NOT NULL,
  `data_end` date DEFAULT NULL,
  `km_start` int UNSIGNED DEFAULT NULL,
  `km_end` int UNSIGNED DEFAULT NULL,
  `status_end` enum('spare','removed','damaged','retreaded','moved') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `anvelope_alocari`
--

INSERT INTO `anvelope_alocari` (`id`, `tire_id`, `vehicle_id`, `position_id`, `data_start`, `data_end`, `km_start`, `km_end`, `status_end`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 1, 28, 163, '2026-05-12', NULL, 323232, NULL, NULL, 1, '2026-05-12 11:33:50', '2026-05-12 11:33:50'),
(2, 2, 28, 164, '2026-05-12', '2026-05-19', 323232, 323472, 'spare', NULL, '2026-05-12 12:43:28', '2026-05-19 15:30:10'),
(3, 3, 28, 165, '2026-05-12', NULL, 323232, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(4, 4, 28, 166, '2026-05-12', NULL, 323232, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(5, 5, 28, 167, '2026-05-12', NULL, 323232, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(6, 6, 28, 168, '2026-05-12', NULL, 323232, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(7, 7, 27, 157, '2026-05-12', NULL, 200000, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(8, 8, 27, 158, '2026-05-12', NULL, 200000, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(9, 9, 27, 159, '2026-05-12', NULL, 200000, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(10, 10, 27, 160, '2026-05-12', NULL, 200000, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(11, 11, 27, 161, '2026-05-12', NULL, 200000, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(12, 12, 27, 162, '2026-05-12', NULL, 200000, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(13, 13, 26, 151, '2026-05-12', NULL, 20250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(14, 14, 26, 152, '2026-05-12', NULL, 20250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(15, 15, 26, 153, '2026-05-12', NULL, 20250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(16, 16, 26, 154, '2026-05-12', NULL, 20250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(17, 17, 26, 155, '2026-05-12', NULL, 20250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(18, 18, 26, 156, '2026-05-12', NULL, 20250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(19, 19, 24, 139, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(20, 20, 24, 140, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(21, 21, 24, 141, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(22, 22, 24, 142, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(23, 23, 24, 143, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(24, 24, 24, 144, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(25, 25, 23, 133, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(26, 26, 23, 134, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(27, 27, 23, 135, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(28, 28, 23, 136, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(29, 29, 23, 137, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(30, 30, 23, 138, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(31, 31, 22, 127, '2026-05-12', NULL, 254546, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(32, 32, 22, 128, '2026-05-12', NULL, 254546, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(33, 33, 22, 129, '2026-05-12', NULL, 254546, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(34, 34, 22, 130, '2026-05-12', NULL, 254546, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(35, 35, 22, 131, '2026-05-12', NULL, 254546, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(36, 36, 22, 132, '2026-05-12', NULL, 254546, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(37, 37, 21, 121, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(38, 38, 21, 122, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(39, 39, 21, 123, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(40, 40, 21, 124, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(41, 41, 21, 125, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(42, 42, 21, 126, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(43, 43, 20, 115, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(44, 44, 20, 116, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(45, 45, 20, 117, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(46, 46, 20, 118, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(47, 47, 20, 119, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(48, 48, 20, 120, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(49, 49, 19, 109, '2026-05-12', NULL, 900, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(50, 50, 19, 110, '2026-05-12', NULL, 900, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(51, 51, 19, 111, '2026-05-12', NULL, 900, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(52, 52, 19, 112, '2026-05-12', NULL, 900, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(53, 53, 19, 113, '2026-05-12', NULL, 900, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(54, 54, 19, 114, '2026-05-12', NULL, 900, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(55, 55, 18, 103, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(56, 56, 18, 104, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(57, 57, 18, 105, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(58, 58, 18, 106, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(59, 59, 18, 107, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(60, 60, 18, 108, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(61, 61, 17, 99, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(62, 62, 17, 100, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(63, 63, 17, 221, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-06-08 13:49:10'),
(64, 64, 17, 224, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-06-08 13:49:10'),
(65, 65, 16, 93, '2026-05-12', NULL, 346678, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(66, 66, 16, 94, '2026-05-12', NULL, 346678, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(67, 67, 16, 95, '2026-05-12', NULL, 346678, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(68, 68, 16, 96, '2026-05-12', NULL, 346678, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(69, 69, 16, 97, '2026-05-12', NULL, 346678, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(70, 70, 16, 98, '2026-05-12', NULL, 346678, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(71, 71, 15, 87, '2026-05-12', NULL, 248, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(72, 72, 15, 88, '2026-05-12', NULL, 248, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(73, 73, 15, 89, '2026-05-12', NULL, 248, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(74, 74, 15, 90, '2026-05-12', NULL, 248, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(75, 75, 15, 91, '2026-05-12', NULL, 248, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(76, 76, 15, 92, '2026-05-12', NULL, 248, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(77, 77, 12, 57, '2026-05-12', '2026-06-12', 198, 12345, 'spare', NULL, '2026-05-12 12:43:28', '2026-06-12 14:41:43'),
(78, 78, 12, 509, '2026-05-12', NULL, 198, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-06-12 14:46:38'),
(79, 79, 12, 510, '2026-05-12', NULL, 198, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-06-12 14:46:38'),
(80, 80, 12, 510, '2026-05-12', '2026-06-12', 198, 12345, 'spare', NULL, '2026-05-12 12:43:28', '2026-06-12 14:41:59'),
(81, 81, 12, 61, '2026-05-12', '2026-06-12', 198, 12345, 'spare', NULL, '2026-05-12 12:43:28', '2026-06-12 14:41:59'),
(82, 82, 12, 511, '2026-05-12', NULL, 198, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-06-12 14:46:38'),
(83, 83, 12, 512, '2026-05-12', NULL, 198, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-06-12 14:46:38'),
(84, 84, 12, 64, '2026-05-12', '2026-06-12', 198, 12345, 'spare', NULL, '2026-05-12 12:43:28', '2026-06-12 14:41:59'),
(85, 85, 12, 65, '2026-05-12', '2026-06-12', 198, 12345, 'spare', NULL, '2026-05-12 12:43:28', '2026-06-12 14:41:59'),
(86, 86, 12, 513, '2026-05-12', NULL, 198, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-06-12 14:46:38'),
(87, 87, 12, 514, '2026-05-12', NULL, 198, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-06-12 14:46:38'),
(88, 88, 12, 68, '2026-05-12', '2026-06-12', 198, 12345, 'spare', NULL, '2026-05-12 12:43:28', '2026-06-12 14:41:59'),
(89, 89, 11, 51, '2026-05-12', NULL, 710860, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(90, 90, 11, 52, '2026-05-12', NULL, 710860, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(91, 91, 11, 53, '2026-05-12', NULL, 710860, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(92, 92, 11, 54, '2026-05-12', NULL, 710860, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(93, 93, 11, 55, '2026-05-12', NULL, 710860, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(94, 94, 11, 56, '2026-05-12', NULL, 710860, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(95, 95, 9, 27, '2026-05-12', NULL, 500, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(96, 96, 9, 225, '2026-05-12', NULL, 500, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-06-09 09:38:04'),
(97, 97, 9, 226, '2026-05-12', NULL, 500, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-06-09 09:38:04'),
(98, 98, 9, 30, '2026-05-12', NULL, 500, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(99, 99, 9, 31, '2026-05-12', NULL, 500, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(100, 100, 9, 32, '2026-05-12', NULL, 500, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(101, 101, 9, 33, '2026-05-12', NULL, 500, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(102, 102, 9, 34, '2026-05-12', NULL, 500, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(103, 103, 9, 35, '2026-05-12', NULL, 500, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(104, 104, 9, 36, '2026-05-12', NULL, 500, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(105, 105, 9, 37, '2026-05-12', NULL, 500, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(106, 106, 9, 38, '2026-05-12', NULL, 500, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(107, 107, 6, 17, '2026-05-12', NULL, 0, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(108, 108, 6, 18, '2026-05-12', NULL, 0, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(109, 109, 6, 19, '2026-05-12', NULL, 0, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(110, 110, 6, 20, '2026-05-12', NULL, 0, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(111, 111, 6, 21, '2026-05-12', NULL, 0, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(112, 112, 6, 22, '2026-05-12', NULL, 0, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(113, 113, 6, 23, '2026-05-12', '2026-06-15', 0, 12345, 'spare', NULL, '2026-05-12 12:43:28', '2026-06-15 09:03:33'),
(114, 114, 6, 24, '2026-05-12', '2026-06-15', 0, 12345, 'spare', NULL, '2026-05-12 12:43:28', '2026-06-15 09:03:33'),
(115, 115, 6, 25, '2026-05-12', '2026-06-15', 0, 12345, 'spare', NULL, '2026-05-12 12:43:28', '2026-06-15 09:03:33'),
(116, 116, 6, 26, '2026-05-12', '2026-06-15', 0, 12345, 'spare', NULL, '2026-05-12 12:43:28', '2026-06-15 09:03:33'),
(117, 134, 31, 173, '2026-05-19', NULL, 250000, NULL, NULL, 1, '2026-05-19 12:40:45', '2026-05-19 12:40:45'),
(118, 133, 31, 174, '2026-05-19', NULL, 250000, NULL, NULL, 1, '2026-05-19 12:41:03', '2026-05-19 12:41:03'),
(119, 132, 31, 175, '2026-05-19', NULL, 250000, NULL, NULL, 1, '2026-05-19 12:41:20', '2026-05-19 12:41:20'),
(120, 131, 31, 176, '2026-05-19', NULL, 250000, NULL, NULL, 1, '2026-05-19 12:41:53', '2026-05-19 12:41:53'),
(121, 120, 32, 477, '2026-05-19', NULL, 250000, NULL, NULL, 3, '2026-05-19 15:30:34', '2026-06-03 14:55:30'),
(122, 119, 32, 478, '2026-05-19', NULL, 250000, NULL, NULL, 3, '2026-05-19 15:30:53', '2026-06-03 14:55:30'),
(123, 130, 52, 325, '2026-05-26', NULL, 12345, NULL, NULL, 1, '2026-05-26 13:38:43', '2026-05-26 13:38:43'),
(124, 129, 52, 326, '2026-05-26', NULL, 12345, NULL, NULL, 1, '2026-05-26 13:39:15', '2026-05-26 13:39:15');

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` int UNSIGNED NOT NULL,
  `modul` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `record_id` int UNSIGNED NOT NULL,
  `actiune` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descriere` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `before_data` longtext COLLATE utf8mb4_unicode_ci,
  `after_data` longtext COLLATE utf8mb4_unicode_ci,
  `user_id` int UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_log`
--

INSERT INTO `audit_log` (`id`, `modul`, `record_id`, `actiune`, `descriere`, `before_data`, `after_data`, `user_id`, `created_at`) VALUES
(1, 'documente', 5, 'create', 'Document creat: Test audit (TEST-AUDIT-20260406095449) pentru B-101-FLT', NULL, '{\"vehicul\":\"B-101-FLT\",\"tip_document\":\"Test audit\",\"numar_document\":\"TEST-AUDIT-20260406095449\",\"data_expirare\":\"2026-07-05\",\"fisier_original\":null}', 1, '2026-04-06 09:54:49'),
(2, 'documente', 5, 'delete', 'Document sters: Test audit (TEST-AUDIT-20260406095449) pentru B-101-FLT', '{\"vehicul\":\"B-101-FLT\",\"tip_document\":\"Test audit\",\"numar_document\":\"TEST-AUDIT-20260406095449\",\"data_expirare\":\"2026-07-05\",\"fisier_original\":null}', NULL, 1, '2026-04-06 09:54:49'),
(3, 'documente', 6, 'create', 'Document creat: ROV (123) pentru B-101-FLT', NULL, '{\"vehicul\":\"B-101-FLT\",\"tip_document\":\"ROV\",\"numar_document\":\"123\",\"data_expirare\":\"2026-04-16\",\"fisier_original\":null}', 1, '2026-04-06 09:58:29'),
(4, 'documente', 7, 'create', 'Document creat: ROV () pentru B-101-FLT', NULL, '{\"vehicul\":\"B-101-FLT\",\"tip_document\":\"ROV\",\"numar_document\":\"\",\"data_expirare\":\"2026-04-15\",\"fisier_original\":\"430_Iprochim.pdf\"}', 1, '2026-04-06 11:04:39'),
(5, 'documente', 6, 'delete', 'Document sters: ROV (123) pentru B-101-FLT', '{\"vehicul\":\"B-101-FLT\",\"tip_document\":\"ROV\",\"numar_document\":\"123\",\"data_expirare\":\"2026-04-16\",\"fisier_original\":null}', NULL, 1, '2026-04-07 13:38:05'),
(6, 'documente', 8, 'create', 'Document creat: Rovinieta () pentru B-101-FLT', NULL, '{\"vehicul\":\"B-101-FLT\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-04-15\",\"fisier_original\":null}', 1, '2026-04-15 10:38:10'),
(7, 'documente', 8, 'update', 'Document actualizat: Rovinieta () pentru B-101-FLT', '{\"vehicul\":\"B-101-FLT\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-04-15\",\"fisier_original\":null}', '{\"vehicul\":\"B-101-FLT\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-04-14\",\"fisier_original\":null}', 1, '2026-04-15 10:38:53'),
(8, 'documente', 9, 'create', 'Document creat: RCA () pentru B 235 NET', NULL, '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-14\",\"fisier_original\":\"235_RCA.pdf\"}', 1, '2026-04-16 10:53:45'),
(9, 'documente', 10, 'create', 'Document creat: ITP () pentru B 235 NET', NULL, '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-22\",\"fisier_original\":\"235_Certificat_ITP.pdf\"}', 1, '2026-04-16 10:57:13'),
(10, 'documente', 11, 'create', 'Document creat: Rovinieta () pentru B 235 NET', NULL, '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-16\",\"fisier_original\":\"235_RCA.pdf\"}', 1, '2026-04-16 10:59:56'),
(11, 'documente', 12, 'create', 'Document creat: RCA () pentru B 218 NET', NULL, '{\"vehicul\":\"B 218 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-28\",\"fisier_original\":\"805_Carte.pdf\"}', 1, '2026-04-20 09:32:58'),
(12, 'documente', 13, 'create', 'Document creat: ITP () pentru B 218 NET', NULL, '{\"vehicul\":\"B 218 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-20\",\"fisier_original\":\"235_Certificat_ITP.pdf\"}', 1, '2026-04-20 09:33:49'),
(13, 'documente', 14, 'create', 'Document creat: Rovinieta () pentru B 218 NET', NULL, '{\"vehicul\":\"B 218 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-27\",\"fisier_original\":\"235_Carte.pdf\"}', 1, '2026-04-20 09:34:23'),
(14, 'documente', 14, 'update', 'Document actualizat: Rovinieta () pentru B 218 NET', '{\"vehicul\":\"B 218 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-27\",\"fisier_original\":\"235_Carte.pdf\"}', '{\"vehicul\":\"B 218 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-02-19\",\"fisier_original\":\"235_Carte.pdf\"}', 1, '2026-04-20 11:07:24'),
(15, 'documente', 14, 'update', 'Document actualizat: Rovinieta () pentru B 218 NET', '{\"vehicul\":\"B 218 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-02-19\",\"fisier_original\":\"235_Carte.pdf\"}', '{\"vehicul\":\"B 218 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-03-26\",\"fisier_original\":\"235_Carte.pdf\"}', 1, '2026-04-20 11:08:44'),
(16, 'documente', 14, 'update', 'Document actualizat: Rovinieta () pentru B 218 NET', '{\"vehicul\":\"B 218 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-03-26\",\"fisier_original\":\"235_Carte.pdf\"}', '{\"vehicul\":\"B 218 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-27\",\"fisier_original\":\"235_Carte.pdf\"}', 1, '2026-04-20 11:09:16'),
(17, 'documente', 15, 'create', 'Document creat: RCA () pentru B 655 NET', NULL, '{\"vehicul\":\"B 655 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-27\",\"fisier_original\":\"665_RCA.pdf\"}', 1, '2026-04-21 12:10:16'),
(18, 'documente', 15, 'delete', 'Document sters: RCA () pentru B 655 NET', '{\"vehicul\":\"B 655 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-27\",\"fisier_original\":\"665_RCA.pdf\"}', NULL, 1, '2026-04-21 12:11:21'),
(19, 'documente', 16, 'create', 'Document creat: RCA () pentru B 665 NET', NULL, '{\"vehicul\":\"B 665 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-27\",\"fisier_original\":\"665_RCA.pdf\"}', 1, '2026-04-21 12:12:03'),
(20, 'documente', 17, 'create', 'Document creat: ITP () pentru B 665 NET', NULL, '{\"vehicul\":\"B 665 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-12\",\"fisier_original\":\"665_ADR.pdf\"}', 1, '2026-04-21 12:13:44'),
(21, 'documente', 18, 'create', 'Document creat: Rovinieta () pentru B 665 NET', NULL, '{\"vehicul\":\"B 665 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-12\",\"fisier_original\":\"665_Casco.pdf\"}', 1, '2026-04-21 12:15:23'),
(22, 'documente', 19, 'create', 'Document creat: RCA () pentru B 405 NET', NULL, '{\"vehicul\":\"B 405 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-24\",\"fisier_original\":\"405_RCA.pdf\"}', 1, '2026-04-21 12:22:31'),
(23, 'documente', 20, 'create', 'Document creat: Rovinieta () pentru B 405 NET', NULL, '{\"vehicul\":\"B 405 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-23\",\"fisier_original\":\"405_Casco.pdf\"}', 1, '2026-04-21 12:35:15'),
(24, 'documente', 21, 'create', 'Document creat: ITP () pentru B 405 NET', NULL, '{\"vehicul\":\"B 405 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-12\",\"fisier_original\":\"405_ADR.pdf\"}', 1, '2026-04-21 12:39:04'),
(25, 'documente', 22, 'create', 'Document creat: RCA () pentru B 677 NET', NULL, '{\"vehicul\":\"B 677 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-27\",\"fisier_original\":null}', 1, '2026-05-05 12:46:08'),
(26, 'documente', 23, 'create', 'Document creat: Rovinieta () pentru B 677 NET', NULL, '{\"vehicul\":\"B 677 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-02\",\"fisier_original\":null}', 1, '2026-05-05 12:47:45'),
(27, 'documente', 24, 'create', 'Document creat: ITP () pentru B 677 NET', NULL, '{\"vehicul\":\"B 677 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-08\",\"fisier_original\":null}', 1, '2026-05-05 12:49:47'),
(28, 'documente', 25, 'create', 'Document creat: Rovinieta () pentru B 435 NET', NULL, '{\"vehicul\":\"B 435 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-25\",\"fisier_original\":null}', 1, '2026-05-05 13:01:20'),
(29, 'documente', 26, 'create', 'Document creat: RCA () pentru B 435 NET', NULL, '{\"vehicul\":\"B 435 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-20\",\"fisier_original\":null}', 1, '2026-05-05 13:01:58'),
(30, 'documente', 27, 'create', 'Document creat: ITP () pentru B 435 NET', NULL, '{\"vehicul\":\"B 435 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-25\",\"fisier_original\":null}', 1, '2026-05-05 13:02:26'),
(31, 'documente', 28, 'create', 'Document creat: muie () pentru B 435 NET', NULL, '{\"vehicul\":\"B 435 NET\",\"tip_document\":\"muie\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-22\",\"fisier_original\":null}', 1, '2026-05-06 09:21:39'),
(32, 'documente', 28, 'delete', 'Document sters: muie () pentru B 435 NET', '{\"vehicul\":\"B 435 NET\",\"tip_document\":\"muie\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-22\",\"fisier_original\":null}', NULL, 1, '2026-05-06 09:34:48'),
(33, 'documente', 29, 'create', 'Document creat: muie () pentru B 435 NET', NULL, '{\"vehicul\":\"B 435 NET\",\"tip_document\":\"muie\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-27\",\"fisier_original\":null}', 1, '2026-05-06 09:35:04'),
(34, 'documente', 30, 'create', 'Document creat: muie2 () pentru B 435 NET', NULL, '{\"vehicul\":\"B 435 NET\",\"tip_document\":\"muie2\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-20\",\"fisier_original\":null}', 1, '2026-05-06 09:35:21'),
(35, 'documente', 30, 'delete', 'Document sters: muie2 () pentru B 435 NET', '{\"vehicul\":\"B 435 NET\",\"tip_document\":\"muie2\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-20\",\"fisier_original\":null}', NULL, 1, '2026-05-06 09:35:43'),
(36, 'documente', 29, 'delete', 'Document sters: muie () pentru B 435 NET', '{\"vehicul\":\"B 435 NET\",\"tip_document\":\"muie\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-27\",\"fisier_original\":null}', NULL, 1, '2026-05-06 09:36:22'),
(37, 'documente', 31, 'create', 'Document creat: RCA () pentru B 219 NET', NULL, '{\"vehicul\":\"B 219 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-24\",\"fisier_original\":null}', 1, '2026-05-06 11:51:34'),
(38, 'documente', 32, 'create', 'Document creat: ITP () pentru B 219 NET', NULL, '{\"vehicul\":\"B 219 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-17\",\"fisier_original\":null}', 1, '2026-05-06 11:51:44'),
(39, 'documente', 33, 'create', 'Document creat: Rovinieta () pentru B 219 NET', NULL, '{\"vehicul\":\"B 219 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-25\",\"fisier_original\":null}', 1, '2026-05-06 11:51:57'),
(40, 'documente', 34, 'create', 'Document creat: RCA () pentru B 345 NET', NULL, '{\"vehicul\":\"B 345 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-25\",\"fisier_original\":null}', 1, '2026-05-06 11:52:39'),
(41, 'documente', 35, 'create', 'Document creat: ITP () pentru B 345 NET', NULL, '{\"vehicul\":\"B 345 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-24\",\"fisier_original\":null}', 1, '2026-05-06 11:52:52'),
(42, 'documente', 36, 'create', 'Document creat: Rovinieta () pentru B 345 NET', NULL, '{\"vehicul\":\"B 345 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-17\",\"fisier_original\":null}', 1, '2026-05-06 11:53:00'),
(43, 'documente', 37, 'create', 'Document creat: RCA () pentru B 430 NET', NULL, '{\"vehicul\":\"B 430 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-23\",\"fisier_original\":null}', 1, '2026-05-06 11:53:38'),
(44, 'documente', 38, 'create', 'Document creat: ITP () pentru B 430 NET', NULL, '{\"vehicul\":\"B 430 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-23\",\"fisier_original\":null}', 1, '2026-05-06 11:53:51'),
(45, 'documente', 39, 'create', 'Document creat: Rovinieta () pentru B 430 NET', NULL, '{\"vehicul\":\"B 430 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-24\",\"fisier_original\":null}', 1, '2026-05-06 11:54:09'),
(46, 'documente', 40, 'create', 'Document creat: ITP () pentru B 311 NET', NULL, '{\"vehicul\":\"B 311 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-24\",\"fisier_original\":null}', 1, '2026-05-06 11:54:40'),
(47, 'documente', 41, 'create', 'Document creat: Rovinieta () pentru B 311 NET', NULL, '{\"vehicul\":\"B 311 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-26\",\"fisier_original\":null}', 1, '2026-05-06 11:54:50'),
(48, 'documente', 42, 'create', 'Document creat: RCA () pentru B 311 NET', NULL, '{\"vehicul\":\"B 311 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-28\",\"fisier_original\":null}', 1, '2026-05-06 11:54:57'),
(49, 'documente', 43, 'create', 'Document creat: RCA () pentru B 285 NET', NULL, '{\"vehicul\":\"B 285 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-27\",\"fisier_original\":null}', 1, '2026-05-06 11:55:26'),
(50, 'documente', 44, 'create', 'Document creat: ITP () pentru B 285 NET', NULL, '{\"vehicul\":\"B 285 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-23\",\"fisier_original\":null}', 1, '2026-05-06 11:55:35'),
(51, 'documente', 45, 'create', 'Document creat: Rovinieta () pentru B 285 NET', NULL, '{\"vehicul\":\"B 285 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-23\",\"fisier_original\":null}', 1, '2026-05-06 11:55:44'),
(52, 'documente', 46, 'create', 'Document creat: RCA () pentru B 375 NET', NULL, '{\"vehicul\":\"B 375 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-17\",\"fisier_original\":null}', 1, '2026-05-06 11:56:17'),
(53, 'documente', 47, 'create', 'Document creat: Rovinieta () pentru B 375 NET', NULL, '{\"vehicul\":\"B 375 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-24\",\"fisier_original\":null}', 1, '2026-05-06 11:56:28'),
(54, 'documente', 48, 'create', 'Document creat: ITP () pentru B 375 NET', NULL, '{\"vehicul\":\"B 375 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-24\",\"fisier_original\":null}', 1, '2026-05-06 11:56:47'),
(55, 'documente', 49, 'create', 'Document creat: RCA () pentru B 275 NET', NULL, '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-01\",\"fisier_original\":null}', 1, '2026-05-07 11:13:23'),
(56, 'documente', 50, 'create', 'Document creat: Rovinieta () pentru B 275 NET', NULL, '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-23\",\"fisier_original\":null}', 1, '2026-05-07 11:13:30'),
(57, 'documente', 51, 'create', 'Document creat: ITP () pentru B 275 NET', NULL, '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-23\",\"fisier_original\":null}', 1, '2026-05-07 11:13:38'),
(58, 'documente', 52, 'create', 'Document creat: RCA () pentru B 325 NET', NULL, '{\"vehicul\":\"B 325 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-02\",\"fisier_original\":null}', 1, '2026-05-07 11:14:00'),
(59, 'documente', 53, 'create', 'Document creat: Rovinieta () pentru B 325 NET', NULL, '{\"vehicul\":\"B 325 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-10\",\"fisier_original\":null}', 1, '2026-05-07 11:14:08'),
(60, 'documente', 54, 'create', 'Document creat: ITP () pentru B 325 NET', NULL, '{\"vehicul\":\"B 325 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-23\",\"fisier_original\":null}', 1, '2026-05-07 11:14:14'),
(61, 'documente', 55, 'create', 'Document creat: Rovinieta () pentru B 437 NET', NULL, '{\"vehicul\":\"B 437 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-14\",\"fisier_original\":null}', 1, '2026-05-07 11:54:52'),
(62, 'documente', 56, 'create', 'Document creat: RCA () pentru B 437 NET', NULL, '{\"vehicul\":\"B 437 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-23\",\"fisier_original\":null}', 1, '2026-05-07 11:55:03'),
(63, 'documente', 57, 'create', 'Document creat: ITP () pentru B 437 NET', NULL, '{\"vehicul\":\"B 437 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-22\",\"fisier_original\":null}', 1, '2026-05-07 11:55:12'),
(64, 'documente', 58, 'create', 'Document creat: Rovinieta () pentru B 385 NET', NULL, '{\"vehicul\":\"B 385 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-23\",\"fisier_original\":null}', 1, '2026-05-07 11:55:34'),
(65, 'documente', 59, 'create', 'Document creat: ITP () pentru B 385 NET', NULL, '{\"vehicul\":\"B 385 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-15\",\"fisier_original\":null}', 1, '2026-05-07 11:55:42'),
(66, 'documente', 60, 'create', 'Document creat: RCA () pentru B 385 NET', NULL, '{\"vehicul\":\"B 385 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-26\",\"fisier_original\":null}', 1, '2026-05-07 11:55:47'),
(67, 'documente', 61, 'create', 'Document creat: Rovinieta () pentru B 605 NET', NULL, '{\"vehicul\":\"B 605 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-29\",\"fisier_original\":null}', 1, '2026-05-07 12:28:46'),
(68, 'documente', 62, 'create', 'Document creat: RCA () pentru B 605 NET', NULL, '{\"vehicul\":\"B 605 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-25\",\"fisier_original\":null}', 1, '2026-05-07 12:29:02'),
(69, 'documente', 63, 'create', 'Document creat: ITP () pentru B 605 NET', NULL, '{\"vehicul\":\"B 605 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-30\",\"fisier_original\":null}', 1, '2026-05-07 12:29:11'),
(70, 'concedii', 1, 'create', 'Cerere concediu creată pentru Condrea Daniel', NULL, '{\"id\":1,\"driver_id\":11,\"tip_concediu\":\"odihna\",\"data_inceput\":\"2026-05-14\",\"data_sfarsit\":\"2026-05-27\",\"inlocuitor_id\":null,\"note\":null,\"status\":\"in_asteptare\",\"created_by\":1,\"created_at\":\"2026-05-08 12:01:32\",\"updated_at\":\"2026-05-08 12:01:32\",\"sofer_nume\":\"Condrea Daniel\",\"sofer_telefon\":\"079892942\",\"sofer_nr_inmatriculare\":\"B 311 NET\",\"inlocuitor_nume\":null,\"inlocuitor_nr_inmatriculare\":null,\"creat_de_nume\":\"Administrator Sistem\"}', 1, '2026-05-08 12:01:32'),
(71, 'concedii', 1, 'update_status', 'Status cerere concediu actualizat pentru Condrea Daniel', '{\"id\":1,\"driver_id\":11,\"tip_concediu\":\"odihna\",\"data_inceput\":\"2026-05-14\",\"data_sfarsit\":\"2026-05-27\",\"inlocuitor_id\":null,\"note\":null,\"status\":\"in_asteptare\",\"created_by\":1,\"created_at\":\"2026-05-08 12:01:32\",\"updated_at\":\"2026-05-08 12:01:32\",\"sofer_nume\":\"Condrea Daniel\",\"sofer_telefon\":\"079892942\",\"sofer_nr_inmatriculare\":\"B 311 NET\",\"inlocuitor_nume\":null,\"inlocuitor_nr_inmatriculare\":null,\"creat_de_nume\":\"Administrator Sistem\"}', '{\"id\":1,\"driver_id\":11,\"tip_concediu\":\"odihna\",\"data_inceput\":\"2026-05-14\",\"data_sfarsit\":\"2026-05-27\",\"inlocuitor_id\":null,\"note\":null,\"status\":\"aprobat\",\"created_by\":1,\"created_at\":\"2026-05-08 12:01:32\",\"updated_at\":\"2026-05-08 12:02:19\",\"sofer_nume\":\"Condrea Daniel\",\"sofer_telefon\":\"079892942\",\"sofer_nr_inmatriculare\":\"B 311 NET\",\"inlocuitor_nume\":null,\"inlocuitor_nr_inmatriculare\":null,\"creat_de_nume\":\"Administrator Sistem\"}', 1, '2026-05-08 12:02:19'),
(72, 'concedii', 1, 'update', 'Cerere concediu actualizată pentru Condrea Daniel', '{\"id\":1,\"driver_id\":11,\"tip_concediu\":\"odihna\",\"data_inceput\":\"2026-05-14\",\"data_sfarsit\":\"2026-05-27\",\"inlocuitor_id\":null,\"note\":null,\"status\":\"aprobat\",\"created_by\":1,\"created_at\":\"2026-05-08 12:01:32\",\"updated_at\":\"2026-05-08 12:02:19\",\"sofer_nume\":\"Condrea Daniel\",\"sofer_telefon\":\"079892942\",\"sofer_nr_inmatriculare\":\"B 311 NET\",\"inlocuitor_nume\":null,\"inlocuitor_nr_inmatriculare\":null,\"creat_de_nume\":\"Administrator Sistem\"}', '{\"id\":1,\"driver_id\":11,\"tip_concediu\":\"odihna\",\"data_inceput\":\"2026-05-20\",\"data_sfarsit\":\"2026-05-27\",\"inlocuitor_id\":null,\"note\":null,\"status\":\"aprobat\",\"created_by\":1,\"created_at\":\"2026-05-08 12:01:32\",\"updated_at\":\"2026-05-08 12:08:59\",\"sofer_nume\":\"Condrea Daniel\",\"sofer_telefon\":\"079892942\",\"sofer_nr_inmatriculare\":\"B 311 NET\",\"inlocuitor_nume\":null,\"inlocuitor_nr_inmatriculare\":null,\"creat_de_nume\":\"Administrator Sistem\"}', 1, '2026-05-08 12:08:59'),
(73, 'concedii', 1, 'update', 'Cerere concediu actualizată pentru Condrea Daniel', '{\"id\":1,\"driver_id\":11,\"tip_concediu\":\"odihna\",\"data_inceput\":\"2026-05-20\",\"data_sfarsit\":\"2026-05-27\",\"inlocuitor_id\":null,\"note\":null,\"status\":\"aprobat\",\"created_by\":1,\"created_at\":\"2026-05-08 12:01:32\",\"updated_at\":\"2026-05-08 12:08:59\",\"sofer_nume\":\"Condrea Daniel\",\"sofer_telefon\":\"079892942\",\"sofer_nr_inmatriculare\":\"B 311 NET\",\"inlocuitor_nume\":null,\"inlocuitor_nr_inmatriculare\":null,\"creat_de_nume\":\"Administrator Sistem\"}', '{\"id\":1,\"driver_id\":11,\"tip_concediu\":\"odihna\",\"data_inceput\":\"2026-05-25\",\"data_sfarsit\":\"2026-05-27\",\"inlocuitor_id\":null,\"note\":null,\"status\":\"in_asteptare_aprobare\",\"created_by\":1,\"created_at\":\"2026-05-08 12:01:32\",\"updated_at\":\"2026-05-08 12:20:49\",\"sofer_nume\":\"Condrea Daniel\",\"sofer_telefon\":\"079892942\",\"sofer_nr_inmatriculare\":\"B 311 NET\",\"inlocuitor_nume\":null,\"inlocuitor_nr_inmatriculare\":null,\"creat_de_nume\":\"Administrator Sistem\"}', 1, '2026-05-08 12:20:49'),
(74, 'concedii', 1, 'delete', 'Cerere concediu ștearsă pentru Condrea Daniel', '{\"id\":1,\"driver_id\":11,\"tip_concediu\":\"odihna\",\"data_inceput\":\"2026-05-25\",\"data_sfarsit\":\"2026-05-27\",\"inlocuitor_id\":null,\"note\":null,\"status\":\"in_asteptare_aprobare\",\"created_by\":1,\"created_at\":\"2026-05-08 12:01:32\",\"updated_at\":\"2026-05-08 12:20:49\",\"sofer_nume\":\"Condrea Daniel\",\"sofer_telefon\":\"079892942\",\"sofer_nr_inmatriculare\":\"B 311 NET\",\"inlocuitor_nume\":null,\"inlocuitor_nr_inmatriculare\":null,\"creat_de_nume\":\"Administrator Sistem\"}', NULL, 1, '2026-05-08 12:21:01'),
(75, 'concedii', 2, 'create', 'Cerere concediu creată pentru Condrea Daniel', NULL, '{\"id\":2,\"driver_id\":11,\"tip_concediu\":\"odihna\",\"data_inceput\":\"2026-05-26\",\"data_sfarsit\":\"2026-05-28\",\"inlocuitor_id\":null,\"note\":null,\"status\":\"in_asteptare\",\"created_by\":1,\"created_at\":\"2026-05-08 12:21:46\",\"updated_at\":\"2026-05-08 12:21:46\",\"sofer_nume\":\"Condrea Daniel\",\"sofer_telefon\":\"079892942\",\"sofer_nr_inmatriculare\":\"B 311 NET\",\"inlocuitor_nume\":null,\"inlocuitor_nr_inmatriculare\":null,\"creat_de_nume\":\"Administrator Sistem\"}', 1, '2026-05-08 12:21:46'),
(76, 'concedii', 2, 'update_status', 'Status cerere concediu actualizat pentru Condrea Daniel', '{\"id\":2,\"driver_id\":11,\"tip_concediu\":\"odihna\",\"data_inceput\":\"2026-05-26\",\"data_sfarsit\":\"2026-05-28\",\"inlocuitor_id\":null,\"note\":null,\"status\":\"in_asteptare\",\"created_by\":1,\"created_at\":\"2026-05-08 12:21:46\",\"updated_at\":\"2026-05-08 12:21:46\",\"sofer_nume\":\"Condrea Daniel\",\"sofer_telefon\":\"079892942\",\"sofer_nr_inmatriculare\":\"B 311 NET\",\"inlocuitor_nume\":null,\"inlocuitor_nr_inmatriculare\":null,\"creat_de_nume\":\"Administrator Sistem\"}', '{\"id\":2,\"driver_id\":11,\"tip_concediu\":\"odihna\",\"data_inceput\":\"2026-05-26\",\"data_sfarsit\":\"2026-05-28\",\"inlocuitor_id\":null,\"note\":null,\"status\":\"aprobat\",\"created_by\":1,\"created_at\":\"2026-05-08 12:21:46\",\"updated_at\":\"2026-05-08 12:22:17\",\"sofer_nume\":\"Condrea Daniel\",\"sofer_telefon\":\"079892942\",\"sofer_nr_inmatriculare\":\"B 311 NET\",\"inlocuitor_nume\":null,\"inlocuitor_nr_inmatriculare\":null,\"creat_de_nume\":\"Administrator Sistem\"}', 1, '2026-05-08 12:22:17'),
(77, 'concedii', 2, 'delete', 'Cerere concediu ștearsă pentru Condrea Daniel', '{\"id\":2,\"driver_id\":11,\"tip_concediu\":\"odihna\",\"data_inceput\":\"2026-05-26\",\"data_sfarsit\":\"2026-05-28\",\"inlocuitor_id\":null,\"note\":null,\"status\":\"aprobat\",\"created_by\":1,\"created_at\":\"2026-05-08 12:21:46\",\"updated_at\":\"2026-05-08 12:22:17\",\"sofer_nume\":\"Condrea Daniel\",\"sofer_telefon\":\"079892942\",\"sofer_nr_inmatriculare\":\"B 311 NET\",\"inlocuitor_nume\":null,\"inlocuitor_nr_inmatriculare\":null,\"creat_de_nume\":\"Administrator Sistem\"}', NULL, 1, '2026-05-08 12:22:30'),
(78, 'concedii', 3, 'create', 'Cerere concediu creată pentru Dan Dobrin', NULL, '{\"id\":3,\"driver_id\":6,\"tip_concediu\":\"personal\",\"data_inceput\":\"2026-05-15\",\"data_sfarsit\":\"2026-05-21\",\"inlocuitor_id\":null,\"note\":null,\"status\":\"in_asteptare\",\"created_by\":1,\"created_at\":\"2026-05-08 12:22:54\",\"updated_at\":\"2026-05-08 12:22:54\",\"sofer_nume\":\"Dan Dobrin\",\"sofer_telefon\":\"07883929\",\"sofer_nr_inmatriculare\":\"B 430 NET\",\"inlocuitor_nume\":null,\"inlocuitor_nr_inmatriculare\":null,\"creat_de_nume\":\"Administrator Sistem\"}', 1, '2026-05-08 12:22:54'),
(79, 'concedii', 3, 'update_status', 'Status cerere concediu actualizat pentru Dan Dobrin', '{\"id\":3,\"driver_id\":6,\"tip_concediu\":\"personal\",\"data_inceput\":\"2026-05-15\",\"data_sfarsit\":\"2026-05-21\",\"inlocuitor_id\":null,\"note\":null,\"status\":\"in_asteptare\",\"created_by\":1,\"created_at\":\"2026-05-08 12:22:54\",\"updated_at\":\"2026-05-08 12:22:54\",\"sofer_nume\":\"Dan Dobrin\",\"sofer_telefon\":\"07883929\",\"sofer_nr_inmatriculare\":\"B 430 NET\",\"inlocuitor_nume\":null,\"inlocuitor_nr_inmatriculare\":null,\"creat_de_nume\":\"Administrator Sistem\"}', '{\"id\":3,\"driver_id\":6,\"tip_concediu\":\"personal\",\"data_inceput\":\"2026-05-15\",\"data_sfarsit\":\"2026-05-21\",\"inlocuitor_id\":null,\"note\":null,\"status\":\"aprobat\",\"created_by\":1,\"created_at\":\"2026-05-08 12:22:54\",\"updated_at\":\"2026-05-08 12:23:19\",\"sofer_nume\":\"Dan Dobrin\",\"sofer_telefon\":\"07883929\",\"sofer_nr_inmatriculare\":\"B 430 NET\",\"inlocuitor_nume\":null,\"inlocuitor_nr_inmatriculare\":null,\"creat_de_nume\":\"Administrator Sistem\"}', 1, '2026-05-08 12:23:19'),
(80, 'concedii', 3, 'update', 'Cerere concediu actualizată pentru Dan Dobrin', '{\"id\":3,\"driver_id\":6,\"tip_concediu\":\"personal\",\"data_inceput\":\"2026-05-15\",\"data_sfarsit\":\"2026-05-21\",\"inlocuitor_id\":null,\"note\":null,\"status\":\"aprobat\",\"created_by\":1,\"created_at\":\"2026-05-08 12:22:54\",\"updated_at\":\"2026-05-08 12:23:19\",\"sofer_nume\":\"Dan Dobrin\",\"sofer_telefon\":\"07883929\",\"sofer_nr_inmatriculare\":\"B 430 NET\",\"inlocuitor_nume\":null,\"inlocuitor_nr_inmatriculare\":null,\"creat_de_nume\":\"Administrator Sistem\"}', '{\"id\":3,\"driver_id\":6,\"tip_concediu\":\"personal\",\"data_inceput\":\"2026-05-16\",\"data_sfarsit\":\"2026-05-21\",\"inlocuitor_id\":null,\"note\":null,\"status\":\"in_asteptare_aprobare\",\"created_by\":1,\"created_at\":\"2026-05-08 12:22:54\",\"updated_at\":\"2026-05-08 12:24:38\",\"sofer_nume\":\"Dan Dobrin\",\"sofer_telefon\":\"07883929\",\"sofer_nr_inmatriculare\":\"B 430 NET\",\"inlocuitor_nume\":null,\"inlocuitor_nr_inmatriculare\":null,\"creat_de_nume\":\"Administrator Sistem\"}', 1, '2026-05-08 12:24:38'),
(81, 'concedii', 3, 'update_status', 'Status cerere concediu actualizat pentru Dan Dobrin', '{\"id\":3,\"driver_id\":6,\"tip_concediu\":\"personal\",\"data_inceput\":\"2026-05-16\",\"data_sfarsit\":\"2026-05-21\",\"inlocuitor_id\":null,\"note\":null,\"status\":\"in_asteptare_aprobare\",\"created_by\":1,\"created_at\":\"2026-05-08 12:22:54\",\"updated_at\":\"2026-05-08 12:24:38\",\"sofer_nume\":\"Dan Dobrin\",\"sofer_telefon\":\"07883929\",\"sofer_nr_inmatriculare\":\"B 430 NET\",\"inlocuitor_nume\":null,\"inlocuitor_nr_inmatriculare\":null,\"creat_de_nume\":\"Administrator Sistem\"}', '{\"id\":3,\"driver_id\":6,\"tip_concediu\":\"personal\",\"data_inceput\":\"2026-05-16\",\"data_sfarsit\":\"2026-05-21\",\"inlocuitor_id\":null,\"note\":null,\"status\":\"aprobat\",\"created_by\":1,\"created_at\":\"2026-05-08 12:22:54\",\"updated_at\":\"2026-05-08 12:24:46\",\"sofer_nume\":\"Dan Dobrin\",\"sofer_telefon\":\"07883929\",\"sofer_nr_inmatriculare\":\"B 430 NET\",\"inlocuitor_nume\":null,\"inlocuitor_nr_inmatriculare\":null,\"creat_de_nume\":\"Administrator Sistem\"}', 1, '2026-05-08 12:24:46'),
(82, 'concedii', 4, 'create', 'Cerere concediu creată pentru Condrea Daniel', NULL, '{\"id\":4,\"driver_id\":11,\"tip_concediu\":\"odihna\",\"data_inceput\":\"2026-06-08\",\"data_sfarsit\":\"2026-06-14\",\"inlocuitor_id\":null,\"note\":null,\"status\":\"in_asteptare\",\"created_by\":1,\"created_at\":\"2026-05-08 12:28:57\",\"updated_at\":\"2026-05-08 12:28:57\",\"sofer_nume\":\"Condrea Daniel\",\"sofer_telefon\":\"079892942\",\"sofer_nr_inmatriculare\":\"B 311 NET\",\"inlocuitor_nume\":null,\"inlocuitor_nr_inmatriculare\":null,\"creat_de_nume\":\"Administrator Sistem\"}', 1, '2026-05-08 12:28:57'),
(83, 'concedii', 4, 'update_status', 'Status cerere concediu actualizat pentru Condrea Daniel', '{\"id\":4,\"driver_id\":11,\"tip_concediu\":\"odihna\",\"data_inceput\":\"2026-06-08\",\"data_sfarsit\":\"2026-06-14\",\"inlocuitor_id\":null,\"note\":null,\"status\":\"in_asteptare\",\"created_by\":1,\"created_at\":\"2026-05-08 12:28:57\",\"updated_at\":\"2026-05-08 12:28:57\",\"sofer_nume\":\"Condrea Daniel\",\"sofer_telefon\":\"079892942\",\"sofer_nr_inmatriculare\":\"B 311 NET\",\"inlocuitor_nume\":null,\"inlocuitor_nr_inmatriculare\":null,\"creat_de_nume\":\"Administrator Sistem\"}', '{\"id\":4,\"driver_id\":11,\"tip_concediu\":\"odihna\",\"data_inceput\":\"2026-06-08\",\"data_sfarsit\":\"2026-06-14\",\"inlocuitor_id\":null,\"note\":null,\"status\":\"aprobat\",\"created_by\":1,\"created_at\":\"2026-05-08 12:28:57\",\"updated_at\":\"2026-05-08 12:29:08\",\"sofer_nume\":\"Condrea Daniel\",\"sofer_telefon\":\"079892942\",\"sofer_nr_inmatriculare\":\"B 311 NET\",\"inlocuitor_nume\":null,\"inlocuitor_nr_inmatriculare\":null,\"creat_de_nume\":\"Administrator Sistem\"}', 1, '2026-05-08 12:29:08'),
(84, 'concedii', 4, 'delete', 'Cerere concediu ștearsă pentru Condrea Daniel', '{\"id\":4,\"driver_id\":11,\"tip_concediu\":\"odihna\",\"data_inceput\":\"2026-06-08\",\"data_sfarsit\":\"2026-06-14\",\"inlocuitor_id\":null,\"note\":null,\"status\":\"aprobat\",\"created_by\":1,\"created_at\":\"2026-05-08 12:28:57\",\"updated_at\":\"2026-05-08 12:29:08\",\"sofer_nume\":\"Condrea Daniel\",\"sofer_telefon\":\"079892942\",\"sofer_nr_inmatriculare\":\"B 311 NET\",\"inlocuitor_nume\":null,\"inlocuitor_nr_inmatriculare\":null,\"creat_de_nume\":\"Administrator Sistem\"}', NULL, 1, '2026-05-08 12:31:39'),
(85, 'concedii', 3, 'delete', 'Cerere concediu ștearsă pentru Dan Dobrin', '{\"id\":3,\"driver_id\":6,\"tip_concediu\":\"personal\",\"data_inceput\":\"2026-05-16\",\"data_sfarsit\":\"2026-05-21\",\"inlocuitor_id\":null,\"note\":null,\"status\":\"aprobat\",\"created_by\":1,\"created_at\":\"2026-05-08 12:22:54\",\"updated_at\":\"2026-05-08 12:24:46\",\"sofer_nume\":\"Dan Dobrin\",\"sofer_telefon\":\"07883929\",\"sofer_nr_inmatriculare\":\"B 430 NET\",\"inlocuitor_nume\":null,\"inlocuitor_nr_inmatriculare\":null,\"creat_de_nume\":\"Administrator Sistem\"}', NULL, 1, '2026-05-08 12:31:43'),
(86, 'concedii', 5, 'create', 'Cerere concediu creată pentru Condrea Daniel', NULL, '{\"id\":5,\"driver_id\":11,\"tip_concediu\":\"odihna\",\"data_inceput\":\"2026-05-10\",\"data_sfarsit\":\"2026-05-17\",\"inlocuitor_id\":10,\"note\":null,\"status\":\"in_asteptare\",\"created_by\":1,\"created_at\":\"2026-05-08 16:16:05\",\"updated_at\":\"2026-05-08 16:16:05\",\"sofer_nume\":\"Condrea Daniel\",\"sofer_telefon\":\"079892942\",\"sofer_nr_inmatriculare\":\"B 311 NET\",\"inlocuitor_nume\":\"Magnolia Dorel\",\"inlocuitor_nr_inmatriculare\":\"B 219 NET\",\"creat_de_nume\":\"Administrator Sistem\"}', 1, '2026-05-08 16:16:05'),
(87, 'concedii', 5, 'update_status', 'Status cerere concediu actualizat pentru Condrea Daniel', '{\"id\":5,\"driver_id\":11,\"tip_concediu\":\"odihna\",\"data_inceput\":\"2026-05-10\",\"data_sfarsit\":\"2026-05-17\",\"inlocuitor_id\":10,\"note\":null,\"status\":\"in_asteptare\",\"created_by\":1,\"created_at\":\"2026-05-08 16:16:05\",\"updated_at\":\"2026-05-08 16:16:05\",\"sofer_nume\":\"Condrea Daniel\",\"sofer_telefon\":\"079892942\",\"sofer_nr_inmatriculare\":\"B 311 NET\",\"inlocuitor_nume\":\"Magnolia Dorel\",\"inlocuitor_nr_inmatriculare\":\"B 219 NET\",\"creat_de_nume\":\"Administrator Sistem\"}', '{\"id\":5,\"driver_id\":11,\"tip_concediu\":\"odihna\",\"data_inceput\":\"2026-05-10\",\"data_sfarsit\":\"2026-05-17\",\"inlocuitor_id\":10,\"note\":null,\"status\":\"aprobat\",\"created_by\":1,\"created_at\":\"2026-05-08 16:16:05\",\"updated_at\":\"2026-05-08 16:19:22\",\"sofer_nume\":\"Condrea Daniel\",\"sofer_telefon\":\"079892942\",\"sofer_nr_inmatriculare\":\"B 311 NET\",\"inlocuitor_nume\":\"Magnolia Dorel\",\"inlocuitor_nr_inmatriculare\":\"B 219 NET\",\"creat_de_nume\":\"Administrator Sistem\"}', 1, '2026-05-08 16:19:22'),
(88, 'concedii', 6, 'create', 'Cerere concediu creată pentru Dan Spataru', NULL, '{\"id\":6,\"driver_id\":5,\"tip_concediu\":\"odihna\",\"data_inceput\":\"2026-05-08\",\"data_sfarsit\":\"2026-05-19\",\"inlocuitor_id\":null,\"note\":null,\"status\":\"in_asteptare\",\"created_by\":1,\"created_at\":\"2026-05-08 16:20:15\",\"updated_at\":\"2026-05-08 16:20:15\",\"sofer_nume\":\"Dan Spataru\",\"sofer_telefon\":\"077383993\",\"sofer_nr_inmatriculare\":\"B 345 NET\",\"inlocuitor_nume\":null,\"inlocuitor_nr_inmatriculare\":null,\"creat_de_nume\":\"Administrator Sistem\"}', 1, '2026-05-08 16:20:15'),
(89, 'concedii', 6, 'update_status', 'Status cerere concediu actualizat pentru Dan Spataru', '{\"id\":6,\"driver_id\":5,\"tip_concediu\":\"odihna\",\"data_inceput\":\"2026-05-08\",\"data_sfarsit\":\"2026-05-19\",\"inlocuitor_id\":null,\"note\":null,\"status\":\"in_asteptare\",\"created_by\":1,\"created_at\":\"2026-05-08 16:20:15\",\"updated_at\":\"2026-05-08 16:20:15\",\"sofer_nume\":\"Dan Spataru\",\"sofer_telefon\":\"077383993\",\"sofer_nr_inmatriculare\":\"B 345 NET\",\"inlocuitor_nume\":null,\"inlocuitor_nr_inmatriculare\":null,\"creat_de_nume\":\"Administrator Sistem\"}', '{\"id\":6,\"driver_id\":5,\"tip_concediu\":\"odihna\",\"data_inceput\":\"2026-05-08\",\"data_sfarsit\":\"2026-05-19\",\"inlocuitor_id\":null,\"note\":null,\"status\":\"aprobat\",\"created_by\":1,\"created_at\":\"2026-05-08 16:20:15\",\"updated_at\":\"2026-05-08 16:20:25\",\"sofer_nume\":\"Dan Spataru\",\"sofer_telefon\":\"077383993\",\"sofer_nr_inmatriculare\":\"B 345 NET\",\"inlocuitor_nume\":null,\"inlocuitor_nr_inmatriculare\":null,\"creat_de_nume\":\"Administrator Sistem\"}', 1, '2026-05-08 16:20:25'),
(90, 'concedii', 5, 'delete', 'Cerere concediu ștearsă pentru Condrea Daniel', '{\"id\":5,\"driver_id\":11,\"tip_concediu\":\"odihna\",\"data_inceput\":\"2026-05-10\",\"data_sfarsit\":\"2026-05-17\",\"inlocuitor_id\":10,\"note\":null,\"status\":\"aprobat\",\"created_by\":1,\"created_at\":\"2026-05-08 16:16:05\",\"updated_at\":\"2026-05-08 16:19:22\",\"sofer_nume\":\"Condrea Daniel\",\"sofer_telefon\":\"079892942\",\"sofer_nr_inmatriculare\":\"B 311 NET\",\"inlocuitor_nume\":\"Magnolia Dorel\",\"inlocuitor_nr_inmatriculare\":\"B 219 NET\",\"creat_de_nume\":\"Administrator Sistem\"}', NULL, 1, '2026-05-08 17:07:46'),
(91, 'concedii', 6, 'delete', 'Cerere concediu ștearsă pentru Dan Spataru', '{\"id\":6,\"driver_id\":5,\"tip_concediu\":\"odihna\",\"data_inceput\":\"2026-05-08\",\"data_sfarsit\":\"2026-05-19\",\"inlocuitor_id\":null,\"note\":null,\"status\":\"aprobat\",\"created_by\":1,\"created_at\":\"2026-05-08 16:20:15\",\"updated_at\":\"2026-05-08 16:20:25\",\"sofer_nume\":\"Dan Spataru\",\"sofer_telefon\":\"077383993\",\"sofer_nr_inmatriculare\":\"B 345 NET\",\"inlocuitor_nume\":null,\"inlocuitor_nr_inmatriculare\":null,\"creat_de_nume\":\"Administrator Sistem\"}', NULL, 1, '2026-05-08 17:07:50'),
(92, 'documente', 24, 'update', 'Document actualizat: ITP () pentru B 677 NET', '{\"vehicul\":\"B 677 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-08\",\"fisier_original\":null}', '{\"vehicul\":\"B 677 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-27\",\"fisier_original\":null}', 1, '2026-05-11 10:32:30'),
(93, 'documente', 64, 'create', 'Document creat: RCA () pentru B 105 NET', NULL, '{\"vehicul\":\"B 105 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-29\",\"fisier_original\":null}', 1, '2026-05-19 12:28:33'),
(94, 'documente', 65, 'create', 'Document creat: ITP () pentru B 105 NET', NULL, '{\"vehicul\":\"B 105 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-29\",\"fisier_original\":null}', 1, '2026-05-19 12:28:48'),
(95, 'documente', 66, 'create', 'Document creat: Rovinieta () pentru B 105 NET', NULL, '{\"vehicul\":\"B 105 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-08-30\",\"fisier_original\":null}', 1, '2026-05-19 12:29:13'),
(96, 'documente', 67, 'create', 'Document creat: RCA () pentru B 935 NET', NULL, '{\"vehicul\":\"B 935 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-30\",\"fisier_original\":null}', 1, '2026-05-19 12:39:00'),
(97, 'documente', 68, 'create', 'Document creat: ITP () pentru B 935 NET', NULL, '{\"vehicul\":\"B 935 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-10-28\",\"fisier_original\":null}', 1, '2026-05-19 12:39:11'),
(98, 'documente', 69, 'create', 'Document creat: Rovinieta () pentru B 935 NET', NULL, '{\"vehicul\":\"B 935 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-30\",\"fisier_original\":null}', 1, '2026-05-19 12:39:21'),
(99, 'documente', 70, 'create', 'Document creat: ITP () pentru B 400 NET', NULL, '{\"vehicul\":\"B 400 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-28\",\"fisier_original\":null}', 1, '2026-05-28 12:04:38'),
(100, 'documente', 71, 'create', 'Document creat: Adr () pentru B 835 NET', NULL, '{\"vehicul\":\"B 835 NET\",\"tip_document\":\"Adr\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-23\",\"fisier_original\":null}', 1, '2026-06-03 08:47:45'),
(101, 'documente', 72, 'create', 'Document creat: RCA () pentru B 835 NET', NULL, '{\"vehicul\":\"B 835 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-22\",\"fisier_original\":null}', 1, '2026-06-03 08:48:43'),
(102, 'documente', 73, 'create', 'Document creat: Tuv () pentru B 835 NET', NULL, '{\"vehicul\":\"B 835 NET\",\"tip_document\":\"Tuv\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-30\",\"fisier_original\":null}', 1, '2026-06-03 08:49:13'),
(103, 'documente', 74, 'create', 'Document creat: IPROCHIM () pentru B 835 NET', NULL, '{\"vehicul\":\"B 835 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-23\",\"fisier_original\":null}', 1, '2026-06-03 08:49:39'),
(104, 'documente', 75, 'create', 'Document creat: ITP () pentru B 835 NET', NULL, '{\"vehicul\":\"B 835 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-23\",\"fisier_original\":null}', 1, '2026-06-03 08:51:26'),
(105, 'documente', 76, 'create', 'Document creat: Carte () pentru B 835 NET', NULL, '{\"vehicul\":\"B 835 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-11\",\"fisier_original\":\"835_Carte.pdf\"}', 3, '2026-06-03 13:46:41'),
(106, 'documente', 77, 'create', 'Document creat: ADR () pentru B 652 NET', NULL, '{\"vehicul\":\"B 652 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-24\",\"fisier_original\":\"652_ADR.pdf\"}', 3, '2026-06-03 14:00:33'),
(107, 'documente', 78, 'create', 'Document creat: Carte () pentru B 652 NET', NULL, '{\"vehicul\":\"B 652 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-11\",\"fisier_original\":\"652_Carte.pdf\"}', 3, '2026-06-03 14:00:55'),
(108, 'documente', 79, 'create', 'Document creat: CASCO () pentru B 652 NET', NULL, '{\"vehicul\":\"B 652 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-25\",\"fisier_original\":\"652_Casco.pdf\"}', 3, '2026-06-03 14:01:38'),
(109, 'documente', 80, 'create', 'Document creat: Copie conforma () pentru B 652 NET', NULL, '{\"vehicul\":\"B 652 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_652.pdf\"}', 3, '2026-06-03 14:02:15'),
(110, 'documente', 81, 'create', 'Document creat: ITP () pentru B 652 NET', NULL, '{\"vehicul\":\"B 652 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-24\",\"fisier_original\":\"652_Talon.pdf\"}', 3, '2026-06-03 14:03:18'),
(111, 'documente', 82, 'create', 'Document creat: Tahograf () pentru B 652 NET', NULL, '{\"vehicul\":\"B 652 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-29\",\"fisier_original\":\"652_Taho.pdf\"}', 3, '2026-06-03 14:03:51'),
(112, 'documente', 83, 'create', 'Document creat: ADR () pentru B 645 NET', NULL, '{\"vehicul\":\"B 645 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-10-13\",\"fisier_original\":\"645_ADR.pdf\"}', 3, '2026-06-03 14:18:10'),
(113, 'documente', 84, 'create', 'Document creat: Carte () pentru B 645 NET', NULL, '{\"vehicul\":\"B 645 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-11\",\"fisier_original\":\"645_Carte.pdf\"}', 3, '2026-06-03 14:18:28'),
(114, 'documente', 85, 'create', 'Document creat: CASCO () pentru B 645 NET', NULL, '{\"vehicul\":\"B 645 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2029-01-04\",\"fisier_original\":\"645_CASCO.pdf\"}', 3, '2026-06-03 14:19:35'),
(115, 'documente', 86, 'create', 'Document creat: Copie conforma () pentru B 645 NET', NULL, '{\"vehicul\":\"B 645 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_645.pdf\"}', 3, '2026-06-03 14:20:13'),
(116, 'documente', 87, 'create', 'Document creat: ITP () pentru B 645 NET', NULL, '{\"vehicul\":\"B 645 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-10-13\",\"fisier_original\":\"645_Talon.pdf\"}', 3, '2026-06-03 14:21:05'),
(117, 'documente', 88, 'create', 'Document creat: RCA () pentru B 645 NET', NULL, '{\"vehicul\":\"B 645 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-03\",\"fisier_original\":\"645_rca.pdf\"}', 3, '2026-06-03 14:21:27'),
(118, 'documente', 89, 'create', 'Document creat: Tahograf () pentru B 645 NET', NULL, '{\"vehicul\":\"B 645 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2028-03-20\",\"fisier_original\":\"645_TAHO_NOU_1.pdf\"}', 3, '2026-06-03 14:22:35'),
(119, 'documente', 90, 'create', 'Document creat: Brml () pentru B 915 NET', NULL, '{\"vehicul\":\"B 915 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2028-11-30\",\"fisier_original\":\"915_BRML-MID_1.pdf\"}', 3, '2026-06-03 14:27:36'),
(120, 'documente', 91, 'create', 'Document creat: Carte () pentru B 915 NET', NULL, '{\"vehicul\":\"B 915 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-11\",\"fisier_original\":\"915_Carte.pdf\"}', 3, '2026-06-03 14:27:56'),
(121, 'documente', 92, 'create', 'Document creat: CASCO () pentru B 915 NET', NULL, '{\"vehicul\":\"B 915 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2030-02-12\",\"fisier_original\":null}', 3, '2026-06-03 14:28:13'),
(122, 'documente', 92, 'update', 'Document actualizat: CASCO () pentru B 915 NET', '{\"vehicul\":\"B 915 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2030-02-12\",\"fisier_original\":null}', '{\"vehicul\":\"B 915 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2030-02-12\",\"fisier_original\":\"915_Casco.pdf\"}', 3, '2026-06-03 14:29:12'),
(123, 'documente', 93, 'create', 'Document creat: IPROCHIM () pentru B 915 NET', NULL, '{\"vehicul\":\"B 915 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-19\",\"fisier_original\":\"915_IPROCHIM.pdf\"}', 3, '2026-06-03 14:29:56'),
(124, 'documente', 94, 'create', 'Document creat: ITP () pentru B 915 NET', NULL, '{\"vehicul\":\"B 915 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-26\",\"fisier_original\":\"915_TALON.pdf\"}', 3, '2026-06-03 14:30:25'),
(125, 'documente', 95, 'create', 'Document creat: RCA () pentru B 915 NET', NULL, '{\"vehicul\":\"B 915 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-12\",\"fisier_original\":\"915_RCA.pdf\"}', 3, '2026-06-03 14:31:37'),
(126, 'documente', 96, 'create', 'Document creat: ADR () pentru B 635 NET', NULL, '{\"vehicul\":\"B 635 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-27\",\"fisier_original\":\"635_ADR_1.pdf\"}', 3, '2026-06-03 14:53:52'),
(127, 'documente', 97, 'create', 'Document creat: Carte () pentru B 635 NET', NULL, '{\"vehicul\":\"B 635 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-11\",\"fisier_original\":\"635_Carte.pdf\"}', 3, '2026-06-03 14:54:32'),
(128, 'documente', 98, 'create', 'Document creat: Copie conforma () pentru B 635 NET', NULL, '{\"vehicul\":\"B 635 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_635.pdf\"}', 3, '2026-06-03 14:55:08'),
(129, 'documente', 99, 'create', 'Document creat: ITP () pentru B 635 NET', NULL, '{\"vehicul\":\"B 635 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-07\",\"fisier_original\":\"635_TALON_.pdf\"}', 3, '2026-06-03 14:55:26'),
(130, 'documente', 100, 'create', 'Document creat: RCA () pentru B 635 NET', NULL, '{\"vehicul\":\"B 635 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-16\",\"fisier_original\":\"635_RCA.pdf\"}', 3, '2026-06-03 14:55:46'),
(131, 'documente', 101, 'create', 'Document creat: Tahograf () pentru B 635 NET', NULL, '{\"vehicul\":\"B 635 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2027-07-15\",\"fisier_original\":\"635_Taho.pdf\"}', 3, '2026-06-03 15:03:44'),
(132, 'documente', 102, 'create', 'Document creat: Adr () pentru B 845 NET', NULL, '{\"vehicul\":\"B 845 NET\",\"tip_document\":\"Adr\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-11\",\"fisier_original\":\"635_ADR_1.pdf\"}', 3, '2026-06-03 15:25:41'),
(133, 'documente', 103, 'create', 'Document creat: Carte () pentru B 845 NET', NULL, '{\"vehicul\":\"B 845 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-11\",\"fisier_original\":\"845_Carte.pdf\"}', 3, '2026-06-03 15:26:06'),
(134, 'documente', 102, 'update', 'Document actualizat: Adr () pentru B 845 NET', '{\"vehicul\":\"B 845 NET\",\"tip_document\":\"Adr\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-11\",\"fisier_original\":\"635_ADR_1.pdf\"}', '{\"vehicul\":\"B 845 NET\",\"tip_document\":\"Adr\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-11\",\"fisier_original\":\"845_ADR_1.pdf\"}', 3, '2026-06-03 15:26:27'),
(135, 'documente', 104, 'create', 'Document creat: Casco () pentru B 845 NET', NULL, '{\"vehicul\":\"B 845 NET\",\"tip_document\":\"Casco\",\"numar_document\":\"\",\"data_expirare\":\"2030-04-02\",\"fisier_original\":\"845_Casco.pdf\"}', 3, '2026-06-03 15:27:36'),
(136, 'documente', 105, 'create', 'Document creat: IPROCHIM () pentru B 845 NET', NULL, '{\"vehicul\":\"B 845 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-11\",\"fisier_original\":\"845_Iprochim_1.pdf\"}', 3, '2026-06-03 15:28:20'),
(137, 'documente', 106, 'create', 'Document creat: ITP () pentru B 845 NET', NULL, '{\"vehicul\":\"B 845 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-11\",\"fisier_original\":\"845_Talon_1.pdf\"}', 3, '2026-06-03 15:28:54'),
(138, 'documente', 107, 'create', 'Document creat: RCA () pentru B 845 NET', NULL, '{\"vehicul\":\"B 845 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-02\",\"fisier_original\":\"845_RCA_1.pdf\"}', 3, '2026-06-03 15:29:40'),
(139, 'documente', 108, 'create', 'Document creat: ADR () pentru B 625 NET', NULL, '{\"vehicul\":\"B 625 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-12\",\"fisier_original\":\"625_ADR_1.pdf\"}', 3, '2026-06-03 15:44:23'),
(140, 'documente', 109, 'create', 'Document creat: Carte () pentru B 625 NET', NULL, '{\"vehicul\":\"B 625 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-11\",\"fisier_original\":\"625_Carte.pdf\"}', 3, '2026-06-03 15:44:35'),
(141, 'documente', 110, 'create', 'Document creat: Copie conforma () pentru B 625 NET', NULL, '{\"vehicul\":\"B 625 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_625.pdf\"}', 3, '2026-06-03 15:45:03');
INSERT INTO `audit_log` (`id`, `modul`, `record_id`, `actiune`, `descriere`, `before_data`, `after_data`, `user_id`, `created_at`) VALUES
(142, 'documente', 111, 'create', 'Document creat: ITP () pentru B 625 NET', NULL, '{\"vehicul\":\"B 625 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-12\",\"fisier_original\":\"625_Talon_1.pdf\"}', 3, '2026-06-03 15:45:44'),
(143, 'documente', 112, 'create', 'Document creat: RCA () pentru B 625 NET', NULL, '{\"vehicul\":\"B 625 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-18\",\"fisier_original\":\"625_RCA_1-3.pdf\"}', 3, '2026-06-03 15:48:59'),
(144, 'documente', 113, 'create', 'Document creat: Tahograf () pentru B 625 NET', NULL, '{\"vehicul\":\"B 625 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2028-03-20\",\"fisier_original\":\"625_TAHO_1.pdf\"}', 3, '2026-06-03 15:49:17'),
(145, 'documente', 114, 'create', 'Document creat: ADR () pentru B 915 NET', NULL, '{\"vehicul\":\"B 915 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-26\",\"fisier_original\":\"915_ADR.pdf\"}', 3, '2026-06-03 15:50:46'),
(146, 'documente', 115, 'create', 'Document creat: Adr () pentru B 825 NET', NULL, '{\"vehicul\":\"B 825 NET\",\"tip_document\":\"Adr\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-22\",\"fisier_original\":\"825_ADR_1.pdf\"}', 3, '2026-06-03 15:57:01'),
(147, 'documente', 116, 'create', 'Document creat: Carte () pentru B 825 NET', NULL, '{\"vehicul\":\"B 825 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-11\",\"fisier_original\":\"825_Carte.pdf\"}', 3, '2026-06-03 15:58:34'),
(148, 'documente', 117, 'create', 'Document creat: Casco () pentru B 825 NET', NULL, '{\"vehicul\":\"B 825 NET\",\"tip_document\":\"Casco\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-05\",\"fisier_original\":\"825_Casco_1.pdf\"}', 3, '2026-06-03 15:59:02'),
(149, 'documente', 118, 'create', 'Document creat: IPROCHIM () pentru B 825 NET', NULL, '{\"vehicul\":\"B 825 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-22\",\"fisier_original\":\"825_Iprochim.pdf\"}', 3, '2026-06-03 15:59:27'),
(150, 'documente', 119, 'create', 'Document creat: ITP () pentru B 825 NET', NULL, '{\"vehicul\":\"B 825 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-22\",\"fisier_original\":\"825_Talon.pdf\"}', 3, '2026-06-03 15:59:50'),
(151, 'documente', 120, 'create', 'Document creat: RCA () pentru B 825 NET', NULL, '{\"vehicul\":\"B 825 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-05\",\"fisier_original\":\"825_RCA.pdf\"}', 3, '2026-06-03 16:00:06'),
(152, 'documente', 121, 'create', 'Document creat: Tuv () pentru B 825 NET', NULL, '{\"vehicul\":\"B 825 NET\",\"tip_document\":\"Tuv\",\"numar_document\":\"\",\"data_expirare\":\"2027-12-30\",\"fisier_original\":\"825_TUV_1.pdf\"}', 3, '2026-06-03 16:01:56'),
(153, 'documente', 122, 'create', 'Document creat: ADR () pentru B 615 NET', NULL, '{\"vehicul\":\"B 615 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-22\",\"fisier_original\":\"615_ADR.pdf\"}', 3, '2026-06-03 16:38:26'),
(154, 'documente', 123, 'create', 'Document creat: Carte () pentru B 615 NET', NULL, '{\"vehicul\":\"B 615 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-11\",\"fisier_original\":\"615_Carte.pdf\"}', 3, '2026-06-03 16:38:45'),
(155, 'documente', 124, 'create', 'Document creat: CASCO () pentru B 615 NET', NULL, '{\"vehicul\":\"B 615 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-25\",\"fisier_original\":\"POLITA_CASCO_FLOTA_2026.pdf\"}', 3, '2026-06-03 16:39:06'),
(156, 'documente', 125, 'create', 'Document creat: Copie conforma () pentru B 615 NET', NULL, '{\"vehicul\":\"B 615 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_652_1.pdf\"}', 3, '2026-06-03 16:39:26'),
(157, 'documente', 126, 'create', 'Document creat: ITP () pentru B 615 NET', NULL, '{\"vehicul\":\"B 615 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-22\",\"fisier_original\":\"615_Talon.pdf\"}', 3, '2026-06-03 16:39:41'),
(158, 'documente', 127, 'create', 'Document creat: RCA () pentru B 615 NET', NULL, '{\"vehicul\":\"B 615 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-01-26\",\"fisier_original\":\"615_Talon.pdf\"}', 3, '2026-06-03 16:39:58'),
(159, 'documente', 128, 'create', 'Document creat: Tahograf () pentru B 615 NET', NULL, '{\"vehicul\":\"B 615 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2027-12-27\",\"fisier_original\":\"615_Taho.pdf\"}', 3, '2026-06-03 16:40:16'),
(160, 'documente', 129, 'create', 'Document creat: ADR () pentru B 705 NET', NULL, '{\"vehicul\":\"B 705 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-16\",\"fisier_original\":\"705_ADR.pdf\"}', 3, '2026-06-04 10:20:42'),
(161, 'documente', 130, 'create', 'Document creat: Brml () pentru B 705 NET', NULL, '{\"vehicul\":\"B 705 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2026-11-08\",\"fisier_original\":\"705_BRML.pdf\"}', 3, '2026-06-04 10:21:00'),
(162, 'documente', 131, 'create', 'Document creat: Carte () pentru B 705 NET', NULL, '{\"vehicul\":\"B 705 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-11\",\"fisier_original\":null}', 3, '2026-06-04 10:21:27'),
(163, 'documente', 132, 'create', 'Document creat: CASCO () pentru B 705 NET', NULL, '{\"vehicul\":\"B 705 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-12\",\"fisier_original\":\"705_Casco.pdf\"}', 3, '2026-06-04 10:22:09'),
(164, 'documente', 133, 'create', 'Document creat: IPROCHIM () pentru B 705 NET', NULL, '{\"vehicul\":\"B 705 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-16\",\"fisier_original\":\"705_Iprochim.pdf\"}', 3, '2026-06-04 10:22:35'),
(165, 'documente', 134, 'create', 'Document creat: ITP () pentru B 705 NET', NULL, '{\"vehicul\":\"B 705 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-16\",\"fisier_original\":\"705_Talon.pdf\"}', 3, '2026-06-04 10:22:54'),
(166, 'documente', 135, 'create', 'Document creat: RCA () pentru B 705 NET', NULL, '{\"vehicul\":\"B 705 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-11-12\",\"fisier_original\":\"705_RCA.pdf\"}', 3, '2026-06-04 10:23:13'),
(167, 'documente', 136, 'create', 'Document creat: Tuv () pentru B 705 NET', NULL, '{\"vehicul\":\"B 705 NET\",\"tip_document\":\"Tuv\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-30\",\"fisier_original\":\"705_TUV_1.pdf\"}', 3, '2026-06-04 10:23:48'),
(168, 'documente', 131, 'update', 'Document actualizat: Carte () pentru B 705 NET', '{\"vehicul\":\"B 705 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-11\",\"fisier_original\":null}', '{\"vehicul\":\"B 705 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-11\",\"fisier_original\":\"705_Carte.pdf\"}', 3, '2026-06-04 10:24:17'),
(169, 'documente', 137, 'create', 'Document creat: ADR () pentru B 905 NET', NULL, '{\"vehicul\":\"B 905 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-12\",\"fisier_original\":\"705_ADR.pdf\"}', 3, '2026-06-04 10:40:16'),
(170, 'documente', 138, 'create', 'Document creat: Mid () pentru B 905 NET', NULL, '{\"vehicul\":\"B 905 NET\",\"tip_document\":\"Mid\",\"numar_document\":\"\",\"data_expirare\":\"2028-11-17\",\"fisier_original\":\"905_BRML_-_MID_1.pdf\"}', 3, '2026-06-04 10:40:53'),
(171, 'documente', 137, 'update', 'Document actualizat: ADR () pentru B 905 NET', '{\"vehicul\":\"B 905 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-12\",\"fisier_original\":\"705_ADR.pdf\"}', '{\"vehicul\":\"B 905 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-12\",\"fisier_original\":\"905_ADR_1.pdf\"}', 3, '2026-06-04 10:42:27'),
(172, 'documente', 139, 'create', 'Document creat: Carte () pentru B 905 NET', NULL, '{\"vehicul\":\"B 905 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-11\",\"fisier_original\":\"905_Carte._1.pdf\"}', 3, '2026-06-04 10:43:09'),
(173, 'documente', 140, 'create', 'Document creat: CASCO () pentru B 905 NET', NULL, '{\"vehicul\":\"B 905 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-11\",\"fisier_original\":\"CASCO-905.pdf\"}', 3, '2026-06-04 10:43:29'),
(174, 'documente', 141, 'create', 'Document creat: IPROCHIM () pentru B 905 NET', NULL, '{\"vehicul\":\"B 905 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-12\",\"fisier_original\":\"905_Iprochim_2.pdf\"}', 3, '2026-06-04 10:43:50'),
(175, 'documente', 142, 'create', 'Document creat: ITP () pentru B 905 NET', NULL, '{\"vehicul\":\"B 905 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-12\",\"fisier_original\":\"905_Talon_nou_1.pdf\"}', 3, '2026-06-04 10:44:10'),
(176, 'documente', 143, 'create', 'Document creat: RCA () pentru B 905 NET', NULL, '{\"vehicul\":\"B 905 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-11\",\"fisier_original\":\"905_RCA.pdf\"}', 3, '2026-06-04 10:44:37'),
(177, 'documente', 144, 'create', 'Document creat: ADR () pentru B 402 NET', NULL, '{\"vehicul\":\"B 402 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-13\",\"fisier_original\":\"402_ADR.pdf\"}', 3, '2026-06-04 10:56:41'),
(178, 'documente', 145, 'create', 'Document creat: Carte () pentru B 402 NET', NULL, '{\"vehicul\":\"B 402 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-11\",\"fisier_original\":\"402_Carte.pdf\"}', 3, '2026-06-04 10:57:33'),
(179, 'documente', 146, 'create', 'Document creat: Copie conforma () pentru B 402 NET', NULL, '{\"vehicul\":\"B 402 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_402.pdf\"}', 3, '2026-06-04 10:58:30'),
(180, 'documente', 147, 'create', 'Document creat: ITP () pentru B 402 NET', NULL, '{\"vehicul\":\"B 402 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-13\",\"fisier_original\":\"402_Talon.pdf\"}', 3, '2026-06-04 10:58:49'),
(181, 'documente', 148, 'create', 'Document creat: RCA () pentru B 402 NET', NULL, '{\"vehicul\":\"B 402 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-09\",\"fisier_original\":\"402_RCA.pdf\"}', 3, '2026-06-04 10:59:06'),
(182, 'documente', 149, 'create', 'Document creat: Tahograf () pentru B 402 NET', NULL, '{\"vehicul\":\"B 402 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-05\",\"fisier_original\":\"402_Taho.pdf\"}', 3, '2026-06-04 10:59:32'),
(183, 'documente', 150, 'create', 'Document creat: ADR () pentru B 815 NET', NULL, '{\"vehicul\":\"B 815 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-21\",\"fisier_original\":\"815_ADR.pdf\"}', 3, '2026-06-04 11:08:53'),
(184, 'documente', 151, 'create', 'Document creat: ADR () pentru B 815 NET', NULL, '{\"vehicul\":\"B 815 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-21\",\"fisier_original\":\"815_ADR.pdf\"}', 3, '2026-06-04 11:08:54'),
(185, 'documente', 152, 'create', 'Document creat: Carte () pentru B 815 NET', NULL, '{\"vehicul\":\"B 815 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-11\",\"fisier_original\":\"815_carte.pdf\"}', 3, '2026-06-04 11:09:09'),
(186, 'documente', 153, 'create', 'Document creat: ADR () pentru B 815 NET', NULL, '{\"vehicul\":\"B 815 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-21\",\"fisier_original\":\"815_ADR.pdf\"}', 3, '2026-06-04 11:32:56'),
(187, 'documente', 154, 'create', 'Document creat: Carte () pentru B 815 NET', NULL, '{\"vehicul\":\"B 815 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-11\",\"fisier_original\":\"815_carte.pdf\"}', 3, '2026-06-04 11:33:16'),
(188, 'documente', 155, 'create', 'Document creat: CASCO () pentru B 815 NET', NULL, '{\"vehicul\":\"B 815 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-08\",\"fisier_original\":\"815_Casco_1.pdf\"}', 3, '2026-06-04 11:33:38'),
(189, 'documente', 156, 'create', 'Document creat: IPROCHIM () pentru B 815 NET', NULL, '{\"vehicul\":\"B 815 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-21\",\"fisier_original\":\"815_Iprochim.pdf\"}', 3, '2026-06-04 11:33:58'),
(190, 'documente', 154, 'delete', 'Document sters: Carte () pentru B 815 NET', '{\"vehicul\":\"B 815 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-11\",\"fisier_original\":\"815_carte.pdf\"}', NULL, 3, '2026-06-04 11:39:47'),
(191, 'documente', 153, 'delete', 'Document sters: ADR () pentru B 815 NET', '{\"vehicul\":\"B 815 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-21\",\"fisier_original\":\"815_ADR.pdf\"}', NULL, 3, '2026-06-04 11:40:00'),
(192, 'documente', 151, 'delete', 'Document sters: ADR () pentru B 815 NET', '{\"vehicul\":\"B 815 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-21\",\"fisier_original\":\"815_ADR.pdf\"}', NULL, 3, '2026-06-04 11:40:10'),
(193, 'documente', 157, 'create', 'Document creat: ITP () pentru B 815 NET', NULL, '{\"vehicul\":\"B 815 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-21\",\"fisier_original\":\"815_Talon.pdf\"}', 3, '2026-06-04 11:45:04'),
(194, 'documente', 158, 'create', 'Document creat: RCA () pentru B 815 NET', NULL, '{\"vehicul\":\"B 815 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-08-08\",\"fisier_original\":\"815_RCA.pdf\"}', 3, '2026-06-04 12:10:44'),
(195, 'documente', 159, 'create', 'Document creat: ADR () pentru B 401 NET', NULL, '{\"vehicul\":\"B 401 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-12\",\"fisier_original\":\"815_ADR.pdf\"}', 3, '2026-06-04 12:16:56'),
(196, 'documente', 160, 'create', 'Document creat: Carte () pentru B 401 NET', NULL, '{\"vehicul\":\"B 401 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-11\",\"fisier_original\":\"815_carte.pdf\"}', 3, '2026-06-04 12:17:12'),
(197, 'documente', 160, 'update', 'Document actualizat: Carte () pentru B 401 NET', '{\"vehicul\":\"B 401 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-11\",\"fisier_original\":\"815_carte.pdf\"}', '{\"vehicul\":\"B 401 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-11\",\"fisier_original\":\"401_CARTE.pdf\"}', 3, '2026-06-04 12:19:42'),
(198, 'documente', 159, 'update', 'Document actualizat: ADR () pentru B 401 NET', '{\"vehicul\":\"B 401 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-12\",\"fisier_original\":\"815_ADR.pdf\"}', '{\"vehicul\":\"B 401 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-12\",\"fisier_original\":\"401_ADR.pdf\"}', 3, '2026-06-04 12:19:59'),
(199, 'documente', 161, 'create', 'Document creat: Copie conforma () pentru B 401 NET', NULL, '{\"vehicul\":\"B 401 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_401.pdf\"}', 3, '2026-06-04 12:20:38'),
(200, 'documente', 162, 'create', 'Document creat: ITP () pentru B 401 NET', NULL, '{\"vehicul\":\"B 401 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-12\",\"fisier_original\":\"401_TALON.pdf\"}', 3, '2026-06-04 12:21:15'),
(201, 'documente', 163, 'create', 'Document creat: RCA () pentru B 401 NET', NULL, '{\"vehicul\":\"B 401 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-22\",\"fisier_original\":\"401RCA_1.pdf\"}', 3, '2026-06-04 12:21:33'),
(202, 'documente', 164, 'create', 'Document creat: Tahograf () pentru B 401 NET', NULL, '{\"vehicul\":\"B 401 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-04\",\"fisier_original\":\"401_Taho.pdf\"}', 3, '2026-06-04 12:21:51'),
(203, 'documente', 78, 'update', 'Document actualizat: Carte () pentru B 652 NET', '{\"vehicul\":\"B 652 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-11\",\"fisier_original\":\"652_Carte.pdf\"}', '{\"vehicul\":\"B 652 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"652_Carte.pdf\"}', 1, '2026-06-04 13:21:24'),
(204, 'documente', 78, 'update', 'Document actualizat: Carte () pentru B 652 NET', '{\"vehicul\":\"B 652 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"652_Carte.pdf\"}', '{\"vehicul\":\"B 652 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"652_Carte.pdf\"}', 1, '2026-06-04 13:47:19'),
(205, 'documente', 165, 'create', 'Document creat: Adr () pentru B 925 NET', NULL, '{\"vehicul\":\"B 925 NET\",\"tip_document\":\"Adr\",\"numar_document\":\"\",\"data_expirare\":\"2026-10-31\",\"fisier_original\":\"925_ADR.pdf\"}', 3, '2026-06-04 14:01:49'),
(206, 'documente', 166, 'create', 'Document creat: Carte () pentru B 925 NET', NULL, '{\"vehicul\":\"B 925 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-11\",\"fisier_original\":\"925_Carte.pdf\"}', 3, '2026-06-04 14:02:05'),
(207, 'documente', 167, 'create', 'Document creat: IPROCHIM () pentru B 925 NET', NULL, '{\"vehicul\":\"B 925 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2026-10-31\",\"fisier_original\":\"925_Iprochim.pdf\"}', 3, '2026-06-04 14:02:27'),
(208, 'documente', 168, 'create', 'Document creat: ITP () pentru B 925 NET', NULL, '{\"vehicul\":\"B 925 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-10-31\",\"fisier_original\":\"925_Talon.pdf\"}', 3, '2026-06-04 14:02:41'),
(209, 'documente', 169, 'create', 'Document creat: RCA () pentru B 925 NET', NULL, '{\"vehicul\":\"B 925 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-10-26\",\"fisier_original\":\"925_RCA_1.pdf\"}', 3, '2026-06-04 14:03:09'),
(210, 'documente', 70, 'update', 'Document actualizat: ITP () pentru B 400 NET', '{\"vehicul\":\"B 400 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-28\",\"fisier_original\":null}', '{\"vehicul\":\"B 400 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-11\",\"fisier_original\":\"925_Talon.pdf\"}', 3, '2026-06-04 14:18:58'),
(211, 'documente', 70, 'update', 'Document actualizat: ITP () pentru B 400 NET', '{\"vehicul\":\"B 400 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-11\",\"fisier_original\":\"925_Talon.pdf\"}', '{\"vehicul\":\"B 400 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-11\",\"fisier_original\":\"400_Talon_1.pdf\"}', 3, '2026-06-04 14:20:05'),
(212, 'documente', 170, 'create', 'Document creat: ADR () pentru B 400 NET', NULL, '{\"vehicul\":\"B 400 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-11\",\"fisier_original\":\"400_ADR_1.pdf\"}', 3, '2026-06-04 14:20:35'),
(213, 'documente', 171, 'create', 'Document creat: Carte () pentru B 400 NET', NULL, '{\"vehicul\":\"B 400 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"400_Carte_1.pdf\"}', 3, '2026-06-04 14:21:05'),
(214, 'documente', 172, 'create', 'Document creat: Copie conforma () pentru B 400 NET', NULL, '{\"vehicul\":\"B 400 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_400.pdf\"}', 3, '2026-06-04 14:21:29'),
(215, 'documente', 173, 'create', 'Document creat: RCA () pentru B 400 NET', NULL, '{\"vehicul\":\"B 400 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-08\",\"fisier_original\":\"400_RCA_1.pdf\"}', 3, '2026-06-04 14:21:51'),
(216, 'documente', 174, 'create', 'Document creat: Rovinieta () pentru B 400 NET', NULL, '{\"vehicul\":\"B 400 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-08-04\",\"fisier_original\":null}', 3, '2026-06-04 14:22:43'),
(217, 'documente', 175, 'create', 'Document creat: Adr () pentru B 945 NET', NULL, '{\"vehicul\":\"B 945 NET\",\"tip_document\":\"Adr\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-03\",\"fisier_original\":\"945_ADR_1.pdf\"}', 3, '2026-06-05 09:21:47'),
(218, 'documente', 176, 'create', 'Document creat: Carte () pentru B 945 NET', NULL, '{\"vehicul\":\"B 945 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"945_Carte_1.pdf\"}', 3, '2026-06-05 09:21:56'),
(219, 'documente', 177, 'create', 'Document creat: Casco () pentru B 945 NET', NULL, '{\"vehicul\":\"B 945 NET\",\"tip_document\":\"Casco\",\"numar_document\":\"\",\"data_expirare\":\"2031-03-18\",\"fisier_original\":\"945_Casco_1.pdf\"}', 3, '2026-06-05 09:22:38'),
(220, 'documente', 178, 'create', 'Document creat: IPROCHIM () pentru B 945 NET', NULL, '{\"vehicul\":\"B 945 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-02\",\"fisier_original\":\"945_Iprochim_1.pdf\"}', 3, '2026-06-05 09:22:56'),
(221, 'documente', 179, 'create', 'Document creat: ITP () pentru B 945 NET', NULL, '{\"vehicul\":\"B 945 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-03\",\"fisier_original\":\"945_Talon_1.pdf\"}', 3, '2026-06-05 09:23:16'),
(222, 'documente', 180, 'create', 'Document creat: RCA () pentru B 945 NET', NULL, '{\"vehicul\":\"B 945 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-18\",\"fisier_original\":\"945_Talon_1.pdf\"}', 3, '2026-06-05 09:23:35'),
(223, 'documente', 181, 'create', 'Document creat: ADR () pentru B 165 NET', NULL, '{\"vehicul\":\"B 165 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-08\",\"fisier_original\":\"165_ADR.pdf\"}', 3, '2026-06-05 09:26:31'),
(224, 'documente', 182, 'create', 'Document creat: Carte () pentru B 165 NET', NULL, '{\"vehicul\":\"B 165 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"165_Carte.pdf\"}', 3, '2026-06-05 09:26:58'),
(225, 'documente', 183, 'create', 'Document creat: Copie conforma () pentru B 165 NET', NULL, '{\"vehicul\":\"B 165 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_165.pdf\"}', 3, '2026-06-05 09:27:28'),
(226, 'documente', 184, 'create', 'Document creat: ITP () pentru B 165 NET', NULL, '{\"vehicul\":\"B 165 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-08\",\"fisier_original\":\"165_TALON.pdf\"}', 3, '2026-06-05 09:27:45'),
(227, 'documente', 185, 'create', 'Document creat: RCA () pentru B 165 NET', NULL, '{\"vehicul\":\"B 165 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-23\",\"fisier_original\":\"165_RCA.pdf\"}', 3, '2026-06-05 09:28:03'),
(228, 'documente', 186, 'create', 'Document creat: ITP () pentru B 165 NET', NULL, '{\"vehicul\":\"B 165 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-08\",\"fisier_original\":\"165_TALON.pdf\"}', 3, '2026-06-05 09:29:01'),
(229, 'documente', 187, 'create', 'Document creat: RCA () pentru B 165 NET', NULL, '{\"vehicul\":\"B 165 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-23\",\"fisier_original\":\"165_RCA.pdf\"}', 3, '2026-06-05 09:29:17'),
(230, 'documente', 188, 'create', 'Document creat: Rovinieta () pentru B 165 NET', NULL, '{\"vehicul\":\"B 165 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-26\",\"fisier_original\":null}', 3, '2026-06-05 09:29:35'),
(231, 'documente', 189, 'create', 'Document creat: Tahograf () pentru B 165 NET', NULL, '{\"vehicul\":\"B 165 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2028-04-27\",\"fisier_original\":\"Taho_165.pdf\"}', 3, '2026-06-05 09:29:56'),
(232, 'documente', 184, 'delete', 'Document sters: ITP () pentru B 165 NET', '{\"vehicul\":\"B 165 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-08\",\"fisier_original\":\"165_TALON.pdf\"}', NULL, 3, '2026-06-05 09:45:25'),
(233, 'documente', 185, 'delete', 'Document sters: RCA () pentru B 165 NET', '{\"vehicul\":\"B 165 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-23\",\"fisier_original\":\"165_RCA.pdf\"}', NULL, 3, '2026-06-05 09:45:38'),
(234, 'documente', 190, 'create', 'Document creat: Adr () pentru B 679 NET', NULL, '{\"vehicul\":\"B 679 NET\",\"tip_document\":\"Adr\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-18\",\"fisier_original\":\"679_ADR_2.pdf\"}', 3, '2026-06-05 09:49:44'),
(235, 'documente', 191, 'create', 'Document creat: Carte () pentru B 679 NET', NULL, '{\"vehicul\":\"B 679 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"679_Carte.pdf\"}', 3, '2026-06-05 09:49:53'),
(236, 'documente', 192, 'create', 'Document creat: Casco () pentru B 679 NET', NULL, '{\"vehicul\":\"B 679 NET\",\"tip_document\":\"Casco\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-25\",\"fisier_original\":\"POLITA_CASCO_FLOTA_2026.pdf\"}', 3, '2026-06-05 09:50:11'),
(237, 'documente', 193, 'create', 'Document creat: CNCIR () pentru B 679 NET', NULL, '{\"vehicul\":\"B 679 NET\",\"tip_document\":\"CNCIR\",\"numar_document\":\"\",\"data_expirare\":\"2028-03-15\",\"fisier_original\":\"679_CNCIR_1.pdf\"}', 3, '2026-06-05 09:50:32'),
(238, 'documente', 194, 'create', 'Document creat: IPROCHIM () pentru B 679 NET', NULL, '{\"vehicul\":\"B 679 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-18\",\"fisier_original\":\"679_Iprocim.pdf\"}', 3, '2026-06-05 09:51:05'),
(239, 'documente', 195, 'create', 'Document creat: ITP () pentru B 679 NET', NULL, '{\"vehicul\":\"B 679 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-18\",\"fisier_original\":\"679_Talon.pdf\"}', 3, '2026-06-05 09:51:22'),
(240, 'documente', 196, 'create', 'Document creat: RCA () pentru B 679 NET', NULL, '{\"vehicul\":\"B 679 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-08-22\",\"fisier_original\":\"679_RCA.pdf\"}', 3, '2026-06-05 09:51:44'),
(241, 'documente', 197, 'create', 'Document creat: Adr () pentru B 680 NET', NULL, '{\"vehicul\":\"B 680 NET\",\"tip_document\":\"Adr\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-18\",\"fisier_original\":\"679_ADR_2.pdf\"}', 3, '2026-06-05 10:07:22'),
(242, 'documente', 198, 'create', 'Document creat: Carte () pentru B 680 NET', NULL, '{\"vehicul\":\"B 680 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"680_Carte.pdf\"}', 3, '2026-06-05 10:07:42'),
(243, 'documente', 197, 'update', 'Document actualizat: Adr () pentru B 680 NET', '{\"vehicul\":\"B 680 NET\",\"tip_document\":\"Adr\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-18\",\"fisier_original\":\"679_ADR_2.pdf\"}', '{\"vehicul\":\"B 680 NET\",\"tip_document\":\"Adr\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-18\",\"fisier_original\":\"680_ADR.pdf\"}', 3, '2026-06-05 10:11:05'),
(244, 'documente', 199, 'create', 'Document creat: Casco () pentru B 680 NET', NULL, '{\"vehicul\":\"B 680 NET\",\"tip_document\":\"Casco\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-25\",\"fisier_original\":\"POLITA_CASCO_FLOTA_2026.pdf\"}', 3, '2026-06-05 10:12:25'),
(245, 'documente', 200, 'create', 'Document creat: CNCIR () pentru B 680 NET', NULL, '{\"vehicul\":\"B 680 NET\",\"tip_document\":\"CNCIR\",\"numar_document\":\"\",\"data_expirare\":\"2028-03-15\",\"fisier_original\":\"680_CNCIR_1.pdf\"}', 3, '2026-06-05 10:12:53'),
(246, 'documente', 201, 'create', 'Document creat: IPROCHIM () pentru B 680 NET', NULL, '{\"vehicul\":\"B 680 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-18\",\"fisier_original\":\"680_Iprochim_1.pdf\"}', 3, '2026-06-05 10:13:10'),
(247, 'documente', 202, 'create', 'Document creat: ITP () pentru B 680 NET', NULL, '{\"vehicul\":\"B 680 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-18\",\"fisier_original\":\"680_Talon.pdf\"}', 3, '2026-06-05 10:13:25'),
(248, 'documente', 203, 'create', 'Document creat: RCA () pentru B 680 NET', NULL, '{\"vehicul\":\"B 680 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-10-06\",\"fisier_original\":\"680_RCA.pdf\"}', 3, '2026-06-05 10:13:53'),
(249, 'documente', 204, 'create', 'Document creat: ADR () pentru B 678 NET', NULL, '{\"vehicul\":\"B 678 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-19\",\"fisier_original\":\"ADR_678.pdf\"}', 3, '2026-06-05 11:41:01'),
(250, 'documente', 205, 'create', 'Document creat: Carte () pentru B 678 NET', NULL, '{\"vehicul\":\"B 678 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"678_Carte.pdf\"}', 3, '2026-06-05 11:41:31'),
(251, 'documente', 206, 'create', 'Document creat: CASCO () pentru B 678 NET', NULL, '{\"vehicul\":\"B 678 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-04\",\"fisier_original\":\"678_Casco_1.pdf\"}', 3, '2026-06-05 11:41:54'),
(252, 'documente', 207, 'create', 'Document creat: Copie conforma () pentru B 678 NET', NULL, '{\"vehicul\":\"B 678 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_678.pdf\"}', 3, '2026-06-05 11:42:32'),
(253, 'documente', 208, 'create', 'Document creat: ITP () pentru B 678 NET', NULL, '{\"vehicul\":\"B 678 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-19\",\"fisier_original\":\"ITP_678.pdf\"}', 3, '2026-06-05 11:43:07'),
(254, 'documente', 209, 'create', 'Document creat: RCA () pentru B 678 NET', NULL, '{\"vehicul\":\"B 678 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-06-21\",\"fisier_original\":\"RCA_678_1.pdf\"}', 3, '2026-06-05 11:43:56'),
(255, 'documente', 210, 'create', 'Document creat: Rovinieta () pentru B 678 NET', NULL, '{\"vehicul\":\"B 678 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-04\",\"fisier_original\":null}', 3, '2026-06-05 11:44:18'),
(256, 'documente', 211, 'create', 'Document creat: Tahograf () pentru B 678 NET', NULL, '{\"vehicul\":\"B 678 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-21\",\"fisier_original\":\"678_Taho.pdf\"}', 3, '2026-06-05 11:44:47'),
(257, 'documente', 212, 'create', 'Document creat: ADR () pentru B 199 NET', NULL, '{\"vehicul\":\"B 199 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-17\",\"fisier_original\":\"199_ADR.pdf\"}', 3, '2026-06-05 11:47:55'),
(258, 'documente', 213, 'create', 'Document creat: Brml () pentru B 199 NET', NULL, '{\"vehicul\":\"B 199 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2026-08-18\",\"fisier_original\":\"199_BRML.pdf\"}', 3, '2026-06-05 11:48:18'),
(259, 'documente', 214, 'create', 'Document creat: Carte () pentru B 199 NET', NULL, '{\"vehicul\":\"B 199 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"199_Carte.pdf\"}', 3, '2026-06-05 11:49:46'),
(260, 'documente', 215, 'create', 'Document creat: Copie conforma () pentru B 199 NET', NULL, '{\"vehicul\":\"B 199 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_199.pdf\"}', 3, '2026-06-05 11:50:39'),
(261, 'documente', 216, 'create', 'Document creat: IPROCHIM () pentru B 199 NET', NULL, '{\"vehicul\":\"B 199 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-17\",\"fisier_original\":\"199_Iprochim.pdf\"}', 3, '2026-06-05 11:51:06'),
(262, 'documente', 217, 'create', 'Document creat: ITP () pentru B 199 NET', NULL, '{\"vehicul\":\"B 199 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-17\",\"fisier_original\":\"199_Talon.pdf\"}', 3, '2026-06-05 11:51:25'),
(263, 'documente', 218, 'create', 'Document creat: RCA () pentru B 199 NET', NULL, '{\"vehicul\":\"B 199 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-08-22\",\"fisier_original\":\"199_RCA.pdf\"}', 3, '2026-06-05 11:51:44'),
(264, 'documente', 219, 'create', 'Document creat: Rovinieta () pentru B 199 NET', NULL, '{\"vehicul\":\"B 199 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-22\",\"fisier_original\":null}', 3, '2026-06-05 11:52:06'),
(265, 'documente', 220, 'create', 'Document creat: Tahograf () pentru B 199 NET', NULL, '{\"vehicul\":\"B 199 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2027-09-03\",\"fisier_original\":\"199_Taho.pdf\"}', 3, '2026-06-05 11:52:29'),
(266, 'documente', 221, 'create', 'Document creat: Tuv () pentru B 199 NET', NULL, '{\"vehicul\":\"B 199 NET\",\"tip_document\":\"Tuv\",\"numar_document\":\"\",\"data_expirare\":\"2027-06-10\",\"fisier_original\":\"199_TUV.pdf\"}', 3, '2026-06-05 11:52:51'),
(267, 'documente', 222, 'create', 'Document creat: ADR () pentru B 189 NET', NULL, '{\"vehicul\":\"B 189 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-23\",\"fisier_original\":\"189_ADR.pdf\"}', 3, '2026-06-05 12:10:49'),
(268, 'documente', 223, 'create', 'Document creat: Brml () pentru B 189 NET', NULL, '{\"vehicul\":\"B 189 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2026-11-08\",\"fisier_original\":\"189_BRML.pdf\"}', 3, '2026-06-05 12:37:57'),
(269, 'documente', 224, 'create', 'Document creat: Carte () pentru B 189 NET', NULL, '{\"vehicul\":\"B 189 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"189_Carte.pdf\"}', 3, '2026-06-05 12:38:11'),
(270, 'documente', 225, 'create', 'Document creat: Copie conforma () pentru B 189 NET', NULL, '{\"vehicul\":\"B 189 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_189.pdf\"}', 3, '2026-06-05 12:39:31'),
(271, 'documente', 226, 'create', 'Document creat: IPROCHIM () pentru B 189 NET', NULL, '{\"vehicul\":\"B 189 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-12\",\"fisier_original\":\"189_Iprochim.pdf\"}', 3, '2026-06-05 12:39:59'),
(272, 'documente', 227, 'create', 'Document creat: ITP () pentru B 189 NET', NULL, '{\"vehicul\":\"B 189 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-23\",\"fisier_original\":\"189_Talon.pdf\"}', 3, '2026-06-05 12:40:44'),
(273, 'documente', 228, 'create', 'Document creat: RCA () pentru B 189 NET', NULL, '{\"vehicul\":\"B 189 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-23\",\"fisier_original\":\"189_RCA.pdf\"}', 3, '2026-06-05 12:41:26'),
(274, 'documente', 229, 'create', 'Document creat: Rovinieta () pentru B 189 NET', NULL, '{\"vehicul\":\"B 189 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-08-04\",\"fisier_original\":null}', 3, '2026-06-05 12:41:50'),
(275, 'documente', 230, 'create', 'Document creat: Tahograf () pentru B 189 NET', NULL, '{\"vehicul\":\"B 189 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2026-10-04\",\"fisier_original\":\"189_Tajo.pdf\"}', 3, '2026-06-05 12:42:10'),
(276, 'documente', 231, 'create', 'Document creat: Tuv () pentru B 189 NET', NULL, '{\"vehicul\":\"B 189 NET\",\"tip_document\":\"Tuv\",\"numar_document\":\"\",\"data_expirare\":\"2028-09-01\",\"fisier_original\":\"189_TUV.pdf\"}', 3, '2026-06-05 12:42:29'),
(277, 'documente', 232, 'create', 'Document creat: ADR () pentru B 439 NET', NULL, '{\"vehicul\":\"B 439 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-05\",\"fisier_original\":\"439_ADR.pdf\"}', 3, '2026-06-05 12:46:26'),
(278, 'documente', 233, 'create', 'Document creat: Carte () pentru B 439 NET', NULL, '{\"vehicul\":\"B 439 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"439_Carte.pdf\"}', 3, '2026-06-05 12:46:43'),
(279, 'documente', 234, 'create', 'Document creat: CASCO () pentru B 439 NET', NULL, '{\"vehicul\":\"B 439 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-25\",\"fisier_original\":\"POLITA_CASCO_FLOTA_2026.pdf\"}', 3, '2026-06-05 12:47:00'),
(280, 'documente', 235, 'create', 'Document creat: Copie conforma () pentru B 439 NET', NULL, '{\"vehicul\":\"B 439 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"439_Copie_Conf.pdf\"}', 3, '2026-06-05 12:47:29'),
(281, 'documente', 236, 'create', 'Document creat: IPROCHIM () pentru B 439 NET', NULL, '{\"vehicul\":\"B 439 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-05\",\"fisier_original\":\"439_IPROCHIM.pdf\"}', 3, '2026-06-05 12:47:51'),
(282, 'documente', 237, 'create', 'Document creat: ITP () pentru B 439 NET', NULL, '{\"vehicul\":\"B 439 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-05\",\"fisier_original\":\"439_Talon.pdf\"}', 3, '2026-06-05 12:48:08'),
(283, 'documente', 238, 'create', 'Document creat: RCA () pentru B 439 NET', NULL, '{\"vehicul\":\"B 439 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-11-27\",\"fisier_original\":\"439_RCA.pdf\"}', 3, '2026-06-05 12:48:46'),
(284, 'documente', 239, 'create', 'Document creat: Rovinieta () pentru B 439 NET', NULL, '{\"vehicul\":\"B 439 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-21\",\"fisier_original\":null}', 3, '2026-06-05 13:04:57'),
(285, 'documente', 239, 'update', 'Document actualizat: Rovinieta () pentru B 439 NET', '{\"vehicul\":\"B 439 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-21\",\"fisier_original\":null}', '{\"vehicul\":\"B 439 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-21\",\"fisier_original\":null}', 3, '2026-06-05 13:06:22'),
(286, 'documente', 240, 'create', 'Document creat: ADR () pentru B 433 NET', NULL, '{\"vehicul\":\"B 433 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-19\",\"fisier_original\":\"433_ADR.pdf\"}', 3, '2026-06-05 13:14:17'),
(287, 'documente', 241, 'create', 'Document creat: Brml () pentru B 433 NET', NULL, '{\"vehicul\":\"B 433 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-26\",\"fisier_original\":\"433_BRML.pdf\"}', 3, '2026-06-05 13:14:45'),
(288, 'documente', 242, 'create', 'Document creat: Carte () pentru B 433 NET', NULL, '{\"vehicul\":\"B 433 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"Carte_433.pdf\"}', 3, '2026-06-05 13:17:44'),
(289, 'documente', 243, 'create', 'Document creat: CASCO () pentru B 433 NET', NULL, '{\"vehicul\":\"B 433 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-25\",\"fisier_original\":\"POLITA_CASCO_FLOTA_2026.pdf\"}', 3, '2026-06-05 13:18:08'),
(290, 'documente', 244, 'create', 'Document creat: Copie conforma () pentru B 433 NET', NULL, '{\"vehicul\":\"B 433 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_433.pdf\"}', 3, '2026-06-05 13:18:51'),
(291, 'documente', 245, 'create', 'Document creat: IPROCHIM () pentru B 433 NET', NULL, '{\"vehicul\":\"B 433 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-19\",\"fisier_original\":\"433_Iprochim.pdf\"}', 3, '2026-06-05 13:19:12'),
(292, 'documente', 246, 'create', 'Document creat: ITP () pentru B 433 NET', NULL, '{\"vehicul\":\"B 433 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-19\",\"fisier_original\":\"433_Talon.pdf\"}', 3, '2026-06-05 13:19:29'),
(293, 'documente', 247, 'create', 'Document creat: RCA () pentru B 433 NET', NULL, '{\"vehicul\":\"B 433 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-11-17\",\"fisier_original\":\"433_RCA.pdf\"}', 3, '2026-06-05 13:19:47'),
(294, 'documente', 248, 'create', 'Document creat: Rovinieta () pentru B 433 NET', NULL, '{\"vehicul\":\"B 433 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-01\",\"fisier_original\":null}', 3, '2026-06-05 13:21:02'),
(295, 'documente', 249, 'create', 'Document creat: Tahograf () pentru B 433 NET', NULL, '{\"vehicul\":\"B 433 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2027-11-13\",\"fisier_original\":\"433_Taho.pdf\"}', 3, '2026-06-05 13:21:19'),
(296, 'documente', 250, 'create', 'Document creat: Tuv () pentru B 433 NET', NULL, '{\"vehicul\":\"B 433 NET\",\"tip_document\":\"Tuv\",\"numar_document\":\"\",\"data_expirare\":\"2029-01-31\",\"fisier_original\":\"433_TUV..pdf\"}', 3, '2026-06-05 13:21:36'),
(297, 'documente', 251, 'create', 'Document creat: ADR () pentru B 295 NET', NULL, '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-16\",\"fisier_original\":\"295_ADR.pdf\"}', 3, '2026-06-05 13:25:52'),
(298, 'documente', 252, 'create', 'Document creat: Brml () pentru B 295 NET', NULL, '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2028-03-31\",\"fisier_original\":\"295_BRML.pdf\"}', 3, '2026-06-05 13:26:12'),
(299, 'documente', 253, 'create', 'Document creat: Brml () pentru B 295 NET', NULL, '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2028-03-31\",\"fisier_original\":\"295_BRML.pdf\"}', 3, '2026-06-05 13:27:55'),
(300, 'documente', 254, 'create', 'Document creat: Carte () pentru B 295 NET', NULL, '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"295_Carte.pdf\"}', 3, '2026-06-05 13:28:03'),
(301, 'documente', 255, 'create', 'Document creat: Copie conforma () pentru B 295 NET', NULL, '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_295.pdf\"}', 3, '2026-06-05 13:28:38'),
(302, 'documente', 256, 'create', 'Document creat: IPROCHIM () pentru B 295 NET', NULL, '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-16\",\"fisier_original\":\"295_Iprochim.pdf\"}', 3, '2026-06-05 13:29:01'),
(303, 'documente', 257, 'create', 'Document creat: ITP () pentru B 295 NET', NULL, '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-16\",\"fisier_original\":\"295_Talon.pdf\"}', 3, '2026-06-05 13:29:18'),
(304, 'documente', 258, 'create', 'Document creat: RCA () pentru B 295 NET', NULL, '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-09\",\"fisier_original\":\"295_RCA.pdf\"}', 3, '2026-06-05 13:29:39'),
(305, 'documente', 259, 'create', 'Document creat: Rovinieta () pentru B 295 NET', NULL, '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2027-04-14\",\"fisier_original\":null}', 3, '2026-06-05 13:30:09'),
(306, 'documente', 260, 'create', 'Document creat: Tahograf () pentru B 295 NET', NULL, '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2027-04-03\",\"fisier_original\":\"295_TAHOGRAF_1.pdf\"}', 3, '2026-06-05 13:30:35'),
(307, 'documente', 261, 'create', 'Document creat: CNCIR () pentru B 295 NET', NULL, '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"CNCIR\",\"numar_document\":\"\",\"data_expirare\":\"2028-01-14\",\"fisier_original\":\"295_CNCIR.pdf\"}', 3, '2026-06-05 13:31:06'),
(308, 'documente', 262, 'create', 'Document creat: ADR () pentru B232NET', NULL, '{\"vehicul\":\"B232NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-11\",\"fisier_original\":\"232_ADR.pdf\"}', 3, '2026-06-05 14:00:29'),
(309, 'documente', 263, 'create', 'Document creat: Brml () pentru B232NET', NULL, '{\"vehicul\":\"B232NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2026-11-08\",\"fisier_original\":\"232_BRML.pdf\"}', 3, '2026-06-05 14:00:52'),
(310, 'documente', 264, 'create', 'Document creat: Carte () pentru B232NET', NULL, '{\"vehicul\":\"B232NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"232_Carte.pdf\"}', 3, '2026-06-05 14:01:12'),
(311, 'documente', 265, 'create', 'Document creat: CASCO () pentru B232NET', NULL, '{\"vehicul\":\"B232NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-27\",\"fisier_original\":\"POLITA_CASCO_FLOTA_2026_2.pdf\"}', 3, '2026-06-05 14:01:54'),
(312, 'documente', 266, 'create', 'Document creat: Copie conforma () pentru B232NET', NULL, '{\"vehicul\":\"B232NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_232.pdf\"}', 3, '2026-06-05 14:02:40'),
(313, 'documente', 267, 'create', 'Document creat: IPROCHIM () pentru B232NET', NULL, '{\"vehicul\":\"B232NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2027-06-09\",\"fisier_original\":\"232-IPRO_1.pdf\"}', 3, '2026-06-05 14:04:56'),
(314, 'documente', 268, 'create', 'Document creat: ITP () pentru B232NET', NULL, '{\"vehicul\":\"B232NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-11\",\"fisier_original\":\"232_Talon.pdf\"}', 3, '2026-06-05 14:05:17'),
(315, 'documente', 269, 'create', 'Document creat: RCA () pentru B232NET', NULL, '{\"vehicul\":\"B232NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-22\",\"fisier_original\":\"232_RCA_1.pdf\"}', 3, '2026-06-05 14:05:59'),
(316, 'documente', 270, 'create', 'Document creat: Rovinieta () pentru B232NET', NULL, '{\"vehicul\":\"B232NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-10-16\",\"fisier_original\":null}', 3, '2026-06-05 14:06:45'),
(317, 'documente', 271, 'create', 'Document creat: Tahograf () pentru B232NET', NULL, '{\"vehicul\":\"B232NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2026-08-09\",\"fisier_original\":\"232_Taho.pdf\"}', 3, '2026-06-05 14:07:08'),
(318, 'documente', 272, 'create', 'Document creat: Tuv () pentru B232NET', NULL, '{\"vehicul\":\"B232NET\",\"tip_document\":\"Tuv\",\"numar_document\":\"\",\"data_expirare\":\"2029-06-30\",\"fisier_original\":\"232TUV.pdf\"}', 3, '2026-06-05 14:07:24'),
(319, 'documente', 273, 'create', 'Document creat: ADR () pentru B235NET', NULL, '{\"vehicul\":\"B235NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-22\",\"fisier_original\":\"232_ADR.pdf\"}', 3, '2026-06-05 14:15:11'),
(320, 'documente', 274, 'create', 'Document creat: Brml () pentru B235NET', NULL, '{\"vehicul\":\"B235NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2026-11-08\",\"fisier_original\":\"Brml_235.pdf\"}', 3, '2026-06-05 14:18:42'),
(321, 'documente', 275, 'create', 'Document creat: Carte () pentru B235NET', NULL, '{\"vehicul\":\"B235NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"235_Carte.pdf\"}', 3, '2026-06-05 14:19:01'),
(322, 'documente', 276, 'create', 'Document creat: Copie conforma () pentru B235NET', NULL, '{\"vehicul\":\"B235NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"235_Copie_confotma.pdf\"}', 3, '2026-06-05 14:20:45'),
(323, 'documente', 277, 'create', 'Document creat: IPROCHIM () pentru B235NET', NULL, '{\"vehicul\":\"B235NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-12\",\"fisier_original\":\"235_Iprochim.pdf\"}', 3, '2026-06-05 14:21:14'),
(324, 'documente', 278, 'create', 'Document creat: ITP () pentru B235NET', NULL, '{\"vehicul\":\"B235NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-22\",\"fisier_original\":\"235_Talon.pdf\"}', 3, '2026-06-05 14:21:29'),
(325, 'documente', 279, 'create', 'Document creat: RCA () pentru B235NET', NULL, '{\"vehicul\":\"B235NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-14\",\"fisier_original\":\"235_RCA.pdf\"}', 3, '2026-06-05 14:21:48');
INSERT INTO `audit_log` (`id`, `modul`, `record_id`, `actiune`, `descriere`, `before_data`, `after_data`, `user_id`, `created_at`) VALUES
(326, 'documente', 53, 'update', 'Document actualizat: Rovinieta () pentru B 235 NET', '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-10\",\"fisier_original\":null}', '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-26\",\"fisier_original\":null}', 3, '2026-06-05 14:35:11'),
(327, 'documente', 52, 'update', 'Document actualizat: RCA () pentru B 235 NET', '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-02\",\"fisier_original\":null}', '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-14\",\"fisier_original\":\"235_RCA.pdf\"}', 3, '2026-06-05 14:36:22'),
(328, 'documente', 14, 'delete', 'Document sters: Rovinieta () pentru B 218 NET', '{\"vehicul\":\"B 218 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-27\",\"fisier_original\":\"235_Carte.pdf\"}', NULL, 3, '2026-06-05 14:37:30'),
(329, 'documente', 13, 'delete', 'Document sters: ITP () pentru B 218 NET', '{\"vehicul\":\"B 218 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-20\",\"fisier_original\":\"235_Certificat_ITP.pdf\"}', NULL, 3, '2026-06-05 14:37:48'),
(330, 'documente', 9, 'delete', 'Document sters: RCA () pentru B 325 NET', '{\"vehicul\":\"B 325 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-14\",\"fisier_original\":\"235_RCA.pdf\"}', NULL, 3, '2026-06-05 14:38:24'),
(331, 'documente', 54, 'delete', 'Document sters: ITP () pentru B 235 NET', '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-23\",\"fisier_original\":null}', NULL, 3, '2026-06-05 14:38:54'),
(332, 'documente', 10, 'delete', 'Document sters: ITP () pentru B 325 NET', '{\"vehicul\":\"B 325 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-22\",\"fisier_original\":\"235_Certificat_ITP.pdf\"}', NULL, 3, '2026-06-05 14:39:49'),
(333, 'documente', 11, 'delete', 'Document sters: Rovinieta () pentru B 325 NET', '{\"vehicul\":\"B 325 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-16\",\"fisier_original\":\"235_RCA.pdf\"}', NULL, 3, '2026-06-05 14:40:00'),
(334, 'documente', 280, 'create', 'Document creat: ITP () pentru B 235 NET', NULL, '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-22\",\"fisier_original\":\"235_Talon.pdf\"}', 3, '2026-06-05 14:40:48'),
(335, 'documente', 281, 'create', 'Document creat: Brml () pentru B 235 NET', NULL, '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2026-11-08\",\"fisier_original\":\"Brml_235.pdf\"}', 3, '2026-06-05 14:42:09'),
(336, 'documente', 282, 'create', 'Document creat: Carte () pentru B 235 NET', NULL, '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"235_Carte.pdf\"}', 3, '2026-06-05 14:42:20'),
(337, 'documente', 283, 'create', 'Document creat: Copie conforma () pentru B 235 NET', NULL, '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2026-02-11\",\"fisier_original\":\"235_Copie_confotma.pdf\"}', 3, '2026-06-05 14:43:02'),
(338, 'documente', 284, 'create', 'Document creat: IPROCHIM () pentru B 235 NET', NULL, '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-12\",\"fisier_original\":\"235_Iprochim.pdf\"}', 3, '2026-06-05 14:43:18'),
(339, 'documente', 285, 'create', 'Document creat: RCA () pentru B 235 NET', NULL, '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-14\",\"fisier_original\":\"235_RCA.pdf\"}', 3, '2026-06-05 14:43:41'),
(340, 'documente', 286, 'create', 'Document creat: Tahograf () pentru B 235 NET', NULL, '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"235_Taho.pdf\"}', 3, '2026-06-05 14:44:24'),
(341, 'documente', 69, 'update', 'Document actualizat: Adr () pentru B 935 NET', '{\"vehicul\":\"B 935 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-30\",\"fisier_original\":null}', '{\"vehicul\":\"B 935 NET\",\"tip_document\":\"Adr\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-11\",\"fisier_original\":\"935_ADR_1.pdf\"}', 3, '2026-06-05 15:01:45'),
(342, 'documente', 68, 'update', 'Document actualizat: ITP () pentru B 935 NET', '{\"vehicul\":\"B 935 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-10-28\",\"fisier_original\":null}', '{\"vehicul\":\"B 935 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-11\",\"fisier_original\":\"935_Talon_1.pdf\"}', 3, '2026-06-05 15:02:38'),
(343, 'documente', 67, 'update', 'Document actualizat: RCA () pentru B 935 NET', '{\"vehicul\":\"B 935 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-30\",\"fisier_original\":null}', '{\"vehicul\":\"B 935 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-29\",\"fisier_original\":\"935_RCA_1.pdf\"}', 3, '2026-06-05 15:03:38'),
(344, 'documente', 287, 'create', 'Document creat: Carte () pentru B 935 NET', NULL, '{\"vehicul\":\"B 935 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"935_CARTE.pdf\"}', 3, '2026-06-05 15:04:34'),
(345, 'documente', 288, 'create', 'Document creat: IPROCHIM () pentru B 935 NET', NULL, '{\"vehicul\":\"B 935 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-11\",\"fisier_original\":\"935_Iprochim_1.pdf\"}', 3, '2026-06-05 15:05:49'),
(346, 'documente', 64, 'delete', 'Document sters: RCA () pentru B 105 NET', '{\"vehicul\":\"B 105 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-29\",\"fisier_original\":null}', NULL, 3, '2026-06-05 15:08:04'),
(347, 'documente', 66, 'delete', 'Document sters: Rovinieta () pentru B 105 NET', '{\"vehicul\":\"B 105 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-08-30\",\"fisier_original\":null}', NULL, 3, '2026-06-05 15:08:16'),
(348, 'documente', 65, 'delete', 'Document sters: ITP () pentru B 105 NET', '{\"vehicul\":\"B 105 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-29\",\"fisier_original\":null}', NULL, 3, '2026-06-05 15:08:26'),
(349, 'documente', 289, 'create', 'Document creat: ADR () pentru B 105 NET', NULL, '{\"vehicul\":\"B 105 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-05\",\"fisier_original\":\"105_ADR.pdf\"}', 3, '2026-06-05 15:10:58'),
(350, 'documente', 290, 'create', 'Document creat: Carte () pentru B 105 NET', NULL, '{\"vehicul\":\"B 105 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"105_Carte.pdf\"}', 3, '2026-06-05 15:11:12'),
(351, 'documente', 291, 'create', 'Document creat: Copie conforma () pentru B 105 NET', NULL, '{\"vehicul\":\"B 105 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_105.pdf\"}', 3, '2026-06-05 15:11:31'),
(352, 'documente', 292, 'create', 'Document creat: ITP () pentru B 105 NET', NULL, '{\"vehicul\":\"B 105 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-05\",\"fisier_original\":null}', 3, '2026-06-05 15:11:41'),
(353, 'documente', 292, 'update', 'Document actualizat: ITP () pentru B 105 NET', '{\"vehicul\":\"B 105 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-05\",\"fisier_original\":null}', '{\"vehicul\":\"B 105 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-05\",\"fisier_original\":\"105_Talon.pdf\"}', 3, '2026-06-05 15:12:00'),
(354, 'documente', 293, 'create', 'Document creat: RCA () pentru B 105 NET', NULL, '{\"vehicul\":\"B 105 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-16\",\"fisier_original\":\"105_RCA.pdf\"}', 3, '2026-06-05 15:13:05'),
(355, 'documente', 294, 'create', 'Document creat: Rovinieta () pentru B 105 NET', NULL, '{\"vehicul\":\"B 105 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-07\",\"fisier_original\":null}', 3, '2026-06-05 15:13:23'),
(356, 'documente', 295, 'create', 'Document creat: Tahograf () pentru B 105 NET', NULL, '{\"vehicul\":\"B 105 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-05\",\"fisier_original\":\"105_Taho.pdf\"}', 3, '2026-06-05 15:14:27'),
(357, 'documente', 62, 'delete', 'Document sters: RCA () pentru B 605 NET', '{\"vehicul\":\"B 605 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-25\",\"fisier_original\":null}', NULL, 3, '2026-06-05 15:15:18'),
(358, 'documente', 61, 'delete', 'Document sters: Rovinieta () pentru B 605 NET', '{\"vehicul\":\"B 605 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-29\",\"fisier_original\":null}', NULL, 3, '2026-06-05 15:15:30'),
(359, 'documente', 63, 'delete', 'Document sters: ITP () pentru B 605 NET', '{\"vehicul\":\"B 605 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-30\",\"fisier_original\":null}', NULL, 3, '2026-06-05 15:15:42'),
(360, 'documente', 296, 'create', 'Document creat: ADR () pentru B 605 NET', NULL, '{\"vehicul\":\"B 605 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-17\",\"fisier_original\":\"105_ADR.pdf\"}', 3, '2026-06-05 15:26:08'),
(361, 'documente', 297, 'create', 'Document creat: Carte () pentru B 605 NET', NULL, '{\"vehicul\":\"B 605 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"605_Carte.pdf\"}', 3, '2026-06-05 15:26:36'),
(362, 'documente', 296, 'update', 'Document actualizat: ADR () pentru B 605 NET', '{\"vehicul\":\"B 605 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-17\",\"fisier_original\":\"105_ADR.pdf\"}', '{\"vehicul\":\"B 605 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-17\",\"fisier_original\":\"605_ADR.pdf\"}', 3, '2026-06-05 15:27:02'),
(363, 'documente', 298, 'create', 'Document creat: Copie conforma () pentru B 605 NET', NULL, '{\"vehicul\":\"B 605 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_605.pdf\"}', 3, '2026-06-05 15:27:40'),
(364, 'documente', 299, 'create', 'Document creat: ITP () pentru B 605 NET', NULL, '{\"vehicul\":\"B 605 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-17\",\"fisier_original\":\"605_Talon.pdf\"}', 3, '2026-06-05 15:28:04'),
(365, 'documente', 300, 'create', 'Document creat: RCA () pentru B 605 NET', NULL, '{\"vehicul\":\"B 605 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-22\",\"fisier_original\":\"605_RCA.pdf\"}', 3, '2026-06-05 15:28:32'),
(366, 'documente', 301, 'create', 'Document creat: Rovinieta () pentru B 605 NET', NULL, '{\"vehicul\":\"B 605 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-04\",\"fisier_original\":null}', 3, '2026-06-05 15:28:54'),
(367, 'documente', 302, 'create', 'Document creat: Tahograf () pentru B 605 NET', NULL, '{\"vehicul\":\"B 605 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-24\",\"fisier_original\":\"605_Taho.pdf\"}', 3, '2026-06-05 15:29:16'),
(368, 'documente', 27, 'delete', 'Document sters: ITP () pentru B 435 NET', '{\"vehicul\":\"B 435 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-25\",\"fisier_original\":null}', NULL, 3, '2026-06-05 15:31:05'),
(369, 'documente', 25, 'delete', 'Document sters: Rovinieta () pentru B 435 NET', '{\"vehicul\":\"B 435 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-25\",\"fisier_original\":null}', NULL, 3, '2026-06-05 15:31:15'),
(370, 'documente', 26, 'delete', 'Document sters: RCA () pentru B 435 NET', '{\"vehicul\":\"B 435 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-20\",\"fisier_original\":null}', NULL, 3, '2026-06-05 15:31:25'),
(371, 'documente', 303, 'create', 'Document creat: ADR () pentru B 435 NET', NULL, '{\"vehicul\":\"B 435 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-05\",\"fisier_original\":\"435_ADR_1.pdf\"}', 3, '2026-06-08 08:58:37'),
(372, 'documente', 304, 'create', 'Document creat: Brml () pentru B 435 NET', NULL, '{\"vehicul\":\"B 435 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-25\",\"fisier_original\":\"435_BRML.pdf\"}', 3, '2026-06-08 08:58:54'),
(373, 'documente', 305, 'create', 'Document creat: Carte () pentru B 435 NET', NULL, '{\"vehicul\":\"B 435 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"435_Carte.pdf\"}', 3, '2026-06-08 08:59:05'),
(374, 'documente', 306, 'create', 'Document creat: CASCO () pentru B 435 NET', NULL, '{\"vehicul\":\"B 435 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-25\",\"fisier_original\":\"POLITA_CASCO_FLOTA_2026.pdf\"}', 3, '2026-06-08 09:17:41'),
(375, 'documente', 307, 'create', 'Document creat: Copie conforma () pentru B 435 NET', NULL, '{\"vehicul\":\"B 435 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_435.pdf\"}', 3, '2026-06-08 09:37:03'),
(376, 'documente', 308, 'create', 'Document creat: IPROCHIM () pentru B 435 NET', NULL, '{\"vehicul\":\"B 435 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-06\",\"fisier_original\":\"435_Iprochim.pdf\"}', 3, '2026-06-08 09:37:34'),
(377, 'documente', 309, 'create', 'Document creat: ITP () pentru B 435 NET', NULL, '{\"vehicul\":\"B 435 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-05\",\"fisier_original\":\"435_Talon_1.pdf\"}', 3, '2026-06-08 09:37:56'),
(378, 'documente', 310, 'create', 'Document creat: RCA () pentru B 435 NET', NULL, '{\"vehicul\":\"B 435 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-06\",\"fisier_original\":\"435_RCA_.pdf\"}', 3, '2026-06-08 09:38:33'),
(379, 'documente', 311, 'create', 'Document creat: Rovinieta () pentru B 435 NET', NULL, '{\"vehicul\":\"B 435 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-29\",\"fisier_original\":null}', 3, '2026-06-08 09:38:56'),
(380, 'documente', 312, 'create', 'Document creat: Tahograf () pentru B 435 NET', NULL, '{\"vehicul\":\"B 435 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-11\",\"fisier_original\":\"435_TAHO.jpeg\"}', 3, '2026-06-08 09:39:18'),
(381, 'documente', 313, 'create', 'Document creat: Tuv () pentru B 435 NET', NULL, '{\"vehicul\":\"B 435 NET\",\"tip_document\":\"Tuv\",\"numar_document\":\"\",\"data_expirare\":\"2029-02-28\",\"fisier_original\":\"435_TUV_1.pdf\"}', 3, '2026-06-08 09:39:56'),
(382, 'documente', 24, 'delete', 'Document sters: ITP () pentru B 677 NET', '{\"vehicul\":\"B 677 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-27\",\"fisier_original\":null}', NULL, 3, '2026-06-08 09:57:02'),
(383, 'documente', 22, 'delete', 'Document sters: RCA () pentru B 677 NET', '{\"vehicul\":\"B 677 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-27\",\"fisier_original\":null}', NULL, 3, '2026-06-08 09:57:16'),
(384, 'documente', 23, 'delete', 'Document sters: Rovinieta () pentru B 677 NET', '{\"vehicul\":\"B 677 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-02\",\"fisier_original\":null}', NULL, 3, '2026-06-08 09:57:33'),
(385, 'documente', 314, 'create', 'Document creat: ADR () pentru B 677 NET', NULL, '{\"vehicul\":\"B 677 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-20\",\"fisier_original\":\"677_Adr_1.pdf\"}', 3, '2026-06-08 10:37:17'),
(386, 'documente', 315, 'create', 'Document creat: Carte () pentru B 677 NET', NULL, '{\"vehicul\":\"B 677 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"677_Carte.pdf\"}', 3, '2026-06-08 10:37:27'),
(387, 'documente', 316, 'create', 'Document creat: CASCO () pentru B 677 NET', NULL, '{\"vehicul\":\"B 677 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-04\",\"fisier_original\":\"CASCO_677.pdf\"}', 3, '2026-06-08 10:38:02'),
(388, 'documente', 317, 'create', 'Document creat: Copie conforma () pentru B 677 NET', NULL, '{\"vehicul\":\"B 677 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_677.pdf\"}', 3, '2026-06-08 10:38:16'),
(389, 'documente', 318, 'create', 'Document creat: ITP () pentru B 677 NET', NULL, '{\"vehicul\":\"B 677 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-20\",\"fisier_original\":\"677_Talon_2.pdf\"}', 3, '2026-06-08 10:38:37'),
(390, 'documente', 319, 'create', 'Document creat: RCA () pentru B 677 NET', NULL, '{\"vehicul\":\"B 677 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-24\",\"fisier_original\":\"677_Talon_2.pdf\"}', 3, '2026-06-08 10:38:56'),
(391, 'documente', 320, 'create', 'Document creat: Rovinieta () pentru B 677 NET', NULL, '{\"vehicul\":\"B 677 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-08\",\"fisier_original\":null}', 3, '2026-06-08 10:39:06'),
(392, 'documente', 321, 'create', 'Document creat: Tahograf () pentru B 677 NET', NULL, '{\"vehicul\":\"B 677 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2027-08-13\",\"fisier_original\":\"677_Taho.pdf\"}', 3, '2026-06-08 10:39:30'),
(393, 'documente', 322, 'create', 'Document creat: ADR () pentru B 775 NET', NULL, '{\"vehicul\":\"B 775 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-08\",\"fisier_original\":\"775_ADR.pdf\"}', 3, '2026-06-08 11:22:12'),
(394, 'documente', 323, 'create', 'Document creat: Brml () pentru B 775 NET', NULL, '{\"vehicul\":\"B 775 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2028-11-17\",\"fisier_original\":\"775_BRML-MID_1.pdf\"}', 3, '2026-06-08 11:24:06'),
(395, 'documente', 324, 'create', 'Document creat: Carte () pentru B 775 NET', NULL, '{\"vehicul\":\"B 775 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"775_Carte.pdf\"}', 3, '2026-06-08 11:24:14'),
(396, 'documente', 325, 'create', 'Document creat: CASCO () pentru B 775 NET', NULL, '{\"vehicul\":\"B 775 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-15\",\"fisier_original\":\"775_CASCO_.pdf\"}', 3, '2026-06-08 11:24:38'),
(397, 'documente', 326, 'create', 'Document creat: Copie conforma () pentru B 775 NET', NULL, '{\"vehicul\":\"B 775 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_775.pdf\"}', 3, '2026-06-08 11:25:08'),
(398, 'documente', 327, 'create', 'Document creat: IPROCHIM () pentru B 775 NET', NULL, '{\"vehicul\":\"B 775 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-17\",\"fisier_original\":\"775_Iprochim.pdf\"}', 3, '2026-06-08 11:25:26'),
(399, 'documente', 328, 'create', 'Document creat: ITP () pentru B 775 NET', NULL, '{\"vehicul\":\"B 775 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-08\",\"fisier_original\":\"775_Talon.pdf\"}', 3, '2026-06-08 11:25:44'),
(400, 'documente', 329, 'create', 'Document creat: RCA () pentru B 775 NET', NULL, '{\"vehicul\":\"B 775 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-29\",\"fisier_original\":\"775_RCA.pdf\"}', 3, '2026-06-08 11:26:11'),
(401, 'documente', 330, 'create', 'Document creat: Rovinieta () pentru B 775 NET', NULL, '{\"vehicul\":\"B 775 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-28\",\"fisier_original\":null}', 3, '2026-06-08 11:26:28'),
(402, 'documente', 331, 'create', 'Document creat: Tahograf () pentru B 775 NET', NULL, '{\"vehicul\":\"B 775 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-09\",\"fisier_original\":\"775_Taho.pdf\"}', 3, '2026-06-08 11:26:45'),
(403, 'documente', 332, 'create', 'Document creat: Tuv () pentru B 775 NET', NULL, '{\"vehicul\":\"B 775 NET\",\"tip_document\":\"Tuv\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-30\",\"fisier_original\":\"775_TUV_2.pdf\"}', 3, '2026-06-08 11:27:04'),
(404, 'documente', 283, 'update', 'Document actualizat: Copie conforma () pentru B 235 NET', '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2026-02-11\",\"fisier_original\":\"235_Copie_confotma.pdf\"}', '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"235_Copie_confotma.pdf\"}', 3, '2026-06-08 11:42:31'),
(405, 'documente', 51, 'delete', 'Document sters: ITP () pentru B 275 NET', '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-23\",\"fisier_original\":null}', NULL, 3, '2026-06-08 11:47:07'),
(406, 'documente', 50, 'delete', 'Document sters: Rovinieta () pentru B 275 NET', '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-23\",\"fisier_original\":null}', NULL, 3, '2026-06-08 11:47:18'),
(407, 'documente', 49, 'delete', 'Document sters: RCA () pentru B 275 NET', '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-01\",\"fisier_original\":null}', NULL, 3, '2026-06-08 11:47:27'),
(408, 'documente', 333, 'create', 'Document creat: ADR () pentru B 275 NET', NULL, '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-07-29\",\"fisier_original\":\"275_ADR.pdf\"}', 3, '2026-06-08 11:51:57'),
(409, 'documente', 334, 'create', 'Document creat: Brml () pentru B 275 NET', NULL, '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2027-09-04\",\"fisier_original\":\"275_BRML.pdf\"}', 3, '2026-06-08 11:52:18'),
(410, 'documente', 335, 'create', 'Document creat: Carte () pentru B 275 NET', NULL, '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"275_Carte.pdf\"}', 3, '2026-06-08 11:53:25'),
(411, 'documente', 336, 'create', 'Document creat: CNCIR () pentru B 275 NET', NULL, '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"CNCIR\",\"numar_document\":\"\",\"data_expirare\":\"2028-01-14\",\"fisier_original\":\"275_CNCIR.pdf\"}', 3, '2026-06-08 11:53:56'),
(412, 'documente', 337, 'create', 'Document creat: Copie conforma () pentru B 275 NET', NULL, '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_275.pdf\"}', 3, '2026-06-08 11:54:07'),
(413, 'documente', 338, 'create', 'Document creat: IPROCHIM () pentru B 275 NET', NULL, '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-16\",\"fisier_original\":\"275_Iprochim.pdf\"}', 3, '2026-06-08 11:54:24'),
(414, 'documente', 339, 'create', 'Document creat: ITP () pentru B 275 NET', NULL, '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-29\",\"fisier_original\":\"275_Talon..pdf\"}', 3, '2026-06-08 11:54:43'),
(415, 'documente', 340, 'create', 'Document creat: RCA () pentru B 275 NET', NULL, '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-08-17\",\"fisier_original\":\"275_RCA.pdf\"}', 3, '2026-06-08 11:55:04'),
(416, 'documente', 341, 'create', 'Document creat: Rovinieta () pentru B 275 NET', NULL, '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-11-02\",\"fisier_original\":null}', 3, '2026-06-08 11:55:26'),
(417, 'documente', 342, 'create', 'Document creat: Tahograf () pentru B 275 NET', NULL, '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2027-12-09\",\"fisier_original\":\"275_Taho.pdf\"}', 3, '2026-06-08 11:55:41'),
(418, 'documente', 7, 'delete', 'Document sters: ROV () pentru B 315 NET', '{\"vehicul\":\"B 315 NET\",\"tip_document\":\"ROV\",\"numar_document\":\"\",\"data_expirare\":\"2026-04-15\",\"fisier_original\":\"430_Iprochim.pdf\"}', NULL, 3, '2026-06-08 11:56:58'),
(419, 'documente', 38, 'delete', 'Document sters: ITP () pentru B 430 NET', '{\"vehicul\":\"B 430 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-23\",\"fisier_original\":null}', NULL, 3, '2026-06-08 11:57:10'),
(420, 'documente', 37, 'delete', 'Document sters: RCA () pentru B 430 NET', '{\"vehicul\":\"B 430 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-23\",\"fisier_original\":null}', NULL, 3, '2026-06-08 11:57:19'),
(421, 'documente', 39, 'delete', 'Document sters: Rovinieta () pentru B 430 NET', '{\"vehicul\":\"B 430 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-24\",\"fisier_original\":null}', NULL, 3, '2026-06-08 11:57:27'),
(422, 'documente', 343, 'create', 'Document creat: ADR () pentru B 430 NET', NULL, '{\"vehicul\":\"B 430 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-26\",\"fisier_original\":\"430_ADR.pdf\"}', 3, '2026-06-08 12:18:04'),
(423, 'documente', 344, 'create', 'Document creat: ADR () pentru B 430 NET', NULL, '{\"vehicul\":\"B 430 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-26\",\"fisier_original\":\"430_ADR.pdf\"}', 3, '2026-06-08 12:18:06'),
(424, 'documente', 345, 'create', 'Document creat: Brml () pentru B 430 NET', NULL, '{\"vehicul\":\"B 430 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2027-04-19\",\"fisier_original\":\"430_BRML.pdf\"}', 3, '2026-06-08 12:18:23'),
(425, 'documente', 346, 'create', 'Document creat: Carte () pentru B 430 NET', NULL, '{\"vehicul\":\"B 430 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"430_Carte.pdf\"}', 3, '2026-06-08 12:18:32'),
(426, 'documente', 347, 'create', 'Document creat: CASCO () pentru B 430 NET', NULL, '{\"vehicul\":\"B 430 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-25\",\"fisier_original\":\"POLITA_CASCO_FLOTA_2026.pdf\"}', 3, '2026-06-08 12:18:55'),
(427, 'documente', 348, 'create', 'Document creat: CASCO () pentru B 430 NET', NULL, '{\"vehicul\":\"B 430 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-25\",\"fisier_original\":\"POLITA_CASCO_FLOTA_2026.pdf\"}', 3, '2026-06-08 12:18:55'),
(428, 'documente', 349, 'create', 'Document creat: IPROCHIM () pentru B 430 NET', NULL, '{\"vehicul\":\"B 430 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-26\",\"fisier_original\":\"430_Iprochim.pdf\"}', 3, '2026-06-08 12:19:31'),
(429, 'documente', 350, 'create', 'Document creat: ITP () pentru B 430 NET', NULL, '{\"vehicul\":\"B 430 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-26\",\"fisier_original\":\"430_Talon.pdf\"}', 3, '2026-06-08 12:19:47'),
(430, 'documente', 351, 'create', 'Document creat: RCA () pentru B 430 NET', NULL, '{\"vehicul\":\"B 430 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-11-24\",\"fisier_original\":\"430_RCA.pdf\"}', 3, '2026-06-08 12:20:09'),
(431, 'documente', 352, 'create', 'Document creat: Rovinieta () pentru B 430 NET', NULL, '{\"vehicul\":\"B 430 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-11\",\"fisier_original\":null}', 3, '2026-06-08 12:20:23'),
(432, 'documente', 353, 'create', 'Document creat: Tahograf () pentru B 430 NET', NULL, '{\"vehicul\":\"B 430 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2027-10-25\",\"fisier_original\":\"430_Taho.pdf\"}', 3, '2026-06-08 12:20:41'),
(433, 'documente', 354, 'create', 'Document creat: Tuv () pentru B 430 NET', NULL, '{\"vehicul\":\"B 430 NET\",\"tip_document\":\"Tuv\",\"numar_document\":\"\",\"data_expirare\":\"2028-06-30\",\"fisier_original\":\"430_TUV.pdf\"}', 3, '2026-06-08 12:21:12'),
(434, 'documente', 41, 'delete', 'Document sters: Rovinieta () pentru B 311 NET', '{\"vehicul\":\"B 311 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-26\",\"fisier_original\":null}', NULL, 3, '2026-06-08 12:21:59'),
(435, 'documente', 42, 'delete', 'Document sters: RCA () pentru B 311 NET', '{\"vehicul\":\"B 311 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-28\",\"fisier_original\":null}', NULL, 3, '2026-06-08 12:22:08'),
(436, 'documente', 40, 'delete', 'Document sters: ITP () pentru B 311 NET', '{\"vehicul\":\"B 311 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-24\",\"fisier_original\":null}', NULL, 3, '2026-06-08 12:22:17'),
(437, 'documente', 355, 'create', 'Document creat: ADR () pentru B 311 NET', NULL, '{\"vehicul\":\"B 311 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-08\",\"fisier_original\":\"311ADR.pdf\"}', 3, '2026-06-08 12:35:29'),
(438, 'documente', 356, 'create', 'Document creat: Brml () pentru B 311 NET', NULL, '{\"vehicul\":\"B 311 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2026-11-09\",\"fisier_original\":\"311_BRML.pdf\"}', 3, '2026-06-08 12:35:44'),
(439, 'documente', 357, 'create', 'Document creat: Carte () pentru B 311 NET', NULL, '{\"vehicul\":\"B 311 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"311_Carte.pdf\"}', 3, '2026-06-08 12:35:53'),
(440, 'documente', 358, 'create', 'Document creat: Carte () pentru B 311 NET', NULL, '{\"vehicul\":\"B 311 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"311_Carte.pdf\"}', 3, '2026-06-08 12:35:55'),
(441, 'documente', 359, 'create', 'Document creat: Copie conforma () pentru B 311 NET', NULL, '{\"vehicul\":\"B 311 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_311.pdf\"}', 3, '2026-06-08 12:36:21'),
(442, 'documente', 360, 'create', 'Document creat: IPROCHIM () pentru B 311 NET', NULL, '{\"vehicul\":\"B 311 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-08\",\"fisier_original\":\"311_Iprochim.pdf\"}', 3, '2026-06-08 12:36:38'),
(443, 'documente', 361, 'create', 'Document creat: ITP () pentru B 311 NET', NULL, '{\"vehicul\":\"B 311 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-08\",\"fisier_original\":\"311_Talon.pdf\"}', 3, '2026-06-08 12:36:56'),
(444, 'documente', 36, 'update', 'Document actualizat: Rovinieta () pentru B 345 NET', '{\"vehicul\":\"B 345 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-17\",\"fisier_original\":null}', '{\"vehicul\":\"B 345 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-23\",\"fisier_original\":null}', 3, '2026-06-08 12:50:08'),
(445, 'documente', 35, 'update', 'Document actualizat: ITP () pentru B 345 NET', '{\"vehicul\":\"B 345 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-24\",\"fisier_original\":null}', '{\"vehicul\":\"B 345 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-24\",\"fisier_original\":\"345_Talon.pdf\"}', 3, '2026-06-08 13:08:37'),
(446, 'documente', 34, 'update', 'Document actualizat: RCA () pentru B 345 NET', '{\"vehicul\":\"B 345 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-25\",\"fisier_original\":null}', '{\"vehicul\":\"B 345 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-04-23\",\"fisier_original\":\"345_RCA_2.pdf\"}', 3, '2026-06-08 13:09:23'),
(447, 'documente', 362, 'create', 'Document creat: ADR () pentru B 345 NET', NULL, '{\"vehicul\":\"B 345 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-24\",\"fisier_original\":\"345_ADR.pdf\"}', 3, '2026-06-08 13:11:16'),
(448, 'documente', 363, 'create', 'Document creat: Brml () pentru B 345 NET', NULL, '{\"vehicul\":\"B 345 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2028-11-30\",\"fisier_original\":\"345_BRML-MID.pdf\"}', 3, '2026-06-08 13:11:30'),
(449, 'documente', 364, 'create', 'Document creat: Carte () pentru B 345 NET', NULL, '{\"vehicul\":\"B 345 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"345_Cartre.pdf\"}', 3, '2026-06-08 13:11:42'),
(450, 'documente', 365, 'create', 'Document creat: CASCO () pentru B 345 NET', NULL, '{\"vehicul\":\"B 345 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-30\",\"fisier_original\":\"345_Casco.pdf\"}', 3, '2026-06-08 13:12:10'),
(451, 'documente', 366, 'create', 'Document creat: Copie conforma () pentru B 345 NET', NULL, '{\"vehicul\":\"B 345 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_345.pdf\"}', 3, '2026-06-08 13:12:28'),
(452, 'documente', 367, 'create', 'Document creat: IPROCHIM () pentru B 345 NET', NULL, '{\"vehicul\":\"B 345 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-23\",\"fisier_original\":\"345_Iprochim.pdf\"}', 3, '2026-06-08 13:12:44'),
(453, 'documente', 368, 'create', 'Document creat: Tahograf () pentru B 345 NET', NULL, '{\"vehicul\":\"B 345 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2027-07-18\",\"fisier_original\":\"Taho_345_1.pdf\"}', 3, '2026-06-08 13:13:35'),
(454, 'documente', 369, 'create', 'Document creat: Tuv () pentru B 345 NET', NULL, '{\"vehicul\":\"B 345 NET\",\"tip_document\":\"Tuv\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-01\",\"fisier_original\":\"345_TUV.pdf\"}', 3, '2026-06-08 13:14:01'),
(455, 'documente', 32, 'update', 'Document actualizat: ITP () pentru B 219 NET', '{\"vehicul\":\"B 219 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-17\",\"fisier_original\":null}', '{\"vehicul\":\"B 219 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-02\",\"fisier_original\":\"219_Talon.pdf\"}', 3, '2026-06-08 13:25:42'),
(456, 'documente', 33, 'update', 'Document actualizat: Rovinieta () pentru B 219 NET', '{\"vehicul\":\"B 219 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-25\",\"fisier_original\":null}', '{\"vehicul\":\"B 219 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-01\",\"fisier_original\":null}', 3, '2026-06-08 13:26:24'),
(457, 'documente', 31, 'update', 'Document actualizat: RCA () pentru B 219 NET', '{\"vehicul\":\"B 219 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-24\",\"fisier_original\":null}', '{\"vehicul\":\"B 219 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-08-07\",\"fisier_original\":\"219_RCA.pdf\"}', 3, '2026-06-08 13:27:08'),
(458, 'documente', 370, 'create', 'Document creat: ADR () pentru B 219 NET', NULL, '{\"vehicul\":\"B 219 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-02\",\"fisier_original\":\"219_ADR.pdf\"}', 3, '2026-06-08 13:28:34'),
(459, 'documente', 371, 'create', 'Document creat: Brml () pentru B 219 NET', NULL, '{\"vehicul\":\"B 219 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2026-11-09\",\"fisier_original\":\"219_BRML.pdf\"}', 3, '2026-06-08 13:28:53'),
(460, 'documente', 372, 'create', 'Document creat: Carte () pentru B 219 NET', NULL, '{\"vehicul\":\"B 219 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"219_Carte.pdf\"}', 3, '2026-06-08 13:29:01'),
(461, 'documente', 373, 'create', 'Document creat: Copie conforma () pentru B 219 NET', NULL, '{\"vehicul\":\"B 219 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_219.pdf\"}', 3, '2026-06-08 13:30:52'),
(462, 'documente', 374, 'create', 'Document creat: Tahograf () pentru B 219 NET', NULL, '{\"vehicul\":\"B 219 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2026-08-08\",\"fisier_original\":\"219_Taho.pdf\"}', 3, '2026-06-08 13:38:01'),
(463, 'documente', 375, 'create', 'Document creat: Tuv () pentru B 219 NET', NULL, '{\"vehicul\":\"B 219 NET\",\"tip_document\":\"Tuv\",\"numar_document\":\"\",\"data_expirare\":\"2029-03-06\",\"fisier_original\":\"219_TUV_1.pdf\"}', 3, '2026-06-08 13:38:16'),
(464, 'documente', 375, 'update', 'Document actualizat: Tuv () pentru B 219 NET', '{\"vehicul\":\"B 219 NET\",\"tip_document\":\"Tuv\",\"numar_document\":\"\",\"data_expirare\":\"2029-03-06\",\"fisier_original\":\"219_TUV_1.pdf\"}', '{\"vehicul\":\"B 219 NET\",\"tip_document\":\"Tuv\",\"numar_document\":\"\",\"data_expirare\":\"2029-03-30\",\"fisier_original\":\"219_TUV_1.pdf\"}', 3, '2026-06-08 13:38:46'),
(465, 'documente', 57, 'update', 'Document actualizat: ITP () pentru B 437 NET', '{\"vehicul\":\"B 437 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-22\",\"fisier_original\":null}', '{\"vehicul\":\"B 437 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-08-29\",\"fisier_original\":\"437_Talon.pdf\"}', 3, '2026-06-08 13:41:47'),
(466, 'documente', 55, 'update', 'Document actualizat: Rovinieta () pentru B 437 NET', '{\"vehicul\":\"B 437 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-14\",\"fisier_original\":null}', '{\"vehicul\":\"B 437 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-10-17\",\"fisier_original\":null}', 3, '2026-06-08 13:42:10'),
(467, 'documente', 56, 'update', 'Document actualizat: RCA () pentru B 437 NET', '{\"vehicul\":\"B 437 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-23\",\"fisier_original\":null}', '{\"vehicul\":\"B 437 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-04-26\",\"fisier_original\":\"437_RCA.pdf\"}', 3, '2026-06-08 13:42:44'),
(468, 'documente', 56, 'update', 'Document actualizat: RCA () pentru B 437 NET', '{\"vehicul\":\"B 437 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-04-26\",\"fisier_original\":\"437_RCA.pdf\"}', '{\"vehicul\":\"B 437 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-04-26\",\"fisier_original\":\"437_RCA_1.pdf\"}', 3, '2026-06-08 13:43:34'),
(469, 'documente', 376, 'create', 'Document creat: ADR () pentru B 437 NET', NULL, '{\"vehicul\":\"B 437 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-08-29\",\"fisier_original\":\"437_Talon.pdf\"}', 3, '2026-06-08 13:45:42'),
(470, 'documente', 377, 'create', 'Document creat: Brml () pentru B 437 NET', NULL, '{\"vehicul\":\"B 437 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-11\",\"fisier_original\":\"437_BRML.pdf\"}', 3, '2026-06-08 13:46:02'),
(471, 'documente', 378, 'create', 'Document creat: Carte () pentru B 437 NET', NULL, '{\"vehicul\":\"B 437 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"437_Cartre.pdf\"}', 3, '2026-06-08 13:46:09'),
(472, 'documente', 379, 'create', 'Document creat: CASCO () pentru B 437 NET', NULL, '{\"vehicul\":\"B 437 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-25\",\"fisier_original\":\"POLITA_CASCO_FLOTA_2026.pdf\"}', 3, '2026-06-08 13:46:23'),
(473, 'documente', 380, 'create', 'Document creat: Copie conforma () pentru B 437 NET', NULL, '{\"vehicul\":\"B 437 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_437.pdf\"}', 3, '2026-06-08 13:47:06'),
(474, 'documente', 381, 'create', 'Document creat: IPROCHIM () pentru B 437 NET', NULL, '{\"vehicul\":\"B 437 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2026-08-29\",\"fisier_original\":\"437_Iprochim.pdf\"}', 3, '2026-06-08 13:47:26'),
(475, 'documente', 382, 'create', 'Document creat: Tahograf () pentru B 437 NET', NULL, '{\"vehicul\":\"B 437 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2028-01-21\",\"fisier_original\":\"437_Taho.jpeg\"}', 3, '2026-06-08 13:47:47'),
(476, 'documente', 383, 'create', 'Document creat: Tuv () pentru B 437 NET', NULL, '{\"vehicul\":\"B 437 NET\",\"tip_document\":\"Tuv\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-30\",\"fisier_original\":\"437_TUV.pdf\"}', 3, '2026-06-08 13:48:45'),
(477, 'documente', 90, 'update', 'Document actualizat: BRML/MID () pentru B 915 NET', '{\"vehicul\":\"B 915 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2028-11-30\",\"fisier_original\":\"915_BRML-MID_1.pdf\"}', '{\"vehicul\":\"B 915 NET\",\"tip_document\":\"BRML/MID\",\"numar_document\":\"\",\"data_expirare\":\"2028-11-30\",\"fisier_original\":\"915_BRML-MID_1.pdf\"}', 1, '2026-06-08 14:25:43'),
(478, 'documente', 90, 'update', 'Document actualizat: METROLOGIE () pentru B 915 NET', '{\"vehicul\":\"B 915 NET\",\"tip_document\":\"BRML/MID\",\"numar_document\":\"\",\"data_expirare\":\"2028-11-30\",\"fisier_original\":\"915_BRML-MID_1.pdf\"}', '{\"vehicul\":\"B 915 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2028-11-30\",\"fisier_original\":\"915_BRML-MID_1.pdf\"}', 1, '2026-06-08 14:31:03'),
(479, 'documente', 384, 'create', 'Document creat: Carte () pentru B 385 NET', NULL, '{\"vehicul\":\"B 385 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"385_Carte.pdf\"}', 3, '2026-06-08 14:34:53'),
(480, 'documente', 58, 'update', 'Document actualizat: Rovinieta () pentru B 385 NET', '{\"vehicul\":\"B 385 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-23\",\"fisier_original\":null}', '{\"vehicul\":\"B 385 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-10-17\",\"fisier_original\":null}', 3, '2026-06-08 14:37:58'),
(481, 'documente', 59, 'update', 'Document actualizat: ITP () pentru B 385 NET', '{\"vehicul\":\"B 385 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-15\",\"fisier_original\":null}', '{\"vehicul\":\"B 385 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-22\",\"fisier_original\":\"385_Talon.pdf\"}', 3, '2026-06-08 14:38:53'),
(482, 'documente', 60, 'update', 'Document actualizat: RCA () pentru B 385 NET', '{\"vehicul\":\"B 385 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-26\",\"fisier_original\":null}', '{\"vehicul\":\"B 385 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-26\",\"fisier_original\":\"385_RCA.pdf\"}', 3, '2026-06-08 14:42:15'),
(483, 'documente', 60, 'update', 'Document actualizat: RCA () pentru B 385 NET', '{\"vehicul\":\"B 385 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-26\",\"fisier_original\":\"385_RCA.pdf\"}', '{\"vehicul\":\"B 385 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-21\",\"fisier_original\":\"385_RCA.pdf\"}', 3, '2026-06-08 14:43:03'),
(484, 'documente', 385, 'create', 'Document creat: ADR () pentru B 385 NET', NULL, '{\"vehicul\":\"B 385 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-22\",\"fisier_original\":\"385_ADR.pdf\"}', 3, '2026-06-08 14:43:46'),
(485, 'documente', 386, 'create', 'Document creat: Copie conforma () pentru B 385 NET', NULL, '{\"vehicul\":\"B 385 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_385.pdf\"}', 3, '2026-06-08 14:44:09'),
(486, 'documente', 387, 'create', 'Document creat: IPROCHIM () pentru B 385 NET', NULL, '{\"vehicul\":\"B 385 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-21\",\"fisier_original\":\"385_Iprochim.pdf\"}', 3, '2026-06-08 14:44:27'),
(487, 'documente', 388, 'create', 'Document creat: METROLOGIE () pentru B 385 NET', NULL, '{\"vehicul\":\"B 385 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2027-09-04\",\"fisier_original\":\"385_BRML.pdf\"}', 3, '2026-06-08 14:44:57'),
(488, 'documente', 389, 'create', 'Document creat: ORGANISM NOTIFICAT () pentru B 385 NET', NULL, '{\"vehicul\":\"B 385 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-17\",\"fisier_original\":\"385_TUV.pdf\"}', 3, '2026-06-08 14:45:15'),
(489, 'documente', 390, 'create', 'Document creat: ORGANISM NOTIFICAT () pentru B 385 NET', NULL, '{\"vehicul\":\"B 385 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-17\",\"fisier_original\":\"385_TUV.pdf\"}', 3, '2026-06-08 14:45:17'),
(490, 'documente', 391, 'create', 'Document creat: Tahograf () pentru B 385 NET', NULL, '{\"vehicul\":\"B 385 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2027-09-06\",\"fisier_original\":\"385_Taho.pdf\"}', 3, '2026-06-08 14:45:34'),
(491, 'documente', 43, 'update', 'Document actualizat: RCA () pentru B 285 NET', '{\"vehicul\":\"B 285 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-27\",\"fisier_original\":null}', '{\"vehicul\":\"B 285 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-30\",\"fisier_original\":\"285_RCA.pdf\"}', 3, '2026-06-08 14:49:05'),
(492, 'documente', 43, 'update', 'Document actualizat: RCA () pentru B 285 NET', '{\"vehicul\":\"B 285 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-30\",\"fisier_original\":\"285_RCA.pdf\"}', '{\"vehicul\":\"B 285 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-30\",\"fisier_original\":\"285_RCA.pdf\"}', 3, '2026-06-08 14:49:06'),
(493, 'documente', 45, 'update', 'Document actualizat: Rovinieta () pentru B 285 NET', '{\"vehicul\":\"B 285 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-23\",\"fisier_original\":null}', '{\"vehicul\":\"B 285 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-21\",\"fisier_original\":null}', 3, '2026-06-08 14:49:28'),
(494, 'documente', 44, 'update', 'Document actualizat: ITP () pentru B 285 NET', '{\"vehicul\":\"B 285 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-23\",\"fisier_original\":null}', '{\"vehicul\":\"B 285 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-18\",\"fisier_original\":\"285_Talon.pdf\"}', 3, '2026-06-08 14:50:02'),
(495, 'documente', 392, 'create', 'Document creat: ADR () pentru B 285 NET', NULL, '{\"vehicul\":\"B 285 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-18\",\"fisier_original\":\"285_ADR.pdf\"}', 3, '2026-06-08 14:51:13'),
(496, 'documente', 393, 'create', 'Document creat: Carte () pentru B 285 NET', NULL, '{\"vehicul\":\"B 285 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"285_Carte.pdf\"}', 3, '2026-06-08 14:51:26'),
(497, 'documente', 394, 'create', 'Document creat: Copie conforma () pentru B 285 NET', NULL, '{\"vehicul\":\"B 285 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_285.pdf\"}', 3, '2026-06-08 14:51:43'),
(498, 'documente', 395, 'create', 'Document creat: IPROCHIM () pentru B 285 NET', NULL, '{\"vehicul\":\"B 285 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-18\",\"fisier_original\":\"285_Iprochim.pdf\"}', 3, '2026-06-08 14:51:59'),
(499, 'documente', 396, 'create', 'Document creat: METROLOGIE () pentru B 285 NET', NULL, '{\"vehicul\":\"B 285 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2027-12-26\",\"fisier_original\":\"285_BRML.pdf\"}', 3, '2026-06-08 14:52:32');
INSERT INTO `audit_log` (`id`, `modul`, `record_id`, `actiune`, `descriere`, `before_data`, `after_data`, `user_id`, `created_at`) VALUES
(500, 'documente', 397, 'create', 'Document creat: ORGANISM NOTIFICAT () pentru B 285 NET', NULL, '{\"vehicul\":\"B 285 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2027-12-15\",\"fisier_original\":\"285_CNCIR.pdf\"}', 3, '2026-06-08 14:53:12'),
(501, 'documente', 398, 'create', 'Document creat: Tahograf () pentru B 285 NET', NULL, '{\"vehicul\":\"B 285 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2026-08-22\",\"fisier_original\":\"285_Taho.pdf\"}', 3, '2026-06-08 14:53:33'),
(502, 'documente', 46, 'update', 'Document actualizat: RCA () pentru B 375 NET', '{\"vehicul\":\"B 375 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-17\",\"fisier_original\":null}', '{\"vehicul\":\"B 375 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-04\",\"fisier_original\":\"375_RCA.pdf\"}', 3, '2026-06-08 15:00:05'),
(503, 'documente', 47, 'update', 'Document actualizat: Rovinieta () pentru B 375 NET', '{\"vehicul\":\"B 375 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-24\",\"fisier_original\":null}', '{\"vehicul\":\"B 375 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-09\",\"fisier_original\":null}', 3, '2026-06-08 15:01:09'),
(504, 'documente', 48, 'update', 'Document actualizat: ITP () pentru B 375 NET', '{\"vehicul\":\"B 375 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-24\",\"fisier_original\":null}', '{\"vehicul\":\"B 375 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-04\",\"fisier_original\":\"375_Talon.pdf\"}', 3, '2026-06-08 15:02:06'),
(505, 'documente', 399, 'create', 'Document creat: Adr () pentru B 305 NET', NULL, '{\"vehicul\":\"B 305 NET\",\"tip_document\":\"Adr\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-05\",\"fisier_original\":\"305_ADR.pdf\"}', 3, '2026-06-08 15:32:35'),
(506, 'documente', 400, 'create', 'Document creat: Carte () pentru B 305 NET', NULL, '{\"vehicul\":\"B 305 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"305_Carte.pdf\"}', 3, '2026-06-08 15:32:46'),
(507, 'documente', 401, 'create', 'Document creat: Casco () pentru B 305 NET', NULL, '{\"vehicul\":\"B 305 NET\",\"tip_document\":\"Casco\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-25\",\"fisier_original\":\"POLITA_CASCO_FLOTA_2026.pdf\"}', 3, '2026-06-08 15:33:02'),
(508, 'documente', 402, 'create', 'Document creat: IPROCHIM () pentru B 305 NET', NULL, '{\"vehicul\":\"B 305 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-06\",\"fisier_original\":\"305_Iprochim.pdf\"}', 3, '2026-06-08 15:33:18'),
(509, 'documente', 403, 'create', 'Document creat: ITP () pentru B 305 NET', NULL, '{\"vehicul\":\"B 305 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-06\",\"fisier_original\":\"305_Talon.pdf\"}', 3, '2026-06-08 15:33:29'),
(510, 'documente', 404, 'create', 'Document creat: ORGANISM NOTIFICAT () pentru B 305 NET', NULL, '{\"vehicul\":\"B 305 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-07\",\"fisier_original\":\"305_TUV.pdf\"}', 3, '2026-06-08 15:33:47'),
(511, 'documente', 405, 'create', 'Document creat: RCA () pentru B 305 NET', NULL, '{\"vehicul\":\"B 305 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-21\",\"fisier_original\":\"305_RCA.pdf\"}', 3, '2026-06-08 15:34:07'),
(512, 'documente', 406, 'create', 'Document creat: METROLOGIE () pentru B 305 NET', NULL, '{\"vehicul\":\"B 305 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2029-04-29\",\"fisier_original\":\"305BRML.pdf\"}', 3, '2026-06-08 15:35:58'),
(513, 'documente', 407, 'create', 'Document creat: ADR () pentru B 655 NET', NULL, '{\"vehicul\":\"B 655 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-09\",\"fisier_original\":\"655_ADR.pdf\"}', 3, '2026-06-08 15:48:30'),
(514, 'documente', 408, 'create', 'Document creat: Carte () pentru B 655 NET', NULL, '{\"vehicul\":\"B 655 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"655_Carte.pdf\"}', 3, '2026-06-08 15:48:42'),
(515, 'documente', 409, 'create', 'Document creat: CASCO () pentru B 655 NET', NULL, '{\"vehicul\":\"B 655 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-12\",\"fisier_original\":\"655_Casco.pdf\"}', 3, '2026-06-08 15:49:07'),
(516, 'documente', 410, 'create', 'Document creat: Copie conforma () pentru B 655 NET', NULL, '{\"vehicul\":\"B 655 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-04\",\"fisier_original\":\"Copie_Conforma_B655NET_Treiro.pdf\"}', 3, '2026-06-08 15:49:30'),
(517, 'documente', 411, 'create', 'Document creat: ITP () pentru B 655 NET', NULL, '{\"vehicul\":\"B 655 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-09\",\"fisier_original\":\"655_Talon.pdf\"}', 3, '2026-06-08 15:49:46'),
(518, 'documente', 412, 'create', 'Document creat: RCA () pentru B 655 NET', NULL, '{\"vehicul\":\"B 655 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-26\",\"fisier_original\":\"655_RCA_1.pdf\"}', 3, '2026-06-08 15:50:25'),
(519, 'documente', 413, 'create', 'Document creat: Rovinieta () pentru B 655 NET', NULL, '{\"vehicul\":\"B 655 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-27\",\"fisier_original\":null}', 3, '2026-06-08 15:50:46'),
(520, 'documente', 414, 'create', 'Document creat: Tahograf () pentru B 655 NET', NULL, '{\"vehicul\":\"B 655 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2028-01-29\",\"fisier_original\":\"655_Taho.pdf\"}', 3, '2026-06-08 15:51:01'),
(521, 'documente', 21, 'update', 'Document actualizat: ITP () pentru B 405 NET', '{\"vehicul\":\"B 405 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-12\",\"fisier_original\":\"405_ADR.pdf\"}', '{\"vehicul\":\"B 405 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-10\",\"fisier_original\":\"405_Talon_1.pdf\"}', 3, '2026-06-08 16:03:26'),
(522, 'documente', 19, 'update', 'Document actualizat: RCA () pentru B 405 NET', '{\"vehicul\":\"B 405 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-24\",\"fisier_original\":\"405_RCA.pdf\"}', '{\"vehicul\":\"B 405 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-24\",\"fisier_original\":\"405_RCA_1.pdf\"}', 3, '2026-06-08 16:04:38'),
(523, 'documente', 20, 'update', 'Document actualizat: CASCO () pentru B 405 NET', '{\"vehicul\":\"B 405 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-23\",\"fisier_original\":\"405_Casco.pdf\"}', '{\"vehicul\":\"B 405 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-25\",\"fisier_original\":\"405_Casco.pdf\"}', 3, '2026-06-08 16:05:25'),
(524, 'documente', 415, 'create', 'Document creat: ADR () pentru B 405 NET', NULL, '{\"vehicul\":\"B 405 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-10\",\"fisier_original\":\"405_ADR_1.pdf\"}', 3, '2026-06-08 16:11:39'),
(525, 'documente', 416, 'create', 'Document creat: Carte () pentru B 405 NET', NULL, '{\"vehicul\":\"B 405 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"405_Carte.pdf\"}', 3, '2026-06-08 16:13:07'),
(526, 'documente', 417, 'create', 'Document creat: IPROCHIM () pentru B 405 NET', NULL, '{\"vehicul\":\"B 405 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-07\",\"fisier_original\":\"405_IPRO.pdf\"}', 3, '2026-06-08 16:15:24'),
(527, 'documente', 418, 'create', 'Document creat: METROLOGIE () pentru B 405 NET', NULL, '{\"vehicul\":\"B 405 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2029-04-29\",\"fisier_original\":\"405_BRML.pdf\"}', 3, '2026-06-08 16:18:51'),
(528, 'documente', 419, 'create', 'Document creat: ORGANISM NOTIFICAT () pentru B 405 NET', NULL, '{\"vehicul\":\"B 405 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2028-05-30\",\"fisier_original\":\"405_TUV.pdf\"}', 3, '2026-06-08 16:19:10'),
(529, 'documente', 17, 'update', 'Document actualizat: ITP () pentru B 665 NET', '{\"vehicul\":\"B 665 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-12\",\"fisier_original\":\"665_ADR.pdf\"}', '{\"vehicul\":\"B 665 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-12\",\"fisier_original\":\"665_Talon.pdf\"}', 3, '2026-06-09 08:59:09'),
(530, 'documente', 16, 'update', 'Document actualizat: RCA () pentru B 665 NET', '{\"vehicul\":\"B 665 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-27\",\"fisier_original\":\"665_RCA.pdf\"}', '{\"vehicul\":\"B 665 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-27\",\"fisier_original\":\"665_RCA.pdf\"}', 3, '2026-06-09 08:59:55'),
(531, 'documente', 18, 'update', 'Document actualizat: Rovinieta () pentru B 665 NET', '{\"vehicul\":\"B 665 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-12\",\"fisier_original\":\"665_Casco.pdf\"}', '{\"vehicul\":\"B 665 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-27\",\"fisier_original\":null}', 3, '2026-06-09 09:01:01'),
(532, 'documente', 420, 'create', 'Document creat: ADR () pentru B 665 NET', NULL, '{\"vehicul\":\"B 665 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-12\",\"fisier_original\":\"665_ADR.pdf\"}', 3, '2026-06-09 09:02:11'),
(533, 'documente', 421, 'create', 'Document creat: Carte () pentru B 665 NET', NULL, '{\"vehicul\":\"B 665 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"665_Carte.pdf\"}', 3, '2026-06-09 09:02:19'),
(534, 'documente', 422, 'create', 'Document creat: CASCO () pentru B 665 NET', NULL, '{\"vehicul\":\"B 665 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-12\",\"fisier_original\":\"665_Casco.pdf\"}', 3, '2026-06-09 09:02:54'),
(535, 'documente', 423, 'create', 'Document creat: Copie conforma () pentru B 665 NET', NULL, '{\"vehicul\":\"B 665 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-04\",\"fisier_original\":\"Copie_Conforma_B665NET_Treiro.pdf\"}', 3, '2026-06-09 09:03:20'),
(536, 'documente', 424, 'create', 'Document creat: Tahograf () pentru B 665 NET', NULL, '{\"vehicul\":\"B 665 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2028-01-29\",\"fisier_original\":\"665_Taho.pdf\"}', 3, '2026-06-09 09:04:20'),
(537, 'documente', 425, 'create', 'Document creat: Adr () pentru B 805 NET', NULL, '{\"vehicul\":\"B 805 NET\",\"tip_document\":\"Adr\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-11\",\"fisier_original\":\"805_ADR_1.pdf\"}', 3, '2026-06-09 09:07:04'),
(538, 'documente', 426, 'create', 'Document creat: Carte () pentru B 805 NET', NULL, '{\"vehicul\":\"B 805 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"805_Carte.pdf\"}', 3, '2026-06-09 09:07:12'),
(539, 'documente', 427, 'create', 'Document creat: IPROCHIM () pentru B 805 NET', NULL, '{\"vehicul\":\"B 805 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-10\",\"fisier_original\":\"805_IPROCHIM.pdf\"}', 3, '2026-06-09 09:07:41'),
(540, 'documente', 428, 'create', 'Document creat: ITP () pentru B 805 NET', NULL, '{\"vehicul\":\"B 805 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-11\",\"fisier_original\":\"805_Talon_1.pdf\"}', 3, '2026-06-09 09:08:07'),
(541, 'documente', 429, 'create', 'Document creat: ORGANISM NOTIFICAT () pentru B 805 NET', NULL, '{\"vehicul\":\"B 805 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-28\",\"fisier_original\":\"805_TUV_1.pdf\"}', 3, '2026-06-09 09:10:06'),
(542, 'documente', 430, 'create', 'Document creat: RCA () pentru B 805 NET', NULL, '{\"vehicul\":\"B 805 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-19\",\"fisier_original\":\"805_RCA_1.pdf\"}', 3, '2026-06-09 09:10:29'),
(543, 'documente', 12, 'update', 'Document actualizat: RCA () pentru B 218 NET', '{\"vehicul\":\"B 218 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-28\",\"fisier_original\":\"805_Carte.pdf\"}', '{\"vehicul\":\"B 218 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-25\",\"fisier_original\":\"218_RCA.pdf\"}', 3, '2026-06-09 09:45:29'),
(544, 'documente', 431, 'create', 'Document creat: ADR () pentru B 218 NET', NULL, '{\"vehicul\":\"B 218 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-22\",\"fisier_original\":\"218_ADR.pdf\"}', 3, '2026-06-09 09:46:30'),
(545, 'documente', 432, 'create', 'Document creat: Carte () pentru B 218 NET', NULL, '{\"vehicul\":\"B 218 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"218_Carte.pdf\"}', 3, '2026-06-09 09:46:39'),
(546, 'documente', 433, 'create', 'Document creat: Copie conforma () pentru B 218 NET', NULL, '{\"vehicul\":\"B 218 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_218.pdf\"}', 3, '2026-06-09 09:47:06'),
(547, 'documente', 434, 'create', 'Document creat: IPROCHIM () pentru B 218 NET', NULL, '{\"vehicul\":\"B 218 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-21\",\"fisier_original\":\"ipro_218f_1.pdf\"}', 3, '2026-06-09 09:48:10'),
(548, 'documente', 435, 'create', 'Document creat: ITP () pentru B 218 NET', NULL, '{\"vehicul\":\"B 218 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-22\",\"fisier_original\":\"218_TALON_3.pdf\"}', 3, '2026-06-09 09:48:32'),
(549, 'documente', 436, 'create', 'Document creat: METROLOGIE () pentru B 218 NET', NULL, '{\"vehicul\":\"B 218 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2026-11-09\",\"fisier_original\":\"218_BRML.pdf\"}', 3, '2026-06-09 09:48:58'),
(550, 'documente', 437, 'create', 'Document creat: ORGANISM NOTIFICAT () pentru B 218 NET', NULL, '{\"vehicul\":\"B 218 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-19\",\"fisier_original\":null}', 3, '2026-06-09 09:49:40'),
(551, 'documente', 438, 'create', 'Document creat: RCA () pentru B 218 NET', NULL, '{\"vehicul\":\"B 218 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-22\",\"fisier_original\":\"218_RCA.pdf\"}', 3, '2026-06-09 09:50:06'),
(552, 'documente', 439, 'create', 'Document creat: Rovinieta () pentru B 218 NET', NULL, '{\"vehicul\":\"B 218 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-19\",\"fisier_original\":null}', 3, '2026-06-09 09:50:23'),
(553, 'documente', 440, 'create', 'Document creat: Tahograf () pentru B 218 NET', NULL, '{\"vehicul\":\"B 218 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2028-05-27\",\"fisier_original\":\"TAHO_218_1.pdf\"}', 3, '2026-06-09 09:50:45'),
(554, 'documente', 438, 'delete', 'Document sters: RCA () pentru B 218 NET', '{\"vehicul\":\"B 218 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-22\",\"fisier_original\":\"218_RCA.pdf\"}', NULL, 3, '2026-06-09 09:51:37'),
(555, 'documente', 441, 'create', 'Document creat: ADR () pentru B 325 NET', NULL, '{\"vehicul\":\"B 325 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-07\",\"fisier_original\":\"325_ADR.pdf\"}', 3, '2026-06-09 11:02:26'),
(556, 'documente', 442, 'create', 'Document creat: Carte () pentru B 325 NET', NULL, '{\"vehicul\":\"B 325 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"325_Carte.pdf\"}', 3, '2026-06-09 11:02:40'),
(557, 'documente', 443, 'create', 'Document creat: Copie conforma () pentru B 325 NET', NULL, '{\"vehicul\":\"B 325 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_325.pdf\"}', 3, '2026-06-09 11:03:12'),
(558, 'documente', 444, 'create', 'Document creat: IPROCHIM () pentru B 325 NET', NULL, '{\"vehicul\":\"B 325 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-07\",\"fisier_original\":\"325_Iprochim.pdf\"}', 3, '2026-06-09 11:03:46'),
(559, 'documente', 445, 'create', 'Document creat: ITP () pentru B 325 NET', NULL, '{\"vehicul\":\"B 325 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-07\",\"fisier_original\":\"325_Talon.pdf\"}', 3, '2026-06-09 11:04:06'),
(560, 'documente', 446, 'create', 'Document creat: METROLOGIE () pentru B 325 NET', NULL, '{\"vehicul\":\"B 325 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2027-08-12\",\"fisier_original\":\"325_BRML.pdf\"}', 3, '2026-06-09 11:04:29'),
(561, 'documente', 447, 'create', 'Document creat: ORGANISM NOTIFICAT () pentru B 325 NET', NULL, '{\"vehicul\":\"B 325 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-30\",\"fisier_original\":\"CNCIR_325.pdf\"}', 3, '2026-06-09 11:18:42'),
(562, 'documente', 448, 'create', 'Document creat: RCA () pentru B 325 NET', NULL, '{\"vehicul\":\"B 325 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-27\",\"fisier_original\":\"325_RCA.pdf\"}', 3, '2026-06-09 11:19:29'),
(563, 'documente', 449, 'create', 'Document creat: Rovinieta () pentru B 325 NET', NULL, '{\"vehicul\":\"B 325 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-08-06\",\"fisier_original\":null}', 3, '2026-06-09 11:19:49'),
(564, 'documente', 450, 'create', 'Document creat: Tahograf () pentru B 325 NET', NULL, '{\"vehicul\":\"B 325 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2026-08-12\",\"fisier_original\":\"325_Taho.pdf\"}', 3, '2026-06-09 11:20:08'),
(565, 'documente', 451, 'create', 'Document creat: ADR () pentru B 232 NET', NULL, '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-11\",\"fisier_original\":\"232_ADR.pdf\"}', 3, '2026-06-09 11:34:57'),
(566, 'documente', 452, 'create', 'Document creat: Carte () pentru B 232 NET', NULL, '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"232_Carte.pdf\"}', 3, '2026-06-09 11:35:04'),
(567, 'documente', 453, 'create', 'Document creat: CASCO () pentru B 232 NET', NULL, '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-27\",\"fisier_original\":\"POLITA_CASCO_FLOTA_2026.pdf\"}', 3, '2026-06-09 11:35:28'),
(568, 'documente', 454, 'create', 'Document creat: Copie conforma () pentru B 232 NET', NULL, '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_232_1.pdf\"}', 3, '2026-06-09 11:35:54'),
(569, 'documente', 455, 'create', 'Document creat: IPROCHIM () pentru B 232 NET', NULL, '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2027-06-09\",\"fisier_original\":\"232-IPRO_2.pdf\"}', 3, '2026-06-09 11:40:28'),
(570, 'documente', 456, 'create', 'Document creat: ITP () pentru B 232 NET', NULL, '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-11\",\"fisier_original\":\"232_Talon.pdf\"}', 3, '2026-06-09 11:40:50'),
(571, 'documente', 457, 'create', 'Document creat: METROLOGIE () pentru B 232 NET', NULL, '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2026-11-08\",\"fisier_original\":\"232_BRML.pdf\"}', 3, '2026-06-09 11:41:06'),
(572, 'documente', 458, 'create', 'Document creat: ORGANISM NOTIFICAT () pentru B 232 NET', NULL, '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2029-06-30\",\"fisier_original\":\"232TUV.pdf\"}', 3, '2026-06-09 11:41:37'),
(573, 'documente', 8, 'update', 'Document actualizat: Rovinieta () pentru B 315 NET', '{\"vehicul\":\"B 315 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-04-14\",\"fisier_original\":null}', '{\"vehicul\":\"B 315 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-05\",\"fisier_original\":null}', 3, '2026-06-09 11:45:45'),
(574, 'documente', 2, 'update', 'Document actualizat: ITP (ITP-001-2026) pentru B 315 NET', '{\"vehicul\":\"B 315 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"ITP-001-2026\",\"data_expirare\":\"2026-05-28\",\"fisier_original\":null}', '{\"vehicul\":\"B 315 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"ITP-001-2026\",\"data_expirare\":\"2027-02-23\",\"fisier_original\":\"315_Talon..pdf\"}', 3, '2026-06-09 11:46:18'),
(575, 'documente', 1, 'update', 'Document actualizat: RCA (RCA-001-2026) pentru B 315 NET', '{\"vehicul\":\"B 315 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"RCA-001-2026\",\"data_expirare\":\"2026-04-18\",\"fisier_original\":null}', '{\"vehicul\":\"B 315 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"RCA-001-2026\",\"data_expirare\":\"2027-03-05\",\"fisier_original\":\"315_RCA.pdf\"}', 3, '2026-06-09 11:49:04'),
(576, 'documente', 459, 'create', 'Document creat: ADR () pentru B 315 NET', NULL, '{\"vehicul\":\"B 315 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-23\",\"fisier_original\":\"315_ADR.pdf\"}', 3, '2026-06-09 11:49:54'),
(577, 'documente', 460, 'create', 'Document creat: Carte () pentru B 315 NET', NULL, '{\"vehicul\":\"B 315 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"315_Carte.pdf\"}', 3, '2026-06-09 11:50:07'),
(578, 'documente', 461, 'create', 'Document creat: Copie conforma () pentru B 315 NET', NULL, '{\"vehicul\":\"B 315 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_315.pdf\"}', 3, '2026-06-09 11:50:55'),
(579, 'documente', 462, 'create', 'Document creat: IPROCHIM () pentru B 315 NET', NULL, '{\"vehicul\":\"B 315 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-23\",\"fisier_original\":\"315_IPROCHIM_2.PDF\"}', 3, '2026-06-09 11:51:11'),
(580, 'documente', 463, 'create', 'Document creat: METROLOGIE () pentru B 315 NET', NULL, '{\"vehicul\":\"B 315 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2028-03-10\",\"fisier_original\":\"315_BRML.pdf\"}', 3, '2026-06-09 11:52:02'),
(581, 'documente', 464, 'create', 'Document creat: ORGANISM NOTIFICAT () pentru B 315 NET', NULL, '{\"vehicul\":\"B 315 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-23\",\"fisier_original\":\"315_CNCIR_2.pdf\"}', 3, '2026-06-09 11:52:29'),
(582, 'documente', 465, 'create', 'Document creat: Tahograf () pentru B 315 NET', NULL, '{\"vehicul\":\"B 315 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-10\",\"fisier_original\":\"315_taho.pdf\"}', 3, '2026-06-09 11:53:02'),
(583, 'documente', 4, 'update', 'Document actualizat: RCA (RCA-003-2026) pentru B 395 NET', '{\"vehicul\":\"B 395 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"RCA-003-2026\",\"data_expirare\":\"2026-04-01\",\"fisier_original\":null}', '{\"vehicul\":\"B 395 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"RCA-003-2026\",\"data_expirare\":\"2027-04-01\",\"fisier_original\":\"315_RCA.pdf\"}', 3, '2026-06-09 12:09:45'),
(584, 'documente', 466, 'create', 'Document creat: ADR () pentru B 395 NET', NULL, '{\"vehicul\":\"B 395 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-04\",\"fisier_original\":\"315_ADR.pdf\"}', 3, '2026-06-09 12:10:50'),
(585, 'documente', 467, 'create', 'Document creat: Carte () pentru B 395 NET', NULL, '{\"vehicul\":\"B 395 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"395_Carte.pdf\"}', 3, '2026-06-09 12:12:50'),
(586, 'documente', 468, 'create', 'Document creat: Copie conforma () pentru B 395 NET', NULL, '{\"vehicul\":\"B 395 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_395.pdf\"}', 3, '2026-06-09 12:13:13'),
(587, 'documente', 469, 'create', 'Document creat: IPROCHIM () pentru B 395 NET', NULL, '{\"vehicul\":\"B 395 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-28\",\"fisier_original\":\"395_Iprochim.pdf\"}', 3, '2026-06-09 12:13:29'),
(588, 'documente', 470, 'create', 'Document creat: ITP () pentru B 395 NET', NULL, '{\"vehicul\":\"B 395 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-04\",\"fisier_original\":\"395_Talon.pdf\"}', 3, '2026-06-09 12:13:52'),
(589, 'documente', 471, 'create', 'Document creat: METROLOGIE () pentru B 395 NET', NULL, '{\"vehicul\":\"B 395 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2029-04-29\",\"fisier_original\":\"BRML_NOU_B_395_NET.pdf\"}', 3, '2026-06-09 12:15:07'),
(590, 'documente', 472, 'create', 'Document creat: ORGANISM NOTIFICAT () pentru B 395 NET', NULL, '{\"vehicul\":\"B 395 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2029-05-30\",\"fisier_original\":\"395_TUV.pdf\"}', 3, '2026-06-09 12:15:35'),
(591, 'documente', 3, 'update', 'Document actualizat: Rovinieta (ROV-7788) pentru B 335 NET', '{\"vehicul\":\"B 335 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"ROV-7788\",\"data_expirare\":\"2026-04-11\",\"fisier_original\":null}', '{\"vehicul\":\"B 335 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"ROV-7788\",\"data_expirare\":\"2026-08-11\",\"fisier_original\":null}', 3, '2026-06-09 12:16:47'),
(592, 'documente', 473, 'create', 'Document creat: ADR () pentru B 335 NET', NULL, '{\"vehicul\":\"B 335 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-28\",\"fisier_original\":\"335_ADR.pdf\"}', 3, '2026-06-09 12:23:17'),
(593, 'documente', 474, 'create', 'Document creat: Carte () pentru B 335 NET', NULL, '{\"vehicul\":\"B 335 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"335_Carte.pdf\"}', 3, '2026-06-09 12:23:58'),
(594, 'documente', 475, 'create', 'Document creat: Copie conforma () pentru B 335 NET', NULL, '{\"vehicul\":\"B 335 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_335.pdf\"}', 3, '2026-06-09 12:24:47'),
(595, 'documente', 476, 'create', 'Document creat: IPROCHIM () pentru B 335 NET', NULL, '{\"vehicul\":\"B 335 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-28\",\"fisier_original\":\"335_Iprochim.pdf\"}', 3, '2026-06-09 12:26:22'),
(596, 'documente', 477, 'create', 'Document creat: ITP () pentru B 335 NET', NULL, '{\"vehicul\":\"B 335 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-28\",\"fisier_original\":\"335_Talon.pdf\"}', 3, '2026-06-09 12:27:09'),
(597, 'documente', 478, 'create', 'Document creat: ITP () pentru B 335 NET', NULL, '{\"vehicul\":\"B 335 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-28\",\"fisier_original\":\"335_Talon.pdf\"}', 3, '2026-06-09 12:44:55'),
(598, 'documente', 478, 'delete', 'Document sters: ITP () pentru B 335 NET', '{\"vehicul\":\"B 335 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-28\",\"fisier_original\":\"335_Talon.pdf\"}', NULL, 3, '2026-06-09 12:45:20'),
(599, 'documente', 479, 'create', 'Document creat: Tahograf () pentru B 335 NET', NULL, '{\"vehicul\":\"B 335 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2026-08-09\",\"fisier_original\":\"335_Taho.pdf\"}', 3, '2026-06-09 12:47:18'),
(600, 'documente', 480, 'create', 'Document creat: METROLOGIE () pentru B 945 NET', NULL, '{\"vehicul\":\"B 945 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2028-11-30\",\"fisier_original\":\"MID_945.pdf\"}', 3, '2026-06-09 15:05:29'),
(601, 'documente', 481, 'create', 'Document creat: METROLOGIE () pentru B 235 NET', NULL, '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2027-08-30\",\"fisier_original\":\"Cncir_235.pdf\"}', 3, '2026-06-09 15:31:13'),
(602, 'documente', 437, 'update', 'Document actualizat: ORGANISM NOTIFICAT () pentru B 218 NET', '{\"vehicul\":\"B 218 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-19\",\"fisier_original\":null}', '{\"vehicul\":\"B 218 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2029-05-30\",\"fisier_original\":\"TEC_164-11052026174201.pdf\"}', 3, '2026-06-09 15:34:30'),
(603, 'documente', 148, 'update', 'Document actualizat: RCA () pentru B 402 NET', '{\"vehicul\":\"B 402 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-09\",\"fisier_original\":\"402_RCA.pdf\"}', '{\"vehicul\":\"B 402 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-06-09\",\"fisier_original\":\"RCA_402.pdf\"}', 3, '2026-06-09 15:37:10'),
(604, 'documente', 422, 'update', 'Document actualizat: CASCO () pentru B 665 NET', '{\"vehicul\":\"B 665 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-12\",\"fisier_original\":\"665_Casco.pdf\"}', '{\"vehicul\":\"B 665 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-12\",\"fisier_original\":\"665_Casco.pdf\"}', 1, '2026-06-09 15:45:34'),
(605, 'documente', 401, 'update', 'Document actualizat: CASCO () pentru B 305 NET', '{\"vehicul\":\"B 305 NET\",\"tip_document\":\"Casco\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-25\",\"fisier_original\":\"POLITA_CASCO_FLOTA_2026.pdf\"}', '{\"vehicul\":\"B 305 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-25\",\"fisier_original\":\"POLITA_CASCO_FLOTA_2026.pdf\"}', 1, '2026-06-09 15:46:46'),
(606, 'documente', 482, 'create', 'Document creat: CASCO () pentru B 835 NET', NULL, '{\"vehicul\":\"B 835 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-23\",\"fisier_original\":null}', 1, '2026-06-10 09:43:02'),
(607, 'documente', 482, 'delete', 'Document sters: CASCO () pentru B 835 NET', '{\"vehicul\":\"B 835 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-23\",\"fisier_original\":null}', NULL, 1, '2026-06-10 09:43:44'),
(608, 'documente', 483, 'create', 'Document creat: CASCO () pentru B 835 NET', NULL, '{\"vehicul\":\"B 835 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-10 09:44:55'),
(609, 'documente', 104, 'update', 'Document actualizat: CASCO () pentru B 845 NET', '{\"vehicul\":\"B 845 NET\",\"tip_document\":\"Casco\",\"numar_document\":\"\",\"data_expirare\":\"2030-04-02\",\"fisier_original\":\"845_Casco.pdf\"}', '{\"vehicul\":\"B 845 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2030-04-02\",\"fisier_original\":\"845_Casco.pdf\"}', 1, '2026-06-10 09:45:28'),
(610, 'documente', 117, 'update', 'Document actualizat: CASCO () pentru B 825 NET', '{\"vehicul\":\"B 825 NET\",\"tip_document\":\"Casco\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-05\",\"fisier_original\":\"825_Casco_1.pdf\"}', '{\"vehicul\":\"B 825 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-05\",\"fisier_original\":\"825_Casco_1.pdf\"}', 1, '2026-06-10 09:46:00'),
(611, 'documente', 484, 'create', 'Document creat: CASCO () pentru B 925 NET', NULL, '{\"vehicul\":\"B 925 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-10 09:46:42'),
(612, 'documente', 192, 'update', 'Document actualizat: CASCO () pentru B 679 NET', '{\"vehicul\":\"B 679 NET\",\"tip_document\":\"Casco\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-25\",\"fisier_original\":\"POLITA_CASCO_FLOTA_2026.pdf\"}', '{\"vehicul\":\"B 679 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-25\",\"fisier_original\":\"POLITA_CASCO_FLOTA_2026.pdf\"}', 1, '2026-06-10 09:47:48'),
(613, 'documente', 199, 'update', 'Document actualizat: CASCO () pentru B 680 NET', '{\"vehicul\":\"B 680 NET\",\"tip_document\":\"Casco\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-25\",\"fisier_original\":\"POLITA_CASCO_FLOTA_2026.pdf\"}', '{\"vehicul\":\"B 680 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-25\",\"fisier_original\":\"POLITA_CASCO_FLOTA_2026.pdf\"}', 1, '2026-06-10 09:49:04'),
(614, 'documente', 177, 'update', 'Document actualizat: CASCO () pentru B 945 NET', '{\"vehicul\":\"B 945 NET\",\"tip_document\":\"Casco\",\"numar_document\":\"\",\"data_expirare\":\"2031-03-18\",\"fisier_original\":\"945_Casco_1.pdf\"}', '{\"vehicul\":\"B 945 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2031-03-18\",\"fisier_original\":\"945_Casco_1.pdf\"}', 1, '2026-06-10 09:49:36'),
(615, 'documente', 334, 'update', 'Document actualizat: METROLOGIE () pentru B 275 NET', '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2027-09-04\",\"fisier_original\":\"275_BRML.pdf\"}', '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2027-09-04\",\"fisier_original\":\"275_BRML.pdf\"}', 1, '2026-06-10 10:14:35'),
(616, 'documente', 336, 'update', 'Document actualizat: ORGANISM NOTIFICAT () pentru B 275 NET', '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"CNCIR\",\"numar_document\":\"\",\"data_expirare\":\"2028-01-14\",\"fisier_original\":\"275_CNCIR.pdf\"}', '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2028-01-14\",\"fisier_original\":\"275_CNCIR.pdf\"}', 1, '2026-06-10 10:15:18'),
(617, 'documente', 252, 'delete', 'Document sters: Brml () pentru B 295 NET', '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2028-03-31\",\"fisier_original\":\"295_BRML.pdf\"}', NULL, 3, '2026-06-10 10:15:33'),
(618, 'documente', 356, 'update', 'Document actualizat: METROLOGIE () pentru B 311 NET', '{\"vehicul\":\"B 311 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2026-11-09\",\"fisier_original\":\"311_BRML.pdf\"}', '{\"vehicul\":\"B 311 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2026-11-09\",\"fisier_original\":\"311_BRML.pdf\"}', 1, '2026-06-10 10:15:48'),
(619, 'documente', 261, 'update', 'Document actualizat: ORGANISM NOTIFICAT () pentru B 295 NET', '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"CNCIR\",\"numar_document\":\"\",\"data_expirare\":\"2028-01-14\",\"fisier_original\":\"295_CNCIR.pdf\"}', '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2028-01-14\",\"fisier_original\":\"295_CNCIR.pdf\"}', 3, '2026-06-10 10:16:10'),
(620, 'documente', 253, 'update', 'Document actualizat: METROLOGIE () pentru B 295 NET', '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2028-03-31\",\"fisier_original\":\"295_BRML.pdf\"}', '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2028-03-31\",\"fisier_original\":\"295_BRML.pdf\"}', 3, '2026-06-10 10:16:26'),
(621, 'documente', 358, 'delete', 'Document sters: Carte () pentru B 311 NET', '{\"vehicul\":\"B 311 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"311_Carte.pdf\"}', NULL, 1, '2026-06-10 10:16:41'),
(622, 'documente', 485, 'create', 'Document creat: RCA () pentru B 652 NET', NULL, '{\"vehicul\":\"B 652 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-25\",\"fisier_original\":\"652_RCA_1.pdf\"}', 3, '2026-06-10 10:19:24'),
(623, 'documente', 486, 'create', 'Document creat: Rovinieta () pentru B 652 NET', NULL, '{\"vehicul\":\"B 652 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-26\",\"fisier_original\":null}', 3, '2026-06-10 10:19:37'),
(624, 'documente', 487, 'create', 'Document creat: METROLOGIE () pentru B 335 NET', NULL, '{\"vehicul\":\"B 335 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2027-08-12\",\"fisier_original\":\"335_BRML.pdf\"}', 3, '2026-06-10 10:23:13'),
(625, 'documente', 488, 'create', 'Document creat: ORGANISM NOTIFICAT () pentru B 335 NET', NULL, '{\"vehicul\":\"B 335 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2027-07-30\",\"fisier_original\":\"335_TUV_1.pdf\"}', 3, '2026-06-10 10:23:33'),
(626, 'documente', 489, 'create', 'Document creat: Rovinieta () pentru B 645 NET', NULL, '{\"vehicul\":\"B 645 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-12\",\"fisier_original\":null}', 3, '2026-06-10 10:25:53'),
(627, 'documente', 490, 'create', 'Document creat: Tahograf () pentru B 400 NET', NULL, '{\"vehicul\":\"B 400 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-04\",\"fisier_original\":\"400_Taho_2.pdf\"}', 3, '2026-06-10 10:27:57'),
(628, 'documente', 491, 'create', 'Document creat: Rovinieta () pentru B 401 NET', NULL, '{\"vehicul\":\"B 401 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-10-16\",\"fisier_original\":null}', 3, '2026-06-10 10:30:06'),
(629, 'documente', 492, 'create', 'Document creat: Rovinieta () pentru B 402 NET', NULL, '{\"vehicul\":\"B 402 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2027-06-17\",\"fisier_original\":null}', 3, '2026-06-10 10:31:07'),
(630, 'documente', 493, 'create', 'Document creat: Rovinieta () pentru B 635 NET', NULL, '{\"vehicul\":\"B 635 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-15\",\"fisier_original\":null}', 3, '2026-06-10 10:35:43'),
(631, 'documente', 127, 'update', 'Document actualizat: RCA () pentru B 615 NET', '{\"vehicul\":\"B 615 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-01-26\",\"fisier_original\":\"615_Talon.pdf\"}', '{\"vehicul\":\"B 615 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-26\",\"fisier_original\":\"615_RCA.pdf\"}', 3, '2026-06-10 10:37:37'),
(632, 'documente', 494, 'create', 'Document creat: Rovinieta () pentru B 615 NET', NULL, '{\"vehicul\":\"B 615 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-04\",\"fisier_original\":null}', 3, '2026-06-10 10:38:16'),
(633, 'documente', 495, 'create', 'Document creat: Rovinieta () pentru B 625 NET', NULL, '{\"vehicul\":\"B 625 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2027-04-13\",\"fisier_original\":null}', 3, '2026-06-10 10:39:52'),
(634, 'documente', 496, 'create', 'Document creat: ORGANISM NOTIFICAT () pentru B 825 NET', NULL, '{\"vehicul\":\"B 825 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2027-12-30\",\"fisier_original\":\"825_TUV_2.pdf\"}', 3, '2026-06-10 10:41:01'),
(635, 'documente', 130, 'update', 'Document actualizat: METROLOGIE () pentru B 705 NET', '{\"vehicul\":\"B 705 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2026-11-08\",\"fisier_original\":\"705_BRML.pdf\"}', '{\"vehicul\":\"B 705 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2026-11-08\",\"fisier_original\":\"705_BRML.pdf\"}', 3, '2026-06-10 10:48:08'),
(636, 'documente', 136, 'update', 'Document actualizat: ORGANISM NOTIFICAT () pentru B 705 NET', '{\"vehicul\":\"B 705 NET\",\"tip_document\":\"Tuv\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-30\",\"fisier_original\":\"705_TUV_1.pdf\"}', '{\"vehicul\":\"B 705 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-30\",\"fisier_original\":\"705_TUV_1.pdf\"}', 3, '2026-06-10 10:48:25'),
(637, 'documente', 497, 'create', 'Document creat: CASCO () pentru B 105 NET', NULL, '{\"vehicul\":\"B 105 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-10 10:53:04'),
(638, 'documente', 498, 'create', 'Document creat: CASCO () pentru B 165 NET', NULL, '{\"vehicul\":\"B 165 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-10 10:53:42'),
(639, 'documente', 499, 'create', 'Document creat: CASCO () pentru B 189 NET', NULL, '{\"vehicul\":\"B 189 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-10 10:54:19'),
(640, 'documente', 223, 'update', 'Document actualizat: METROLOGIE () pentru B 189 NET', '{\"vehicul\":\"B 189 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2026-11-08\",\"fisier_original\":\"189_BRML.pdf\"}', '{\"vehicul\":\"B 189 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2026-11-08\",\"fisier_original\":\"189_BRML.pdf\"}', 1, '2026-06-10 10:55:07'),
(641, 'documente', 231, 'update', 'Document actualizat: ORGANISM NOTIFICAT () pentru B 189 NET', '{\"vehicul\":\"B 189 NET\",\"tip_document\":\"Tuv\",\"numar_document\":\"\",\"data_expirare\":\"2028-09-01\",\"fisier_original\":\"189_TUV.pdf\"}', '{\"vehicul\":\"B 189 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2028-09-01\",\"fisier_original\":\"189_TUV.pdf\"}', 1, '2026-06-10 10:55:26'),
(642, 'documente', 138, 'update', 'Document actualizat: METROLOGIE () pentru B 905 NET', '{\"vehicul\":\"B 905 NET\",\"tip_document\":\"Mid\",\"numar_document\":\"\",\"data_expirare\":\"2028-11-17\",\"fisier_original\":\"905_BRML_-_MID_1.pdf\"}', '{\"vehicul\":\"B 905 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2028-11-17\",\"fisier_original\":\"905_BRML_-_MID_1.pdf\"}', 3, '2026-06-10 10:55:36'),
(643, 'documente', 213, 'update', 'Document actualizat: METROLOGIE () pentru B 199 NET', '{\"vehicul\":\"B 199 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2026-08-18\",\"fisier_original\":\"199_BRML.pdf\"}', '{\"vehicul\":\"B 199 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2026-08-18\",\"fisier_original\":\"199_BRML.pdf\"}', 1, '2026-06-10 10:56:00'),
(644, 'documente', 221, 'update', 'Document actualizat: ORGANISM NOTIFICAT () pentru B 199 NET', '{\"vehicul\":\"B 199 NET\",\"tip_document\":\"Tuv\",\"numar_document\":\"\",\"data_expirare\":\"2027-06-10\",\"fisier_original\":\"199_TUV.pdf\"}', '{\"vehicul\":\"B 199 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2027-06-10\",\"fisier_original\":\"199_TUV.pdf\"}', 1, '2026-06-10 10:56:38'),
(645, 'documente', 500, 'create', 'Document creat: CASCO () pentru B 199 NET', NULL, '{\"vehicul\":\"B 199 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-10 10:57:23'),
(646, 'documente', 501, 'create', 'Document creat: CASCO () pentru B 218 NET', NULL, '{\"vehicul\":\"B 218 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-10 10:57:59'),
(647, 'documente', 371, 'update', 'Document actualizat: METROLOGIE () pentru B 219 NET', '{\"vehicul\":\"B 219 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2026-11-09\",\"fisier_original\":\"219_BRML.pdf\"}', '{\"vehicul\":\"B 219 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2026-11-09\",\"fisier_original\":\"219_BRML.pdf\"}', 1, '2026-06-10 10:58:24'),
(648, 'documente', 502, 'create', 'Document creat: ORGANISM NOTIFICAT () pentru B 905 NET', NULL, '{\"vehicul\":\"B 905 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-28\",\"fisier_original\":\"905_TUV_2.pdf\"}', 3, '2026-06-10 10:58:38'),
(649, 'documente', 375, 'update', 'Document actualizat: ORGANISM NOTIFICAT () pentru B 219 NET', '{\"vehicul\":\"B 219 NET\",\"tip_document\":\"Tuv\",\"numar_document\":\"\",\"data_expirare\":\"2029-03-30\",\"fisier_original\":\"219_TUV_1.pdf\"}', '{\"vehicul\":\"B 219 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2029-03-30\",\"fisier_original\":\"219_TUV_1.pdf\"}', 1, '2026-06-10 10:58:57'),
(650, 'documente', 503, 'create', 'Document creat: CASCO () pentru B 219 NET', NULL, '{\"vehicul\":\"B 219 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-10 10:59:29'),
(651, 'documente', 504, 'create', 'Document creat: ADR () pentru B 311 NET', NULL, '{\"vehicul\":\"B 311 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-08\",\"fisier_original\":\"311ADR.pdf\"}', 3, '2026-06-10 11:00:22'),
(652, 'documente', 505, 'create', 'Document creat: ORGANISM NOTIFICAT () pentru B 311 NET', NULL, '{\"vehicul\":\"B 311 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-30\",\"fisier_original\":\"311_TUV_1.pdf\"}', 3, '2026-06-10 11:01:02'),
(653, 'documente', 506, 'create', 'Document creat: IPROCHIM () pentru B 219 NET', NULL, '{\"vehicul\":\"B 219 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-31\",\"fisier_original\":\"219_Iptochim.pdf\"}', 1, '2026-06-10 11:01:18'),
(654, 'documente', 507, 'create', 'Document creat: RCA () pentru B 311 NET', NULL, '{\"vehicul\":\"B 311 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-20\",\"fisier_original\":\"311_RCA.pdf\"}', 3, '2026-06-10 11:01:22'),
(655, 'documente', 508, 'create', 'Document creat: Rovinieta () pentru B 311 NET', NULL, '{\"vehicul\":\"B 311 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-23\",\"fisier_original\":null}', 3, '2026-06-10 11:01:32'),
(656, 'documente', 509, 'create', 'Document creat: Tahograf () pentru B 311 NET', NULL, '{\"vehicul\":\"B 311 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-08\",\"fisier_original\":\"311_Taho_1.pdf\"}', 3, '2026-06-10 11:01:45'),
(657, 'documente', 510, 'create', 'Document creat: ORGANISM NOTIFICAT () pentru B 235 NET', NULL, '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2027-08-30\",\"fisier_original\":\"Cncir_235.pdf\"}', 1, '2026-06-10 11:04:39'),
(658, 'documente', 511, 'create', 'Document creat: CASCO () pentru B 235 NET', NULL, '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-10 11:05:13'),
(659, 'documente', 193, 'update', 'Document actualizat: ORGANISM NOTIFICAT () pentru B 679 NET', '{\"vehicul\":\"B 679 NET\",\"tip_document\":\"CNCIR\",\"numar_document\":\"\",\"data_expirare\":\"2028-03-15\",\"fisier_original\":\"679_CNCIR_1.pdf\"}', '{\"vehicul\":\"B 679 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2028-03-15\",\"fisier_original\":\"679_CNCIR_1.pdf\"}', 3, '2026-06-10 11:05:13'),
(660, 'documente', 52, 'delete', 'Document sters: RCA () pentru B 235 NET', '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-14\",\"fisier_original\":\"235_RCA.pdf\"}', NULL, 1, '2026-06-10 11:05:32'),
(661, 'documente', 200, 'update', 'Document actualizat: ORGANISM NOTIFICAT () pentru B 680 NET', '{\"vehicul\":\"B 680 NET\",\"tip_document\":\"CNCIR\",\"numar_document\":\"\",\"data_expirare\":\"2028-03-15\",\"fisier_original\":\"680_CNCIR_1.pdf\"}', '{\"vehicul\":\"B 680 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2028-03-15\",\"fisier_original\":\"680_CNCIR_1.pdf\"}', 3, '2026-06-10 11:05:45'),
(662, 'documente', 512, 'create', 'Document creat: ADR () pentru B 235 NET', NULL, '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-22\",\"fisier_original\":\"235_ADR.pdf\"}', 1, '2026-06-10 11:08:05');
INSERT INTO `audit_log` (`id`, `modul`, `record_id`, `actiune`, `descriere`, `before_data`, `after_data`, `user_id`, `created_at`) VALUES
(663, 'documente', 513, 'create', 'Document creat: CASCO () pentru B 275 NET', NULL, '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-10 11:09:02'),
(664, 'documente', 514, 'create', 'Document creat: CASCO () pentru B 285 NET', NULL, '{\"vehicul\":\"B 285 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-10 11:09:49'),
(665, 'documente', 515, 'create', 'Document creat: CASCO () pentru B 295 NET', NULL, '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-10 11:10:25'),
(666, 'documente', 516, 'create', 'Document creat: CASCO () pentru B 311 NET', NULL, '{\"vehicul\":\"B 311 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-10 11:11:05'),
(667, 'documente', 517, 'create', 'Document creat: CASCO () pentru B 315 NET', NULL, '{\"vehicul\":\"B 315 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-10 11:12:12'),
(668, 'documente', 518, 'create', 'Document creat: CASCO () pentru B 325 NET', NULL, '{\"vehicul\":\"B 325 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-10 11:12:54'),
(669, 'documente', 519, 'create', 'Document creat: CASCO () pentru B 335 NET', NULL, '{\"vehicul\":\"B 335 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-10 11:13:35'),
(670, 'documente', 520, 'create', 'Document creat: RCA () pentru B 335 NET', NULL, '{\"vehicul\":\"B 335 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-28\",\"fisier_original\":\"335_RCA.pdf\"}', 1, '2026-06-10 11:14:50'),
(671, 'documente', 521, 'create', 'Document creat: CASCO () pentru B 335 NET', NULL, '{\"vehicul\":\"B 335 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-10 11:15:02'),
(672, 'documente', 522, 'create', 'Document creat: Tahograf () pentru B 439 NET', NULL, '{\"vehicul\":\"B 439 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-12\",\"fisier_original\":\"439_Taho_1.pdf\"}', 3, '2026-06-10 11:15:44'),
(673, 'documente', 523, 'create', 'Document creat: Copie conforma () pentru B 375 NET', NULL, '{\"vehicul\":\"B 375 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_375.pdf\"}', 1, '2026-06-10 11:16:59'),
(674, 'documente', 524, 'create', 'Document creat: METROLOGIE () pentru B 375 NET', NULL, '{\"vehicul\":\"B 375 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2027-07-04\",\"fisier_original\":\"375_BRML.pdf\"}', 1, '2026-06-10 11:18:27'),
(675, 'documente', 525, 'create', 'Document creat: IPROCHIM () pentru B 375 NET', NULL, '{\"vehicul\":\"B 375 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-23\",\"fisier_original\":\"375_Iprochim.pdf\"}', 1, '2026-06-10 11:19:49'),
(676, 'documente', 526, 'create', 'Document creat: ORGANISM NOTIFICAT () pentru B 375 NET', NULL, '{\"vehicul\":\"B 375 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2028-01-01\",\"fisier_original\":\"375_TUV.pdf\"}', 1, '2026-06-10 11:21:03'),
(677, 'documente', 527, 'create', 'Document creat: ORGANISM NOTIFICAT () pentru B 439 NET', NULL, '{\"vehicul\":\"B 439 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-26\",\"fisier_original\":\"tuv_439.pdf\"}', 3, '2026-06-10 11:21:11'),
(678, 'documente', 528, 'create', 'Document creat: CASCO () pentru B 375 NET', NULL, '{\"vehicul\":\"B 375 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-10 11:21:22'),
(679, 'documente', 529, 'create', 'Document creat: ADR () pentru B 375 NET', NULL, '{\"vehicul\":\"B 375 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-04\",\"fisier_original\":\"375_ADR.pdf\"}', 1, '2026-06-10 11:22:55'),
(680, 'documente', 250, 'update', 'Document actualizat: ORGANISM NOTIFICAT () pentru B 433 NET', '{\"vehicul\":\"B 433 NET\",\"tip_document\":\"Tuv\",\"numar_document\":\"\",\"data_expirare\":\"2029-01-31\",\"fisier_original\":\"433_TUV..pdf\"}', '{\"vehicul\":\"B 433 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2029-01-31\",\"fisier_original\":\"433_TUV..pdf\"}', 3, '2026-06-10 11:22:55'),
(681, 'documente', 241, 'update', 'Document actualizat: METROLOGIE () pentru B 433 NET', '{\"vehicul\":\"B 433 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-26\",\"fisier_original\":\"433_BRML.pdf\"}', '{\"vehicul\":\"B 433 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-26\",\"fisier_original\":\"433_BRML.pdf\"}', 3, '2026-06-10 11:23:09'),
(682, 'documente', 263, 'update', 'Document actualizat: METROLOGIE () pentru B 232 NET', '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2026-11-08\",\"fisier_original\":\"232_BRML.pdf\"}', '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2026-11-08\",\"fisier_original\":\"232_BRML.pdf\"}', 3, '2026-06-10 11:23:34'),
(683, 'documente', 272, 'update', 'Document actualizat: ORGANISM NOTIFICAT () pentru B 232 NET', '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"Tuv\",\"numar_document\":\"\",\"data_expirare\":\"2029-06-30\",\"fisier_original\":\"232TUV.pdf\"}', '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2029-06-30\",\"fisier_original\":\"232TUV.pdf\"}', 3, '2026-06-10 11:23:45'),
(684, 'documente', 304, 'update', 'Document actualizat: METROLOGIE () pentru B 435 NET', '{\"vehicul\":\"B 435 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-25\",\"fisier_original\":\"435_BRML.pdf\"}', '{\"vehicul\":\"B 435 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-25\",\"fisier_original\":\"435_BRML.pdf\"}', 3, '2026-06-10 11:24:28'),
(685, 'documente', 313, 'update', 'Document actualizat: ORGANISM NOTIFICAT () pentru B 435 NET', '{\"vehicul\":\"B 435 NET\",\"tip_document\":\"Tuv\",\"numar_document\":\"\",\"data_expirare\":\"2029-02-28\",\"fisier_original\":\"435_TUV_1.pdf\"}', '{\"vehicul\":\"B 435 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2029-02-28\",\"fisier_original\":\"435_TUV_1.pdf\"}', 3, '2026-06-10 11:24:36'),
(686, 'documente', 530, 'create', 'Document creat: Tahograf () pentru B 375 NET', NULL, '{\"vehicul\":\"B 375 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2028-03-20\",\"fisier_original\":null}', 1, '2026-06-10 11:25:28'),
(687, 'documente', 332, 'update', 'Document actualizat: ORGANISM NOTIFICAT () pentru B 775 NET', '{\"vehicul\":\"B 775 NET\",\"tip_document\":\"Tuv\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-30\",\"fisier_original\":\"775_TUV_2.pdf\"}', '{\"vehicul\":\"B 775 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-30\",\"fisier_original\":\"775_TUV_2.pdf\"}', 3, '2026-06-10 11:25:33'),
(688, 'documente', 323, 'update', 'Document actualizat: METROLOGIE () pentru B 775 NET', '{\"vehicul\":\"B 775 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2028-11-17\",\"fisier_original\":\"775_BRML-MID_1.pdf\"}', '{\"vehicul\":\"B 775 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2028-11-17\",\"fisier_original\":\"775_BRML-MID_1.pdf\"}', 3, '2026-06-10 11:25:41'),
(689, 'documente', 345, 'update', 'Document actualizat: METROLOGIE () pentru B 430 NET', '{\"vehicul\":\"B 430 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2027-04-19\",\"fisier_original\":\"430_BRML.pdf\"}', '{\"vehicul\":\"B 430 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2027-04-19\",\"fisier_original\":\"430_BRML.pdf\"}', 3, '2026-06-10 11:26:12'),
(690, 'documente', 354, 'update', 'Document actualizat: ORGANISM NOTIFICAT () pentru B 430 NET', '{\"vehicul\":\"B 430 NET\",\"tip_document\":\"Tuv\",\"numar_document\":\"\",\"data_expirare\":\"2028-06-30\",\"fisier_original\":\"430_TUV.pdf\"}', '{\"vehicul\":\"B 430 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2028-06-30\",\"fisier_original\":\"430_TUV.pdf\"}', 3, '2026-06-10 11:26:22'),
(691, 'documente', 531, 'create', 'Document creat: CASCO () pentru B 385 NET', NULL, '{\"vehicul\":\"B 385 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-10 11:27:10'),
(692, 'documente', 369, 'update', 'Document actualizat: ORGANISM NOTIFICAT () pentru B 345 NET', '{\"vehicul\":\"B 345 NET\",\"tip_document\":\"Tuv\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-01\",\"fisier_original\":\"345_TUV.pdf\"}', '{\"vehicul\":\"B 345 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-01\",\"fisier_original\":\"345_TUV.pdf\"}', 3, '2026-06-10 11:27:10'),
(693, 'documente', 363, 'update', 'Document actualizat: METROLOGIE () pentru B 345 NET', '{\"vehicul\":\"B 345 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2028-11-30\",\"fisier_original\":\"345_BRML-MID.pdf\"}', '{\"vehicul\":\"B 345 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2028-11-30\",\"fisier_original\":\"345_BRML-MID.pdf\"}', 3, '2026-06-10 11:27:19'),
(694, 'documente', 532, 'create', 'Document creat: CASCO () pentru B 395 NET', NULL, '{\"vehicul\":\"B 395 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-10 11:27:51'),
(695, 'documente', 533, 'create', 'Document creat: Copie conforma () pentru B 430 NET', NULL, '{\"vehicul\":\"B 430 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_430.pdf\"}', 3, '2026-06-10 11:28:52'),
(696, 'documente', 534, 'create', 'Document creat: Rovinieta () pentru B 395 NET', NULL, '{\"vehicul\":\"B 395 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2027-04-26\",\"fisier_original\":null}', 1, '2026-06-10 11:30:26'),
(697, 'documente', 535, 'create', 'Document creat: CASCO () pentru B 400 NET', NULL, '{\"vehicul\":\"B 400 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-10 11:31:17'),
(698, 'documente', 536, 'create', 'Document creat: CASCO () pentru B 401 NET', NULL, '{\"vehicul\":\"B 401 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-10 11:31:51'),
(699, 'documente', 537, 'create', 'Document creat: CASCO () pentru B 402 NET', NULL, '{\"vehicul\":\"B 402 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-10 11:32:24'),
(700, 'documente', 538, 'create', 'Document creat: CASCO () pentru B 605 NET', NULL, '{\"vehicul\":\"B 605 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-10 11:33:11'),
(701, 'documente', 539, 'create', 'Document creat: CASCO () pentru B 625 NET', NULL, '{\"vehicul\":\"B 625 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-10 11:42:36'),
(702, 'documente', 540, 'create', 'Document creat: CASCO () pentru B 635 NET', NULL, '{\"vehicul\":\"B 635 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2026-01-01\",\"fisier_original\":null}', 1, '2026-06-10 11:43:17'),
(703, 'documente', 540, 'update', 'Document actualizat: CASCO () pentru B 635 NET', '{\"vehicul\":\"B 635 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2026-01-01\",\"fisier_original\":null}', '{\"vehicul\":\"B 635 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-10 11:43:53'),
(704, 'documente', 541, 'create', 'Document creat: CASCO () pentru B 805 NET', NULL, '{\"vehicul\":\"B 805 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-10 11:44:28'),
(705, 'documente', 73, 'update', 'Document actualizat: ORGANISM NOTIFICAT () pentru B 835 NET', '{\"vehicul\":\"B 835 NET\",\"tip_document\":\"Tuv\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-30\",\"fisier_original\":null}', '{\"vehicul\":\"B 835 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-30\",\"fisier_original\":null}', 1, '2026-06-10 11:45:02'),
(706, 'documente', 542, 'create', 'Document creat: CASCO () pentru B 935 NET', NULL, '{\"vehicul\":\"B 935 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-10 11:47:20'),
(707, 'documente', 543, 'create', 'Document creat: ORGANISM NOTIFICAT () pentru B 925 NET', NULL, '{\"vehicul\":\"B 925 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2027-10-01\",\"fisier_original\":\"925_Iprochim.pdf\"}', 1, '2026-06-10 14:05:31'),
(708, 'documente', 544, 'create', 'Document creat: METROLOGIE () pentru B 925 NET', NULL, '{\"vehicul\":\"B 925 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2027-12-31\",\"fisier_original\":null}', 1, '2026-06-10 14:08:59'),
(709, 'documente', 545, 'create', 'Document creat: ORGANISM NOTIFICAT () pentru B 845 NET', NULL, '{\"vehicul\":\"B 845 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2028-03-01\",\"fisier_original\":\"org_845.pdf\"}', 1, '2026-06-10 14:22:23'),
(710, 'documente', 546, 'create', 'Document creat: ORGANISM NOTIFICAT () pentru B 945 NET', NULL, '{\"vehicul\":\"B 945 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2029-01-01\",\"fisier_original\":\"org_945.pdf\"}', 1, '2026-06-10 14:24:44'),
(711, 'documente', 470, 'update', 'Document actualizat: ITP () pentru B 395 NET', '{\"vehicul\":\"B 395 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-04\",\"fisier_original\":\"395_Talon.pdf\"}', '{\"vehicul\":\"B 395 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-28\",\"fisier_original\":null}', 3, '2026-06-11 10:22:23'),
(712, 'documente', 466, 'update', 'Document actualizat: ADR () pentru B 395 NET', '{\"vehicul\":\"B 395 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-04\",\"fisier_original\":\"315_ADR.pdf\"}', '{\"vehicul\":\"B 395 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-28\",\"fisier_original\":null}', 3, '2026-06-11 10:24:08'),
(713, 'documente', 547, 'create', 'Document creat: Tahograf () pentru B 395 NET', NULL, '{\"vehicul\":\"B 395 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2028-04-23\",\"fisier_original\":null}', 3, '2026-06-11 10:24:53'),
(714, 'documente', 547, 'update', 'Document actualizat: Tahograf () pentru B 395 NET', '{\"vehicul\":\"B 395 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2028-04-23\",\"fisier_original\":null}', '{\"vehicul\":\"B 395 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2028-04-23\",\"fisier_original\":\"TAHOGRAF_395.pdf\"}', 3, '2026-06-11 10:28:59'),
(715, 'documente', 470, 'update', 'Document actualizat: ITP () pentru B 395 NET', '{\"vehicul\":\"B 395 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-28\",\"fisier_original\":null}', '{\"vehicul\":\"B 395 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-28\",\"fisier_original\":\"TALON_395.pdf\"}', 3, '2026-06-11 10:29:49'),
(716, 'documente', 466, 'update', 'Document actualizat: ADR () pentru B 395 NET', '{\"vehicul\":\"B 395 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-28\",\"fisier_original\":null}', '{\"vehicul\":\"B 395 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-28\",\"fisier_original\":\"ADR_395.pdf\"}', 3, '2026-06-11 10:31:21'),
(717, 'documente', 344, 'delete', 'Document sters: ADR () pentru B 430 NET', '{\"vehicul\":\"B 430 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-26\",\"fisier_original\":\"430_ADR.pdf\"}', NULL, 1, '2026-06-11 10:37:09'),
(718, 'documente', 268, 'update', 'Document actualizat: ITP () pentru B 232 NET', '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-11\",\"fisier_original\":\"232_Talon.pdf\"}', '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-06-09\",\"fisier_original\":\"232_talon.pdf\"}', 3, '2026-06-11 10:39:39'),
(719, 'documente', 262, 'update', 'Document actualizat: ADR () pentru B 232 NET', '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-11\",\"fisier_original\":\"232_ADR.pdf\"}', '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-06-09\",\"fisier_original\":\"232_adr.pdf\"}', 3, '2026-06-11 10:41:01'),
(720, 'documente', 377, 'update', 'Document actualizat: METROLOGIE () pentru B 437 NET', '{\"vehicul\":\"B 437 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-11\",\"fisier_original\":\"437_BRML.pdf\"}', '{\"vehicul\":\"B 437 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-11\",\"fisier_original\":\"437_BRML.pdf\"}', 3, '2026-06-11 11:45:26'),
(721, 'documente', 383, 'update', 'Document actualizat: ORGANISM NOTIFICAT () pentru B 437 NET', '{\"vehicul\":\"B 437 NET\",\"tip_document\":\"Tuv\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-30\",\"fisier_original\":\"437_TUV.pdf\"}', '{\"vehicul\":\"B 437 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-30\",\"fisier_original\":\"437_TUV.pdf\"}', 3, '2026-06-11 11:45:38'),
(722, 'documente', 548, 'create', 'Document creat: Carte () pentru B 34 NET', NULL, '{\"vehicul\":\"B 34 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"34_CARTE.pdf\"}', 3, '2026-06-11 11:55:55'),
(723, 'documente', 549, 'create', 'Document creat: ITP () pentru B 34 NET', NULL, '{\"vehicul\":\"B 34 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-24\",\"fisier_original\":\"34_Talon_1.pdf\"}', 3, '2026-06-11 11:56:13'),
(724, 'documente', 550, 'create', 'Document creat: RCA () pentru B 34 NET', NULL, '{\"vehicul\":\"B 34 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-01\",\"fisier_original\":\"34_RCA.pdf\"}', 3, '2026-06-11 11:56:30'),
(725, 'documente', 551, 'create', 'Document creat: Rovinieta () pentru B 34 NET', NULL, '{\"vehicul\":\"B 34 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-28\",\"fisier_original\":null}', 3, '2026-06-11 11:56:45'),
(726, 'documente', 552, 'create', 'Document creat: Carte () pentru B 72 NET', NULL, '{\"vehicul\":\"B 72 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":null}', 3, '2026-06-11 12:02:58'),
(727, 'documente', 553, 'create', 'Document creat: ITP () pentru B 72 NET', NULL, '{\"vehicul\":\"B 72 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-12\",\"fisier_original\":\"72_talon.pdf\"}', 3, '2026-06-11 12:19:45'),
(728, 'documente', 554, 'create', 'Document creat: RCA () pentru B 72 NET', NULL, '{\"vehicul\":\"B 72 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-12\",\"fisier_original\":\"72_RCA_1.pdf\"}', 3, '2026-06-11 12:20:15'),
(729, 'documente', 555, 'create', 'Document creat: Rovinieta () pentru B 72 NET', NULL, '{\"vehicul\":\"B 72 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-07\",\"fisier_original\":null}', 3, '2026-06-11 12:20:26'),
(730, 'documente', 556, 'create', 'Document creat: Carte () pentru B 82 NET', NULL, '{\"vehicul\":\"B 82 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"82_carte.pdf\"}', 3, '2026-06-11 12:42:55'),
(731, 'documente', 557, 'create', 'Document creat: ITP () pentru B 82 NET', NULL, '{\"vehicul\":\"B 82 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-16\",\"fisier_original\":\"82_talon.jpg\"}', 3, '2026-06-11 12:45:54'),
(732, 'documente', 558, 'create', 'Document creat: Carte () pentru B 177 NET', NULL, '{\"vehicul\":\"B 177 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"177_carteb.pdf\"}', 3, '2026-06-11 14:31:40'),
(733, 'documente', 559, 'create', 'Document creat: CASCO () pentru B 177 NET', NULL, '{\"vehicul\":\"B 177 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2029-02-15\",\"fisier_original\":\"casco_177.pdf\"}', 3, '2026-06-11 14:34:31'),
(734, 'documente', 560, 'create', 'Document creat: ITP () pentru B 177 NET', NULL, '{\"vehicul\":\"B 177 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2028-01-08\",\"fisier_original\":\"177_Talon_2.pdf\"}', 3, '2026-06-11 14:34:53'),
(735, 'documente', 561, 'create', 'Document creat: RCA () pentru B 177 NET', NULL, '{\"vehicul\":\"B 177 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2028-01-15\",\"fisier_original\":\"rca_177.pdf\"}', 3, '2026-06-11 14:35:10'),
(736, 'documente', 562, 'create', 'Document creat: Rovinieta () pentru B 177 NET', NULL, '{\"vehicul\":\"B 177 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-26\",\"fisier_original\":null}', 3, '2026-06-11 14:35:33'),
(737, 'documente', 527, 'update', 'Document actualizat: ORGANISM NOTIFICAT () pentru B 439 NET', '{\"vehicul\":\"B 439 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-26\",\"fisier_original\":\"tuv_439.pdf\"}', '{\"vehicul\":\"B 439 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-26\",\"fisier_original\":\"439_TUV.pdf\"}', 3, '2026-06-11 16:30:17'),
(738, 'documente', 563, 'create', 'Document creat: Carte (G437030) pentru B 112 NET', NULL, '{\"vehicul\":\"B 112 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"G437030\",\"data_expirare\":null,\"fisier_original\":\"112_carte.pdf\"}', 3, '2026-06-12 10:53:43'),
(739, 'documente', 564, 'create', 'Document creat: ITP () pentru B 112 NET', NULL, '{\"vehicul\":\"B 112 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-04-27\",\"fisier_original\":\"112_itp.pdf\"}', 3, '2026-06-12 10:54:05'),
(740, 'documente', 565, 'create', 'Document creat: RCA () pentru B 112 NET', NULL, '{\"vehicul\":\"B 112 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-09\",\"fisier_original\":\"112_rca.pdf\"}', 3, '2026-06-12 10:54:53'),
(741, 'documente', 566, 'create', 'Document creat: Rovinieta () pentru B 112 NET', NULL, '{\"vehicul\":\"B 112 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2027-04-26\",\"fisier_original\":null}', 3, '2026-06-12 10:55:38'),
(742, 'documente', 567, 'create', 'Document creat: Carte () pentru B 875 NET', NULL, '{\"vehicul\":\"B 875 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"875_CARTE.pdf\"}', 3, '2026-06-12 11:17:40'),
(743, 'documente', 568, 'create', 'Document creat: ITP () pentru B 875 NET', NULL, '{\"vehicul\":\"B 875 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-06-12\",\"fisier_original\":\"875_certificat_ITP.pdf\"}', 3, '2026-06-12 11:18:30'),
(744, 'documente', 569, 'create', 'Document creat: RCA () pentru B 875 NET', NULL, '{\"vehicul\":\"B 875 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-15\",\"fisier_original\":\"875_RCA.pdf\"}', 3, '2026-06-12 11:18:48'),
(745, 'documente', 570, 'create', 'Document creat: Rovinieta () pentru B 875 NET', NULL, '{\"vehicul\":\"B 875 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-04\",\"fisier_original\":null}', 3, '2026-06-12 11:19:04'),
(746, 'documente', 571, 'create', 'Document creat: Carte () pentru B 669 NET', NULL, '{\"vehicul\":\"B 669 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"669_carte.pdf\"}', 3, '2026-06-12 11:42:43'),
(747, 'documente', 572, 'create', 'Document creat: ITP () pentru B 669 NET', NULL, '{\"vehicul\":\"B 669 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-02\",\"fisier_original\":null}', 3, '2026-06-12 11:42:54'),
(748, 'documente', 573, 'create', 'Document creat: RCA () pentru B 669 NET', NULL, '{\"vehicul\":\"B 669 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-27\",\"fisier_original\":\"669_RCA.pdf\"}', 3, '2026-06-12 11:43:19'),
(749, 'documente', 574, 'create', 'Document creat: Rovinieta () pentru B 669 NET', NULL, '{\"vehicul\":\"B 669 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-13\",\"fisier_original\":null}', 3, '2026-06-12 11:43:29'),
(750, 'documente', 575, 'create', 'Document creat: Carte () pentru B 888 NET', NULL, '{\"vehicul\":\"B 888 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"carte_888.pdf\"}', 3, '2026-06-12 12:23:57'),
(751, 'documente', 576, 'create', 'Document creat: ITP () pentru B 888 NET', NULL, '{\"vehicul\":\"B 888 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-24\",\"fisier_original\":\"talon_888.pdf\"}', 3, '2026-06-12 12:24:31'),
(752, 'documente', 577, 'create', 'Document creat: RCA () pentru B 888 NET', NULL, '{\"vehicul\":\"B 888 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-20\",\"fisier_original\":\"888_rca.pdf\"}', 3, '2026-06-12 12:25:02'),
(753, 'documente', 578, 'create', 'Document creat: Rovinieta () pentru B 888 NET', NULL, '{\"vehicul\":\"B 888 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-26\",\"fisier_original\":null}', 3, '2026-06-12 12:26:13'),
(754, 'documente', 579, 'create', 'Document creat: Carte () pentru B 230 NET', NULL, '{\"vehicul\":\"B 230 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"230_Carte_1.pdf\"}', 3, '2026-06-12 12:28:27'),
(755, 'documente', 580, 'create', 'Document creat: ITP () pentru B 230 NET', NULL, '{\"vehicul\":\"B 230 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-08-18\",\"fisier_original\":\"230_Talon_1.pdf\"}', 3, '2026-06-12 12:30:20'),
(756, 'documente', 581, 'create', 'Document creat: RCA () pentru B 230 NET', NULL, '{\"vehicul\":\"B 230 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-14\",\"fisier_original\":\"230_RCA_1.pdf\"}', 3, '2026-06-12 12:30:53'),
(757, 'documente', 582, 'create', 'Document creat: Rovinieta () pentru B 230 NET', NULL, '{\"vehicul\":\"B 230 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-10-16\",\"fisier_original\":null}', 3, '2026-06-12 12:34:46'),
(758, 'documente', 583, 'create', 'Document creat: Carte () pentru B 184 DFA', NULL, '{\"vehicul\":\"B 184 DFA\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"184_carte.pdf\"}', 3, '2026-06-12 13:05:53'),
(759, 'documente', 584, 'create', 'Document creat: ITP () pentru B 184 DFA', NULL, '{\"vehicul\":\"B 184 DFA\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-19\",\"fisier_original\":null}', 3, '2026-06-12 13:06:58'),
(760, 'documente', 585, 'create', 'Document creat: RCA () pentru B 184 DFA', NULL, '{\"vehicul\":\"B 184 DFA\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-29\",\"fisier_original\":\"184_rca.pdf\"}', 3, '2026-06-12 13:07:21'),
(761, 'documente', 586, 'create', 'Document creat: Rovinieta () pentru B 184 DFA', NULL, '{\"vehicul\":\"B 184 DFA\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-18\",\"fisier_original\":null}', 3, '2026-06-12 13:08:59'),
(762, 'documente', 587, 'create', 'Document creat: ORGANISM NOTIFICAT () pentru B 915 NET', NULL, '{\"vehicul\":\"B 915 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2028-01-01\",\"fisier_original\":null}', 3, '2026-06-12 13:20:10'),
(763, 'documente', 588, 'create', 'Document creat: METROLOGIE () pentru B 439 NET', NULL, '{\"vehicul\":\"B 439 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2025-06-17\",\"fisier_original\":null}', 3, '2026-06-12 13:41:52'),
(764, 'documente', 589, 'create', 'Document creat: ORGANISM NOTIFICAT () pentru B 935 NET', NULL, '{\"vehicul\":\"B 935 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-16\",\"fisier_original\":\"935_CNCIR_PED_2.pdf\"}', 3, '2026-06-12 13:44:23'),
(765, 'documente', 590, 'create', 'Document creat: CASCO () pentru B 34 NET', NULL, '{\"vehicul\":\"B 34 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-11\",\"fisier_original\":null}', 1, '2026-06-12 14:14:05'),
(766, 'documente', 590, 'update', 'Document actualizat: CASCO () pentru B 34 NET', '{\"vehicul\":\"B 34 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-11\",\"fisier_original\":null}', '{\"vehicul\":\"B 34 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-12 14:15:01'),
(767, 'documente', 591, 'create', 'Document creat: CASCO () pentru B 72 NET', NULL, '{\"vehicul\":\"B 72 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-12 14:16:01'),
(768, 'documente', 592, 'create', 'Document creat: CASCO () pentru B 82 NET', NULL, '{\"vehicul\":\"B 82 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-12 14:16:59'),
(769, 'documente', 593, 'create', 'Document creat: CASCO () pentru B 112 NET', NULL, '{\"vehicul\":\"B 112 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-12 14:17:45'),
(770, 'documente', 594, 'create', 'Document creat: CASCO () pentru B 184 DFA', NULL, '{\"vehicul\":\"B 184 DFA\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-12 14:18:45'),
(771, 'documente', 595, 'create', 'Document creat: CASCO () pentru B 669 NET', NULL, '{\"vehicul\":\"B 669 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-12 14:19:12'),
(772, 'documente', 596, 'create', 'Document creat: CASCO () pentru B 875 NET', NULL, '{\"vehicul\":\"B 875 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-12 14:19:44'),
(773, 'documente', 597, 'create', 'Document creat: CASCO () pentru B 875 NET', NULL, '{\"vehicul\":\"B 875 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-12 14:20:26'),
(774, 'documente', 598, 'create', 'Document creat: CASCO () pentru B 888 NET', NULL, '{\"vehicul\":\"B 888 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-12 14:21:21'),
(775, 'documente', 599, 'create', 'Document creat: RCA () pentru B 82 NET', NULL, '{\"vehicul\":\"B 82 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-03-11\",\"fisier_original\":\"82_RCA_2.pdf\"}', 3, '2026-06-12 14:27:12'),
(776, 'documente', 600, 'create', 'Document creat: Rovinieta () pentru B 82 NET', NULL, '{\"vehicul\":\"B 82 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-12\",\"fisier_original\":null}', 3, '2026-06-12 14:27:32'),
(777, 'documente', 268, 'update', 'Document actualizat: ITP (B04824705) pentru B 232 NET', '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-06-09\",\"fisier_original\":\"232_talon.pdf\"}', '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"B04824705\",\"data_expirare\":\"2027-06-09\",\"fisier_original\":\"232_talon.pdf\"}', 3, '2026-06-12 14:36:29'),
(778, 'documente', 262, 'update', 'Document actualizat: ADR (OBBM4481) pentru B 232 NET', '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-06-09\",\"fisier_original\":\"232_adr.pdf\"}', '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"OBBM4481\",\"data_expirare\":\"2027-06-09\",\"fisier_original\":\"232_adr.pdf\"}', 3, '2026-06-12 14:37:18'),
(779, 'documente', 271, 'update', 'Document actualizat: Tahograf (D229608) pentru B 232 NET', '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2026-08-09\",\"fisier_original\":\"232_Taho.pdf\"}', '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"D229608\",\"data_expirare\":\"2026-08-09\",\"fisier_original\":\"232_Taho.pdf\"}', 3, '2026-06-12 14:37:37'),
(780, 'documente', 269, 'update', 'Document actualizat: RCA (128864441) pentru B 232 NET', '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-22\",\"fisier_original\":\"232_RCA_1.pdf\"}', '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"128864441\",\"data_expirare\":\"2027-05-22\",\"fisier_original\":\"232_RCA_1.pdf\"}', 3, '2026-06-12 14:42:27'),
(781, 'documente', 265, 'update', 'Document actualizat: CASCO (C3588512) pentru B 232 NET', '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-05-27\",\"fisier_original\":\"POLITA_CASCO_FLOTA_2026_2.pdf\"}', '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"C3588512\",\"data_expirare\":\"2027-05-27\",\"fisier_original\":\"POLITA_CASCO_FLOTA_2026_2.pdf\"}', 3, '2026-06-12 14:44:18'),
(782, 'documente', 266, 'update', 'Document actualizat: Copie conforma (3019149) pentru B 232 NET', '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_232.pdf\"}', '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"3019149\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_232.pdf\"}', 3, '2026-06-12 14:44:51'),
(783, 'documente', 267, 'update', 'Document actualizat: IPROCHIM (85998) pentru B 232 NET', '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2027-06-09\",\"fisier_original\":\"232-IPRO_1.pdf\"}', '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"85998\",\"data_expirare\":\"2027-06-09\",\"fisier_original\":\"232-IPRO_1.pdf\"}', 3, '2026-06-12 14:45:54'),
(784, 'documente', 272, 'update', 'Document actualizat: ORGANISM NOTIFICAT (2026-TEC-197) pentru B 232 NET', '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2029-06-30\",\"fisier_original\":\"232TUV.pdf\"}', '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"2026-TEC-197\",\"data_expirare\":\"2029-06-30\",\"fisier_original\":\"232TUV.pdf\"}', 3, '2026-06-12 14:47:59'),
(785, 'documente', 263, 'update', 'Document actualizat: METROLOGIE (0017075) pentru B 232 NET', '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2026-11-08\",\"fisier_original\":\"232_BRML.pdf\"}', '{\"vehicul\":\"B 232 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"0017075\",\"data_expirare\":\"2026-11-08\",\"fisier_original\":\"232_BRML.pdf\"}', 3, '2026-06-12 14:50:13'),
(786, 'documente', 280, 'update', 'Document actualizat: ITP (B05996200) pentru B 235 NET', '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-22\",\"fisier_original\":\"235_Talon.pdf\"}', '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"B05996200\",\"data_expirare\":\"2026-12-22\",\"fisier_original\":\"235_Talon.pdf\"}', 3, '2026-06-12 14:51:47'),
(787, 'documente', 282, 'update', 'Document actualizat: Carte (T439522) pentru B 235 NET', '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"235_Carte.pdf\"}', '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"T439522\",\"data_expirare\":null,\"fisier_original\":\"235_Carte.pdf\"}', 3, '2026-06-12 14:52:46'),
(788, 'documente', 512, 'update', 'Document actualizat: ADR (PHBT8578) pentru B 235 NET', '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2026-12-22\",\"fisier_original\":\"235_ADR.pdf\"}', '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"PHBT8578\",\"data_expirare\":\"2026-12-22\",\"fisier_original\":\"235_ADR.pdf\"}', 3, '2026-06-12 14:53:16'),
(789, 'documente', 286, 'update', 'Document actualizat: Tahograf (D286712) pentru B 235 NET', '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"235_Taho.pdf\"}', '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"D286712\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"235_Taho.pdf\"}', 3, '2026-06-12 14:53:40'),
(790, 'documente', 285, 'update', 'Document actualizat: RCA (127833832) pentru B 235 NET', '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-14\",\"fisier_original\":\"235_RCA.pdf\"}', '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"127833832\",\"data_expirare\":\"2027-01-14\",\"fisier_original\":\"235_RCA.pdf\"}', 3, '2026-06-12 14:54:03'),
(791, 'documente', 283, 'update', 'Document actualizat: Copie conforma (3019863) pentru B 235 NET', '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"235_Copie_confotma.pdf\"}', '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"3019863\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"235_Copie_confotma.pdf\"}', 3, '2026-06-12 14:54:40'),
(792, 'documente', 284, 'update', 'Document actualizat: IPROCHIM (85670) pentru B 235 NET', '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-12\",\"fisier_original\":\"235_Iprochim.pdf\"}', '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"85670\",\"data_expirare\":\"2027-01-12\",\"fisier_original\":\"235_Iprochim.pdf\"}', 3, '2026-06-12 14:55:04'),
(793, 'documente', 510, 'update', 'Document actualizat: ORGANISM NOTIFICAT (110-541) pentru B 235 NET', '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2027-08-30\",\"fisier_original\":\"Cncir_235.pdf\"}', '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"110-541\",\"data_expirare\":\"2027-08-30\",\"fisier_original\":\"Cncir_235.pdf\"}', 3, '2026-06-12 14:59:50'),
(794, 'documente', 481, 'update', 'Document actualizat: METROLOGIE (0305526) pentru B 235 NET', '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2027-08-30\",\"fisier_original\":\"Cncir_235.pdf\"}', '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"0305526\",\"data_expirare\":\"2026-11-08\",\"fisier_original\":\"Brml_235.pdf\"}', 3, '2026-06-12 15:03:20'),
(795, 'documente', 281, 'delete', 'Document sters: Brml () pentru B 235 NET', '{\"vehicul\":\"B 235 NET\",\"tip_document\":\"Brml\",\"numar_document\":\"\",\"data_expirare\":\"2026-11-08\",\"fisier_original\":\"Brml_235.pdf\"}', NULL, 3, '2026-06-12 15:04:29'),
(796, 'documente', 339, 'update', 'Document actualizat: ITP (B05603513) pentru B 275 NET', '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-29\",\"fisier_original\":\"275_Talon..pdf\"}', '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"B05603513\",\"data_expirare\":\"2026-07-29\",\"fisier_original\":\"275_Talon..pdf\"}', 3, '2026-06-12 15:08:09'),
(797, 'documente', 335, 'update', 'Document actualizat: Carte (S158741) pentru B 275 NET', '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"275_Carte.pdf\"}', '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"S158741\",\"data_expirare\":null,\"fisier_original\":\"275_Carte.pdf\"}', 3, '2026-06-12 15:14:03'),
(798, 'documente', 333, 'update', 'Document actualizat: ADR (VLBR726) pentru B 275 NET', '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-07-29\",\"fisier_original\":\"275_ADR.pdf\"}', '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"VLBR726\",\"data_expirare\":\"2027-07-29\",\"fisier_original\":\"275_ADR.pdf\"}', 3, '2026-06-12 15:15:24'),
(799, 'documente', 342, 'update', 'Document actualizat: Tahograf (D250773) pentru B 275 NET', '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2027-12-09\",\"fisier_original\":\"275_Taho.pdf\"}', '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"D250773\",\"data_expirare\":\"2027-12-09\",\"fisier_original\":\"275_Taho.pdf\"}', 3, '2026-06-12 15:16:01'),
(800, 'documente', 340, 'update', 'Document actualizat: RCA (53439395220) pentru B 275 NET', '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-08-17\",\"fisier_original\":\"275_RCA.pdf\"}', '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"53439395220\",\"data_expirare\":\"2026-08-17\",\"fisier_original\":\"275_RCA.pdf\"}', 3, '2026-06-12 15:21:16'),
(801, 'documente', 337, 'update', 'Document actualizat: Copie conforma (3019151) pentru B 275 NET', '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_275.pdf\"}', '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"3019151\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_275.pdf\"}', 3, '2026-06-12 15:21:45'),
(802, 'documente', 338, 'update', 'Document actualizat: IPROCHIM (85691) pentru B 275 NET', '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-16\",\"fisier_original\":\"275_Iprochim.pdf\"}', '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"85691\",\"data_expirare\":\"2027-01-16\",\"fisier_original\":\"275_Iprochim.pdf\"}', 3, '2026-06-12 15:22:04'),
(803, 'documente', 336, 'update', 'Document actualizat: ORGANISM NOTIFICAT (110-007/14.01.2026) pentru B 275 NET', '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2028-01-14\",\"fisier_original\":\"275_CNCIR.pdf\"}', '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"110-007/14.01.2026\",\"data_expirare\":\"2028-01-14\",\"fisier_original\":\"275_CNCIR.pdf\"}', 3, '2026-06-12 15:22:58'),
(804, 'documente', 334, 'update', 'Document actualizat: METROLOGIE (0579370) pentru B 275 NET', '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2027-09-04\",\"fisier_original\":\"275_BRML.pdf\"}', '{\"vehicul\":\"B 275 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"0579370\",\"data_expirare\":\"2027-09-04\",\"fisier_original\":\"275_BRML.pdf\"}', 3, '2026-06-12 15:23:29'),
(805, 'documente', 257, 'update', 'Document actualizat: ITP (B05725325) pentru B 295 NET', '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-16\",\"fisier_original\":\"295_Talon.pdf\"}', '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"B05725325\",\"data_expirare\":\"2027-01-16\",\"fisier_original\":\"295_Talon.pdf\"}', 3, '2026-06-12 15:27:50'),
(806, 'documente', 254, 'update', 'Document actualizat: Carte (S656082) pentru B 295 NET', '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"295_Carte.pdf\"}', '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"S656082\",\"data_expirare\":null,\"fisier_original\":\"295_Carte.pdf\"}', 3, '2026-06-12 15:31:26'),
(807, 'documente', 251, 'update', 'Document actualizat: ADR (VLBS755) pentru B 295 NET', '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-16\",\"fisier_original\":\"295_ADR.pdf\"}', '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"ADR\",\"numar_document\":\"VLBS755\",\"data_expirare\":\"2027-01-16\",\"fisier_original\":\"295_ADR.pdf\"}', 3, '2026-06-12 15:31:46'),
(808, 'documente', 260, 'update', 'Document actualizat: Tahograf (D192921) pentru B 295 NET', '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"\",\"data_expirare\":\"2027-04-03\",\"fisier_original\":\"295_TAHOGRAF_1.pdf\"}', '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"Tahograf\",\"numar_document\":\"D192921\",\"data_expirare\":\"2027-04-03\",\"fisier_original\":\"295_TAHOGRAF_1.pdf\"}', 3, '2026-06-12 15:32:08'),
(809, 'documente', 258, 'update', 'Document actualizat: RCA (016154044) pentru B 295 NET', '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-02-09\",\"fisier_original\":\"295_RCA.pdf\"}', '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"016154044\",\"data_expirare\":\"2027-02-09\",\"fisier_original\":\"295_RCA.pdf\"}', 3, '2026-06-12 15:35:11'),
(810, 'documente', 255, 'update', 'Document actualizat: Copie conforma (3019153) pentru B 295 NET', '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_295.pdf\"}', '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"3019153\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_295.pdf\"}', 3, '2026-06-12 15:43:26'),
(811, 'documente', 255, 'update', 'Document actualizat: Copie conforma (3019153) pentru B 295 NET', '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"3019153\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_295.pdf\"}', '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"Copie conforma\",\"numar_document\":\"3019153\",\"data_expirare\":\"2028-02-11\",\"fisier_original\":\"COPIE_CONFORMA_295.pdf\"}', 3, '2026-06-12 15:49:53'),
(812, 'documente', 256, 'update', 'Document actualizat: IPROCHIM (85671) pentru B 295 NET', '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-16\",\"fisier_original\":\"295_Iprochim.pdf\"}', '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"85671\",\"data_expirare\":\"2027-01-16\",\"fisier_original\":\"295_Iprochim.pdf\"}', 3, '2026-06-12 15:50:07'),
(813, 'documente', 261, 'update', 'Document actualizat: ORGANISM NOTIFICAT (110-006/14.01.2026) pentru B 295 NET', '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2028-01-14\",\"fisier_original\":\"295_CNCIR.pdf\"}', '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"110-006/14.01.2026\",\"data_expirare\":\"2028-01-14\",\"fisier_original\":\"295_CNCIR.pdf\"}', 3, '2026-06-12 15:51:57');
INSERT INTO `audit_log` (`id`, `modul`, `record_id`, `actiune`, `descriere`, `before_data`, `after_data`, `user_id`, `created_at`) VALUES
(814, 'documente', 253, 'update', 'Document actualizat: METROLOGIE (0584440) pentru B 295 NET', '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2028-03-31\",\"fisier_original\":\"295_BRML.pdf\"}', '{\"vehicul\":\"B 295 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"0584440\",\"data_expirare\":\"2028-03-31\",\"fisier_original\":\"295_BRML.pdf\"}', 3, '2026-06-12 15:52:16'),
(815, 'documente', 444, 'update', 'Document actualizat: IPROCHIM () pentru B 325 NET', '{\"vehicul\":\"B 325 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-07\",\"fisier_original\":\"325_Iprochim.pdf\"}', '{\"vehicul\":\"B 325 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-06\",\"fisier_original\":\"325_Iprochim.pdf\"}', 1, '2026-06-15 09:04:01'),
(816, 'documente', 444, 'update', 'Document actualizat: IPROCHIM () pentru B 325 NET', '{\"vehicul\":\"B 325 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-06\",\"fisier_original\":\"325_Iprochim.pdf\"}', '{\"vehicul\":\"B 325 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-07\",\"fisier_original\":\"325_Iprochim.pdf\"}', 1, '2026-06-15 09:05:09'),
(817, 'documente', 476, 'update', 'Document actualizat: IPROCHIM () pentru B 335 NET', '{\"vehicul\":\"B 335 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-28\",\"fisier_original\":\"335_Iprochim.pdf\"}', '{\"vehicul\":\"B 335 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-06\",\"fisier_original\":\"335_Iprochim.pdf\"}', 1, '2026-06-15 14:54:41'),
(818, 'documente', 476, 'update', 'Document actualizat: IPROCHIM () pentru B 335 NET', '{\"vehicul\":\"B 335 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-06\",\"fisier_original\":\"335_Iprochim.pdf\"}', '{\"vehicul\":\"B 335 NET\",\"tip_document\":\"IPROCHIM\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-28\",\"fisier_original\":\"335_Iprochim.pdf\"}', 1, '2026-06-15 15:45:41'),
(819, 'documente', 100, 'update', 'Document actualizat: RCA () pentru B 635 NET', '{\"vehicul\":\"B 635 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-16\",\"fisier_original\":\"635_RCA.pdf\"}', '{\"vehicul\":\"B 635 NET\",\"tip_document\":\"RCA\",\"numar_document\":\"\",\"data_expirare\":\"2027-06-15\",\"fisier_original\":\"rca_635.pdf\"}', 3, '2026-06-16 09:13:11'),
(820, 'documente', 166, 'update', 'Document actualizat: Carte () pentru B 925 NET', '{\"vehicul\":\"B 925 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-11\",\"fisier_original\":\"925_Carte.pdf\"}', '{\"vehicul\":\"B 925 NET\",\"tip_document\":\"Carte\",\"numar_document\":\"\",\"data_expirare\":null,\"fisier_original\":\"925_Carte.pdf\"}', 3, '2026-06-16 10:02:57'),
(821, 'documente', 588, 'update', 'Document actualizat: METROLOGIE () pentru B 439 NET', '{\"vehicul\":\"B 439 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2025-06-17\",\"fisier_original\":null}', '{\"vehicul\":\"B 439 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-01\",\"fisier_original\":null}', 1, '2026-06-16 10:07:06'),
(822, 'documente', 301, 'update', 'Document actualizat: Rovinieta () pentru B 605 NET', '{\"vehicul\":\"B 605 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-04\",\"fisier_original\":null}', '{\"vehicul\":\"B 605 NET\",\"tip_document\":\"Rovinieta\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-15\",\"fisier_original\":null}', 1, '2026-06-16 10:09:58'),
(823, 'documente', 544, 'update', 'Document actualizat: METROLOGIE () pentru B 925 NET', '{\"vehicul\":\"B 925 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2027-12-31\",\"fisier_original\":null}', '{\"vehicul\":\"B 925 NET\",\"tip_document\":\"METROLOGIE\",\"numar_document\":\"\",\"data_expirare\":\"2026-09-01\",\"fisier_original\":null}', 3, '2026-06-16 10:14:12'),
(824, 'documente', 601, 'create', 'Document creat: ORGANISM NOTIFICAT () pentru B 815 NET', NULL, '{\"vehicul\":\"B 815 NET\",\"tip_document\":\"ORGANISM NOTIFICAT\",\"numar_document\":\"\",\"data_expirare\":\"2027-07-30\",\"fisier_original\":null}', 1, '2026-06-16 10:15:53'),
(825, 'documente', 572, 'update', 'Document actualizat: ITP () pentru B 669 NET', '{\"vehicul\":\"B 669 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-06-02\",\"fisier_original\":null}', '{\"vehicul\":\"B 669 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-07-02\",\"fisier_original\":null}', 3, '2026-06-16 10:18:28'),
(826, 'documente', 602, 'create', 'Document creat: CASCO () pentru B 230 NET', NULL, '{\"vehicul\":\"B 230 NET\",\"tip_document\":\"CASCO\",\"numar_document\":\"\",\"data_expirare\":\"2027-01-01\",\"fisier_original\":null}', 1, '2026-06-16 10:18:33');

-- --------------------------------------------------------

--
-- Table structure for table `concedii`
--

CREATE TABLE `concedii` (
  `id` int UNSIGNED NOT NULL,
  `driver_id` int UNSIGNED NOT NULL,
  `tip_concediu` enum('odihna','personal','medical','fara_plata') COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_inceput` date NOT NULL,
  `data_sfarsit` date NOT NULL,
  `inlocuitor_id` int UNSIGNED DEFAULT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `status` enum('aprobat','respins','in_asteptare','in_asteptare_aprobare') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_asteptare',
  `created_by` int UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `configurare_beneficiari_transport`
--

CREATE TABLE `configurare_beneficiari_transport` (
  `id` int UNSIGNED NOT NULL,
  `nume` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tip_marfa` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pret_tarifare` decimal(12,2) NOT NULL DEFAULT '0.00',
  `suporta_primar` tinyint(1) NOT NULL DEFAULT '1',
  `suporta_distributie` tinyint(1) NOT NULL DEFAULT '1',
  `suporta_primar_distributie` tinyint(1) NOT NULL DEFAULT '0',
  `suporta_compresor` tinyint(1) NOT NULL DEFAULT '0',
  `pret_km` decimal(12,2) NOT NULL DEFAULT '0.00',
  `pret_tona` decimal(12,2) NOT NULL DEFAULT '0.00',
  `pret_distributie_km` decimal(12,2) NOT NULL DEFAULT '0.00',
  `pret_distributie_tona` decimal(12,2) NOT NULL DEFAULT '0.00',
  `pret_ora_aspirare` decimal(12,2) NOT NULL DEFAULT '0.00',
  `pret_km_dislocare` decimal(12,2) NOT NULL DEFAULT '0.00',
  `pret_tona_livrata` decimal(12,2) NOT NULL DEFAULT '0.00',
  `pret_tona_aspirata_lichida` decimal(12,2) NOT NULL DEFAULT '0.00',
  `pret_tona_aspirata_gazoasa` decimal(12,2) NOT NULL DEFAULT '0.00',
  `activ` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `configurare_beneficiari_transport`
--

INSERT INTO `configurare_beneficiari_transport` (`id`, `nume`, `tip_marfa`, `pret_tarifare`, `suporta_primar`, `suporta_distributie`, `suporta_primar_distributie`, `suporta_compresor`, `pret_km`, `pret_tona`, `pret_distributie_km`, `pret_distributie_tona`, `pret_ora_aspirare`, `pret_km_dislocare`, `pret_tona_livrata`, `pret_tona_aspirata_lichida`, `pret_tona_aspirata_gazoasa`, `activ`, `created_at`, `updated_at`) VALUES
(33, 'ButanGas', NULL, 0.00, 1, 1, 1, 1, 1.21, 0.00, 1.50, 0.00, 80.00, 0.00, 50.00, 0.00, 0.00, 1, '2026-05-07 13:37:59', '2026-06-16 12:12:55'),
(53, 'ForVest', NULL, 0.00, 0, 1, 0, 0, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 1, '2026-06-02 14:05:36', '2026-06-02 14:05:36');

-- --------------------------------------------------------

--
-- Table structure for table `configurare_compresor_vehicule`
--

CREATE TABLE `configurare_compresor_vehicule` (
  `id` int UNSIGNED NOT NULL,
  `beneficiar_id` int UNSIGNED NOT NULL,
  `vehicle_id` int UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `configurare_compresor_vehicule`
--

INSERT INTO `configurare_compresor_vehicule` (`id`, `beneficiar_id`, `vehicle_id`, `created_at`, `updated_at`) VALUES
(55, 33, 15, '2026-06-16 12:12:55', '2026-06-16 12:12:55'),
(56, 33, 23, '2026-06-16 12:12:55', '2026-06-16 12:12:55'),
(57, 33, 37, '2026-06-16 12:12:55', '2026-06-16 12:12:55'),
(58, 33, 40, '2026-06-16 12:12:55', '2026-06-16 12:12:55'),
(59, 33, 42, '2026-06-16 12:12:55', '2026-06-16 12:12:55'),
(60, 33, 44, '2026-06-16 12:12:55', '2026-06-16 12:12:55');

-- --------------------------------------------------------

--
-- Table structure for table `configurare_costuri_documente_soferi`
--

CREATE TABLE `configurare_costuri_documente_soferi` (
  `id` int UNSIGNED NOT NULL,
  `driver_id` int UNSIGNED NOT NULL,
  `document_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_cost` decimal(10,2) NOT NULL DEFAULT '0.00',
  `validity_days` int UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `configurare_costuri_documente_vehicule`
--

CREATE TABLE `configurare_costuri_documente_vehicule` (
  `id` int UNSIGNED NOT NULL,
  `vehicle_type` enum('cap_tractor','semiremorca_distributie','semiremorca_primar','camion','autovehicul') COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_type` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_cost` decimal(10,2) NOT NULL DEFAULT '0.00',
  `validity_days` int UNSIGNED NOT NULL,
  `requires_expiry` tinyint(1) NOT NULL DEFAULT '1',
  `custom_fields_json` longtext COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `configurare_costuri_documente_vehicule`
--

INSERT INTO `configurare_costuri_documente_vehicule` (`id`, `vehicle_type`, `document_type`, `document_cost`, `validity_days`, `requires_expiry`, `custom_fields_json`, `created_at`, `updated_at`) VALUES
(1, 'cap_tractor', 'RCA', 0.00, 365, 1, NULL, '2026-05-27 11:45:59', '2026-05-27 11:45:59'),
(2, 'cap_tractor', 'ITP', 0.00, 365, 1, NULL, '2026-05-27 11:45:59', '2026-05-27 11:45:59'),
(3, 'cap_tractor', 'Rovinieta', 0.00, 365, 1, NULL, '2026-05-27 11:45:59', '2026-05-27 11:45:59'),
(4, 'semiremorca_primar', 'RCA', 0.00, 365, 1, NULL, '2026-05-27 11:45:59', '2026-05-27 11:45:59'),
(5, 'semiremorca_primar', 'ITP', 0.00, 365, 1, NULL, '2026-05-27 11:45:59', '2026-05-27 11:45:59'),
(7, 'semiremorca_primar', 'IPROCHIM', 0.00, 365, 1, NULL, '2026-05-27 11:45:59', '2026-05-27 11:45:59'),
(8, 'semiremorca_distributie', 'RCA', 0.00, 365, 1, NULL, '2026-05-27 11:45:59', '2026-05-27 11:45:59'),
(9, 'semiremorca_distributie', 'ITP', 0.00, 365, 1, NULL, '2026-05-27 11:45:59', '2026-05-27 11:45:59'),
(11, 'semiremorca_distributie', 'IPROCHIM', 0.00, 365, 1, NULL, '2026-05-27 11:45:59', '2026-05-27 11:45:59'),
(12, 'camion', 'RCA', 0.00, 365, 1, NULL, '2026-05-27 11:45:59', '2026-05-27 11:45:59'),
(13, 'camion', 'ITP', 0.00, 365, 1, NULL, '2026-05-27 11:45:59', '2026-05-27 11:45:59'),
(14, 'camion', 'Rovinieta', 0.00, 365, 1, NULL, '2026-05-27 11:45:59', '2026-05-27 11:45:59'),
(15, 'camion', 'IPROCHIM', 0.00, 365, 1, NULL, '2026-05-27 11:45:59', '2026-05-27 11:45:59'),
(16, 'autovehicul', 'RCA', 0.00, 365, 1, NULL, '2026-05-27 11:45:59', '2026-05-27 11:45:59'),
(17, 'autovehicul', 'ITP', 0.00, 365, 1, NULL, '2026-05-27 11:45:59', '2026-05-27 11:45:59'),
(18, 'autovehicul', 'Rovinieta', 0.00, 365, 1, NULL, '2026-05-27 11:45:59', '2026-05-27 11:45:59'),
(38, 'cap_tractor', 'Carte', 0.00, 365, 0, NULL, '2026-05-28 11:19:57', '2026-06-04 13:20:28'),
(39, 'cap_tractor', 'Tahograf', 0.00, 365, 1, NULL, '2026-05-28 12:02:06', '2026-05-28 12:02:06'),
(41, 'cap_tractor', 'Copie conforma', 0.00, 365, 1, NULL, '2026-05-28 12:02:53', '2026-05-28 12:02:53'),
(42, 'cap_tractor', 'CASCO', 0.00, 365, 1, NULL, '2026-05-28 12:21:37', '2026-05-28 12:21:37'),
(43, 'cap_tractor', 'ADR', 0.00, 365, 1, NULL, '2026-05-28 12:21:52', '2026-05-28 12:21:52'),
(47, 'semiremorca_primar', 'Adr', 0.00, 365, 1, NULL, '2026-05-29 08:41:37', '2026-05-29 08:41:37'),
(52, 'semiremorca_primar', 'Carte', 0.00, 365, 0, NULL, '2026-05-29 08:43:53', '2026-06-04 14:07:07'),
(53, 'semiremorca_distributie', 'Carte', 0.00, 365, 0, NULL, '2026-05-29 08:46:48', '2026-06-04 14:05:07'),
(54, 'semiremorca_distributie', 'CASCO', 0.00, 365, 1, NULL, '2026-05-29 08:47:21', '2026-05-29 08:47:21'),
(59, 'camion', 'Copie conforma', 0.00, 365, 1, NULL, '2026-05-29 08:50:19', '2026-05-29 08:50:19'),
(60, 'camion', 'Tahograf', 0.00, 365, 1, NULL, '2026-05-29 08:50:29', '2026-05-29 08:50:29'),
(65, 'camion', 'CASCO', 0.00, 365, 1, NULL, '2026-05-29 08:52:13', '2026-05-29 08:52:13'),
(66, 'camion', 'ADR', 0.00, 365, 1, NULL, '2026-05-29 08:52:46', '2026-05-29 08:52:46'),
(67, 'camion', 'Carte', 0.00, 365, 0, NULL, '2026-05-29 08:53:09', '2026-06-04 14:07:13'),
(69, 'autovehicul', 'Carte', 0.00, 365, 0, NULL, '2026-05-29 08:54:27', '2026-06-04 14:07:19'),
(71, 'autovehicul', 'CASCO', 0.00, 365, 1, NULL, '2026-05-29 08:54:49', '2026-05-29 08:54:49'),
(73, 'semiremorca_distributie', 'ADR', 0.00, 365, 1, NULL, '2026-06-03 15:16:25', '2026-06-03 15:16:25'),
(79, 'semiremorca_distributie', 'METROLOGIE', 0.00, 365, 1, NULL, '2026-06-08 14:29:56', '2026-06-08 14:29:56'),
(80, 'camion', 'METROLOGIE', 0.00, 365, 1, NULL, '2026-06-08 14:30:06', '2026-06-08 14:30:06'),
(81, 'semiremorca_primar', 'ORGANISM NOTIFICAT', 0.00, 365, 1, '[{\"key\":\"vcf_91548d56860b\",\"label\":\"Periodica\",\"type\":\"checkbox\"},{\"key\":\"vcf_31f8aa7910e3\",\"label\":\"Data expirare periodica\",\"type\":\"date\",\"show_when_checked\":\"vcf_91548d56860b\"}]', '2026-06-08 14:33:33', '2026-06-11 12:22:57'),
(82, 'semiremorca_distributie', 'ORGANISM NOTIFICAT', 0.00, 365, 1, '[{\"key\":\"vcf_f8caef571cbd\",\"label\":\"Periodica\",\"type\":\"checkbox\"},{\"key\":\"vcf_f8bad4997205\",\"label\":\"Data expirare periodica\",\"type\":\"date\",\"show_when_checked\":\"vcf_f8caef571cbd\"}]', '2026-06-08 14:33:42', '2026-06-11 12:10:51'),
(83, 'camion', 'ORGANISM NOTIFICAT', 0.00, 365, 1, '[{\"key\":\"vcf_16b246e1a6e5\",\"label\":\"Periodica\",\"type\":\"checkbox\"},{\"key\":\"vcf_86a1856ed41b\",\"label\":\"Data expirare periodica\",\"type\":\"date\",\"show_when_checked\":\"vcf_16b246e1a6e5\"}]', '2026-06-08 14:33:50', '2026-06-11 12:23:36'),
(84, 'semiremorca_primar', 'CASCO', 0.00, 365, 1, NULL, '2026-06-10 09:24:12', '2026-06-10 09:24:12');

-- --------------------------------------------------------

--
-- Table structure for table `configurare_costuri_documente_vehicule_override`
--

CREATE TABLE `configurare_costuri_documente_vehicule_override` (
  `id` int UNSIGNED NOT NULL,
  `vehicle_id` int UNSIGNED NOT NULL,
  `document_type` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_cost` decimal(10,2) NOT NULL DEFAULT '0.00',
  `validity_days` int UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `configurare_costuri_documente_vehicule_override`
--

INSERT INTO `configurare_costuri_documente_vehicule_override` (`id`, `vehicle_id`, `document_type`, `document_cost`, `validity_days`, `created_at`, `updated_at`) VALUES
(4, 50, 'ITP', 200.00, 61, '2026-05-28 12:05:09', '2026-05-28 12:05:09');

-- --------------------------------------------------------

--
-- Table structure for table `configurare_documente_obligatorii_soferi`
--

CREATE TABLE `configurare_documente_obligatorii_soferi` (
  `id` int UNSIGNED NOT NULL,
  `document_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `requires_expiry` tinyint(1) NOT NULL DEFAULT '1',
  `custom_fields_json` longtext COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `configurare_documente_obligatorii_soferi`
--

INSERT INTO `configurare_documente_obligatorii_soferi` (`id`, `document_type`, `requires_expiry`, `custom_fields_json`, `created_at`, `updated_at`) VALUES
(7, 'BULETIN (C.I.)', 1, '[{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\"},{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\"}]', '2026-06-08 14:11:08', '2026-06-11 10:54:50'),
(8, 'PERMIS', 0, '[{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\"},{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"show_when_checked\":\"dcf_fd4e64dac509\"},{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\"},{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"show_when_checked\":\"dcf_0e4d9bc43730\"},{\"key\":\"dcf_98323d0c811f\",\"label\":\"CATEGORIA CE\",\"type\":\"checkbox\"},{\"key\":\"dcf_73d0790ad0e5\",\"label\":\"DATA EXPIRARE CAT. CE\",\"type\":\"date\",\"show_when_checked\":\"dcf_98323d0c811f\"},{\"key\":\"dcf_fd6539744e62\",\"label\":\"CATEGORIA D\",\"type\":\"checkbox\"},{\"key\":\"dcf_8add67c2d8a8\",\"label\":\"DATA EXPIRARE CAT. D\",\"type\":\"date\",\"show_when_checked\":\"dcf_fd6539744e62\"}]', '2026-06-08 14:11:27', '2026-06-12 11:32:24'),
(9, 'MEDICINA MUNCII', 1, NULL, '2026-06-08 14:12:00', '2026-06-10 12:36:30'),
(11, 'AVIZ MEDICAL', 1, NULL, '2026-06-08 14:12:22', '2026-06-10 12:36:30'),
(12, 'AVIZ PSIHOLOGIC', 1, NULL, '2026-06-08 14:12:32', '2026-06-10 12:36:30'),
(13, 'ADR', 1, NULL, '2026-06-08 14:12:46', '2026-06-10 12:36:30'),
(14, 'CARTELA CONDUCATOR AUTO', 1, NULL, '2026-06-08 14:12:55', '2026-06-10 12:36:30'),
(15, 'CERTIFICAT COMPETENTA PROFESIONALA', 1, NULL, '2026-06-08 14:13:12', '2026-06-10 12:36:30');

-- --------------------------------------------------------

--
-- Table structure for table `configurare_locuri_incarcare`
--

CREATE TABLE `configurare_locuri_incarcare` (
  `id` int UNSIGNED NOT NULL,
  `beneficiar_id` int UNSIGNED DEFAULT NULL,
  `nume` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tarif` decimal(10,2) NOT NULL DEFAULT '0.00',
  `activ` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `configurare_locuri_incarcare`
--

INSERT INTO `configurare_locuri_incarcare` (`id`, `beneficiar_id`, `nume`, `tarif`, `activ`, `created_at`, `updated_at`) VALUES
(54, 33, 'Oradea', 0.00, 1, '2026-05-07 13:38:25', '2026-05-07 13:38:25'),
(55, 33, 'Lugoj', 0.00, 1, '2026-05-07 13:40:58', '2026-05-07 13:40:58'),
(56, 33, 'Contesti', 0.00, 1, '2026-05-07 13:51:01', '2026-05-07 13:51:01'),
(61, 33, 'Navodari', 0.00, 1, '2026-05-07 13:54:00', '2026-05-07 13:54:00'),
(62, 33, 'Salonta', 0.00, 1, '2026-05-07 14:06:27', '2026-05-07 14:06:27'),
(66, 33, 'Sud', 0.00, 1, '2026-05-08 09:53:24', '2026-05-08 09:53:24'),
(67, 33, 'Moldova', 0.00, 1, '2026-05-08 09:53:24', '2026-05-08 09:53:24'),
(80, 53, 'Tileagd', 0.00, 1, '2026-06-02 14:06:28', '2026-06-02 14:06:28'),
(81, 33, 'Tileagd', 0.00, 1, '2026-06-15 17:24:24', '2026-06-15 17:24:24'),
(82, 33, 'Brazi', 0.00, 1, '2026-06-16 12:23:22', '2026-06-16 12:23:22'),
(83, 33, 'Negoiesti (GASPECO)', 0.00, 1, '2026-06-16 12:24:20', '2026-06-16 12:24:20');

-- --------------------------------------------------------

--
-- Table structure for table `configurare_locuri_incarcare_vehicule`
--

CREATE TABLE `configurare_locuri_incarcare_vehicule` (
  `id` int UNSIGNED NOT NULL,
  `beneficiar_id` int UNSIGNED DEFAULT NULL,
  `vehicle_id` int UNSIGNED NOT NULL,
  `loc_incarcare_id` int UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `configurare_rute_distributie`
--

CREATE TABLE `configurare_rute_distributie` (
  `id` int UNSIGNED NOT NULL,
  `beneficiar_id` int UNSIGNED NOT NULL,
  `loc_incarcare_id` int UNSIGNED NOT NULL,
  `zona_distributie_id` int UNSIGNED NOT NULL,
  `transport_scope` enum('distributie','primar_distributie') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'primar_distributie',
  `tarif_mod` enum('tona_km','tona','km') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tona_km',
  `tarif_tona` decimal(10,2) NOT NULL DEFAULT '0.00',
  `cost_extra_km` decimal(10,2) NOT NULL DEFAULT '0.00',
  `km_tarifare` int UNSIGNED NOT NULL DEFAULT '0',
  `cost_cursa` decimal(12,2) NOT NULL DEFAULT '0.00',
  `aplica_cost_cursa` tinyint(1) NOT NULL DEFAULT '0',
  `vehicle_ids` text COLLATE utf8mb4_unicode_ci,
  `activ` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `configurare_rute_distributie`
--

INSERT INTO `configurare_rute_distributie` (`id`, `beneficiar_id`, `loc_incarcare_id`, `zona_distributie_id`, `transport_scope`, `tarif_mod`, `tarif_tona`, `cost_extra_km`, `km_tarifare`, `cost_cursa`, `aplica_cost_cursa`, `vehicle_ids`, `activ`, `created_at`, `updated_at`) VALUES
(53, 53, 80, 79, 'distributie', 'km', 0.00, 1.20, 0, 0.00, 0, '22', 1, '2026-06-02 14:07:46', '2026-06-02 14:07:46'),
(54, 33, 56, 57, 'distributie', 'tona', 60.00, 0.00, 0, 0.00, 0, '1,2,6,9,23,24,25,28,31,40,43,44,48,50,54,61', 1, '2026-06-15 17:18:34', '2026-06-16 12:14:02'),
(55, 33, 56, 58, 'distributie', 'tona', 75.00, 0.00, 0, 0.00, 0, '1,2,6,9,23,24,25,28,31,40,43,44,48,50,54,61', 1, '2026-06-15 17:20:47', '2026-06-16 12:13:35'),
(56, 33, 55, 56, 'distributie', 'tona', 60.00, 0.00, 0, 0.00, 0, '19,20,21,22', 1, '2026-06-15 17:22:29', '2026-06-15 17:22:29'),
(57, 33, 55, 55, 'distributie', 'tona', 75.00, 0.00, 0, 0.00, 0, '15,16,17,18,19,20,21,22', 1, '2026-06-15 17:23:27', '2026-06-15 17:29:39'),
(58, 33, 81, 55, 'distributie', 'tona', 60.00, 0.00, 0, 0.00, 0, '15,16,17,18', 1, '2026-06-15 17:25:39', '2026-06-15 17:25:39'),
(59, 33, 81, 56, 'distributie', 'tona', 75.00, 0.00, 0, 0.00, 0, '15,16,17,18,19,20,21,22', 1, '2026-06-15 17:26:27', '2026-06-15 17:31:14'),
(61, 33, 56, 55, 'primar_distributie', 'tona_km', 60.00, 1.21, 1350, 0.00, 0, '28,31,48,50,54,61', 1, '2026-06-15 17:39:21', '2026-06-16 12:15:50'),
(62, 33, 56, 56, 'primar_distributie', 'tona_km', 60.00, 1.21, 1100, 0.00, 0, '28,31,48,50,54,61', 1, '2026-06-15 17:42:41', '2026-06-16 12:15:17'),
(63, 33, 61, 57, 'primar_distributie', 'tona_km', 60.00, 1.21, 630, 0.00, 0, '28,31,48,50,54,61', 1, '2026-06-15 17:44:08', '2026-06-16 12:16:54'),
(64, 33, 61, 58, 'primar_distributie', 'tona_km', 75.00, 1.21, 630, 0.00, 0, '28,31,48,50,54,61', 1, '2026-06-15 17:46:04', '2026-06-16 12:16:27');

-- --------------------------------------------------------

--
-- Table structure for table `configurare_rute_primar`
--

CREATE TABLE `configurare_rute_primar` (
  `id` int UNSIGNED NOT NULL,
  `beneficiar_id` int UNSIGNED NOT NULL,
  `loc_incarcare_id` int UNSIGNED NOT NULL,
  `zona_distributie_id` int UNSIGNED NOT NULL,
  `km_tarifare` int UNSIGNED NOT NULL DEFAULT '0',
  `cost_cursa` decimal(12,2) NOT NULL DEFAULT '0.00',
  `aplica_cost_cursa` tinyint(1) NOT NULL DEFAULT '0',
  `activ` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `configurare_rute_primar`
--

INSERT INTO `configurare_rute_primar` (`id`, `beneficiar_id`, `loc_incarcare_id`, `zona_distributie_id`, `km_tarifare`, `activ`, `created_at`, `updated_at`) VALUES
(1, 33, 56, 56, 1100, 1, '2026-05-08 08:53:54', '2026-06-16 12:21:30'),
(2, 33, 56, 55, 1350, 1, '2026-05-08 08:56:25', '2026-06-16 12:22:03'),
(3, 33, 56, 64, 630, 1, '2026-05-08 08:59:26', '2026-06-16 12:21:46'),
(4, 33, 61, 59, 630, 1, '2026-05-08 08:59:40', '2026-06-16 12:22:22'),
(5, 33, 61, 56, 1600, 1, '2026-05-08 09:00:08', '2026-06-16 12:22:56'),
(8, 33, 82, 59, 180, 1, '2026-06-16 12:23:40', '2026-06-16 12:23:40'),
(9, 33, 83, 59, 180, 1, '2026-06-16 12:24:33', '2026-06-16 12:24:33'),
(10, 33, 83, 56, 1200, 1, '2026-06-16 12:26:12', '2026-06-16 12:26:12'),
(11, 33, 82, 56, 1200, 1, '2026-06-16 12:57:05', '2026-06-16 12:57:05');

-- --------------------------------------------------------

--
-- Table structure for table `configurare_zone_distributie`
--

CREATE TABLE `configurare_zone_distributie` (
  `id` int UNSIGNED NOT NULL,
  `beneficiar_id` int UNSIGNED DEFAULT NULL,
  `nume` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tarif_distributie` decimal(10,2) NOT NULL DEFAULT '0.00',
  `cost_extra_km` decimal(10,2) NOT NULL DEFAULT '0.00',
  `activ` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `configurare_zone_distributie`
--

INSERT INTO `configurare_zone_distributie` (`id`, `beneficiar_id`, `nume`, `tarif_distributie`, `cost_extra_km`, `activ`, `created_at`, `updated_at`) VALUES
(55, 33, 'Oradea', 0.00, 0.00, 1, '2026-05-07 13:38:25', '2026-05-07 13:38:25'),
(56, 33, 'Lugoj', 0.00, 0.00, 1, '2026-05-07 13:38:41', '2026-05-07 13:38:41'),
(57, 33, 'Sud', 0.00, 0.00, 1, '2026-05-07 13:51:01', '2026-05-07 13:51:01'),
(58, 33, 'Moldova', 0.00, 0.00, 1, '2026-05-07 13:52:27', '2026-05-07 13:52:27'),
(59, 33, 'Contesti', 0.00, 0.00, 1, '2026-05-07 13:54:00', '2026-05-07 13:54:00'),
(60, 33, 'ALL-RO', 0.00, 0.00, 1, '2026-05-07 14:07:27', '2026-05-07 14:07:27'),
(64, 33, 'Navodari', 0.00, 0.00, 1, '2026-05-08 09:53:24', '2026-05-08 09:53:24'),
(79, 53, 'Maramures', 0.00, 0.00, 1, '2026-06-02 14:06:28', '2026-06-02 14:06:28'),
(80, 33, 'Brazi', 0.00, 0.00, 1, '2026-06-16 12:23:40', '2026-06-16 12:23:40'),
(81, 33, 'Negoiesti (GASPECO)', 0.00, 0.00, 1, '2026-06-16 12:24:33', '2026-06-16 12:24:33');

-- --------------------------------------------------------

--
-- Table structure for table `configurare_zone_distributie_vehicule`
--

CREATE TABLE `configurare_zone_distributie_vehicule` (
  `id` int UNSIGNED NOT NULL,
  `beneficiar_id` int UNSIGNED DEFAULT NULL,
  `vehicle_id` int UNSIGNED NOT NULL,
  `zona_distributie_id` int UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `curse_cheltuieli`
--

CREATE TABLE `curse_cheltuieli` (
  `id` int UNSIGNED NOT NULL,
  `cursa_id` int UNSIGNED NOT NULL,
  `tip_cheltuiala` enum('motorina','taxe_drum','diurna','service','alte') COLLATE utf8mb4_unicode_ci NOT NULL,
  `refacturare_tip_cheltuiala` enum('motorina','taxe_drum','diurna','service','alte') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `refacturare_detalii` text COLLATE utf8mb4_unicode_ci,
  `refacturare_suma` decimal(12,2) DEFAULT NULL,
  `refacturare_data` date DEFAULT NULL,
  `refacturare_observatii` text COLLATE utf8mb4_unicode_ci,
  `refacturare_document_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `refacturare_document_original_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `refacturare_document_mime_type` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `refacturare_document_file_size` int UNSIGNED DEFAULT NULL,
  `refacturare_facturata` tinyint(1) NOT NULL DEFAULT '0',
  `refacturare_facturata_at` datetime DEFAULT NULL,
  `suma` decimal(12,2) NOT NULL,
  `data_cheltuiala` date NOT NULL,
  `observatii` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `curse_cheltuieli`
--

INSERT INTO `curse_cheltuieli` (`id`, `cursa_id`, `tip_cheltuiala`, `refacturare_tip_cheltuiala`, `refacturare_detalii`, `refacturare_suma`, `refacturare_data`, `refacturare_observatii`, `refacturare_document_path`, `refacturare_document_original_name`, `refacturare_document_mime_type`, `refacturare_document_file_size`, `refacturare_facturata`, `refacturare_facturata_at`, `suma`, `data_cheltuiala`, `observatii`, `created_at`, `updated_at`) VALUES
(52, 123, 'taxe_drum', 'taxe_drum', '{\"taxa_acces\":{\"bucati\":1,\"pret\":198,\"total\":198}}', 198.00, '2026-06-16', NULL, NULL, NULL, NULL, NULL, 0, NULL, 0.00, '2026-06-16', NULL, '2026-06-16 12:52:13', '2026-06-16 12:52:13'),
(53, 124, 'taxe_drum', 'taxe_drum', '{\"taxa_acces\":{\"bucati\":1,\"pret\":198,\"total\":198}}', 198.00, '2026-06-16', NULL, NULL, NULL, NULL, NULL, 0, NULL, 0.00, '2026-06-16', NULL, '2026-06-16 12:58:14', '2026-06-16 12:58:14'),
(55, 125, 'taxe_drum', 'taxe_drum', '{\"taxa_acces\":{\"bucati\":1,\"pret\":198,\"total\":198}}', 198.00, '2026-06-16', NULL, NULL, NULL, NULL, NULL, 0, NULL, 0.00, '2026-06-16', NULL, '2026-06-16 13:00:08', '2026-06-16 15:31:25');

-- --------------------------------------------------------

--
-- Table structure for table `curse_cheltuieli_documente`
--

CREATE TABLE `curse_cheltuieli_documente` (
  `id` int UNSIGNED NOT NULL,
  `cheltuiala_id` int UNSIGNED NOT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` int UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `curse_dispecer`
--

CREATE TABLE `curse_dispecer` (
  `id` int UNSIGNED NOT NULL,
  `vehicle_id` int UNSIGNED NOT NULL,
  `driver_id` int UNSIGNED DEFAULT NULL,
  `tip_transport` enum('primar','primar_tona','distributie','primar_distributie','compresor') COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_cursa` date NOT NULL,
  `data_inceput` date NOT NULL,
  `data_sfarsit` date NOT NULL,
  `ora_inceput` time DEFAULT NULL,
  `ora_sfarsit` time DEFAULT NULL,
  `durata_cursa_minute` int UNSIGNED DEFAULT NULL,
  `tip_marfa` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `capacitate_transport` decimal(10,2) DEFAULT NULL,
  `loc_incarcare_id` int UNSIGNED DEFAULT NULL,
  `loc_plecare` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `loc_aspirare` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `loc_livrare` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `loc_livrare_cursa` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `beneficiar_id` int UNSIGNED DEFAULT NULL,
  `cantitate_incarcata` decimal(12,2) DEFAULT NULL,
  `cantitate_prelevata` decimal(12,2) DEFAULT NULL,
  `nr_clienti` int UNSIGNED DEFAULT NULL,
  `km_cursa` int UNSIGNED DEFAULT NULL,
  `ore_functionare` decimal(10,2) DEFAULT NULL,
  `km_totali` int UNSIGNED DEFAULT NULL,
  `ore_aspirare` decimal(12,2) DEFAULT NULL,
  `km_dislocare` decimal(12,2) DEFAULT NULL,
  `tona_livrata` decimal(12,2) DEFAULT NULL,
  `tona_aspirata_lichida` decimal(12,2) DEFAULT NULL,
  `tona_aspirata_gazoasa` decimal(12,2) DEFAULT NULL,
  `zona_distributie_id` int UNSIGNED DEFAULT NULL,
  `status_facturare` enum('in_curs_facturare','facturat','nefacturat') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_curs_facturare',
  `pret_tarifare` decimal(12,2) NOT NULL,
  `total_facturare` decimal(12,2) NOT NULL,
  `cost_km_primar` decimal(12,2) NOT NULL DEFAULT '0.00',
  `cost_km_distributie` decimal(12,2) NOT NULL DEFAULT '0.00',
  `cost_km_mixt` decimal(12,2) NOT NULL DEFAULT '0.00',
  `cost_km_compresor` decimal(12,2) NOT NULL DEFAULT '0.00',
  `observatii` text COLLATE utf8mb4_unicode_ci,
  `cheltuieli_status` enum('pending','not_applicable') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_by` int UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `curse_dispecer`
--

INSERT INTO `curse_dispecer` (`id`, `vehicle_id`, `driver_id`, `tip_transport`, `data_cursa`, `data_inceput`, `data_sfarsit`, `ora_inceput`, `ora_sfarsit`, `durata_cursa_minute`, `tip_marfa`, `capacitate_transport`, `loc_incarcare_id`, `loc_plecare`, `loc_aspirare`, `loc_livrare`, `loc_livrare_cursa`, `beneficiar_id`, `cantitate_incarcata`, `cantitate_prelevata`, `nr_clienti`, `km_cursa`, `ore_functionare`, `km_totali`, `ore_aspirare`, `km_dislocare`, `tona_livrata`, `tona_aspirata_lichida`, `tona_aspirata_gazoasa`, `zona_distributie_id`, `status_facturare`, `pret_tarifare`, `total_facturare`, `cost_km_primar`, `cost_km_distributie`, `cost_km_mixt`, `cost_km_compresor`, `observatii`, `cheltuieli_status`, `created_by`, `created_at`, `updated_at`) VALUES
(122, 50, 31, 'primar', '2026-06-02', '2026-06-02', '2026-06-02', '12:36:00', '21:42:00', 546, 'propan', NULL, 61, NULL, NULL, NULL, NULL, 33, 18.38, NULL, NULL, 630, NULL, 662, NULL, NULL, NULL, NULL, NULL, 59, 'in_curs_facturare', 1.21, 762.30, 1.21, 0.00, 1.21, 0.00, NULL, 'pending', 1, '2026-06-16 12:38:18', '2026-06-16 12:44:54'),
(123, 52, 32, 'primar', '2026-06-15', '2026-06-15', '2026-06-16', '12:50:00', '12:51:00', 1441, 'propan', NULL, 56, NULL, NULL, NULL, NULL, 33, NULL, NULL, NULL, 1100, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 56, 'in_curs_facturare', 1.21, 1331.00, 1.21, 0.00, 1.21, 0.00, NULL, 'pending', 1, '2026-06-16 12:51:32', '2026-06-16 12:58:41'),
(124, 57, 40, 'primar', '2026-06-15', '2026-06-15', '2026-06-16', '12:57:00', '12:57:00', 1440, 'butan', 20.00, 82, NULL, NULL, NULL, NULL, 33, NULL, NULL, NULL, 1200, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 56, 'in_curs_facturare', 1.21, 1452.00, 1.21, 0.00, 1.21, 0.00, NULL, 'pending', 1, '2026-06-16 12:57:52', '2026-06-16 13:16:25'),
(125, 64, 39, 'primar', '2026-06-15', '2026-06-15', '2026-06-16', '12:59:00', '12:59:00', 1440, 'butan', 20.00, 82, NULL, NULL, NULL, NULL, 33, NULL, NULL, NULL, 1200, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 56, 'in_curs_facturare', 1.21, 1452.00, 1.21, 0.00, 1.21, 0.00, NULL, 'pending', 1, '2026-06-16 12:59:50', '2026-06-16 15:47:05'),
(127, 61, 37, 'primar', '2026-06-02', '2026-06-02', '2026-06-03', '13:22:00', '13:22:00', 1440, 'butan', NULL, 56, NULL, NULL, NULL, NULL, 33, NULL, NULL, NULL, 1100, NULL, 974, NULL, NULL, NULL, NULL, NULL, 56, 'in_curs_facturare', 1.21, 1331.00, 1.21, 0.00, 1.21, 0.00, NULL, 'pending', 1, '2026-06-16 13:23:19', '2026-06-16 13:23:19');

-- --------------------------------------------------------

--
-- Table structure for table `documente`
--

CREATE TABLE `documente` (
  `id` int UNSIGNED NOT NULL,
  `vehicle_id` int UNSIGNED NOT NULL,
  `tip_document` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `numar_document` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_expirare` date DEFAULT NULL,
  `fisier_original` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fisier_stocat` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `observatii` text COLLATE utf8mb4_unicode_ci,
  `custom_fields_json` longtext COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `documente`
--

INSERT INTO `documente` (`id`, `vehicle_id`, `tip_document`, `numar_document`, `data_expirare`, `fisier_original`, `fisier_stocat`, `observatii`, `custom_fields_json`, `created_at`, `updated_at`) VALUES
(1, 1, 'RCA', 'RCA-001-2026', '2027-03-05', '315_RCA.pdf', 'document_20260609_114904_a173c84b2829aef0.pdf', 'Prioritate mare pentru reinnoire', NULL, '2026-04-03 15:08:04', '2026-06-09 11:49:04'),
(2, 1, 'ITP', 'ITP-001-2026', '2027-02-23', '315_Talon..pdf', 'document_20260609_114618_e571913c8e482969.pdf', 'Programare deja facuta', NULL, '2026-04-03 15:08:04', '2026-06-09 11:46:18'),
(3, 2, 'Rovinieta', 'ROV-7788', '2026-08-11', NULL, NULL, 'Verificare online recomandata', NULL, '2026-04-03 15:08:04', '2026-06-09 12:16:47'),
(4, 3, 'RCA', 'RCA-003-2026', '2027-04-01', '315_RCA.pdf', 'document_20260609_120945_a0b37954dc5408b3.pdf', 'Expirat - nu circula', NULL, '2026-04-03 15:08:04', '2026-06-09 12:09:45'),
(8, 1, 'Rovinieta', '', '2027-03-05', NULL, NULL, NULL, NULL, '2026-04-15 10:38:10', '2026-06-09 11:45:45'),
(12, 9, 'RCA', '', '2027-05-25', '218_RCA.pdf', 'document_20260609_094529_0de4c85426b8cf1b.pdf', 'Test', NULL, '2026-04-20 09:32:58', '2026-06-09 09:45:29'),
(16, 11, 'RCA', '', '2027-01-27', '665_RCA.pdf', 'document_20260421_121203_5c9c61048566d6b9.pdf', NULL, NULL, '2026-04-21 12:12:03', '2026-06-09 08:59:55'),
(17, 11, 'ITP', '', '2027-01-12', '665_Talon.pdf', 'document_20260609_085909_42dd03c1046c04a2.pdf', NULL, NULL, '2026-04-21 12:13:44', '2026-06-09 08:59:09'),
(18, 11, 'Rovinieta', '', '2027-01-27', NULL, NULL, NULL, NULL, '2026-04-21 12:15:23', '2026-06-09 09:01:01'),
(19, 12, 'RCA', '', '2027-05-24', '405_RCA_1.pdf', 'document_20260608_160438_a4ae1eed52023635.pdf', NULL, NULL, '2026-04-21 12:22:31', '2026-06-08 16:04:38'),
(20, 12, 'CASCO', '', '2027-05-25', '405_Casco.pdf', 'document_20260421_123515_b228408aece0c1ee.pdf', NULL, NULL, '2026-04-21 12:35:15', '2026-06-08 16:05:25'),
(21, 12, 'ITP', '', '2027-05-10', '405_Talon_1.pdf', 'document_20260608_160326_63fea14d51778af9.pdf', NULL, NULL, '2026-04-21 12:39:04', '2026-06-08 16:03:26'),
(31, 19, 'RCA', '', '2026-08-07', '219_RCA.pdf', 'document_20260608_132708_ba97f65a344f6fbe.pdf', NULL, NULL, '2026-05-06 11:51:34', '2026-06-08 13:27:08'),
(32, 19, 'ITP', '', '2026-09-02', '219_Talon.pdf', 'document_20260608_132542_028e1b532ffbe80b.pdf', NULL, NULL, '2026-05-06 11:51:44', '2026-06-08 13:25:42'),
(33, 19, 'Rovinieta', '', '2026-09-01', NULL, NULL, NULL, NULL, '2026-05-06 11:51:57', '2026-06-08 13:26:24'),
(34, 20, 'RCA', '', '2027-04-23', '345_RCA_2.pdf', 'document_20260608_130923_b0b089fbd061befd.pdf', NULL, NULL, '2026-05-06 11:52:39', '2026-06-08 13:09:23'),
(35, 20, 'ITP', '', '2026-12-24', '345_Talon.pdf', 'document_20260608_130837_32066e7837d7ac1f.pdf', NULL, NULL, '2026-05-06 11:52:52', '2026-06-08 13:08:37'),
(36, 20, 'Rovinieta', '', '2027-03-23', NULL, NULL, NULL, NULL, '2026-05-06 11:53:00', '2026-06-08 12:50:08'),
(43, 15, 'RCA', '', '2026-06-30', '285_RCA.pdf', 'document_20260608_144906_ad66c93f5cc72c7d.pdf', NULL, NULL, '2026-05-06 11:55:26', '2026-06-08 14:49:06'),
(44, 15, 'ITP', '', '2026-12-18', '285_Talon.pdf', 'document_20260608_145002_658be7decfae8bd8.pdf', NULL, NULL, '2026-05-06 11:55:35', '2026-06-08 14:50:02'),
(45, 15, 'Rovinieta', '', '2027-01-21', NULL, NULL, NULL, NULL, '2026-05-06 11:55:44', '2026-06-08 14:49:28'),
(46, 16, 'RCA', '', '2027-03-04', '375_RCA.pdf', 'document_20260608_150005_c0d00df8fb0bb974.pdf', NULL, NULL, '2026-05-06 11:56:17', '2026-06-08 15:00:05'),
(47, 16, 'Rovinieta', '', '2026-09-09', NULL, NULL, NULL, NULL, '2026-05-06 11:56:28', '2026-06-08 15:01:09'),
(48, 16, 'ITP', '', '2026-07-04', '375_Talon.pdf', 'document_20260608_150206_ebcbd6382b953e99.pdf', NULL, NULL, '2026-05-06 11:56:47', '2026-06-08 15:02:06'),
(53, 24, 'Rovinieta', '', '2027-02-26', NULL, NULL, NULL, NULL, '2026-05-07 11:14:08', '2026-06-05 14:35:11'),
(55, 18, 'Rovinieta', '', '2026-10-17', NULL, NULL, NULL, NULL, '2026-05-07 11:54:52', '2026-06-08 13:42:10'),
(56, 18, 'RCA', '', '2027-04-26', '437_RCA_1.pdf', 'document_20260608_134334_3f8babb4ab05469e.pdf', NULL, NULL, '2026-05-07 11:55:03', '2026-06-08 13:43:34'),
(57, 18, 'ITP', '', '2026-08-29', '437_Talon.pdf', 'document_20260608_134147_6673bfee20134f6c.pdf', NULL, NULL, '2026-05-07 11:55:12', '2026-06-08 13:41:47'),
(58, 17, 'Rovinieta', '', '2026-10-17', NULL, NULL, NULL, NULL, '2026-05-07 11:55:34', '2026-06-08 14:37:58'),
(59, 17, 'ITP', '', '2026-07-22', '385_Talon.pdf', 'document_20260608_143853_f0edfd5f325f6a7f.pdf', NULL, NULL, '2026-05-07 11:55:42', '2026-06-08 14:38:53'),
(60, 17, 'RCA', '', '2026-07-21', '385_RCA.pdf', 'document_20260608_144215_ca538c98cb4b61ca.pdf', NULL, NULL, '2026-05-07 11:55:47', '2026-06-08 14:43:03'),
(67, 32, 'RCA', '', '2027-01-29', '935_RCA_1.pdf', 'document_20260605_150338_94b3a2e9020dc9d1.pdf', NULL, NULL, '2026-05-19 12:39:00', '2026-06-05 15:03:38'),
(68, 32, 'ITP', '', '2026-12-11', '935_Talon_1.pdf', 'document_20260605_150238_44d9bb09c09eb60b.pdf', NULL, NULL, '2026-05-19 12:39:11', '2026-06-05 15:02:38'),
(69, 32, 'Adr', '', '2026-12-11', '935_ADR_1.pdf', 'document_20260605_150145_5ab264cc42db97c2.pdf', NULL, NULL, '2026-05-19 12:39:21', '2026-06-05 15:01:45'),
(70, 50, 'ITP', '', '2027-02-11', '400_Talon_1.pdf', 'document_20260604_142005_f4d793522f46b538.pdf', NULL, NULL, '2026-05-28 12:04:38', '2026-06-04 14:20:05'),
(71, 65, 'Adr', '', '2026-07-23', NULL, NULL, NULL, NULL, '2026-06-03 08:47:45', '2026-06-03 08:47:45'),
(72, 65, 'RCA', '', '2026-09-22', NULL, NULL, NULL, NULL, '2026-06-03 08:48:43', '2026-06-03 08:48:43'),
(73, 65, 'ORGANISM NOTIFICAT', '', '2026-07-30', NULL, NULL, NULL, NULL, '2026-06-03 08:49:13', '2026-06-10 11:45:02'),
(74, 65, 'IPROCHIM', '', '2026-07-23', NULL, NULL, NULL, NULL, '2026-06-03 08:49:39', '2026-06-03 08:49:39'),
(75, 65, 'ITP', '', '2026-07-23', NULL, NULL, NULL, NULL, '2026-06-03 08:51:26', '2026-06-03 08:51:26'),
(76, 65, 'Carte', '', '2026-07-11', '835_Carte.pdf', 'document_20260603_134641_46f1e3b1d5050cb4.pdf', NULL, NULL, '2026-06-03 13:46:41', '2026-06-03 13:46:41'),
(77, 64, 'ADR', '', '2026-07-24', '652_ADR.pdf', 'document_20260603_140033_d700e5120dac531a.pdf', NULL, NULL, '2026-06-03 14:00:33', '2026-06-03 14:00:33'),
(78, 64, 'Carte', '', NULL, '652_Carte.pdf', 'document_20260603_140055_6eecf47f5bf8800e.pdf', NULL, NULL, '2026-06-03 14:00:55', '2026-06-04 13:47:19'),
(79, 64, 'CASCO', '', '2027-05-25', '652_Casco.pdf', 'document_20260603_140138_84f834f13bcd5394.pdf', NULL, NULL, '2026-06-03 14:01:38', '2026-06-03 14:01:38'),
(80, 64, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_652.pdf', 'document_20260603_140215_49271231aad46e54.pdf', NULL, NULL, '2026-06-03 14:02:15', '2026-06-03 14:02:15'),
(81, 64, 'ITP', '', '2026-07-24', '652_Talon.pdf', 'document_20260603_140318_58a8146d6e3fdc02.pdf', NULL, NULL, '2026-06-03 14:03:18', '2026-06-03 14:03:18'),
(82, 64, 'Tahograf', '', '2027-05-29', '652_Taho.pdf', 'document_20260603_140351_8db53194a8370911.pdf', NULL, NULL, '2026-06-03 14:03:51', '2026-06-03 14:03:51'),
(83, 63, 'ADR', '', '2026-10-13', '645_ADR.pdf', 'document_20260603_141810_8ca0a6c1effe55f6.pdf', NULL, NULL, '2026-06-03 14:18:10', '2026-06-03 14:18:10'),
(84, 63, 'Carte', '', '2026-07-11', '645_Carte.pdf', 'document_20260603_141828_34ec4f601c0625c7.pdf', NULL, NULL, '2026-06-03 14:18:28', '2026-06-03 14:18:28'),
(85, 63, 'CASCO', '', '2029-01-04', '645_CASCO.pdf', 'document_20260603_141935_4fd0b264d9eaa5a3.pdf', NULL, NULL, '2026-06-03 14:19:35', '2026-06-03 14:19:35'),
(86, 63, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_645.pdf', 'document_20260603_142013_c95876fe5a4f2814.pdf', NULL, NULL, '2026-06-03 14:20:13', '2026-06-03 14:20:13'),
(87, 63, 'ITP', '', '2026-10-13', '645_Talon.pdf', 'document_20260603_142105_26e6c9a5e4522bc5.pdf', NULL, NULL, '2026-06-03 14:21:05', '2026-06-03 14:21:05'),
(88, 63, 'RCA', '', '2026-12-03', '645_rca.pdf', 'document_20260603_142127_92a1d1aa31743585.pdf', NULL, NULL, '2026-06-03 14:21:27', '2026-06-03 14:21:27'),
(89, 63, 'Tahograf', '', '2028-03-20', '645_TAHO_NOU_1.pdf', 'document_20260603_142235_e6e588d090a468d6.pdf', NULL, NULL, '2026-06-03 14:22:35', '2026-06-03 14:22:35'),
(90, 62, 'METROLOGIE', '', '2028-11-30', '915_BRML-MID_1.pdf', 'document_20260603_142736_612bc18a107ce599.pdf', NULL, NULL, '2026-06-03 14:27:36', '2026-06-08 14:31:03'),
(91, 62, 'Carte', '', '2026-07-11', '915_Carte.pdf', 'document_20260603_142756_7c9fff00f7573104.pdf', NULL, NULL, '2026-06-03 14:27:56', '2026-06-03 14:27:56'),
(92, 62, 'CASCO', '', '2030-02-12', '915_Casco.pdf', 'document_20260603_142912_f7a9770445fdec80.pdf', NULL, NULL, '2026-06-03 14:28:13', '2026-06-03 14:29:12'),
(93, 62, 'IPROCHIM', '', '2027-02-19', '915_IPROCHIM.pdf', 'document_20260603_142956_2a594a5ee9715889.pdf', NULL, NULL, '2026-06-03 14:29:56', '2026-06-03 14:29:56'),
(94, 62, 'ITP', '', '2027-01-26', '915_TALON.pdf', 'document_20260603_143025_84ce1b7751ce1825.pdf', NULL, NULL, '2026-06-03 14:30:25', '2026-06-03 14:30:25'),
(95, 62, 'RCA', '', '2027-01-12', '915_RCA.pdf', 'document_20260603_143137_43e8256ce2d275d7.pdf', NULL, NULL, '2026-06-03 14:31:37', '2026-06-03 14:31:37'),
(96, 61, 'ADR', '', '2027-01-27', '635_ADR_1.pdf', 'document_20260603_145352_c7acc97248d4e579.pdf', NULL, NULL, '2026-06-03 14:53:52', '2026-06-03 14:53:52'),
(97, 61, 'Carte', '', '2026-07-11', '635_Carte.pdf', 'document_20260603_145432_ad47bce68bd2a0e9.pdf', NULL, NULL, '2026-06-03 14:54:32', '2026-06-03 14:54:32'),
(98, 61, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_635.pdf', 'document_20260603_145508_3ab6a04fbc671779.pdf', NULL, NULL, '2026-06-03 14:55:08', '2026-06-03 14:55:08'),
(99, 61, 'ITP', '', '2027-01-07', '635_TALON_.pdf', 'document_20260603_145526_4ead4756c91c64b6.pdf', NULL, NULL, '2026-06-03 14:55:26', '2026-06-03 14:55:26'),
(100, 61, 'RCA', '', '2027-06-15', 'rca_635.pdf', 'document_20260616_091311_7328829249352ee4.pdf', NULL, NULL, '2026-06-03 14:55:46', '2026-06-16 09:13:11'),
(101, 61, 'Tahograf', '', '2027-07-15', '635_Taho.pdf', 'document_20260603_150344_e2dda49fcc29265c.pdf', NULL, NULL, '2026-06-03 15:03:44', '2026-06-03 15:03:44'),
(102, 60, 'Adr', '', '2027-03-11', '845_ADR_1.pdf', 'document_20260603_152627_04972505af6887c4.pdf', NULL, NULL, '2026-06-03 15:25:41', '2026-06-03 15:26:27'),
(103, 60, 'Carte', '', '2026-07-11', '845_Carte.pdf', 'document_20260603_152606_fdaeb25d08bc9c04.pdf', NULL, NULL, '2026-06-03 15:26:06', '2026-06-03 15:26:06'),
(104, 60, 'CASCO', '', '2030-04-02', '845_Casco.pdf', 'document_20260603_152736_df668341488d37ab.pdf', NULL, NULL, '2026-06-03 15:27:36', '2026-06-10 09:45:28'),
(105, 60, 'IPROCHIM', '', '2027-03-11', '845_Iprochim_1.pdf', 'document_20260603_152820_0e45cd380bc9f0e2.pdf', NULL, NULL, '2026-06-03 15:28:20', '2026-06-03 15:28:20'),
(106, 60, 'ITP', '', '2027-03-11', '845_Talon_1.pdf', 'document_20260603_152854_f7592c0d465f8a17.pdf', NULL, NULL, '2026-06-03 15:28:54', '2026-06-03 15:28:54'),
(107, 60, 'RCA', '', '2027-03-02', '845_RCA_1.pdf', 'document_20260603_152940_36c8f6c8f6b920e9.pdf', NULL, NULL, '2026-06-03 15:29:40', '2026-06-03 15:29:40'),
(108, 59, 'ADR', '', '2027-03-12', '625_ADR_1.pdf', 'document_20260603_154423_3a3d0553cb9008c9.pdf', NULL, NULL, '2026-06-03 15:44:23', '2026-06-03 15:44:23'),
(109, 59, 'Carte', '', '2026-07-11', '625_Carte.pdf', 'document_20260603_154435_1eb4813e74eeb301.pdf', NULL, NULL, '2026-06-03 15:44:35', '2026-06-03 15:44:35'),
(110, 59, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_625.pdf', 'document_20260603_154503_f7fa8ac30470acda.pdf', NULL, NULL, '2026-06-03 15:45:03', '2026-06-03 15:45:03'),
(111, 59, 'ITP', '', '2027-03-12', '625_Talon_1.pdf', 'document_20260603_154544_d3b9241bbb362fba.pdf', NULL, NULL, '2026-06-03 15:45:44', '2026-06-03 15:45:44'),
(112, 59, 'RCA', '', '2027-03-18', '625_RCA_1-3.pdf', 'document_20260603_154859_f97f52892ba78be7.pdf', NULL, NULL, '2026-06-03 15:48:59', '2026-06-03 15:48:59'),
(113, 59, 'Tahograf', '', '2028-03-20', '625_TAHO_1.pdf', 'document_20260603_154917_637203c9aa06ee5f.pdf', NULL, NULL, '2026-06-03 15:49:17', '2026-06-03 15:49:17'),
(114, 62, 'ADR', '', '2027-01-26', '915_ADR.pdf', 'document_20260603_155046_ba7b749f8e4ba82e.pdf', NULL, NULL, '2026-06-03 15:50:46', '2026-06-03 15:50:46'),
(115, 58, 'Adr', '', '2026-12-22', '825_ADR_1.pdf', 'document_20260603_155701_e78e0bc8e9f1b467.pdf', NULL, NULL, '2026-06-03 15:57:01', '2026-06-03 15:57:01'),
(116, 58, 'Carte', '', '2026-07-11', '825_Carte.pdf', 'document_20260603_155834_462b47727848b2e2.pdf', NULL, NULL, '2026-06-03 15:58:34', '2026-06-03 15:58:34'),
(117, 58, 'CASCO', '', '2027-01-05', '825_Casco_1.pdf', 'document_20260603_155902_e91622473ad7c056.pdf', NULL, NULL, '2026-06-03 15:59:02', '2026-06-10 09:46:00'),
(118, 58, 'IPROCHIM', '', '2026-12-22', '825_Iprochim.pdf', 'document_20260603_155927_106582aa865b6057.pdf', NULL, NULL, '2026-06-03 15:59:27', '2026-06-03 15:59:27'),
(119, 58, 'ITP', '', '2026-12-22', '825_Talon.pdf', 'document_20260603_155950_25416c4ef936e88b.pdf', NULL, NULL, '2026-06-03 15:59:50', '2026-06-03 15:59:50'),
(120, 58, 'RCA', '', '2026-12-05', '825_RCA.pdf', 'document_20260603_160006_65519cb3015c10e2.pdf', NULL, NULL, '2026-06-03 16:00:06', '2026-06-03 16:00:06'),
(121, 58, 'Tuv', '', '2027-12-30', '825_TUV_1.pdf', 'document_20260603_160156_ceba64d3b0ab5196.pdf', NULL, NULL, '2026-06-03 16:01:56', '2026-06-03 16:01:56'),
(122, 57, 'ADR', '', '2026-12-22', '615_ADR.pdf', 'document_20260603_163826_6569fcb99bf8feca.pdf', NULL, NULL, '2026-06-03 16:38:26', '2026-06-03 16:38:26'),
(123, 57, 'Carte', '', '2026-07-11', '615_Carte.pdf', 'document_20260603_163845_6dfe5ecd3582c19b.pdf', NULL, NULL, '2026-06-03 16:38:45', '2026-06-03 16:38:45'),
(124, 57, 'CASCO', '', '2027-05-25', 'POLITA_CASCO_FLOTA_2026.pdf', 'document_20260603_163906_13ef9b8cad65b83b.pdf', NULL, NULL, '2026-06-03 16:39:06', '2026-06-03 16:39:06'),
(125, 57, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_652_1.pdf', 'document_20260603_163926_5f8310cc76c44e35.pdf', NULL, NULL, '2026-06-03 16:39:26', '2026-06-03 16:39:26'),
(126, 57, 'ITP', '', '2026-12-22', '615_Talon.pdf', 'document_20260603_163941_ce9f3f7881ef0135.pdf', NULL, NULL, '2026-06-03 16:39:41', '2026-06-03 16:39:41'),
(127, 57, 'RCA', '', '2027-01-26', '615_RCA.pdf', 'document_20260610_103737_df43f55941bd54d0.pdf', NULL, NULL, '2026-06-03 16:39:58', '2026-06-10 10:37:37'),
(128, 57, 'Tahograf', '', '2027-12-27', '615_Taho.pdf', 'document_20260603_164016_a4b5b4f1ee36c068.pdf', NULL, NULL, '2026-06-03 16:40:16', '2026-06-03 16:40:16'),
(129, 56, 'ADR', '', '2026-07-16', '705_ADR.pdf', 'document_20260604_102042_e4f91fba35f1c3c6.pdf', NULL, NULL, '2026-06-04 10:20:42', '2026-06-04 10:20:42'),
(130, 56, 'METROLOGIE', '', '2026-11-08', '705_BRML.pdf', 'document_20260604_102100_222aa713e1b3cddc.pdf', NULL, NULL, '2026-06-04 10:21:00', '2026-06-10 10:48:08'),
(131, 56, 'Carte', '', '2026-07-11', '705_Carte.pdf', 'document_20260604_102417_04c5b0680792199f.pdf', NULL, NULL, '2026-06-04 10:21:27', '2026-06-04 10:24:17'),
(132, 56, 'CASCO', '', '2027-01-12', '705_Casco.pdf', 'document_20260604_102209_cf17cfb9dc6b0448.pdf', NULL, NULL, '2026-06-04 10:22:09', '2026-06-04 10:22:09'),
(133, 56, 'IPROCHIM', '', '2026-07-16', '705_Iprochim.pdf', 'document_20260604_102235_b5cbc23c786b23b7.pdf', NULL, NULL, '2026-06-04 10:22:35', '2026-06-04 10:22:35'),
(134, 56, 'ITP', '', '2026-07-16', '705_Talon.pdf', 'document_20260604_102254_1590350026067a6a.pdf', NULL, NULL, '2026-06-04 10:22:54', '2026-06-04 10:22:54'),
(135, 56, 'RCA', '', '2026-11-12', '705_RCA.pdf', 'document_20260604_102313_625bd4afed6a95d2.pdf', NULL, NULL, '2026-06-04 10:23:13', '2026-06-04 10:23:13'),
(136, 56, 'ORGANISM NOTIFICAT', '', '2026-07-30', '705_TUV_1.pdf', 'document_20260604_102348_9e787e43fca812e1.pdf', NULL, NULL, '2026-06-04 10:23:48', '2026-06-10 10:48:25'),
(137, 55, 'ADR', '', '2027-02-12', '905_ADR_1.pdf', 'document_20260604_104227_2c56316ec016f954.pdf', NULL, NULL, '2026-06-04 10:40:16', '2026-06-04 10:42:27'),
(138, 55, 'METROLOGIE', '', '2028-11-17', '905_BRML_-_MID_1.pdf', 'document_20260604_104053_138ea11e66f54752.pdf', NULL, NULL, '2026-06-04 10:40:53', '2026-06-10 10:55:36'),
(139, 55, 'Carte', '', '2026-07-11', '905_Carte._1.pdf', 'document_20260604_104309_f2cc03186ed4d004.pdf', NULL, NULL, '2026-06-04 10:43:09', '2026-06-04 10:43:09'),
(140, 55, 'CASCO', '', '2027-03-11', 'CASCO-905.pdf', 'document_20260604_104329_e9dd747ae2d489e9.pdf', NULL, NULL, '2026-06-04 10:43:29', '2026-06-04 10:43:29'),
(141, 55, 'IPROCHIM', '', '2027-02-12', '905_Iprochim_2.pdf', 'document_20260604_104350_9798db1ec19c592e.pdf', NULL, NULL, '2026-06-04 10:43:50', '2026-06-04 10:43:50'),
(142, 55, 'ITP', '', '2027-02-12', '905_Talon_nou_1.pdf', 'document_20260604_104410_fa2a078432238a31.pdf', NULL, NULL, '2026-06-04 10:44:10', '2026-06-04 10:44:10'),
(143, 55, 'RCA', '', '2027-02-11', '905_RCA.pdf', 'document_20260604_104437_efe6acb4164a9c94.pdf', NULL, NULL, '2026-06-04 10:44:37', '2026-06-04 10:44:37'),
(144, 54, 'ADR', '', '2027-02-13', '402_ADR.pdf', 'document_20260604_105641_bb7eab3f1210489c.pdf', NULL, NULL, '2026-06-04 10:56:41', '2026-06-04 10:56:41'),
(145, 54, 'Carte', '', '2026-07-11', '402_Carte.pdf', 'document_20260604_105733_0989de777bcfbe45.pdf', NULL, NULL, '2026-06-04 10:57:33', '2026-06-04 10:57:33'),
(146, 54, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_402.pdf', 'document_20260604_105830_212680745a829190.pdf', NULL, NULL, '2026-06-04 10:58:30', '2026-06-04 10:58:30'),
(147, 54, 'ITP', '', '2027-02-13', '402_Talon.pdf', 'document_20260604_105849_264d337032cff74e.pdf', NULL, NULL, '2026-06-04 10:58:49', '2026-06-04 10:58:49'),
(148, 54, 'RCA', '', '2027-06-09', 'RCA_402.pdf', 'document_20260609_153710_2760cae73a69b2bf.pdf', NULL, NULL, '2026-06-04 10:59:06', '2026-06-09 15:37:10'),
(149, 54, 'Tahograf', '', '2026-09-05', '402_Taho.pdf', 'document_20260604_105932_ee9d4d3bd0b0623a.pdf', NULL, NULL, '2026-06-04 10:59:32', '2026-06-04 10:59:32'),
(150, 53, 'ADR', '', '2026-07-21', '815_ADR.pdf', 'document_20260604_110853_5169ccd612fbc16d.pdf', NULL, NULL, '2026-06-04 11:08:53', '2026-06-04 11:08:53'),
(152, 53, 'Carte', '', '2026-07-11', '815_carte.pdf', 'document_20260604_110909_b038e7ba57ce1b94.pdf', NULL, NULL, '2026-06-04 11:09:09', '2026-06-04 11:09:09'),
(155, 53, 'CASCO', '', '2026-09-08', '815_Casco_1.pdf', 'document_20260604_113338_be50aca7c6ae7868.pdf', NULL, NULL, '2026-06-04 11:33:38', '2026-06-04 11:33:38'),
(156, 53, 'IPROCHIM', '', '2026-07-21', '815_Iprochim.pdf', 'document_20260604_113358_22f488cf8f6db628.pdf', NULL, NULL, '2026-06-04 11:33:58', '2026-06-04 11:33:58'),
(157, 53, 'ITP', '', '2026-07-21', '815_Talon.pdf', 'document_20260604_114504_feb1fb03bd801579.pdf', NULL, NULL, '2026-06-04 11:45:04', '2026-06-04 11:45:04'),
(158, 53, 'RCA', '', '2026-08-08', '815_RCA.pdf', 'document_20260604_121044_d70ae009322c881c.pdf', NULL, NULL, '2026-06-04 12:10:44', '2026-06-04 12:10:44'),
(159, 52, 'ADR', '', '2026-07-12', '401_ADR.pdf', 'document_20260604_121959_6671fba7c24daff8.pdf', NULL, NULL, '2026-06-04 12:16:56', '2026-06-04 12:19:59'),
(160, 52, 'Carte', '', '2026-07-11', '401_CARTE.pdf', 'document_20260604_121942_78a88e1747f9e7e3.pdf', NULL, NULL, '2026-06-04 12:17:12', '2026-06-04 12:19:42'),
(161, 52, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_401.pdf', 'document_20260604_122038_5dbe245c396223ff.pdf', NULL, NULL, '2026-06-04 12:20:38', '2026-06-04 12:20:38'),
(162, 52, 'ITP', '', '2026-07-12', '401_TALON.pdf', 'document_20260604_122115_b186101ff2ce9a02.pdf', NULL, NULL, '2026-06-04 12:21:15', '2026-06-04 12:21:15'),
(163, 52, 'RCA', '', '2027-03-22', '401RCA_1.pdf', 'document_20260604_122133_62268924288b386e.pdf', NULL, NULL, '2026-06-04 12:21:33', '2026-06-04 12:21:33'),
(164, 52, 'Tahograf', '', '2026-09-04', '401_Taho.pdf', 'document_20260604_122151_296b949ea0937275.pdf', NULL, NULL, '2026-06-04 12:21:51', '2026-06-04 12:21:51'),
(165, 51, 'Adr', '', '2026-10-31', '925_ADR.pdf', 'document_20260604_140149_fae91726578f81e4.pdf', NULL, NULL, '2026-06-04 14:01:49', '2026-06-04 14:01:49'),
(166, 51, 'Carte', '', NULL, '925_Carte.pdf', 'document_20260604_140205_d17bc6b13e9a0b42.pdf', NULL, NULL, '2026-06-04 14:02:05', '2026-06-16 10:02:57'),
(167, 51, 'IPROCHIM', '', '2026-10-31', '925_Iprochim.pdf', 'document_20260604_140227_38d816ca468f74d3.pdf', NULL, NULL, '2026-06-04 14:02:27', '2026-06-04 14:02:27'),
(168, 51, 'ITP', '', '2026-10-31', '925_Talon.pdf', 'document_20260604_140241_b5bf4a461605d894.pdf', NULL, NULL, '2026-06-04 14:02:41', '2026-06-04 14:02:41'),
(169, 51, 'RCA', '', '2026-10-26', '925_RCA_1.pdf', 'document_20260604_140309_7c9f3a4b33999acd.pdf', NULL, NULL, '2026-06-04 14:03:09', '2026-06-04 14:03:09'),
(170, 50, 'ADR', '', '2027-02-11', '400_ADR_1.pdf', 'document_20260604_142035_fd588c0c081b324a.pdf', NULL, NULL, '2026-06-04 14:20:35', '2026-06-04 14:20:35'),
(171, 50, 'Carte', '', NULL, '400_Carte_1.pdf', 'document_20260604_142105_e3f2743801cd58f9.pdf', NULL, NULL, '2026-06-04 14:21:05', '2026-06-04 14:21:05'),
(172, 50, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_400.pdf', 'document_20260604_142129_7b4d788b5f6b56d9.pdf', NULL, NULL, '2026-06-04 14:21:29', '2026-06-04 14:21:29'),
(173, 50, 'RCA', '', '2026-12-08', '400_RCA_1.pdf', 'document_20260604_142151_f3e81498a1b204d3.pdf', NULL, NULL, '2026-06-04 14:21:51', '2026-06-04 14:21:51'),
(174, 50, 'Rovinieta', '', '2026-08-04', NULL, NULL, NULL, NULL, '2026-06-04 14:22:43', '2026-06-04 14:22:43'),
(175, 49, 'Adr', '', '2027-03-03', '945_ADR_1.pdf', 'document_20260605_092147_99da0f0b5d49f76d.pdf', NULL, NULL, '2026-06-05 09:21:47', '2026-06-05 09:21:47'),
(176, 49, 'Carte', '', NULL, '945_Carte_1.pdf', 'document_20260605_092156_63bab0d79fb6d36d.pdf', NULL, NULL, '2026-06-05 09:21:56', '2026-06-05 09:21:56'),
(177, 49, 'CASCO', '', '2031-03-18', '945_Casco_1.pdf', 'document_20260605_092238_2bc245fa61cf4b6c.pdf', NULL, NULL, '2026-06-05 09:22:38', '2026-06-10 09:49:36'),
(178, 49, 'IPROCHIM', '', '2027-03-02', '945_Iprochim_1.pdf', 'document_20260605_092256_46a97c7cd6c43dce.pdf', NULL, NULL, '2026-06-05 09:22:56', '2026-06-05 09:22:56'),
(179, 49, 'ITP', '', '2027-03-03', '945_Talon_1.pdf', 'document_20260605_092316_b95607daf30ffd71.pdf', NULL, NULL, '2026-06-05 09:23:16', '2026-06-05 09:23:16'),
(180, 49, 'RCA', '', '2027-02-18', '945_Talon_1.pdf', 'document_20260605_092335_7f7f4ef59bcc86ab.pdf', NULL, NULL, '2026-06-05 09:23:35', '2026-06-05 09:23:35'),
(181, 48, 'ADR', '', '2027-05-08', '165_ADR.pdf', 'document_20260605_092631_7cc1da80921a82cd.pdf', NULL, NULL, '2026-06-05 09:26:31', '2026-06-05 09:26:31'),
(182, 48, 'Carte', '', NULL, '165_Carte.pdf', 'document_20260605_092658_72c0a12104378767.pdf', NULL, NULL, '2026-06-05 09:26:58', '2026-06-05 09:26:58'),
(183, 48, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_165.pdf', 'document_20260605_092728_8c7d608da259faec.pdf', NULL, NULL, '2026-06-05 09:27:28', '2026-06-05 09:27:28'),
(186, 48, 'ITP', '', '2027-05-08', '165_TALON.pdf', 'document_20260605_092901_795dbca03f3c568a.pdf', NULL, NULL, '2026-06-05 09:29:01', '2026-06-05 09:29:01'),
(187, 48, 'RCA', '', '2027-05-23', '165_RCA.pdf', 'document_20260605_092917_05555a7800b5cf8e.pdf', NULL, NULL, '2026-06-05 09:29:17', '2026-06-05 09:29:17'),
(188, 48, 'Rovinieta', '', '2027-02-26', NULL, NULL, NULL, NULL, '2026-06-05 09:29:35', '2026-06-05 09:29:35'),
(189, 48, 'Tahograf', '', '2028-04-27', 'Taho_165.pdf', 'document_20260605_092956_490c412776a3e8b4.pdf', NULL, NULL, '2026-06-05 09:29:56', '2026-06-05 09:29:56'),
(190, 47, 'Adr', '', '2027-03-18', '679_ADR_2.pdf', 'document_20260605_094944_82a5d10a0e653a20.pdf', NULL, NULL, '2026-06-05 09:49:44', '2026-06-05 09:49:44'),
(191, 47, 'Carte', '', NULL, '679_Carte.pdf', 'document_20260605_094953_fe1959aae81a1c8e.pdf', NULL, NULL, '2026-06-05 09:49:53', '2026-06-05 09:49:53'),
(192, 47, 'CASCO', '', '2027-05-25', 'POLITA_CASCO_FLOTA_2026.pdf', 'document_20260605_095011_97685ce5eba02f0b.pdf', NULL, NULL, '2026-06-05 09:50:11', '2026-06-10 09:47:48'),
(193, 47, 'ORGANISM NOTIFICAT', '', '2028-03-15', '679_CNCIR_1.pdf', 'document_20260605_095032_3adf4678c24f2f23.pdf', NULL, NULL, '2026-06-05 09:50:32', '2026-06-10 11:05:13'),
(194, 47, 'IPROCHIM', '', '2027-03-18', '679_Iprocim.pdf', 'document_20260605_095105_6f60f82ab710636b.pdf', NULL, NULL, '2026-06-05 09:51:05', '2026-06-05 09:51:05'),
(195, 47, 'ITP', '', '2027-03-18', '679_Talon.pdf', 'document_20260605_095122_3fd6fd9480ea8648.pdf', NULL, NULL, '2026-06-05 09:51:22', '2026-06-05 09:51:22'),
(196, 47, 'RCA', '', '2026-08-22', '679_RCA.pdf', 'document_20260605_095144_d30ad76fc76ae172.pdf', NULL, NULL, '2026-06-05 09:51:44', '2026-06-05 09:51:44'),
(197, 46, 'Adr', '', '2027-03-18', '680_ADR.pdf', 'document_20260605_101105_5caba5e644052327.pdf', NULL, NULL, '2026-06-05 10:07:22', '2026-06-05 10:11:05'),
(198, 46, 'Carte', '', NULL, '680_Carte.pdf', 'document_20260605_100742_03bf28e187a4d153.pdf', NULL, NULL, '2026-06-05 10:07:42', '2026-06-05 10:07:42'),
(199, 46, 'CASCO', '', '2027-05-25', 'POLITA_CASCO_FLOTA_2026.pdf', 'document_20260605_101225_45e035445aa3cde8.pdf', NULL, NULL, '2026-06-05 10:12:25', '2026-06-10 09:49:04'),
(200, 46, 'ORGANISM NOTIFICAT', '', '2028-03-15', '680_CNCIR_1.pdf', 'document_20260605_101253_4926cbf676948d51.pdf', NULL, NULL, '2026-06-05 10:12:53', '2026-06-10 11:05:45'),
(201, 46, 'IPROCHIM', '', '2027-03-18', '680_Iprochim_1.pdf', 'document_20260605_101310_0cd7f01ef82eede9.pdf', NULL, NULL, '2026-06-05 10:13:10', '2026-06-05 10:13:10'),
(202, 46, 'ITP', '', '2027-03-18', '680_Talon.pdf', 'document_20260605_101324_0328c3e47e6bacc7.pdf', NULL, NULL, '2026-06-05 10:13:24', '2026-06-05 10:13:24'),
(203, 46, 'RCA', '', '2026-10-06', '680_RCA.pdf', 'document_20260605_101353_f1831add41f4b2de.pdf', NULL, NULL, '2026-06-05 10:13:53', '2026-06-05 10:13:53'),
(204, 45, 'ADR', '', '2027-03-19', 'ADR_678.pdf', 'document_20260605_114100_67d06e05d2a1c95d.pdf', NULL, NULL, '2026-06-05 11:41:00', '2026-06-05 11:41:00'),
(205, 45, 'Carte', '', NULL, '678_Carte.pdf', 'document_20260605_114131_7298199dda780b75.pdf', NULL, NULL, '2026-06-05 11:41:31', '2026-06-05 11:41:31'),
(206, 45, 'CASCO', '', '2027-05-04', '678_Casco_1.pdf', 'document_20260605_114154_7cb7ac066e8f8c7c.pdf', NULL, NULL, '2026-06-05 11:41:54', '2026-06-05 11:41:54'),
(207, 45, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_678.pdf', 'document_20260605_114232_0ca09f9db9a03663.pdf', NULL, NULL, '2026-06-05 11:42:32', '2026-06-05 11:42:32'),
(208, 45, 'ITP', '', '2027-03-19', 'ITP_678.pdf', 'document_20260605_114307_b59947decb9d564c.pdf', NULL, NULL, '2026-06-05 11:43:07', '2026-06-05 11:43:07'),
(209, 45, 'RCA', '', '2027-06-21', 'RCA_678_1.pdf', 'document_20260605_114356_1d516b10e273b3ea.pdf', NULL, NULL, '2026-06-05 11:43:56', '2026-06-05 11:43:56'),
(210, 45, 'Rovinieta', '', '2027-02-04', NULL, NULL, NULL, NULL, '2026-06-05 11:44:18', '2026-06-05 11:44:18'),
(211, 45, 'Tahograf', '', '2027-05-21', '678_Taho.pdf', 'document_20260605_114447_3c2b50ce71269e33.pdf', NULL, NULL, '2026-06-05 11:44:47', '2026-06-05 11:44:47'),
(212, 44, 'ADR', '', '2026-07-17', '199_ADR.pdf', 'document_20260605_114755_b101bebd17471e50.pdf', NULL, NULL, '2026-06-05 11:47:55', '2026-06-05 11:47:55'),
(213, 44, 'METROLOGIE', '', '2026-08-18', '199_BRML.pdf', 'document_20260605_114818_cfe383633a8ce87b.pdf', NULL, NULL, '2026-06-05 11:48:18', '2026-06-10 10:56:00'),
(214, 44, 'Carte', '', NULL, '199_Carte.pdf', 'document_20260605_114946_92a8e1d10660598b.pdf', NULL, NULL, '2026-06-05 11:49:46', '2026-06-05 11:49:46'),
(215, 44, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_199.pdf', 'document_20260605_115039_e3a8f58fddb484b0.pdf', NULL, NULL, '2026-06-05 11:50:39', '2026-06-05 11:50:39'),
(216, 44, 'IPROCHIM', '', '2026-12-17', '199_Iprochim.pdf', 'document_20260605_115106_4fec524e2b17121b.pdf', NULL, NULL, '2026-06-05 11:51:06', '2026-06-05 11:51:06'),
(217, 44, 'ITP', '', '2026-07-17', '199_Talon.pdf', 'document_20260605_115125_dac0710efad6503d.pdf', NULL, NULL, '2026-06-05 11:51:25', '2026-06-05 11:51:25'),
(218, 44, 'RCA', '', '2026-08-22', '199_RCA.pdf', 'document_20260605_115144_c4bce0c04899327f.pdf', NULL, NULL, '2026-06-05 11:51:44', '2026-06-05 11:51:44'),
(219, 44, 'Rovinieta', '', '2026-09-22', NULL, NULL, NULL, NULL, '2026-06-05 11:52:06', '2026-06-05 11:52:06'),
(220, 44, 'Tahograf', '', '2027-09-03', '199_Taho.pdf', 'document_20260605_115229_41d712519b5bf08a.pdf', NULL, NULL, '2026-06-05 11:52:29', '2026-06-05 11:52:29'),
(221, 44, 'ORGANISM NOTIFICAT', '', '2027-06-10', '199_TUV.pdf', 'document_20260605_115251_4b6fa83da3bee306.pdf', NULL, NULL, '2026-06-05 11:52:51', '2026-06-10 10:56:38'),
(222, 43, 'ADR', '', '2026-09-23', '189_ADR.pdf', 'document_20260605_121049_009f6e6d88fd2428.pdf', NULL, NULL, '2026-06-05 12:10:49', '2026-06-05 12:10:49'),
(223, 43, 'METROLOGIE', '', '2026-11-08', '189_BRML.pdf', 'document_20260605_123757_d1ef2d7a277f1c73.pdf', NULL, NULL, '2026-06-05 12:37:57', '2026-06-10 10:55:07'),
(224, 43, 'Carte', '', NULL, '189_Carte.pdf', 'document_20260605_123811_7f35b9b95af770c2.pdf', NULL, NULL, '2026-06-05 12:38:11', '2026-06-05 12:38:11'),
(225, 43, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_189.pdf', 'document_20260605_123931_5a333d39c71356bf.pdf', NULL, NULL, '2026-06-05 12:39:31', '2026-06-05 12:39:31'),
(226, 43, 'IPROCHIM', '', '2026-09-12', '189_Iprochim.pdf', 'document_20260605_123959_d7465d8ee86031b6.pdf', NULL, NULL, '2026-06-05 12:39:59', '2026-06-05 12:39:59'),
(227, 43, 'ITP', '', '2026-09-23', '189_Talon.pdf', 'document_20260605_124044_b46bc8f4d4ec9efc.pdf', NULL, NULL, '2026-06-05 12:40:44', '2026-06-05 12:40:44'),
(228, 43, 'RCA', '', '2027-01-23', '189_RCA.pdf', 'document_20260605_124126_4e2c552935a159c3.pdf', NULL, NULL, '2026-06-05 12:41:26', '2026-06-05 12:41:26'),
(229, 43, 'Rovinieta', '', '2026-08-04', NULL, NULL, NULL, NULL, '2026-06-05 12:41:50', '2026-06-05 12:41:50'),
(230, 43, 'Tahograf', '', '2026-10-04', '189_Tajo.pdf', 'document_20260605_124210_ebaca9c3d7265316.pdf', NULL, NULL, '2026-06-05 12:42:10', '2026-06-05 12:42:10'),
(231, 43, 'ORGANISM NOTIFICAT', '', '2028-09-01', '189_TUV.pdf', 'document_20260605_124229_18ae51d4be653087.pdf', NULL, NULL, '2026-06-05 12:42:29', '2026-06-10 10:55:26'),
(232, 42, 'ADR', '', '2027-02-05', '439_ADR.pdf', 'document_20260605_124626_1086dece1f1d82b6.pdf', NULL, NULL, '2026-06-05 12:46:26', '2026-06-05 12:46:26'),
(233, 42, 'Carte', '', NULL, '439_Carte.pdf', 'document_20260605_124643_c6e20a20de254ee8.pdf', NULL, NULL, '2026-06-05 12:46:43', '2026-06-05 12:46:43'),
(234, 42, 'CASCO', '', '2027-05-25', 'POLITA_CASCO_FLOTA_2026.pdf', 'document_20260605_124700_a5bc36bbcc4305da.pdf', NULL, NULL, '2026-06-05 12:47:00', '2026-06-05 12:47:00'),
(235, 42, 'Copie conforma', '', '2028-02-11', '439_Copie_Conf.pdf', 'document_20260605_124729_b663e1d961699532.pdf', NULL, NULL, '2026-06-05 12:47:29', '2026-06-05 12:47:29'),
(236, 42, 'IPROCHIM', '', '2027-02-05', '439_IPROCHIM.pdf', 'document_20260605_124751_d55bf0036380d5f8.pdf', NULL, NULL, '2026-06-05 12:47:51', '2026-06-05 12:47:51'),
(237, 42, 'ITP', '', '2027-02-05', '439_Talon.pdf', 'document_20260605_124808_5e37ff9afe6813f1.pdf', NULL, NULL, '2026-06-05 12:48:08', '2026-06-05 12:48:08'),
(238, 42, 'RCA', '', '2026-11-27', '439_RCA.pdf', 'document_20260605_124846_925b6f156e5a51e5.pdf', NULL, NULL, '2026-06-05 12:48:46', '2026-06-05 12:48:46'),
(239, 42, 'Rovinieta', '', '2026-09-21', NULL, NULL, NULL, NULL, '2026-06-05 13:04:57', '2026-06-05 13:06:22'),
(240, 41, 'ADR', '', '2027-01-19', '433_ADR.pdf', 'document_20260605_131417_18a3c31c271584b4.pdf', NULL, NULL, '2026-06-05 13:14:17', '2026-06-05 13:14:17'),
(241, 41, 'METROLOGIE', '', '2026-09-26', '433_BRML.pdf', 'document_20260605_131445_8a74c396264e9da9.pdf', NULL, NULL, '2026-06-05 13:14:45', '2026-06-10 11:23:09'),
(242, 41, 'Carte', '', NULL, 'Carte_433.pdf', 'document_20260605_131744_854549afe1109a64.pdf', NULL, NULL, '2026-06-05 13:17:44', '2026-06-05 13:17:44'),
(243, 41, 'CASCO', '', '2027-05-25', 'POLITA_CASCO_FLOTA_2026.pdf', 'document_20260605_131808_95609ad5abd2f740.pdf', NULL, NULL, '2026-06-05 13:18:08', '2026-06-05 13:18:08'),
(244, 41, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_433.pdf', 'document_20260605_131851_b589888844023fec.pdf', NULL, NULL, '2026-06-05 13:18:51', '2026-06-05 13:18:51'),
(245, 41, 'IPROCHIM', '', '2027-01-19', '433_Iprochim.pdf', 'document_20260605_131912_28be0bc7a22bef6a.pdf', NULL, NULL, '2026-06-05 13:19:12', '2026-06-05 13:19:12'),
(246, 41, 'ITP', '', '2027-01-19', '433_Talon.pdf', 'document_20260605_131929_ee38ab37b03230fe.pdf', NULL, NULL, '2026-06-05 13:19:29', '2026-06-05 13:19:29'),
(247, 41, 'RCA', '', '2026-11-17', '433_RCA.pdf', 'document_20260605_131947_e5e9c4fc3b44ea18.pdf', NULL, NULL, '2026-06-05 13:19:47', '2026-06-05 13:19:47'),
(248, 41, 'Rovinieta', '', '2026-12-01', NULL, NULL, NULL, NULL, '2026-06-05 13:21:02', '2026-06-05 13:21:02'),
(249, 41, 'Tahograf', '', '2027-11-13', '433_Taho.pdf', 'document_20260605_132119_dcf8b7d1f6220385.pdf', NULL, NULL, '2026-06-05 13:21:19', '2026-06-05 13:21:19'),
(250, 41, 'ORGANISM NOTIFICAT', '', '2029-01-31', '433_TUV..pdf', 'document_20260605_132136_9bfe792a1f770e7b.pdf', NULL, NULL, '2026-06-05 13:21:36', '2026-06-10 11:22:55'),
(251, 40, 'ADR', 'VLBS755', '2027-01-16', '295_ADR.pdf', 'document_20260605_132552_e8537df9e3bdef55.pdf', NULL, NULL, '2026-06-05 13:25:52', '2026-06-12 15:31:46'),
(253, 40, 'METROLOGIE', '0584440', '2028-03-31', '295_BRML.pdf', 'document_20260605_132755_fd6263246b299871.pdf', NULL, NULL, '2026-06-05 13:27:55', '2026-06-12 15:52:16'),
(254, 40, 'Carte', 'S656082', NULL, '295_Carte.pdf', 'document_20260605_132803_7b6fcf6fcbafaa23.pdf', NULL, NULL, '2026-06-05 13:28:03', '2026-06-12 15:31:26'),
(255, 40, 'Copie conforma', '3019153', '2028-02-11', 'COPIE_CONFORMA_295.pdf', 'document_20260605_132838_3ea6f4d9b4565d03.pdf', NULL, NULL, '2026-06-05 13:28:38', '2026-06-12 15:49:53'),
(256, 40, 'IPROCHIM', '85671', '2027-01-16', '295_Iprochim.pdf', 'document_20260605_132901_a1d2293208b8bd11.pdf', NULL, NULL, '2026-06-05 13:29:01', '2026-06-12 15:50:07'),
(257, 40, 'ITP', 'B05725325', '2027-01-16', '295_Talon.pdf', 'document_20260605_132918_e23c2493dc6a4d10.pdf', NULL, NULL, '2026-06-05 13:29:18', '2026-06-12 15:27:50'),
(258, 40, 'RCA', '016154044', '2027-02-09', '295_RCA.pdf', 'document_20260605_132939_3c580a595d2fe6d2.pdf', NULL, NULL, '2026-06-05 13:29:39', '2026-06-12 15:35:11'),
(259, 40, 'Rovinieta', '', '2027-04-14', NULL, NULL, NULL, NULL, '2026-06-05 13:30:09', '2026-06-05 13:30:09'),
(260, 40, 'Tahograf', 'D192921', '2027-04-03', '295_TAHOGRAF_1.pdf', 'document_20260605_133035_3e0c7184360e741a.pdf', NULL, NULL, '2026-06-05 13:30:35', '2026-06-12 15:32:08'),
(261, 40, 'ORGANISM NOTIFICAT', '110-006/14.01.2026', '2028-01-14', '295_CNCIR.pdf', 'document_20260605_133106_8127f893c2cb1291.pdf', NULL, NULL, '2026-06-05 13:31:06', '2026-06-12 15:51:57'),
(262, 37, 'ADR', 'OBBM4481', '2027-06-09', '232_adr.pdf', 'document_20260611_104101_ed224c04933ba659.pdf', NULL, NULL, '2026-06-05 14:00:29', '2026-06-12 14:37:18'),
(263, 37, 'METROLOGIE', '0017075', '2026-11-08', '232_BRML.pdf', 'document_20260605_140052_545f86534a0b64fe.pdf', NULL, NULL, '2026-06-05 14:00:52', '2026-06-12 14:50:13'),
(264, 37, 'Carte', '', NULL, '232_Carte.pdf', 'document_20260605_140112_78e0ad7551a97118.pdf', NULL, NULL, '2026-06-05 14:01:12', '2026-06-05 14:01:12'),
(265, 37, 'CASCO', 'C3588512', '2027-05-27', 'POLITA_CASCO_FLOTA_2026_2.pdf', 'document_20260605_140154_22c09b0670c37add.pdf', NULL, NULL, '2026-06-05 14:01:54', '2026-06-12 14:44:18'),
(266, 37, 'Copie conforma', '3019149', '2028-02-11', 'COPIE_CONFORMA_232.pdf', 'document_20260605_140240_4876ff82a56749d2.pdf', NULL, NULL, '2026-06-05 14:02:40', '2026-06-12 14:44:51'),
(267, 37, 'IPROCHIM', '85998', '2027-06-09', '232-IPRO_1.pdf', 'document_20260605_140456_1a529cce43916502.pdf', NULL, NULL, '2026-06-05 14:04:56', '2026-06-12 14:45:54'),
(268, 37, 'ITP', 'B04824705', '2027-06-09', '232_talon.pdf', 'document_20260611_103939_b8c14171addd3d01.pdf', NULL, NULL, '2026-06-05 14:05:17', '2026-06-12 14:36:29'),
(269, 37, 'RCA', '128864441', '2027-05-22', '232_RCA_1.pdf', 'document_20260605_140559_9d2a0537ee26955d.pdf', NULL, NULL, '2026-06-05 14:05:59', '2026-06-12 14:42:27'),
(270, 37, 'Rovinieta', '', '2026-10-16', NULL, NULL, NULL, NULL, '2026-06-05 14:06:45', '2026-06-05 14:06:45'),
(271, 37, 'Tahograf', 'D229608', '2026-08-09', '232_Taho.pdf', 'document_20260605_140708_ef2e88ace8d0bc21.pdf', NULL, NULL, '2026-06-05 14:07:08', '2026-06-12 14:37:37'),
(272, 37, 'ORGANISM NOTIFICAT', '2026-TEC-197', '2029-06-30', '232TUV.pdf', 'document_20260605_140724_26a7ac72aeb93ac7.pdf', NULL, NULL, '2026-06-05 14:07:24', '2026-06-12 14:47:59'),
(280, 24, 'ITP', 'B05996200', '2026-12-22', '235_Talon.pdf', 'document_20260605_144047_7d4f717d52c556d1.pdf', NULL, NULL, '2026-06-05 14:40:47', '2026-06-12 14:51:47'),
(282, 24, 'Carte', 'T439522', NULL, '235_Carte.pdf', 'document_20260605_144220_60ae00e65be1d8c9.pdf', NULL, NULL, '2026-06-05 14:42:20', '2026-06-12 14:52:46'),
(283, 24, 'Copie conforma', '3019863', '2028-02-11', '235_Copie_confotma.pdf', 'document_20260605_144302_321457395a5ce83d.pdf', NULL, NULL, '2026-06-05 14:43:02', '2026-06-12 14:54:40'),
(284, 24, 'IPROCHIM', '85670', '2027-01-12', '235_Iprochim.pdf', 'document_20260605_144318_8a7792197d0b1418.pdf', NULL, NULL, '2026-06-05 14:43:18', '2026-06-12 14:55:04'),
(285, 24, 'RCA', '127833832', '2027-01-14', '235_RCA.pdf', 'document_20260605_144341_63af367353132045.pdf', NULL, NULL, '2026-06-05 14:43:41', '2026-06-12 14:54:03'),
(286, 24, 'Tahograf', 'D286712', '2028-02-11', '235_Taho.pdf', 'document_20260605_144424_384161f4b95f280f.pdf', NULL, NULL, '2026-06-05 14:44:24', '2026-06-12 14:53:40'),
(287, 32, 'Carte', '', NULL, '935_CARTE.pdf', 'document_20260605_150434_6f0abf8e9e103eff.pdf', NULL, NULL, '2026-06-05 15:04:34', '2026-06-05 15:04:34'),
(288, 32, 'IPROCHIM', '', '2026-12-11', '935_Iprochim_1.pdf', 'document_20260605_150549_9cd4ac79f48b37be.pdf', NULL, NULL, '2026-06-05 15:05:49', '2026-06-05 15:05:49'),
(289, 31, 'ADR', '', '2026-09-05', '105_ADR.pdf', 'document_20260605_151058_83a97d8a1a067e23.pdf', NULL, NULL, '2026-06-05 15:10:58', '2026-06-05 15:10:58'),
(290, 31, 'Carte', '', NULL, '105_Carte.pdf', 'document_20260605_151112_0a0e9cf2f19b6894.pdf', NULL, NULL, '2026-06-05 15:11:12', '2026-06-05 15:11:12'),
(291, 31, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_105.pdf', 'document_20260605_151131_2070f8814fde9e7e.pdf', NULL, NULL, '2026-06-05 15:11:31', '2026-06-05 15:11:31'),
(292, 31, 'ITP', '', '2026-09-05', '105_Talon.pdf', 'document_20260605_151200_f8c0aa9e855b15ac.pdf', NULL, NULL, '2026-06-05 15:11:41', '2026-06-05 15:12:00'),
(293, 31, 'RCA', '', '2027-01-16', '105_RCA.pdf', 'document_20260605_151305_e563228d80c76a4c.pdf', NULL, NULL, '2026-06-05 15:13:05', '2026-06-05 15:13:05'),
(294, 31, 'Rovinieta', '', '2026-09-07', NULL, NULL, NULL, NULL, '2026-06-05 15:13:23', '2026-06-05 15:13:23'),
(295, 31, 'Tahograf', '', '2028-02-05', '105_Taho.pdf', 'document_20260605_151427_d3dcaadc1818300a.pdf', NULL, NULL, '2026-06-05 15:14:27', '2026-06-05 15:14:27'),
(296, 28, 'ADR', '', '2026-07-17', '605_ADR.pdf', 'document_20260605_152702_e2ec09b3c7b462c3.pdf', NULL, NULL, '2026-06-05 15:26:08', '2026-06-05 15:27:02'),
(297, 28, 'Carte', '', NULL, '605_Carte.pdf', 'document_20260605_152636_8e50623ad466a7d2.pdf', NULL, NULL, '2026-06-05 15:26:36', '2026-06-05 15:26:36'),
(298, 28, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_605.pdf', 'document_20260605_152740_c6ab32bcb54666e2.pdf', NULL, NULL, '2026-06-05 15:27:40', '2026-06-05 15:27:40'),
(299, 28, 'ITP', '', '2026-07-17', '605_Talon.pdf', 'document_20260605_152804_5008d1383f541f51.pdf', NULL, NULL, '2026-06-05 15:28:04', '2026-06-05 15:28:04'),
(300, 28, 'RCA', '', '2027-02-22', '605_RCA.pdf', 'document_20260605_152832_0839879af88567a5.pdf', NULL, NULL, '2026-06-05 15:28:32', '2026-06-05 15:28:32'),
(301, 28, 'Rovinieta', '', '2026-07-15', NULL, NULL, 'Blank=programare ITP 16.07.2026', NULL, '2026-06-05 15:28:54', '2026-06-16 10:09:58'),
(302, 28, 'Tahograf', '', '2027-01-24', '605_Taho.pdf', 'document_20260605_152916_308193e80f69a6d6.pdf', NULL, NULL, '2026-06-05 15:29:16', '2026-06-05 15:29:16'),
(303, 27, 'ADR', '', '2027-02-05', '435_ADR_1.pdf', 'document_20260608_085837_00433b2f2de7ccb9.pdf', NULL, NULL, '2026-06-08 08:58:37', '2026-06-08 08:58:37'),
(304, 27, 'METROLOGIE', '', '2026-09-25', '435_BRML.pdf', 'document_20260608_085854_9398327bbef0701c.pdf', NULL, NULL, '2026-06-08 08:58:54', '2026-06-10 11:24:28'),
(305, 27, 'Carte', '', NULL, '435_Carte.pdf', 'document_20260608_085905_2f75ef354e012111.pdf', NULL, NULL, '2026-06-08 08:59:05', '2026-06-08 08:59:05'),
(306, 27, 'CASCO', '', '2027-05-25', 'POLITA_CASCO_FLOTA_2026.pdf', 'document_20260608_091741_3caf958dbfc09eae.pdf', NULL, NULL, '2026-06-08 09:17:41', '2026-06-08 09:17:41'),
(307, 27, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_435.pdf', 'document_20260608_093703_349470cad9858267.pdf', NULL, NULL, '2026-06-08 09:37:03', '2026-06-08 09:37:03'),
(308, 27, 'IPROCHIM', '', '2027-02-06', '435_Iprochim.pdf', 'document_20260608_093734_424fc04b5d2be3c4.pdf', NULL, NULL, '2026-06-08 09:37:34', '2026-06-08 09:37:34'),
(309, 27, 'ITP', '', '2027-02-05', '435_Talon_1.pdf', 'document_20260608_093756_20fa41a4b9a9c774.pdf', NULL, NULL, '2026-06-08 09:37:56', '2026-06-08 09:37:56'),
(310, 27, 'RCA', '', '2027-03-06', '435_RCA_.pdf', 'document_20260608_093833_2266176b93346c6d.pdf', NULL, NULL, '2026-06-08 09:38:33', '2026-06-08 09:38:33'),
(311, 27, 'Rovinieta', '', '2026-06-29', NULL, NULL, NULL, NULL, '2026-06-08 09:38:56', '2026-06-08 09:38:56'),
(312, 27, 'Tahograf', '', '2026-09-11', '435_TAHO.jpeg', 'document_20260608_093918_bedbf07c1e354745.jpeg', NULL, NULL, '2026-06-08 09:39:18', '2026-06-08 09:39:18'),
(313, 27, 'ORGANISM NOTIFICAT', '', '2029-02-28', '435_TUV_1.pdf', 'document_20260608_093956_0d93a6b9b64a1b47.pdf', NULL, NULL, '2026-06-08 09:39:56', '2026-06-10 11:24:36'),
(314, 26, 'ADR', '', '2027-03-20', '677_Adr_1.pdf', 'document_20260608_103717_49aafa9b15813b54.pdf', NULL, NULL, '2026-06-08 10:37:17', '2026-06-08 10:37:17'),
(315, 26, 'Carte', '', NULL, '677_Carte.pdf', 'document_20260608_103727_6c983d1653e628ae.pdf', NULL, NULL, '2026-06-08 10:37:27', '2026-06-08 10:37:27'),
(316, 26, 'CASCO', '', '2027-05-04', 'CASCO_677.pdf', 'document_20260608_103802_09b565c3869c0437.pdf', NULL, NULL, '2026-06-08 10:38:02', '2026-06-08 10:38:02'),
(317, 26, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_677.pdf', 'document_20260608_103816_d47c1b66888e45e7.pdf', NULL, NULL, '2026-06-08 10:38:16', '2026-06-08 10:38:16'),
(318, 26, 'ITP', '', '2027-03-20', '677_Talon_2.pdf', 'document_20260608_103837_8efca8bf12d71ec3.pdf', NULL, NULL, '2026-06-08 10:38:37', '2026-06-08 10:38:37'),
(319, 26, 'RCA', '', '2027-05-24', '677_Talon_2.pdf', 'document_20260608_103856_4d19bc95c9db5247.pdf', NULL, NULL, '2026-06-08 10:38:56', '2026-06-08 10:38:56'),
(320, 26, 'Rovinieta', '', '2027-02-08', NULL, NULL, NULL, NULL, '2026-06-08 10:39:06', '2026-06-08 10:39:06'),
(321, 26, 'Tahograf', '', '2027-08-13', '677_Taho.pdf', 'document_20260608_103930_fd535b76c1564ba7.pdf', NULL, NULL, '2026-06-08 10:39:30', '2026-06-08 10:39:30'),
(322, 25, 'ADR', '', '2027-01-08', '775_ADR.pdf', 'document_20260608_112212_68251ee4c9e3aaf7.pdf', NULL, NULL, '2026-06-08 11:22:12', '2026-06-08 11:22:12'),
(323, 25, 'METROLOGIE', '', '2028-11-17', '775_BRML-MID_1.pdf', 'document_20260608_112406_1e5eaacc0031c84d.pdf', NULL, NULL, '2026-06-08 11:24:06', '2026-06-10 11:25:41'),
(324, 25, 'Carte', '', NULL, '775_Carte.pdf', 'document_20260608_112414_93987b425741764b.pdf', NULL, NULL, '2026-06-08 11:24:14', '2026-06-08 11:24:14'),
(325, 25, 'CASCO', '', '2027-05-15', '775_CASCO_.pdf', 'document_20260608_112438_d53595a6db82171c.pdf', NULL, NULL, '2026-06-08 11:24:38', '2026-06-08 11:24:38'),
(326, 25, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_775.pdf', 'document_20260608_112508_ef57fdd53abbe818.pdf', NULL, NULL, '2026-06-08 11:25:08', '2026-06-08 11:25:08'),
(327, 25, 'IPROCHIM', '', '2026-09-17', '775_Iprochim.pdf', 'document_20260608_112526_6f5c9e3db7f90125.pdf', NULL, NULL, '2026-06-08 11:25:26', '2026-06-08 11:25:26'),
(328, 25, 'ITP', '', '2027-01-08', '775_Talon.pdf', 'document_20260608_112544_14b863bfa1807270.pdf', NULL, NULL, '2026-06-08 11:25:44', '2026-06-08 11:25:44'),
(329, 25, 'RCA', '', '2026-12-29', '775_RCA.pdf', 'document_20260608_112611_100bf6b18c225489.pdf', NULL, NULL, '2026-06-08 11:26:11', '2026-06-08 11:26:11'),
(330, 25, 'Rovinieta', '', '2027-05-28', NULL, NULL, NULL, NULL, '2026-06-08 11:26:28', '2026-06-08 11:26:28'),
(331, 25, 'Tahograf', '', '2027-01-09', '775_Taho.pdf', 'document_20260608_112645_2795faaa75015b10.pdf', NULL, NULL, '2026-06-08 11:26:45', '2026-06-08 11:26:45'),
(332, 25, 'ORGANISM NOTIFICAT', '', '2027-01-30', '775_TUV_2.pdf', 'document_20260608_112704_fa4d7bb3b38ffa16.pdf', NULL, NULL, '2026-06-08 11:27:04', '2026-06-10 11:25:33'),
(333, 23, 'ADR', 'VLBR726', '2027-07-29', '275_ADR.pdf', 'document_20260608_115157_3d5fd2f2a648ce4d.pdf', NULL, NULL, '2026-06-08 11:51:57', '2026-06-12 15:15:24'),
(334, 23, 'METROLOGIE', '0579370', '2027-09-04', '275_BRML.pdf', 'document_20260608_115218_6eb909c6e17a39b9.pdf', NULL, NULL, '2026-06-08 11:52:18', '2026-06-12 15:23:29'),
(335, 23, 'Carte', 'S158741', NULL, '275_Carte.pdf', 'document_20260608_115325_3ffbfd3282368a35.pdf', NULL, NULL, '2026-06-08 11:53:25', '2026-06-12 15:14:03'),
(336, 23, 'ORGANISM NOTIFICAT', '110-007/14.01.2026', '2028-01-14', '275_CNCIR.pdf', 'document_20260608_115356_3efffb56834bde46.pdf', NULL, NULL, '2026-06-08 11:53:56', '2026-06-12 15:22:58'),
(337, 23, 'Copie conforma', '3019151', '2028-02-11', 'COPIE_CONFORMA_275.pdf', 'document_20260608_115407_95351d5a23fb0d96.pdf', NULL, NULL, '2026-06-08 11:54:07', '2026-06-12 15:21:45'),
(338, 23, 'IPROCHIM', '85691', '2027-01-16', '275_Iprochim.pdf', 'document_20260608_115424_233f3b33da17c851.pdf', NULL, NULL, '2026-06-08 11:54:24', '2026-06-12 15:22:04'),
(339, 23, 'ITP', 'B05603513', '2026-07-29', '275_Talon..pdf', 'document_20260608_115443_07e98f91cf48fb31.pdf', NULL, NULL, '2026-06-08 11:54:43', '2026-06-12 15:08:09'),
(340, 23, 'RCA', '53439395220', '2026-08-17', '275_RCA.pdf', 'document_20260608_115504_93c20c56467560ca.pdf', NULL, NULL, '2026-06-08 11:55:04', '2026-06-12 15:21:16'),
(341, 23, 'Rovinieta', '', '2026-11-02', NULL, NULL, NULL, NULL, '2026-06-08 11:55:26', '2026-06-08 11:55:26'),
(342, 23, 'Tahograf', 'D250773', '2027-12-09', '275_Taho.pdf', 'document_20260608_115541_8a11d2df5fd4fe08.pdf', NULL, NULL, '2026-06-08 11:55:41', '2026-06-12 15:16:01'),
(343, 22, 'ADR', '', '2026-06-26', '430_ADR.pdf', 'document_20260608_121804_1e60dfe04f3e23db.pdf', NULL, NULL, '2026-06-08 12:18:04', '2026-06-08 12:18:04'),
(345, 22, 'METROLOGIE', '', '2027-04-19', '430_BRML.pdf', 'document_20260608_121823_98cc728e1041e5f5.pdf', NULL, NULL, '2026-06-08 12:18:23', '2026-06-10 11:26:12'),
(346, 22, 'Carte', '', NULL, '430_Carte.pdf', 'document_20260608_121832_2b31ca5455f9ab61.pdf', NULL, NULL, '2026-06-08 12:18:32', '2026-06-08 12:18:32'),
(347, 22, 'CASCO', '', '2027-05-25', 'POLITA_CASCO_FLOTA_2026.pdf', 'document_20260608_121855_f2cd9dd872708a65.pdf', NULL, NULL, '2026-06-08 12:18:55', '2026-06-08 12:18:55'),
(348, 22, 'CASCO', '', '2027-05-25', 'POLITA_CASCO_FLOTA_2026.pdf', 'document_20260608_121855_d373d006f2fd8487.pdf', NULL, NULL, '2026-06-08 12:18:55', '2026-06-08 12:18:55'),
(349, 22, 'IPROCHIM', '', '2026-06-26', '430_Iprochim.pdf', 'document_20260608_121931_14d6a6f40eb7271f.pdf', NULL, NULL, '2026-06-08 12:19:31', '2026-06-08 12:19:31'),
(350, 22, 'ITP', '', '2026-06-26', '430_Talon.pdf', 'document_20260608_121947_f83c2f6a354222c3.pdf', NULL, NULL, '2026-06-08 12:19:47', '2026-06-08 12:19:47'),
(351, 22, 'RCA', '', '2026-11-24', '430_RCA.pdf', 'document_20260608_122009_0a5c7bc20ed27ea8.pdf', NULL, NULL, '2026-06-08 12:20:09', '2026-06-08 12:20:09'),
(352, 22, 'Rovinieta', '', '2027-03-11', NULL, NULL, NULL, NULL, '2026-06-08 12:20:23', '2026-06-08 12:20:23'),
(353, 22, 'Tahograf', '', '2027-10-25', '430_Taho.pdf', 'document_20260608_122041_347831dda3b81ce8.pdf', NULL, NULL, '2026-06-08 12:20:41', '2026-06-08 12:20:41'),
(354, 22, 'ORGANISM NOTIFICAT', '', '2028-06-30', '430_TUV.pdf', 'document_20260608_122112_44d2f776e36d03f8.pdf', NULL, NULL, '2026-06-08 12:21:12', '2026-06-10 11:26:22'),
(355, 21, 'ADR', '', '2027-01-08', '311ADR.pdf', 'document_20260608_123529_2127f4395af68ab9.pdf', NULL, NULL, '2026-06-08 12:35:29', '2026-06-08 12:35:29'),
(356, 21, 'METROLOGIE', '', '2026-11-09', '311_BRML.pdf', 'document_20260608_123544_d483d45f8e74b043.pdf', NULL, NULL, '2026-06-08 12:35:44', '2026-06-10 10:15:48'),
(357, 21, 'Carte', '', NULL, '311_Carte.pdf', 'document_20260608_123553_64c9ec7071515703.pdf', NULL, NULL, '2026-06-08 12:35:53', '2026-06-08 12:35:53'),
(359, 21, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_311.pdf', 'document_20260608_123621_be36d929a7a78723.pdf', NULL, NULL, '2026-06-08 12:36:21', '2026-06-08 12:36:21'),
(360, 21, 'IPROCHIM', '', '2027-01-08', '311_Iprochim.pdf', 'document_20260608_123638_347f959962329675.pdf', NULL, NULL, '2026-06-08 12:36:38', '2026-06-08 12:36:38'),
(361, 21, 'ITP', '', '2027-01-08', '311_Talon.pdf', 'document_20260608_123656_0a806ecba2d4dc0a.pdf', NULL, NULL, '2026-06-08 12:36:56', '2026-06-08 12:36:56'),
(362, 20, 'ADR', '', '2026-12-24', '345_ADR.pdf', 'document_20260608_131116_6b7b9391d0621b2d.pdf', NULL, NULL, '2026-06-08 13:11:16', '2026-06-08 13:11:16'),
(363, 20, 'METROLOGIE', '', '2028-11-30', '345_BRML-MID.pdf', 'document_20260608_131130_7c0a19e493101b4f.pdf', NULL, NULL, '2026-06-08 13:11:30', '2026-06-10 11:27:19'),
(364, 20, 'Carte', '', NULL, '345_Cartre.pdf', 'document_20260608_131142_97ca0693471416b5.pdf', NULL, NULL, '2026-06-08 13:11:42', '2026-06-08 13:11:42'),
(365, 20, 'CASCO', '', '2027-01-30', '345_Casco.pdf', 'document_20260608_131210_9a077008c54b87bf.pdf', NULL, NULL, '2026-06-08 13:12:10', '2026-06-08 13:12:10'),
(366, 20, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_345.pdf', 'document_20260608_131228_fe76e45dd3292986.pdf', NULL, NULL, '2026-06-08 13:12:28', '2026-06-08 13:12:28');
INSERT INTO `documente` (`id`, `vehicle_id`, `tip_document`, `numar_document`, `data_expirare`, `fisier_original`, `fisier_stocat`, `observatii`, `custom_fields_json`, `created_at`, `updated_at`) VALUES
(367, 20, 'IPROCHIM', '', '2027-01-23', '345_Iprochim.pdf', 'document_20260608_131244_ef0cc28fde28b675.pdf', NULL, NULL, '2026-06-08 13:12:44', '2026-06-08 13:12:44'),
(368, 20, 'Tahograf', '', '2027-07-18', 'Taho_345_1.pdf', 'document_20260608_131335_3aaf15e5ffbb5052.pdf', NULL, NULL, '2026-06-08 13:13:35', '2026-06-08 13:13:35'),
(369, 20, 'ORGANISM NOTIFICAT', '', '2026-12-01', '345_TUV.pdf', 'document_20260608_131401_659dbde98a234596.pdf', NULL, NULL, '2026-06-08 13:14:01', '2026-06-10 11:27:10'),
(370, 19, 'ADR', '', '2026-09-02', '219_ADR.pdf', 'document_20260608_132834_2e4b583abf99aafe.pdf', NULL, NULL, '2026-06-08 13:28:34', '2026-06-08 13:28:34'),
(371, 19, 'METROLOGIE', '', '2026-11-09', '219_BRML.pdf', 'document_20260608_132853_4363945a68401e6e.pdf', NULL, NULL, '2026-06-08 13:28:53', '2026-06-10 10:58:24'),
(372, 19, 'Carte', '', NULL, '219_Carte.pdf', 'document_20260608_132901_2ce385badaf69673.pdf', NULL, NULL, '2026-06-08 13:29:01', '2026-06-08 13:29:01'),
(373, 19, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_219.pdf', 'document_20260608_133052_338be3c248fa5f14.pdf', NULL, NULL, '2026-06-08 13:30:52', '2026-06-08 13:30:52'),
(374, 19, 'Tahograf', '', '2026-08-08', '219_Taho.pdf', 'document_20260608_133801_0c428fbec9591517.pdf', NULL, NULL, '2026-06-08 13:38:01', '2026-06-08 13:38:01'),
(375, 19, 'ORGANISM NOTIFICAT', '', '2029-03-30', '219_TUV_1.pdf', 'document_20260608_133816_e743baa570c4c515.pdf', NULL, NULL, '2026-06-08 13:38:16', '2026-06-10 10:58:57'),
(376, 18, 'ADR', '', '2026-08-29', '437_Talon.pdf', 'document_20260608_134542_401a15fa5edfd11a.pdf', NULL, NULL, '2026-06-08 13:45:42', '2026-06-08 13:45:42'),
(377, 18, 'METROLOGIE', '', '2027-03-11', '437_BRML.pdf', 'document_20260608_134602_9b7f027589c15101.pdf', NULL, NULL, '2026-06-08 13:46:02', '2026-06-11 11:45:26'),
(378, 18, 'Carte', '', NULL, '437_Cartre.pdf', 'document_20260608_134609_714840c4226b1a7c.pdf', NULL, NULL, '2026-06-08 13:46:09', '2026-06-08 13:46:09'),
(379, 18, 'CASCO', '', '2027-05-25', 'POLITA_CASCO_FLOTA_2026.pdf', 'document_20260608_134623_7657e76fc9b1a931.pdf', NULL, NULL, '2026-06-08 13:46:23', '2026-06-08 13:46:23'),
(380, 18, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_437.pdf', 'document_20260608_134706_88bfa041cf61c335.pdf', NULL, NULL, '2026-06-08 13:47:06', '2026-06-08 13:47:06'),
(381, 18, 'IPROCHIM', '', '2026-08-29', '437_Iprochim.pdf', 'document_20260608_134726_0bc9274c5947bc95.pdf', NULL, NULL, '2026-06-08 13:47:26', '2026-06-08 13:47:26'),
(382, 18, 'Tahograf', '', '2028-01-21', '437_Taho.jpeg', 'document_20260608_134747_7d8e3e0f29510a11.jpeg', NULL, NULL, '2026-06-08 13:47:47', '2026-06-08 13:47:47'),
(383, 18, 'ORGANISM NOTIFICAT', '', '2026-09-30', '437_TUV.pdf', 'document_20260608_134845_7e5146b27f33c28b.pdf', NULL, NULL, '2026-06-08 13:48:45', '2026-06-11 11:45:38'),
(384, 17, 'Carte', '', NULL, '385_Carte.pdf', 'document_20260608_143453_b58a43071dde9fc4.pdf', NULL, NULL, '2026-06-08 14:34:53', '2026-06-08 14:34:53'),
(385, 17, 'ADR', '', '2026-07-22', '385_ADR.pdf', 'document_20260608_144346_a410bb1a8d6d4b95.pdf', NULL, NULL, '2026-06-08 14:43:46', '2026-06-08 14:43:46'),
(386, 17, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_385.pdf', 'document_20260608_144409_9cb219a6351d1887.pdf', NULL, NULL, '2026-06-08 14:44:09', '2026-06-08 14:44:09'),
(387, 17, 'IPROCHIM', '', '2026-07-21', '385_Iprochim.pdf', 'document_20260608_144427_296299832d29af4d.pdf', NULL, NULL, '2026-06-08 14:44:27', '2026-06-08 14:44:27'),
(388, 17, 'METROLOGIE', '', '2027-09-04', '385_BRML.pdf', 'document_20260608_144457_f9109922869fdb86.pdf', NULL, NULL, '2026-06-08 14:44:57', '2026-06-08 14:44:57'),
(389, 17, 'ORGANISM NOTIFICAT', '', '2027-03-17', '385_TUV.pdf', 'document_20260608_144515_39eda65a36bab8eb.pdf', NULL, NULL, '2026-06-08 14:45:15', '2026-06-08 14:45:15'),
(390, 17, 'ORGANISM NOTIFICAT', '', '2027-03-17', '385_TUV.pdf', 'document_20260608_144517_1b2e130759d11094.pdf', NULL, NULL, '2026-06-08 14:45:17', '2026-06-08 14:45:17'),
(391, 17, 'Tahograf', '', '2027-09-06', '385_Taho.pdf', 'document_20260608_144534_73574812741b301d.pdf', NULL, NULL, '2026-06-08 14:45:34', '2026-06-08 14:45:34'),
(392, 15, 'ADR', '', '2026-12-18', '285_ADR.pdf', 'document_20260608_145113_ff1c7a7db9195d90.pdf', NULL, NULL, '2026-06-08 14:51:13', '2026-06-08 14:51:13'),
(393, 15, 'Carte', '', NULL, '285_Carte.pdf', 'document_20260608_145126_71d83bb2a6b619af.pdf', NULL, NULL, '2026-06-08 14:51:26', '2026-06-08 14:51:26'),
(394, 15, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_285.pdf', 'document_20260608_145143_5183b63e68d84513.pdf', NULL, NULL, '2026-06-08 14:51:43', '2026-06-08 14:51:43'),
(395, 15, 'IPROCHIM', '', '2026-12-18', '285_Iprochim.pdf', 'document_20260608_145159_c89c563f5d1911f3.pdf', NULL, NULL, '2026-06-08 14:51:59', '2026-06-08 14:51:59'),
(396, 15, 'METROLOGIE', '', '2027-12-26', '285_BRML.pdf', 'document_20260608_145232_ece905fbb3f5eee8.pdf', NULL, NULL, '2026-06-08 14:52:32', '2026-06-08 14:52:32'),
(397, 15, 'ORGANISM NOTIFICAT', '', '2027-12-15', '285_CNCIR.pdf', 'document_20260608_145312_f0e188a7a32b12a4.pdf', NULL, NULL, '2026-06-08 14:53:12', '2026-06-08 14:53:12'),
(398, 15, 'Tahograf', '', '2026-08-22', '285_Taho.pdf', 'document_20260608_145333_e9432db3bed798bc.pdf', NULL, NULL, '2026-06-08 14:53:33', '2026-06-08 14:53:33'),
(399, 14, 'Adr', '', '2026-09-05', '305_ADR.pdf', 'document_20260608_153235_f625e3830b057f3a.pdf', NULL, NULL, '2026-06-08 15:32:35', '2026-06-08 15:32:35'),
(400, 14, 'Carte', '', NULL, '305_Carte.pdf', 'document_20260608_153246_673e461d13156c74.pdf', NULL, NULL, '2026-06-08 15:32:46', '2026-06-08 15:32:46'),
(401, 14, 'CASCO', '', '2027-05-25', 'POLITA_CASCO_FLOTA_2026.pdf', 'document_20260608_153302_458911afb82facf6.pdf', NULL, NULL, '2026-06-08 15:33:02', '2026-06-09 15:46:46'),
(402, 14, 'IPROCHIM', '', '2026-09-06', '305_Iprochim.pdf', 'document_20260608_153318_722d88209457cdaf.pdf', NULL, NULL, '2026-06-08 15:33:18', '2026-06-08 15:33:18'),
(403, 14, 'ITP', '', '2026-09-06', '305_Talon.pdf', 'document_20260608_153329_0c1a24694cfdd3dc.pdf', NULL, NULL, '2026-06-08 15:33:29', '2026-06-08 15:33:29'),
(404, 14, 'ORGANISM NOTIFICAT', '', '2026-09-07', '305_TUV.pdf', 'document_20260608_153347_a322014556a15be1.pdf', NULL, NULL, '2026-06-08 15:33:47', '2026-06-08 15:33:47'),
(405, 14, 'RCA', '', '2027-01-21', '305_RCA.pdf', 'document_20260608_153407_39e557e93c842fc2.pdf', NULL, NULL, '2026-06-08 15:34:07', '2026-06-08 15:34:07'),
(406, 14, 'METROLOGIE', '', '2029-04-29', '305BRML.pdf', 'document_20260608_153558_46c3c57c36c26e43.pdf', NULL, NULL, '2026-06-08 15:35:58', '2026-06-08 15:35:58'),
(407, 13, 'ADR', '', '2027-01-09', '655_ADR.pdf', 'document_20260608_154830_2931cd645780a37d.pdf', NULL, NULL, '2026-06-08 15:48:30', '2026-06-08 15:48:30'),
(408, 13, 'Carte', '', NULL, '655_Carte.pdf', 'document_20260608_154842_0d51665ea683edb5.pdf', NULL, NULL, '2026-06-08 15:48:42', '2026-06-08 15:48:42'),
(409, 13, 'CASCO', '', '2027-02-12', '655_Casco.pdf', 'document_20260608_154907_be90d2bea9347386.pdf', NULL, NULL, '2026-06-08 15:49:07', '2026-06-08 15:49:07'),
(410, 13, 'Copie conforma', '', '2027-02-04', 'Copie_Conforma_B655NET_Treiro.pdf', 'document_20260608_154930_26bf8a3dd601b584.pdf', NULL, NULL, '2026-06-08 15:49:30', '2026-06-08 15:49:30'),
(411, 13, 'ITP', '', '2027-01-09', '655_Talon.pdf', 'document_20260608_154946_5558e6cee3d0551f.pdf', NULL, NULL, '2026-06-08 15:49:46', '2026-06-08 15:49:46'),
(412, 13, 'RCA', '', '2027-01-26', '655_RCA_1.pdf', 'document_20260608_155025_ecfc3cb42d88d1df.pdf', NULL, NULL, '2026-06-08 15:50:25', '2026-06-08 15:50:25'),
(413, 13, 'Rovinieta', '', '2027-01-27', NULL, NULL, NULL, NULL, '2026-06-08 15:50:46', '2026-06-08 15:50:46'),
(414, 13, 'Tahograf', '', '2028-01-29', '655_Taho.pdf', 'document_20260608_155101_93bc1de4f2909a2b.pdf', NULL, NULL, '2026-06-08 15:51:01', '2026-06-08 15:51:01'),
(415, 12, 'ADR', '', '2027-05-10', '405_ADR_1.pdf', 'document_20260608_161139_e90f10f7b5deb70a.pdf', NULL, NULL, '2026-06-08 16:11:39', '2026-06-08 16:11:39'),
(416, 12, 'Carte', '', NULL, '405_Carte.pdf', 'document_20260608_161307_9050ec015e8885d7.pdf', NULL, NULL, '2026-06-08 16:13:07', '2026-06-08 16:13:07'),
(417, 12, 'IPROCHIM', '', '2027-05-07', '405_IPRO.pdf', 'document_20260608_161524_a6c1e5a84b71ec96.pdf', NULL, NULL, '2026-06-08 16:15:24', '2026-06-08 16:15:24'),
(418, 12, 'METROLOGIE', '', '2029-04-29', '405_BRML.pdf', 'document_20260608_161851_5694fd8860a3a5dc.pdf', NULL, NULL, '2026-06-08 16:18:51', '2026-06-08 16:18:51'),
(419, 12, 'ORGANISM NOTIFICAT', '', '2028-05-30', '405_TUV.pdf', 'document_20260608_161910_405ef3e12dcfa990.pdf', NULL, NULL, '2026-06-08 16:19:10', '2026-06-08 16:19:10'),
(420, 11, 'ADR', '', '2027-01-12', '665_ADR.pdf', 'document_20260609_090211_972f130c4faa795e.pdf', NULL, NULL, '2026-06-09 09:02:11', '2026-06-09 09:02:11'),
(421, 11, 'Carte', '', NULL, '665_Carte.pdf', 'document_20260609_090219_3cfd02d9866ad73d.pdf', NULL, NULL, '2026-06-09 09:02:19', '2026-06-09 09:02:19'),
(422, 11, 'CASCO', '', '2027-02-12', '665_Casco.pdf', 'document_20260609_090254_dc7f9212eb2ca08a.pdf', NULL, NULL, '2026-06-09 09:02:54', '2026-06-09 15:45:34'),
(423, 11, 'Copie conforma', '', '2027-02-04', 'Copie_Conforma_B665NET_Treiro.pdf', 'document_20260609_090320_bc08711b65f41f74.pdf', NULL, NULL, '2026-06-09 09:03:20', '2026-06-09 09:03:20'),
(424, 11, 'Tahograf', '', '2028-01-29', '665_Taho.pdf', 'document_20260609_090420_80145c0ad96f2e03.pdf', NULL, NULL, '2026-06-09 09:04:20', '2026-06-09 09:04:20'),
(425, 10, 'Adr', '', '2027-02-11', '805_ADR_1.pdf', 'document_20260609_090704_e9f5033c33d5dd50.pdf', NULL, NULL, '2026-06-09 09:07:04', '2026-06-09 09:07:04'),
(426, 10, 'Carte', '', NULL, '805_Carte.pdf', 'document_20260609_090712_0ad9004f7313e828.pdf', NULL, NULL, '2026-06-09 09:07:12', '2026-06-09 09:07:12'),
(427, 10, 'IPROCHIM', '', '2027-02-10', '805_IPROCHIM.pdf', 'document_20260609_090741_718824e79ca09c14.pdf', NULL, NULL, '2026-06-09 09:07:41', '2026-06-09 09:07:41'),
(428, 10, 'ITP', '', '2027-02-11', '805_Talon_1.pdf', 'document_20260609_090807_a4255b3d7b964c6f.pdf', NULL, NULL, '2026-06-09 09:08:07', '2026-06-09 09:08:07'),
(429, 10, 'ORGANISM NOTIFICAT', '', '2028-02-28', '805_TUV_1.pdf', 'document_20260609_091006_1c495a22ee665215.pdf', NULL, NULL, '2026-06-09 09:10:06', '2026-06-09 09:10:06'),
(430, 10, 'RCA', '', '2027-03-19', '805_RCA_1.pdf', 'document_20260609_091029_2b55cf1ebb33303f.pdf', NULL, NULL, '2026-06-09 09:10:29', '2026-06-09 09:10:29'),
(431, 9, 'ADR', '', '2027-05-22', '218_ADR.pdf', 'document_20260609_094630_be43481db72704da.pdf', NULL, NULL, '2026-06-09 09:46:30', '2026-06-09 09:46:30'),
(432, 9, 'Carte', '', NULL, '218_Carte.pdf', 'document_20260609_094639_7c3047325f34bce3.pdf', NULL, NULL, '2026-06-09 09:46:39', '2026-06-09 09:46:39'),
(433, 9, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_218.pdf', 'document_20260609_094706_23447a50c74d0a12.pdf', NULL, NULL, '2026-06-09 09:47:06', '2026-06-09 09:47:06'),
(434, 9, 'IPROCHIM', '', '2027-05-21', 'ipro_218f_1.pdf', 'document_20260609_094810_d522af72088ba3d1.pdf', NULL, NULL, '2026-06-09 09:48:10', '2026-06-09 09:48:10'),
(435, 9, 'ITP', '', '2027-05-22', '218_TALON_3.pdf', 'document_20260609_094832_bf523a4181b3210f.pdf', NULL, NULL, '2026-06-09 09:48:32', '2026-06-09 09:48:32'),
(436, 9, 'METROLOGIE', '', '2026-11-09', '218_BRML.pdf', 'document_20260609_094858_53120f6d62387495.pdf', NULL, NULL, '2026-06-09 09:48:58', '2026-06-09 09:48:58'),
(437, 9, 'ORGANISM NOTIFICAT', '', '2029-05-30', 'TEC_164-11052026174201.pdf', 'document_20260609_153430_812e29278f4cd495.pdf', NULL, NULL, '2026-06-09 09:49:40', '2026-06-09 15:34:30'),
(439, 9, 'Rovinieta', '', '2026-06-19', NULL, NULL, NULL, NULL, '2026-06-09 09:50:23', '2026-06-09 09:50:23'),
(440, 9, 'Tahograf', '', '2028-05-27', 'TAHO_218_1.pdf', 'document_20260609_095045_fd128c93c929f255.pdf', NULL, NULL, '2026-06-09 09:50:45', '2026-06-09 09:50:45'),
(441, 6, 'ADR', '', '2026-07-07', '325_ADR.pdf', 'document_20260609_110226_ca7965f8cecba48a.pdf', NULL, NULL, '2026-06-09 11:02:26', '2026-06-09 11:02:26'),
(442, 6, 'Carte', '', NULL, '325_Carte.pdf', 'document_20260609_110240_67faec634b03b5f1.pdf', NULL, NULL, '2026-06-09 11:02:40', '2026-06-09 11:02:40'),
(443, 6, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_325.pdf', 'document_20260609_110312_2a16773d78fd369e.pdf', NULL, NULL, '2026-06-09 11:03:12', '2026-06-09 11:03:12'),
(444, 6, 'IPROCHIM', '', '2026-07-07', '325_Iprochim.pdf', 'document_20260609_110346_0a1394e7e9bd2814.pdf', NULL, NULL, '2026-06-09 11:03:46', '2026-06-15 09:05:09'),
(445, 6, 'ITP', '', '2026-07-07', '325_Talon.pdf', 'document_20260609_110406_f8631e76d6ede5e4.pdf', NULL, NULL, '2026-06-09 11:04:06', '2026-06-09 11:04:06'),
(446, 6, 'METROLOGIE', '', '2027-08-12', '325_BRML.pdf', 'document_20260609_110429_ee32294791a1a3af.pdf', NULL, NULL, '2026-06-09 11:04:29', '2026-06-09 11:04:29'),
(447, 6, 'ORGANISM NOTIFICAT', '', '2027-05-30', 'CNCIR_325.pdf', 'document_20260609_111842_680f205caecf9e12.pdf', NULL, NULL, '2026-06-09 11:18:42', '2026-06-09 11:18:42'),
(448, 6, 'RCA', '', '2026-07-27', '325_RCA.pdf', 'document_20260609_111929_d25f0e14264da8bb.pdf', NULL, NULL, '2026-06-09 11:19:29', '2026-06-09 11:19:29'),
(449, 6, 'Rovinieta', '', '2026-08-06', NULL, NULL, NULL, NULL, '2026-06-09 11:19:49', '2026-06-09 11:19:49'),
(450, 6, 'Tahograf', '', '2026-08-12', '325_Taho.pdf', 'document_20260609_112008_099004d224b8da80.pdf', NULL, NULL, '2026-06-09 11:20:08', '2026-06-09 11:20:08'),
(459, 1, 'ADR', '', '2027-02-23', '315_ADR.pdf', 'document_20260609_114954_4b25860793afc39a.pdf', NULL, NULL, '2026-06-09 11:49:54', '2026-06-09 11:49:54'),
(460, 1, 'Carte', '', NULL, '315_Carte.pdf', 'document_20260609_115007_6ec8c19d4e16718d.pdf', NULL, NULL, '2026-06-09 11:50:07', '2026-06-09 11:50:07'),
(461, 1, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_315.pdf', 'document_20260609_115055_4768f011b968822e.pdf', NULL, NULL, '2026-06-09 11:50:55', '2026-06-09 11:50:55'),
(462, 1, 'IPROCHIM', '', '2027-02-23', '315_IPROCHIM_2.PDF', 'document_20260609_115111_849e01df3d665165.pdf', NULL, NULL, '2026-06-09 11:51:11', '2026-06-09 11:51:11'),
(463, 1, 'METROLOGIE', '', '2028-03-10', '315_BRML.pdf', 'document_20260609_115202_091229beaf65a8bb.pdf', NULL, NULL, '2026-06-09 11:52:02', '2026-06-09 11:52:02'),
(464, 1, 'ORGANISM NOTIFICAT', '', '2028-02-23', '315_CNCIR_2.pdf', 'document_20260609_115229_df50d44f53909ab1.pdf', NULL, NULL, '2026-06-09 11:52:29', '2026-06-09 11:52:29'),
(465, 1, 'Tahograf', '', '2027-03-10', '315_taho.pdf', 'document_20260609_115302_1625e81242f955b1.pdf', NULL, NULL, '2026-06-09 11:53:02', '2026-06-09 11:53:02'),
(466, 3, 'ADR', '', '2027-05-28', 'ADR_395.pdf', 'document_20260611_103121_85430beba6f0cc9b.pdf', NULL, NULL, '2026-06-09 12:10:50', '2026-06-11 10:31:21'),
(467, 3, 'Carte', '', NULL, '395_Carte.pdf', 'document_20260609_121250_33bd9df70dbe47c4.pdf', NULL, NULL, '2026-06-09 12:12:50', '2026-06-09 12:12:50'),
(468, 3, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_395.pdf', 'document_20260609_121313_a0de98e60b664887.pdf', NULL, NULL, '2026-06-09 12:13:13', '2026-06-09 12:13:13'),
(469, 3, 'IPROCHIM', '', '2027-05-28', '395_Iprochim.pdf', 'document_20260609_121329_6e80d9048888d4d2.pdf', NULL, NULL, '2026-06-09 12:13:29', '2026-06-09 12:13:29'),
(470, 3, 'ITP', '', '2027-05-28', 'TALON_395.pdf', 'document_20260611_102949_d03a688355dc2d2a.pdf', NULL, NULL, '2026-06-09 12:13:52', '2026-06-11 10:29:49'),
(471, 3, 'METROLOGIE', '', '2029-04-29', 'BRML_NOU_B_395_NET.pdf', 'document_20260609_121507_6ead06b3360f27f0.pdf', NULL, NULL, '2026-06-09 12:15:07', '2026-06-09 12:15:07'),
(472, 3, 'ORGANISM NOTIFICAT', '', '2029-05-30', '395_TUV.pdf', 'document_20260609_121535_039998fadd49f0d5.pdf', NULL, NULL, '2026-06-09 12:15:35', '2026-06-09 12:15:35'),
(473, 2, 'ADR', '', '2026-07-28', '335_ADR.pdf', 'document_20260609_122317_0696c276b66bf7a6.pdf', NULL, NULL, '2026-06-09 12:23:17', '2026-06-09 12:23:17'),
(474, 2, 'Carte', '', NULL, '335_Carte.pdf', 'document_20260609_122358_efe8b9e31e00bf13.pdf', NULL, NULL, '2026-06-09 12:23:58', '2026-06-09 12:23:58'),
(475, 2, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_335.pdf', 'document_20260609_122447_1a58802338d27e96.pdf', NULL, NULL, '2026-06-09 12:24:47', '2026-06-09 12:24:47'),
(476, 2, 'IPROCHIM', '', '2026-07-28', '335_Iprochim.pdf', 'document_20260609_122622_3ab81d4d557878ac.pdf', NULL, NULL, '2026-06-09 12:26:22', '2026-06-15 15:45:41'),
(477, 2, 'ITP', '', '2026-07-28', '335_Talon.pdf', 'document_20260609_122709_4e92c78d5c809aad.pdf', NULL, NULL, '2026-06-09 12:27:09', '2026-06-09 12:27:09'),
(479, 2, 'Tahograf', '', '2026-08-09', '335_Taho.pdf', 'document_20260609_124718_c8263840cad67fa6.pdf', NULL, NULL, '2026-06-09 12:47:18', '2026-06-09 12:47:18'),
(480, 49, 'METROLOGIE', '', '2028-11-30', 'MID_945.pdf', 'document_20260609_150529_a5e2e57710ccaea1.pdf', NULL, NULL, '2026-06-09 15:05:29', '2026-06-09 15:05:29'),
(481, 24, 'METROLOGIE', '0305526', '2026-11-08', 'Brml_235.pdf', 'document_20260612_150320_660c914f275fafc2.pdf', NULL, NULL, '2026-06-09 15:31:13', '2026-06-12 15:03:20'),
(483, 65, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-10 09:44:55', '2026-06-10 09:44:55'),
(484, 51, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-10 09:46:42', '2026-06-10 09:46:42'),
(485, 64, 'RCA', '', '2027-03-25', '652_RCA_1.pdf', 'document_20260610_101924_f35969ed2088a9f4.pdf', NULL, NULL, '2026-06-10 10:19:24', '2026-06-10 10:19:24'),
(486, 64, 'Rovinieta', '', '2027-05-26', NULL, NULL, NULL, NULL, '2026-06-10 10:19:37', '2026-06-10 10:19:37'),
(487, 2, 'METROLOGIE', '', '2027-08-12', '335_BRML.pdf', 'document_20260610_102313_f47e7543c8e7333a.pdf', NULL, NULL, '2026-06-10 10:23:13', '2026-06-10 10:23:13'),
(488, 2, 'ORGANISM NOTIFICAT', '', '2027-07-30', '335_TUV_1.pdf', 'document_20260610_102333_00606e8bc9ba9eca.pdf', NULL, NULL, '2026-06-10 10:23:33', '2026-06-10 10:23:33'),
(489, 63, 'Rovinieta', '', '2027-01-12', NULL, NULL, NULL, NULL, '2026-06-10 10:25:53', '2026-06-10 10:25:53'),
(490, 50, 'Tahograf', '', '2026-09-04', '400_Taho_2.pdf', 'document_20260610_102757_5028c41597082a46.pdf', NULL, NULL, '2026-06-10 10:27:57', '2026-06-10 10:27:57'),
(491, 52, 'Rovinieta', '', '2026-10-16', NULL, NULL, NULL, NULL, '2026-06-10 10:30:06', '2026-06-10 10:30:06'),
(492, 54, 'Rovinieta', '', '2027-06-17', NULL, NULL, NULL, NULL, '2026-06-10 10:31:07', '2026-06-10 10:31:07'),
(493, 61, 'Rovinieta', '', '2026-07-15', NULL, NULL, NULL, NULL, '2026-06-10 10:35:43', '2026-06-10 10:35:43'),
(494, 57, 'Rovinieta', '', '2027-02-04', NULL, NULL, NULL, NULL, '2026-06-10 10:38:16', '2026-06-10 10:38:16'),
(495, 59, 'Rovinieta', '', '2027-04-13', NULL, NULL, NULL, NULL, '2026-06-10 10:39:52', '2026-06-10 10:39:52'),
(496, 58, 'ORGANISM NOTIFICAT', '', '2027-12-30', '825_TUV_2.pdf', 'document_20260610_104101_9fa7f66a49ea002d.pdf', NULL, NULL, '2026-06-10 10:41:01', '2026-06-10 10:41:01'),
(497, 31, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-10 10:53:04', '2026-06-10 10:53:04'),
(498, 48, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-10 10:53:42', '2026-06-10 10:53:42'),
(499, 43, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-10 10:54:19', '2026-06-10 10:54:19'),
(500, 44, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-10 10:57:23', '2026-06-10 10:57:23'),
(501, 9, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-10 10:57:59', '2026-06-10 10:57:59'),
(502, 55, 'ORGANISM NOTIFICAT', '', '2027-02-28', '905_TUV_2.pdf', 'document_20260610_105838_a4b7fc9876269901.pdf', NULL, NULL, '2026-06-10 10:58:38', '2026-06-10 10:58:38'),
(503, 19, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-10 10:59:29', '2026-06-10 10:59:29'),
(504, 21, 'ADR', '', '2027-01-08', '311ADR.pdf', 'document_20260610_110022_47fe7ca3bea6973f.pdf', NULL, NULL, '2026-06-10 11:00:22', '2026-06-10 11:00:22'),
(505, 21, 'ORGANISM NOTIFICAT', '', '2027-01-30', '311_TUV_1.pdf', 'document_20260610_110102_dffc477478f3918b.pdf', NULL, NULL, '2026-06-10 11:01:02', '2026-06-10 11:01:02'),
(506, 19, 'IPROCHIM', '', '2027-03-31', '219_Iptochim.pdf', 'document_20260610_110118_f6599ce560526a87.pdf', NULL, NULL, '2026-06-10 11:01:18', '2026-06-10 11:01:18'),
(507, 21, 'RCA', '', '2026-07-20', '311_RCA.pdf', 'document_20260610_110122_e95949ad97156ab0.pdf', NULL, NULL, '2026-06-10 11:01:22', '2026-06-10 11:01:22'),
(508, 21, 'Rovinieta', '', '2026-07-23', NULL, NULL, NULL, NULL, '2026-06-10 11:01:32', '2026-06-10 11:01:32'),
(509, 21, 'Tahograf', '', '2028-02-08', '311_Taho_1.pdf', 'document_20260610_110145_85c5a84ae1034bc5.pdf', NULL, NULL, '2026-06-10 11:01:45', '2026-06-10 11:01:45'),
(510, 24, 'ORGANISM NOTIFICAT', '110-541', '2027-08-30', 'Cncir_235.pdf', 'document_20260610_110439_0b2627a46e771c7f.pdf', NULL, NULL, '2026-06-10 11:04:39', '2026-06-12 14:59:49'),
(511, 24, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-10 11:05:13', '2026-06-10 11:05:13'),
(512, 24, 'ADR', 'PHBT8578', '2026-12-22', '235_ADR.pdf', 'document_20260610_110805_2b19ff9e4f9aeff2.pdf', NULL, NULL, '2026-06-10 11:08:05', '2026-06-12 14:53:16'),
(513, 23, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-10 11:09:02', '2026-06-10 11:09:02'),
(514, 15, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-10 11:09:49', '2026-06-10 11:09:49'),
(515, 40, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-10 11:10:25', '2026-06-10 11:10:25'),
(516, 21, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-10 11:11:05', '2026-06-10 11:11:05'),
(517, 1, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-10 11:12:12', '2026-06-10 11:12:12'),
(518, 6, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-10 11:12:54', '2026-06-10 11:12:54'),
(519, 2, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-10 11:13:35', '2026-06-10 11:13:35'),
(520, 2, 'RCA', '', '2026-07-28', '335_RCA.pdf', 'document_20260610_111450_335ce160b2a170b1.pdf', NULL, NULL, '2026-06-10 11:14:50', '2026-06-10 11:14:50'),
(521, 2, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-10 11:15:02', '2026-06-10 11:15:02'),
(522, 42, 'Tahograf', '', '2028-02-12', '439_Taho_1.pdf', 'document_20260610_111544_99db152c30f7066f.pdf', NULL, NULL, '2026-06-10 11:15:44', '2026-06-10 11:15:44'),
(523, 16, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_375.pdf', 'document_20260610_111659_11ab5fdaa20f9723.pdf', NULL, NULL, '2026-06-10 11:16:59', '2026-06-10 11:16:59'),
(524, 16, 'METROLOGIE', '', '2027-07-04', '375_BRML.pdf', 'document_20260610_111827_09637bea0d59f650.pdf', NULL, NULL, '2026-06-10 11:18:27', '2026-06-10 11:18:27'),
(525, 16, 'IPROCHIM', '', '2027-01-23', '375_Iprochim.pdf', 'document_20260610_111949_1705d7a69766b62f.pdf', NULL, NULL, '2026-06-10 11:19:49', '2026-06-10 11:19:49'),
(526, 16, 'ORGANISM NOTIFICAT', '', '2028-01-01', '375_TUV.pdf', 'document_20260610_112103_50876ee177d4711e.pdf', NULL, NULL, '2026-06-10 11:21:03', '2026-06-10 11:21:03'),
(527, 42, 'ORGANISM NOTIFICAT', '', '2027-01-26', '439_TUV.pdf', 'document_20260611_163017_fe87084fdace4578.pdf', NULL, NULL, '2026-06-10 11:21:11', '2026-06-11 16:30:17'),
(528, 16, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-10 11:21:22', '2026-06-10 11:21:22'),
(529, 16, 'ADR', '', '2026-07-04', '375_ADR.pdf', 'document_20260610_112255_128a0b66885ce0b3.pdf', NULL, NULL, '2026-06-10 11:22:55', '2026-06-10 11:22:55'),
(530, 16, 'Tahograf', '', '2028-03-20', NULL, NULL, NULL, NULL, '2026-06-10 11:25:28', '2026-06-10 11:25:28'),
(531, 17, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-10 11:27:10', '2026-06-10 11:27:10'),
(532, 3, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-10 11:27:51', '2026-06-10 11:27:51'),
(533, 22, 'Copie conforma', '', '2028-02-11', 'COPIE_CONFORMA_430.pdf', 'document_20260610_112852_b504d525d8f657f2.pdf', NULL, NULL, '2026-06-10 11:28:52', '2026-06-10 11:28:52'),
(534, 3, 'Rovinieta', '', '2027-04-26', NULL, NULL, NULL, NULL, '2026-06-10 11:30:26', '2026-06-10 11:30:26'),
(535, 50, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-10 11:31:17', '2026-06-10 11:31:17'),
(536, 52, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-10 11:31:51', '2026-06-10 11:31:51'),
(537, 54, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-10 11:32:24', '2026-06-10 11:32:24'),
(538, 28, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-10 11:33:11', '2026-06-10 11:33:11'),
(539, 59, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-10 11:42:36', '2026-06-10 11:42:36'),
(540, 61, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-10 11:43:17', '2026-06-10 11:43:53'),
(541, 10, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-10 11:44:28', '2026-06-10 11:44:28'),
(542, 32, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-10 11:47:20', '2026-06-10 11:47:20'),
(543, 51, 'ORGANISM NOTIFICAT', '', '2027-10-01', '925_Iprochim.pdf', 'document_20260610_140531_c35d300f98a29609.pdf', NULL, NULL, '2026-06-10 14:05:31', '2026-06-10 14:05:31'),
(544, 51, 'METROLOGIE', '', '2026-09-01', NULL, NULL, 'Urmeaza', NULL, '2026-06-10 14:08:59', '2026-06-16 10:14:12'),
(545, 60, 'ORGANISM NOTIFICAT', '', '2028-03-01', 'org_845.pdf', 'document_20260610_142223_44c2af610a79bf7e.pdf', NULL, NULL, '2026-06-10 14:22:23', '2026-06-10 14:22:23'),
(546, 49, 'ORGANISM NOTIFICAT', '', '2029-01-01', 'org_945.pdf', 'document_20260610_142443_3f0bc6a347f7cf72.pdf', NULL, NULL, '2026-06-10 14:24:43', '2026-06-10 14:24:43'),
(547, 3, 'Tahograf', '', '2028-04-23', 'TAHOGRAF_395.pdf', 'document_20260611_102859_2069d513dc7ba6bc.pdf', NULL, NULL, '2026-06-11 10:24:53', '2026-06-11 10:28:59'),
(548, 72, 'Carte', '', NULL, '34_CARTE.pdf', 'document_20260611_115555_7a0ea7604269937a.pdf', NULL, NULL, '2026-06-11 11:55:55', '2026-06-11 11:55:55'),
(549, 72, 'ITP', '', '2027-02-24', '34_Talon_1.pdf', 'document_20260611_115613_c0a98987376cc84a.pdf', NULL, NULL, '2026-06-11 11:56:13', '2026-06-11 11:56:13'),
(550, 72, 'RCA', '', '2027-03-01', '34_RCA.pdf', 'document_20260611_115630_e379a6c48c539712.pdf', NULL, NULL, '2026-06-11 11:56:30', '2026-06-11 11:56:30'),
(551, 72, 'Rovinieta', '', '2027-02-28', NULL, NULL, NULL, NULL, '2026-06-11 11:56:45', '2026-06-11 11:56:45'),
(552, 73, 'Carte', '', NULL, NULL, NULL, NULL, NULL, '2026-06-11 12:02:58', '2026-06-11 12:02:58'),
(553, 73, 'ITP', '', '2027-02-12', '72_talon.pdf', 'document_20260611_121945_b3ce7c0ab064d77a.pdf', NULL, NULL, '2026-06-11 12:19:45', '2026-06-11 12:19:45'),
(554, 73, 'RCA', '', '2027-05-12', '72_RCA_1.pdf', 'document_20260611_122015_0b5144156bf0c3db.pdf', NULL, NULL, '2026-06-11 12:20:15', '2026-06-11 12:20:15'),
(555, 73, 'Rovinieta', '', '2026-07-07', NULL, NULL, NULL, NULL, '2026-06-11 12:20:26', '2026-06-11 12:20:26'),
(556, 74, 'Carte', '', NULL, '82_carte.pdf', 'document_20260611_124255_c1a86581e4998e97.pdf', NULL, NULL, '2026-06-11 12:42:55', '2026-06-11 12:42:55'),
(557, 74, 'ITP', '', '2026-09-16', '82_talon.jpg', 'document_20260611_124554_f42c9ec316d160b0.jpg', NULL, NULL, '2026-06-11 12:45:54', '2026-06-11 12:45:54'),
(558, 71, 'Carte', '', NULL, '177_carteb.pdf', 'document_20260611_143140_6dcae214ed6db18d.pdf', NULL, NULL, '2026-06-11 14:31:40', '2026-06-11 14:31:40'),
(559, 71, 'CASCO', '', '2029-02-15', 'casco_177.pdf', 'document_20260611_143431_058533e1b0ed8946.pdf', NULL, NULL, '2026-06-11 14:34:31', '2026-06-11 14:34:31'),
(560, 71, 'ITP', '', '2028-01-08', '177_Talon_2.pdf', 'document_20260611_143453_f0c2708259bfffbf.pdf', NULL, NULL, '2026-06-11 14:34:53', '2026-06-11 14:34:53'),
(561, 71, 'RCA', '', '2028-01-15', 'rca_177.pdf', 'document_20260611_143510_ece0fb4ee1838332.pdf', NULL, NULL, '2026-06-11 14:35:10', '2026-06-11 14:35:10'),
(562, 71, 'Rovinieta', '', '2027-02-26', NULL, NULL, NULL, NULL, '2026-06-11 14:35:33', '2026-06-11 14:35:33'),
(563, 76, 'Carte', 'G437030', NULL, '112_carte.pdf', 'document_20260612_105343_47614ea350f91259.pdf', NULL, NULL, '2026-06-12 10:53:43', '2026-06-12 10:53:43'),
(564, 76, 'ITP', '', '2027-04-27', '112_itp.pdf', 'document_20260612_105405_18910fb07ae7d006.pdf', NULL, NULL, '2026-06-12 10:54:05', '2026-06-12 10:54:05'),
(565, 76, 'RCA', '', '2026-12-09', '112_rca.pdf', 'document_20260612_105453_e8a574499d2ff437.pdf', NULL, NULL, '2026-06-12 10:54:53', '2026-06-12 10:54:53'),
(566, 76, 'Rovinieta', '', '2027-04-26', NULL, NULL, NULL, NULL, '2026-06-12 10:55:38', '2026-06-12 10:55:38'),
(567, 67, 'Carte', '', NULL, '875_CARTE.pdf', 'document_20260612_111740_6eb748b12721b09d.pdf', NULL, NULL, '2026-06-12 11:17:40', '2026-06-12 11:17:40'),
(568, 67, 'ITP', '', '2027-06-12', '875_certificat_ITP.pdf', 'document_20260612_111830_83d62432ac64bdb2.pdf', NULL, NULL, '2026-06-12 11:18:30', '2026-06-12 11:18:30'),
(569, 67, 'RCA', '', '2026-07-15', '875_RCA.pdf', 'document_20260612_111848_f3e53ab4a3895318.pdf', NULL, NULL, '2026-06-12 11:18:48', '2026-06-12 11:18:48'),
(570, 67, 'Rovinieta', '', '2026-09-04', NULL, NULL, NULL, NULL, '2026-06-12 11:19:04', '2026-06-12 11:19:04'),
(571, 77, 'Carte', '', NULL, '669_carte.pdf', 'document_20260612_114243_59b0f8f743a82f6f.pdf', NULL, NULL, '2026-06-12 11:42:43', '2026-06-12 11:42:43'),
(572, 77, 'ITP', '', '2026-07-02', NULL, NULL, NULL, NULL, '2026-06-12 11:42:54', '2026-06-16 10:18:28'),
(573, 77, 'RCA', '', '2027-05-27', '669_RCA.pdf', 'document_20260612_114319_07b15de8a564fd33.pdf', NULL, NULL, '2026-06-12 11:43:19', '2026-06-12 11:43:19'),
(574, 77, 'Rovinieta', '', '2027-03-13', NULL, NULL, NULL, NULL, '2026-06-12 11:43:29', '2026-06-12 11:43:29'),
(575, 66, 'Carte', '', NULL, 'carte_888.pdf', 'document_20260612_122357_13d49d98b45b17b2.pdf', NULL, NULL, '2026-06-12 12:23:57', '2026-06-12 12:23:57'),
(576, 66, 'ITP', '', '2027-01-24', 'talon_888.pdf', 'document_20260612_122431_015e87c9e782a619.pdf', NULL, NULL, '2026-06-12 12:24:31', '2026-06-12 12:24:31'),
(577, 66, 'RCA', '', '2027-05-20', '888_rca.pdf', 'document_20260612_122502_90e709cb53fb810a.pdf', NULL, NULL, '2026-06-12 12:25:02', '2026-06-12 12:25:02'),
(578, 66, 'Rovinieta', '', '2027-05-26', NULL, NULL, NULL, NULL, '2026-06-12 12:26:13', '2026-06-12 12:26:13'),
(579, 68, 'Carte', '', NULL, '230_Carte_1.pdf', 'document_20260612_122827_929c380f4e941b4d.pdf', NULL, NULL, '2026-06-12 12:28:27', '2026-06-12 12:28:27'),
(580, 68, 'ITP', '', '2027-08-18', '230_Talon_1.pdf', 'document_20260612_123020_33f3ce8b220a1957.pdf', NULL, NULL, '2026-06-12 12:30:20', '2026-06-12 12:30:20'),
(581, 68, 'RCA', '', '2026-07-14', '230_RCA_1.pdf', 'document_20260612_123053_7a368ce83b1065a9.pdf', NULL, NULL, '2026-06-12 12:30:53', '2026-06-12 12:30:53'),
(582, 68, 'Rovinieta', '', '2026-10-16', NULL, NULL, NULL, NULL, '2026-06-12 12:34:46', '2026-06-12 12:34:46'),
(583, 70, 'Carte', '', NULL, '184_carte.pdf', 'document_20260612_130553_4e04532a51926ea1.pdf', NULL, NULL, '2026-06-12 13:05:53', '2026-06-12 13:05:53'),
(584, 70, 'ITP', '', '2027-01-19', NULL, NULL, NULL, NULL, '2026-06-12 13:06:58', '2026-06-12 13:06:58'),
(585, 70, 'RCA', '', '2027-05-29', '184_rca.pdf', 'document_20260612_130721_619f64c9d4ad6136.pdf', NULL, NULL, '2026-06-12 13:07:21', '2026-06-12 13:07:21'),
(586, 70, 'Rovinieta', '', '2027-05-18', NULL, NULL, NULL, NULL, '2026-06-12 13:08:59', '2026-06-12 13:08:59'),
(587, 62, 'ORGANISM NOTIFICAT', '', '2028-01-01', NULL, NULL, '915- AN FABRICATIE 2025 \r\nORGANISMUL NOTIFICAT SE FACE DUPA 3 ANI, \r\nAM PUS DATA IN CARE EXPIRA IPROCHIMUL IN 2028 SI AM LUAT O MARJA DE 30 ZILE INAINTE.', NULL, '2026-06-12 13:20:10', '2026-06-12 13:20:10'),
(588, 42, 'METROLOGIE', '', '2026-09-01', NULL, NULL, 'blank,urmeaza sa i se faca', NULL, '2026-06-12 13:41:52', '2026-06-16 10:07:06'),
(589, 32, 'ORGANISM NOTIFICAT', '', '2027-01-16', '935_CNCIR_PED_2.pdf', 'document_20260612_134423_29519889a61563bc.pdf', NULL, NULL, '2026-06-12 13:44:23', '2026-06-12 13:44:23'),
(590, 72, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-12 14:14:05', '2026-06-12 14:15:01'),
(591, 73, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-12 14:16:01', '2026-06-12 14:16:01'),
(592, 74, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-12 14:16:59', '2026-06-12 14:16:59'),
(593, 76, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-12 14:17:45', '2026-06-12 14:17:45'),
(594, 70, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-12 14:18:45', '2026-06-12 14:18:45'),
(595, 77, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-12 14:19:12', '2026-06-12 14:19:12'),
(596, 67, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-12 14:19:44', '2026-06-12 14:19:44'),
(597, 67, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-12 14:20:26', '2026-06-12 14:20:26'),
(598, 66, 'CASCO', '', '2027-01-01', NULL, NULL, NULL, NULL, '2026-06-12 14:21:21', '2026-06-12 14:21:21'),
(599, 74, 'RCA', '', '2027-03-11', '82_RCA_2.pdf', 'document_20260612_142712_df65cf50bff09a9b.pdf', NULL, NULL, '2026-06-12 14:27:12', '2026-06-12 14:27:12'),
(600, 74, 'Rovinieta', '', '2027-01-12', NULL, NULL, NULL, NULL, '2026-06-12 14:27:32', '2026-06-12 14:27:32'),
(601, 53, 'ORGANISM NOTIFICAT', '', '2027-07-30', NULL, NULL, 'Urmeaza sa primim atasamentul', NULL, '2026-06-16 10:15:53', '2026-06-16 10:15:53'),
(602, 68, 'CASCO', '', '2027-01-01', NULL, NULL, 'De intrebat daca i se face sau nu', NULL, '2026-06-16 10:18:33', '2026-06-16 10:18:33');

-- --------------------------------------------------------

--
-- Table structure for table `documente_soferi`
--

CREATE TABLE `documente_soferi` (
  `id` int UNSIGNED NOT NULL,
  `driver_id` int UNSIGNED NOT NULL,
  `tip_document` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `numar_document` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_emitere` date DEFAULT NULL,
  `data_expirare` date DEFAULT NULL,
  `fisier_original` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fisier_stocat` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `observatii` text COLLATE utf8mb4_unicode_ci,
  `custom_fields_json` longtext COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `documente_soferi`
--

INSERT INTO `documente_soferi` (`id`, `driver_id`, `tip_document`, `numar_document`, `data_emitere`, `data_expirare`, `fisier_original`, `fisier_stocat`, `observatii`, `custom_fields_json`, `created_at`, `updated_at`) VALUES
(130, 31, 'ADR', '221561', NULL, '2029-05-10', NULL, NULL, NULL, NULL, '2026-06-08 14:54:53', '2026-06-08 14:54:53'),
(132, 31, 'AVIZ PSIHOLOGIC', NULL, NULL, '2027-03-03', NULL, NULL, NULL, NULL, '2026-06-08 14:56:53', '2026-06-08 14:56:53'),
(133, 31, 'MEDICINA MUNCII', NULL, NULL, '2027-03-02', NULL, NULL, NULL, NULL, '2026-06-08 14:57:31', '2026-06-08 14:57:31'),
(134, 31, 'BULETIN (C.I.)', '197061944014', NULL, '2031-08-03', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"RK\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"999353\"}}', '2026-06-08 14:58:40', '2026-06-12 13:40:31'),
(135, 31, 'CARTELA CONDUCATOR AUTO', '000000000ITU000', NULL, '2029-05-15', NULL, NULL, NULL, NULL, '2026-06-08 14:59:41', '2026-06-09 10:44:23'),
(136, 31, 'CERTIFICAT COMPETENTA PROFESIONALA', '0457544001', NULL, '2029-04-26', NULL, NULL, NULL, NULL, '2026-06-08 15:01:18', '2026-06-09 10:43:31'),
(137, 38, 'ADR', '186337', NULL, '2027-02-11', NULL, NULL, NULL, NULL, '2026-06-08 15:31:02', '2026-06-08 15:31:02'),
(139, 38, 'AVIZ PSIHOLOGIC', NULL, NULL, '2026-11-18', NULL, NULL, NULL, NULL, '2026-06-08 15:32:19', '2026-06-08 15:32:19'),
(140, 38, 'MEDICINA MUNCII', NULL, NULL, '2026-11-17', NULL, NULL, NULL, NULL, '2026-06-08 15:32:39', '2026-06-08 15:32:39'),
(141, 38, 'BULETIN (C.I.)', '1831102294747', NULL, '2031-11-02', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"PX\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"778282\"}}', '2026-06-08 15:33:48', '2026-06-12 13:43:46'),
(142, 38, 'CARTELA CONDUCATOR AUTO', '000000000KB3P000', NULL, '2030-10-16', NULL, NULL, NULL, NULL, '2026-06-08 15:34:43', '2026-06-09 10:42:17'),
(143, 38, 'PERMIS', 'P00823160H', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2034-05-08\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2029-05-08\"},\"dcf_98323d0c811f\":{\"key\":\"dcf_98323d0c811f\",\"label\":\"CATEGORIA CE\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_73d0790ad0e5\":{\"key\":\"dcf_73d0790ad0e5\",\"label\":\"DATA EXPIRARE CAT. CE\",\"type\":\"date\",\"value\":\"2029-05-08\"}}', '2026-06-08 15:35:55', '2026-06-12 13:44:38'),
(144, 38, 'CERTIFICAT COMPETENTA PROFESIONALA', '0319915002', NULL, '2030-01-24', NULL, NULL, NULL, NULL, '2026-06-08 15:37:33', '2026-06-09 10:42:40'),
(146, 40, 'AVIZ PSIHOLOGIC', NULL, NULL, '2027-03-05', NULL, NULL, NULL, NULL, '2026-06-08 15:49:31', '2026-06-08 15:49:31'),
(147, 40, 'MEDICINA MUNCII', NULL, NULL, '2027-03-02', NULL, NULL, NULL, NULL, '2026-06-08 15:49:50', '2026-06-08 15:49:50'),
(148, 40, 'PERMIS', 'P00759315H', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2032-05-23\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2027-05-23\"},\"dcf_98323d0c811f\":{\"key\":\"dcf_98323d0c811f\",\"label\":\"CATEGORIA CE\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_73d0790ad0e5\":{\"key\":\"dcf_73d0790ad0e5\",\"label\":\"DATA EXPIRARE CAT. CE\",\"type\":\"date\",\"value\":\"2027-05-23\"}}', '2026-06-08 15:51:25', '2026-06-12 14:40:16'),
(149, 40, 'CERTIFICAT COMPETENTA PROFESIONALA', '0203775002', NULL, '2028-07-12', NULL, NULL, NULL, NULL, '2026-06-08 15:52:12', '2026-06-09 10:40:32'),
(150, 40, 'ADR', '237143', NULL, '2030-07-18', NULL, NULL, NULL, NULL, '2026-06-08 15:53:20', '2026-06-08 15:53:20'),
(151, 40, 'CARTELA CONDUCATOR AUTO', '00000000053IK002', NULL, '2027-06-08', NULL, NULL, NULL, NULL, '2026-06-08 15:54:18', '2026-06-09 10:40:02'),
(152, 14, 'BULETIN (C.I.)', '5000228297285', NULL, '2031-08-03', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"PK\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"090413\"}}', '2026-06-08 16:14:57', '2026-06-12 13:50:12'),
(163, 44, 'BULETIN (C.I.)', '1730915054672', NULL, '2028-09-15', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"ZH\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"207902\"}}', '2026-06-08 16:16:56', '2026-06-11 11:40:44'),
(165, 14, 'AVIZ PSIHOLOGIC', NULL, NULL, '2027-03-04', NULL, NULL, NULL, NULL, '2026-06-09 10:00:41', '2026-06-09 10:00:41'),
(166, 14, 'AVIZ MEDICAL', NULL, NULL, '2027-03-05', NULL, NULL, NULL, NULL, '2026-06-09 10:01:19', '2026-06-09 10:01:19'),
(167, 14, 'MEDICINA MUNCII', NULL, NULL, '2027-03-02', NULL, NULL, NULL, NULL, '2026-06-09 10:01:44', '2026-06-09 10:01:44'),
(168, 14, 'PERMIS', 'P00789794H', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2029-02-18\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2028-02-20\"},\"dcf_98323d0c811f\":{\"key\":\"dcf_98323d0c811f\",\"label\":\"CATEGORIA CE\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_73d0790ad0e5\":{\"key\":\"dcf_73d0790ad0e5\",\"label\":\"DATA EXPIRARE CAT. CE\",\"type\":\"date\",\"value\":\"2028-02-20\"}}', '2026-06-09 10:02:29', '2026-06-12 13:50:55'),
(169, 14, 'CARTELA CONDUCATOR AUTO', '000000000H3HS000', NULL, '2028-03-26', NULL, NULL, NULL, NULL, '2026-06-09 10:03:16', '2026-06-09 10:38:10'),
(170, 14, 'CERTIFICAT COMPETENTA PROFESIONALA', '0554423000', NULL, '2028-03-21', NULL, NULL, NULL, NULL, '2026-06-09 10:03:59', '2026-06-09 10:38:29'),
(171, 14, 'ADR', '203922', NULL, '2028-04-07', NULL, NULL, NULL, NULL, '2026-06-09 10:05:03', '2026-06-09 10:05:03'),
(172, 22, 'BULETIN (C.I.)', '1720603293101', NULL, '2036-05-27', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"PH\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"1077346\"}}', '2026-06-09 10:30:27', '2026-06-12 13:54:08'),
(173, 22, 'AVIZ PSIHOLOGIC', NULL, NULL, '2027-03-04', NULL, NULL, NULL, NULL, '2026-06-09 10:31:36', '2026-06-09 10:31:36'),
(174, 22, 'AVIZ MEDICAL', NULL, NULL, '2027-03-05', NULL, NULL, NULL, NULL, '2026-06-09 10:32:04', '2026-06-09 10:32:04'),
(175, 22, 'MEDICINA MUNCII', NULL, NULL, '2027-03-02', NULL, NULL, NULL, NULL, '2026-06-09 10:32:52', '2026-06-09 10:32:52'),
(176, 22, 'CARTELA CONDUCATOR AUTO', '000000000HE5R000', NULL, '2028-05-21', NULL, NULL, NULL, NULL, '2026-06-09 10:33:39', '2026-06-09 10:35:33'),
(177, 22, 'ADR', '205490', NULL, '2028-05-13', NULL, NULL, NULL, NULL, '2026-06-09 10:34:07', '2026-06-09 10:34:07'),
(178, 22, 'CERTIFICAT COMPETENTA PROFESIONALA', '0209129002', NULL, '2028-08-31', NULL, NULL, NULL, NULL, '2026-06-09 10:34:48', '2026-06-09 10:34:48'),
(179, 22, 'PERMIS', 'P00795550H', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2033-05-04\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2028-05-03\"},\"dcf_98323d0c811f\":{\"key\":\"dcf_98323d0c811f\",\"label\":\"CATEGORIA CE\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_73d0790ad0e5\":{\"key\":\"dcf_73d0790ad0e5\",\"label\":\"DATA EXPIRARE CAT. CE\",\"type\":\"date\",\"value\":\"2028-05-03\"}}', '2026-06-09 10:36:22', '2026-06-12 13:55:10'),
(180, 31, 'PERMIS', 'B02646900', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2033-12-04\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2028-12-04\"},\"dcf_98323d0c811f\":{\"key\":\"dcf_98323d0c811f\",\"label\":\"CATEGORIA CE\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_73d0790ad0e5\":{\"key\":\"dcf_73d0790ad0e5\",\"label\":\"DATA EXPIRARE CAT. CE\",\"type\":\"date\",\"value\":\"2028-12-04\"}}', '2026-06-09 10:45:12', '2026-06-12 13:41:18'),
(181, 31, 'AVIZ MEDICAL', NULL, NULL, '2027-03-03', NULL, NULL, NULL, NULL, '2026-06-09 10:45:39', '2026-06-09 10:45:39'),
(182, 38, 'AVIZ MEDICAL', NULL, NULL, '2026-11-18', NULL, NULL, NULL, NULL, '2026-06-09 10:46:47', '2026-06-09 10:46:47'),
(183, 42, 'BULETIN (C.I.)', '1740913057056', NULL, '2035-08-21', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"BH\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"1021861\"}}', '2026-06-09 12:10:26', '2026-06-12 13:56:56'),
(184, 42, 'AVIZ PSIHOLOGIC', NULL, NULL, '2026-06-27', NULL, NULL, NULL, NULL, '2026-06-09 12:10:58', '2026-06-09 12:10:58'),
(185, 42, 'AVIZ MEDICAL', NULL, NULL, '2026-06-27', NULL, NULL, NULL, NULL, '2026-06-09 12:11:36', '2026-06-09 12:11:36'),
(186, 42, 'MEDICINA MUNCII', NULL, NULL, '2027-03-02', NULL, NULL, NULL, NULL, '2026-06-09 12:12:26', '2026-06-09 12:12:26'),
(187, 42, 'ADR', '201963', NULL, '2028-03-28', NULL, NULL, NULL, NULL, '2026-06-09 12:13:06', '2026-06-09 12:13:27'),
(188, 42, 'PERMIS', 'B00591865H', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2032-02-23\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2027-02-23\"},\"dcf_98323d0c811f\":{\"key\":\"dcf_98323d0c811f\",\"label\":\"CATEGORIA CE\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_73d0790ad0e5\":{\"key\":\"dcf_73d0790ad0e5\",\"label\":\"DATA EXPIRARE CAT. CE\",\"type\":\"date\",\"value\":\"2027-02-23\"}}', '2026-06-09 12:14:08', '2026-06-12 13:58:09'),
(189, 42, 'CERTIFICAT COMPETENTA PROFESIONALA', '0173594002', NULL, '2027-11-16', NULL, NULL, NULL, NULL, '2026-06-09 12:14:40', '2026-06-09 12:14:40'),
(190, 42, 'CARTELA CONDUCATOR AUTO', '0000000004L4K002', NULL, '2027-12-22', NULL, NULL, NULL, NULL, '2026-06-09 12:15:14', '2026-06-09 12:15:14'),
(191, 43, 'AVIZ PSIHOLOGIC', NULL, NULL, '2027-05-03', NULL, NULL, NULL, NULL, '2026-06-09 12:18:02', '2026-06-09 12:18:02'),
(192, 43, 'AVIZ MEDICAL', NULL, NULL, '2027-05-04', NULL, NULL, NULL, NULL, '2026-06-09 12:18:15', '2026-06-09 12:18:15'),
(193, 43, 'MEDICINA MUNCII', NULL, NULL, '2027-03-02', NULL, NULL, NULL, NULL, '2026-06-09 12:18:42', '2026-06-09 12:18:42'),
(194, 43, 'BULETIN (C.I.)', '1780308057055', NULL, '2030-03-08', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"ZH\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"296282\"}}', '2026-06-09 12:19:14', '2026-06-12 14:00:28'),
(195, 43, 'PERMIS', 'B00670298H', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2034-08-30\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2029-08-30\"},\"dcf_98323d0c811f\":{\"key\":\"dcf_98323d0c811f\",\"label\":\"CATEGORIA CE\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_73d0790ad0e5\":{\"key\":\"dcf_73d0790ad0e5\",\"label\":\"DATA EXPIRARE CAT. CE\",\"type\":\"date\",\"value\":\"2029-08-30\"}}', '2026-06-09 12:19:50', '2026-06-12 14:17:16'),
(196, 43, 'ADR', '196185', NULL, '2027-11-16', NULL, NULL, NULL, NULL, '2026-06-09 12:20:32', '2026-06-09 12:20:32'),
(197, 43, 'CERTIFICAT COMPETENTA PROFESIONALA', '0173592002', NULL, '2027-11-16', NULL, NULL, NULL, NULL, '2026-06-09 12:22:02', '2026-06-09 12:22:02'),
(198, 43, 'CARTELA CONDUCATOR AUTO', '0000000004L5G002', NULL, '2027-12-14', NULL, NULL, NULL, NULL, '2026-06-09 12:23:39', '2026-06-09 12:23:39'),
(199, 41, 'BULETIN (C.I.)', '1771201155224', NULL, '2027-12-01', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"DD\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"880555\"}}', '2026-06-09 12:27:15', '2026-06-12 14:07:00'),
(200, 41, 'CERTIFICAT COMPETENTA PROFESIONALA', '0150749002', NULL, '2027-09-02', NULL, NULL, NULL, NULL, '2026-06-09 12:28:23', '2026-06-09 12:28:23'),
(201, 41, 'ADR', '220234', NULL, '2029-05-04', NULL, NULL, NULL, NULL, '2026-06-09 12:28:44', '2026-06-09 12:28:44'),
(202, 41, 'PERMIS', 'D00442229B', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2033-01-17\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2028-01-17\"},\"dcf_98323d0c811f\":{\"key\":\"dcf_98323d0c811f\",\"label\":\"CATEGORIA CE\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_73d0790ad0e5\":{\"key\":\"dcf_73d0790ad0e5\",\"label\":\"DATA EXPIRARE CAT. CE\",\"type\":\"date\",\"value\":\"2028-01-17\"}}', '2026-06-09 12:29:11', '2026-06-12 14:07:49'),
(203, 41, 'CARTELA CONDUCATOR AUTO', '0000000002A6G003', NULL, '2030-01-13', NULL, NULL, NULL, NULL, '2026-06-09 12:29:40', '2026-06-09 12:29:40'),
(204, 41, 'MEDICINA MUNCII', NULL, NULL, '2027-03-02', NULL, NULL, NULL, NULL, '2026-06-09 12:32:15', '2026-06-09 12:32:15'),
(205, 41, 'AVIZ MEDICAL', NULL, NULL, '2027-02-16', NULL, NULL, NULL, NULL, '2026-06-09 12:32:52', '2026-06-09 12:32:52'),
(206, 41, 'AVIZ PSIHOLOGIC', NULL, NULL, '2027-02-16', NULL, NULL, NULL, NULL, '2026-06-09 12:33:05', '2026-06-09 12:33:05'),
(207, 36, 'BULETIN (C.I.)', '1860213204966', NULL, '2027-02-13', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"HD\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"863503\"}}', '2026-06-09 13:24:13', '2026-06-12 14:25:56'),
(208, 36, 'AVIZ MEDICAL', NULL, NULL, '2027-02-23', NULL, NULL, NULL, NULL, '2026-06-09 13:37:43', '2026-06-09 13:37:53'),
(209, 36, 'AVIZ PSIHOLOGIC', NULL, NULL, '2027-02-23', NULL, NULL, NULL, NULL, '2026-06-09 13:38:12', '2026-06-09 13:38:12'),
(210, 36, 'MEDICINA MUNCII', NULL, NULL, '2027-02-23', NULL, NULL, NULL, NULL, '2026-06-09 13:38:49', '2026-06-09 13:38:49'),
(211, 36, 'CERTIFICAT COMPETENTA PROFESIONALA', '0549186000', NULL, '2027-11-10', NULL, NULL, NULL, NULL, '2026-06-09 13:40:02', '2026-06-09 13:40:02'),
(212, 36, 'ADR', '199768', NULL, '2027-08-05', NULL, NULL, NULL, NULL, '2026-06-09 13:40:45', '2026-06-09 13:40:45'),
(213, 36, 'PERMIS', 'H00480513D', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2033-01-18\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2028-01-18\"},\"dcf_98323d0c811f\":{\"key\":\"dcf_98323d0c811f\",\"label\":\"CATEGORIA CE\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_73d0790ad0e5\":{\"key\":\"dcf_73d0790ad0e5\",\"label\":\"DATA EXPIRARE CAT. CE\",\"type\":\"date\",\"value\":\"2028-01-18\"}}', '2026-06-09 13:41:26', '2026-06-12 14:25:20'),
(214, 36, 'CARTELA CONDUCATOR AUTO', '000000000GTUS000', NULL, '2028-01-25', NULL, NULL, NULL, NULL, '2026-06-09 13:42:27', '2026-06-09 13:42:27'),
(215, 29, 'BULETIN (C.I.)', '1841121057644', NULL, '2029-11-21', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"ZH\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"282148\"}}', '2026-06-09 13:45:27', '2026-06-12 14:29:16'),
(216, 29, 'AVIZ MEDICAL', NULL, NULL, '2026-06-25', NULL, NULL, NULL, NULL, '2026-06-09 14:09:23', '2026-06-09 14:09:23'),
(217, 29, 'AVIZ PSIHOLOGIC', NULL, NULL, '2026-06-25', NULL, NULL, NULL, NULL, '2026-06-09 14:09:35', '2026-06-09 14:09:35'),
(218, 29, 'MEDICINA MUNCII', NULL, NULL, '2026-06-25', NULL, NULL, NULL, NULL, '2026-06-09 14:10:32', '2026-06-09 14:10:32'),
(219, 29, 'PERMIS', 'B00596730H', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2032-04-19\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2027-04-19\"},\"dcf_98323d0c811f\":{\"key\":\"dcf_98323d0c811f\",\"label\":\"CATEGORIA CE\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_73d0790ad0e5\":{\"key\":\"dcf_73d0790ad0e5\",\"label\":\"DATA EXPIRARE CAT. CE\",\"type\":\"date\",\"value\":\"2027-04-19\"}}', '2026-06-09 14:12:46', '2026-06-12 14:35:49'),
(220, 29, 'CARTELA CONDUCATOR AUTO', '000000000FZ2N000', NULL, '2027-06-06', NULL, NULL, NULL, NULL, '2026-06-09 14:13:23', '2026-06-09 14:13:23'),
(221, 29, 'ADR', '176372', NULL, '2026-06-17', NULL, NULL, NULL, NULL, '2026-06-09 14:13:51', '2026-06-09 14:13:51'),
(222, 29, 'CERTIFICAT COMPETENTA PROFESIONALA', '0017149003', NULL, '2030-01-10', NULL, NULL, NULL, NULL, '2026-06-09 14:14:22', '2026-06-09 14:14:22'),
(223, 39, 'BULETIN (C.I.)', '1940712297251', NULL, '2026-07-12', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"PX\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"662222\"}}', '2026-06-09 14:17:55', '2026-06-12 14:32:41'),
(224, 39, 'AVIZ MEDICAL', NULL, NULL, '2027-03-04', NULL, NULL, NULL, NULL, '2026-06-09 14:18:32', '2026-06-09 14:19:30'),
(225, 39, 'AVIZ PSIHOLOGIC', NULL, NULL, '2027-03-03', NULL, NULL, NULL, NULL, '2026-06-09 14:19:20', '2026-06-09 14:19:20'),
(226, 39, 'MEDICINA MUNCII', NULL, NULL, '2027-03-02', NULL, NULL, NULL, NULL, '2026-06-09 14:19:55', '2026-06-09 14:19:55'),
(227, 39, 'PERMIS', 'P00838669H', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2029-10-04\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2029-11-14\"},\"dcf_98323d0c811f\":{\"key\":\"dcf_98323d0c811f\",\"label\":\"CATEGORIA CE\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_73d0790ad0e5\":{\"key\":\"dcf_73d0790ad0e5\",\"label\":\"DATA EXPIRARE CAT. CE\",\"type\":\"date\",\"value\":\"2029-11-14\"}}', '2026-06-09 14:20:29', '2026-06-12 14:33:41'),
(228, 39, 'CARTELA CONDUCATOR AUTO', '000000000JIDS000', NULL, '2030-01-26', NULL, NULL, NULL, NULL, '2026-06-09 14:20:58', '2026-06-09 14:20:58'),
(229, 39, 'ADR', '231272', NULL, '2030-01-24', NULL, NULL, NULL, NULL, '2026-06-09 14:21:24', '2026-06-09 14:21:24'),
(230, 39, 'CERTIFICAT COMPETENTA PROFESIONALA', '0597336000', NULL, '2030-01-20', NULL, NULL, NULL, NULL, '2026-06-09 14:21:46', '2026-06-09 14:21:46'),
(231, 32, 'BULETIN (C.I.)', '1670801213060', NULL, '2031-08-03', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"RZ\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"219073\"}}', '2026-06-09 14:24:25', '2026-06-12 14:37:51'),
(232, 32, 'MEDICINA MUNCII', NULL, NULL, '2027-03-02', NULL, NULL, NULL, NULL, '2026-06-09 14:24:57', '2026-06-09 14:24:57'),
(233, 32, 'ADR', '236489', NULL, '2030-07-18', NULL, NULL, NULL, NULL, '2026-06-09 14:25:38', '2026-06-09 14:25:38'),
(234, 32, 'CERTIFICAT COMPETENTA PROFESIONALA', '0019042003', NULL, '2030-04-05', NULL, NULL, NULL, NULL, '2026-06-09 14:26:05', '2026-06-09 14:26:05'),
(235, 32, 'CARTELA CONDUCATOR AUTO', '000000000G0ST000', NULL, '2027-06-19', NULL, NULL, NULL, NULL, '2026-06-09 14:26:54', '2026-06-09 14:26:54'),
(236, 32, 'PERMIS', 'B02484156', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2032-06-16\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2027-06-16\"},\"dcf_98323d0c811f\":{\"key\":\"dcf_98323d0c811f\",\"label\":\"CATEGORIA CE\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_73d0790ad0e5\":{\"key\":\"dcf_73d0790ad0e5\",\"label\":\"DATA EXPIRARE CAT. CE\",\"type\":\"date\",\"value\":\"2027-06-16\"}}', '2026-06-09 14:27:32', '2026-06-12 14:38:30'),
(238, 28, 'BULETIN (C.I.)', '1730104054667', NULL, '2036-02-25', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"BH\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"1051506\"}}', '2026-06-11 10:38:02', '2026-06-12 14:42:20'),
(239, 28, 'CARTELA CONDUCATOR AUTO', '000000000HRCN000', NULL, '2028-08-03', NULL, NULL, NULL, NULL, '2026-06-11 10:38:35', '2026-06-11 10:38:35'),
(240, 28, 'PERMIS', 'B00578563H', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2031-08-27\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2026-08-27\"},\"dcf_98323d0c811f\":{\"key\":\"dcf_98323d0c811f\",\"label\":\"CATEGORIA CE\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_73d0790ad0e5\":{\"key\":\"dcf_73d0790ad0e5\",\"label\":\"DATA EXPIRARE CAT. CE\",\"type\":\"date\",\"value\":\"2026-08-27\"}}', '2026-06-11 10:43:05', '2026-06-12 14:42:52'),
(241, 28, 'CERTIFICAT COMPETENTA PROFESIONALA', '0196569002', NULL, '2028-06-07', NULL, NULL, NULL, NULL, '2026-06-11 10:47:30', '2026-06-11 10:47:30'),
(242, 28, 'ADR', '179691', NULL, '2026-09-16', NULL, NULL, NULL, NULL, '2026-06-11 10:48:52', '2026-06-11 10:48:52'),
(243, 28, 'AVIZ MEDICAL', NULL, NULL, '2027-03-10', NULL, NULL, NULL, NULL, '2026-06-11 10:49:20', '2026-06-11 10:49:20'),
(244, 28, 'AVIZ PSIHOLOGIC', NULL, NULL, '2027-03-11', NULL, NULL, NULL, NULL, '2026-06-11 10:49:35', '2026-06-11 10:49:35'),
(245, 28, 'MEDICINA MUNCII', NULL, NULL, '2027-03-10', NULL, NULL, NULL, NULL, '2026-06-11 10:49:57', '2026-06-11 10:49:57'),
(246, 25, 'BULETIN (C.I.)', '1960926038689', NULL, '2031-08-03', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"AZ\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"428074\"}}', '2026-06-11 10:54:28', '2026-06-12 14:43:43'),
(247, 25, 'AVIZ MEDICAL', NULL, NULL, '2027-02-01', NULL, NULL, NULL, NULL, '2026-06-11 10:56:16', '2026-06-11 10:56:16'),
(248, 25, 'AVIZ PSIHOLOGIC', NULL, NULL, '2027-03-01', NULL, NULL, NULL, NULL, '2026-06-11 10:56:29', '2026-06-11 10:56:29'),
(249, 25, 'MEDICINA MUNCII', NULL, NULL, '2027-03-01', NULL, NULL, NULL, NULL, '2026-06-11 10:56:57', '2026-06-11 10:56:57'),
(250, 25, 'ADR', '209239', NULL, '2028-08-17', NULL, NULL, NULL, NULL, '2026-06-11 11:20:17', '2026-06-11 11:20:17'),
(251, 25, 'CERTIFICAT COMPETENTA PROFESIONALA', 'A00693859G', NULL, '2028-06-26', NULL, NULL, NULL, NULL, '2026-06-11 11:22:55', '2026-06-11 11:22:55'),
(252, 25, 'CARTELA CONDUCATOR AUTO', '000000000A0I0001', NULL, '2028-07-10', NULL, NULL, NULL, NULL, '2026-06-11 11:24:04', '2026-06-11 11:24:04'),
(253, 25, 'PERMIS', 'A00693859G', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2033-07-05\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2028-07-05\"},\"dcf_98323d0c811f\":{\"key\":\"dcf_98323d0c811f\",\"label\":\"CATEGORIA CE\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_73d0790ad0e5\":{\"key\":\"dcf_73d0790ad0e5\",\"label\":\"DATA EXPIRARE CAT. CE\",\"type\":\"date\",\"value\":\"2028-07-05\"}}', '2026-06-11 11:25:53', '2026-06-12 14:44:47'),
(254, 26, 'BULETIN (C.I.)', '1761010290576', NULL, '2030-10-10', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"PX\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"759924\"}}', '2026-06-11 11:28:57', '2026-06-11 11:28:57'),
(255, 26, 'MEDICINA MUNCII', NULL, NULL, '2027-03-02', NULL, NULL, NULL, NULL, '2026-06-11 11:29:24', '2026-06-11 11:29:24'),
(256, 26, 'AVIZ MEDICAL', NULL, NULL, '2027-03-03', NULL, NULL, NULL, NULL, '2026-06-11 11:29:48', '2026-06-11 11:29:48'),
(257, 26, 'AVIZ PSIHOLOGIC', NULL, NULL, '2027-03-03', NULL, NULL, NULL, NULL, '2026-06-11 11:30:08', '2026-06-11 11:30:08'),
(258, 26, 'PERMIS', 'P00800101H', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2033-07-03\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2028-07-03\"},\"dcf_98323d0c811f\":{\"key\":\"dcf_98323d0c811f\",\"label\":\"CATEGORIA CE\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_73d0790ad0e5\":{\"key\":\"dcf_73d0790ad0e5\",\"label\":\"DATA EXPIRARE CAT. CE\",\"type\":\"date\",\"value\":\"2028-07-03\"}}', '2026-06-11 11:32:06', '2026-06-12 14:46:20'),
(259, 26, 'ADR', '229047', NULL, '2029-11-20', NULL, NULL, NULL, NULL, '2026-06-11 11:32:30', '2026-06-11 11:32:30'),
(260, 26, 'CERTIFICAT COMPETENTA PROFESIONALA', '0000795003', NULL, '2029-10-10', NULL, NULL, NULL, NULL, '2026-06-11 11:33:18', '2026-06-11 11:33:18'),
(261, 44, 'CERTIFICAT COMPETENTA PROFESIONALA', '0020337003', NULL, '2031-01-15', NULL, NULL, NULL, NULL, '2026-06-11 11:42:41', '2026-06-11 11:42:41'),
(262, 44, 'CARTELA CONDUCATOR AUTO', '000000000AQ4L001', NULL, '2028-07-31', NULL, NULL, NULL, NULL, '2026-06-11 11:43:20', '2026-06-11 11:43:20'),
(263, 44, 'ADR', '185476', NULL, '2027-01-29', NULL, NULL, NULL, NULL, '2026-06-11 11:44:03', '2026-06-11 11:44:03'),
(264, 44, 'PERMIS', 'B00574176H', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2031-07-08\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2026-07-08\"}}', '2026-06-11 11:45:07', '2026-06-11 11:45:07'),
(265, 27, 'BULETIN (C.I.)', '1670102352625', NULL, '2028-01-02', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"TZ\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"484686\"}}', '2026-06-11 12:01:28', '2026-06-11 12:01:28'),
(266, 27, 'ADR', '250976', NULL, '2031-06-18', NULL, NULL, NULL, NULL, '2026-06-11 12:02:37', '2026-06-11 12:57:02'),
(267, 27, 'CERTIFICAT COMPETENTA PROFESIONALA', '0276409002', NULL, '2029-05-09', NULL, NULL, NULL, NULL, '2026-06-11 12:03:44', '2026-06-11 12:03:44'),
(268, 27, 'CARTELA CONDUCATOR AUTO', '000000000JFDO000', NULL, '2029-12-19', NULL, NULL, NULL, NULL, '2026-06-11 12:04:18', '2026-06-11 12:04:18'),
(269, 27, 'MEDICINA MUNCII', NULL, NULL, '2026-10-18', NULL, NULL, NULL, NULL, '2026-06-11 12:08:35', '2026-06-11 12:09:28'),
(270, 27, 'AVIZ PSIHOLOGIC', NULL, NULL, '2027-04-18', NULL, NULL, NULL, NULL, '2026-06-11 12:08:53', '2026-06-11 12:08:53'),
(271, 27, 'AVIZ MEDICAL', NULL, NULL, '2026-10-18', NULL, NULL, NULL, NULL, '2026-06-11 12:09:11', '2026-06-11 12:09:11'),
(272, 27, 'CI / Buletin', '1670102352625', NULL, '2028-01-02', NULL, NULL, NULL, NULL, '2026-06-11 12:53:02', '2026-06-11 12:53:02'),
(273, 27, 'PERMIS', 'T00650759M', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2034-04-30\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2029-04-30\"}}', '2026-06-11 12:56:01', '2026-06-11 12:56:01'),
(274, 21, 'AVIZ PSIHOLOGIC', NULL, NULL, '2027-03-04', NULL, NULL, NULL, NULL, '2026-06-11 13:04:05', '2026-06-11 13:04:05'),
(275, 21, 'AVIZ MEDICAL', NULL, NULL, '2027-03-05', NULL, NULL, NULL, NULL, '2026-06-11 13:04:25', '2026-06-11 13:04:25'),
(276, 21, 'MEDICINA MUNCII', NULL, NULL, '2027-03-02', NULL, NULL, NULL, NULL, '2026-06-11 13:04:40', '2026-06-11 13:04:40'),
(277, 21, 'CARTELA CONDUCATOR AUTO', '000000000CW39001', NULL, '2030-01-13', NULL, NULL, NULL, NULL, '2026-06-11 13:05:19', '2026-06-11 13:05:19'),
(278, 21, 'ADR', '194860', NULL, '2027-08-11', NULL, NULL, NULL, NULL, '2026-06-11 13:06:08', '2026-06-11 13:06:08'),
(279, 21, 'CERTIFICAT COMPETENTA PROFESIONALA', '0196596002', NULL, '2028-06-07', NULL, NULL, NULL, NULL, '2026-06-11 13:07:05', '2026-06-11 13:07:05'),
(280, 21, 'BULETIN (C.I.)', '1770331054691', NULL, '2031-08-03', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"ZH\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"415227\"}}', '2026-06-11 13:08:57', '2026-06-11 13:08:57'),
(281, 21, 'PERMIS', 'B00608421H', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2032-09-05\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2027-09-05\"}}', '2026-06-11 13:10:16', '2026-06-11 13:10:16'),
(282, 15, 'BULETIN (C.I.)', '1670716131302', NULL, '2031-08-03', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"KZ\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"971354\"}}', '2026-06-12 10:39:43', '2026-06-12 10:39:43'),
(283, 15, 'AVIZ MEDICAL', NULL, NULL, '2026-07-22', NULL, NULL, NULL, NULL, '2026-06-12 10:42:59', '2026-06-12 10:42:59'),
(284, 15, 'AVIZ PSIHOLOGIC', NULL, NULL, '2026-07-22', NULL, NULL, NULL, NULL, '2026-06-12 10:43:18', '2026-06-12 10:43:18'),
(285, 15, 'MEDICINA MUNCII', NULL, NULL, '2026-07-22', NULL, NULL, NULL, NULL, '2026-06-12 10:43:35', '2026-06-12 10:43:35'),
(286, 15, 'ADR', '241257', NULL, '2030-11-06', NULL, NULL, NULL, NULL, '2026-06-12 10:44:26', '2026-06-12 10:44:26'),
(287, 15, 'CARTELA CONDUCATOR AUTO', '000000000KAQU000', NULL, '2030-10-13', NULL, NULL, NULL, NULL, '2026-06-12 10:45:06', '2026-06-12 10:45:06'),
(288, 15, 'CERTIFICAT COMPETENTA PROFESIONALA', '0293940002', NULL, '2029-08-01', NULL, NULL, NULL, NULL, '2026-06-12 10:45:31', '2026-06-12 10:45:31'),
(289, 15, 'PERMIS', 'C02277779T', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2032-07-19\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2027-07-19\"}}', '2026-06-12 10:47:29', '2026-06-12 10:47:29'),
(290, 23, 'BULETIN (C.I.)', '1680907270016', NULL, '2026-09-07', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"TZ\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"326450\"}}', '2026-06-12 11:19:42', '2026-06-12 11:19:42'),
(291, 23, 'ADR', '183906', NULL, '2027-02-16', NULL, NULL, NULL, NULL, '2026-06-12 11:20:35', '2026-06-12 11:20:35'),
(292, 23, 'AVIZ PSIHOLOGIC', NULL, NULL, '2027-03-04', NULL, NULL, NULL, NULL, '2026-06-12 11:20:59', '2026-06-12 11:20:59'),
(293, 23, 'AVIZ MEDICAL', NULL, NULL, '2027-03-04', NULL, NULL, NULL, NULL, '2026-06-12 11:21:15', '2026-06-12 11:21:15'),
(294, 23, 'MEDICINA MUNCII', NULL, NULL, '2027-03-04', NULL, NULL, NULL, NULL, '2026-06-12 11:21:25', '2026-06-12 11:21:25'),
(295, 23, 'CERTIFICAT COMPETENTA PROFESIONALA', '0004454003', NULL, '2029-02-01', NULL, NULL, NULL, NULL, '2026-06-12 11:22:06', '2026-06-12 11:22:06'),
(296, 23, 'CARTELA CONDUCATOR AUTO', '0000000005S45002', NULL, '2029-01-29', NULL, NULL, NULL, NULL, '2026-06-12 11:22:34', '2026-06-12 11:22:34'),
(297, 23, 'PERMIS', 'T00821705M', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2034-01-12\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2029-01-12\"},\"dcf_98323d0c811f\":{\"key\":\"dcf_98323d0c811f\",\"label\":\"CATEGORIA CE\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_73d0790ad0e5\":{\"key\":\"dcf_73d0790ad0e5\",\"label\":\"DATA EXPIRARE CAT. CE\",\"type\":\"date\",\"value\":\"2029-01-12\"}}', '2026-06-12 11:31:44', '2026-06-12 11:31:44'),
(298, 24, 'BULETIN (C.I.)', '1860721297375', NULL, '2031-07-21', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"PX\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"812662\"}}', '2026-06-12 11:34:52', '2026-06-12 11:34:52'),
(299, 24, 'MEDICINA MUNCII', NULL, NULL, '2027-03-02', NULL, NULL, NULL, NULL, '2026-06-12 11:35:39', '2026-06-12 11:35:39'),
(300, 24, 'AVIZ PSIHOLOGIC', NULL, NULL, '2026-07-21', NULL, NULL, NULL, NULL, '2026-06-12 11:36:05', '2026-06-12 11:36:05'),
(301, 24, 'AVIZ MEDICAL', NULL, NULL, '2026-07-18', NULL, NULL, NULL, NULL, '2026-06-12 11:36:21', '2026-06-12 11:36:21'),
(302, 24, 'CARTELA CONDUCATOR AUTO', '00000000062ZD002', NULL, '2029-04-01', NULL, NULL, NULL, NULL, '2026-06-12 11:37:04', '2026-06-12 11:37:04'),
(303, 24, 'ADR', '223139', NULL, '2029-07-04', NULL, NULL, NULL, NULL, '2026-06-12 11:38:16', '2026-06-12 11:38:16'),
(304, 24, 'CERTIFICAT COMPETENTA PROFESIONALA', '0292744002', NULL, '2029-07-30', NULL, NULL, NULL, NULL, '2026-06-12 11:39:23', '2026-06-12 11:39:23'),
(305, 24, 'PERMIS', 'P00818376H', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2034-03-01\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2029-03-01\"},\"dcf_98323d0c811f\":{\"key\":\"dcf_98323d0c811f\",\"label\":\"CATEGORIA CE\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_73d0790ad0e5\":{\"key\":\"dcf_73d0790ad0e5\",\"label\":\"DATA EXPIRARE CAT. CE\",\"type\":\"date\",\"value\":\"2029-03-01\"},\"dcf_fd6539744e62\":{\"key\":\"dcf_fd6539744e62\",\"label\":\"CATEGORIA D\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_8add67c2d8a8\":{\"key\":\"dcf_8add67c2d8a8\",\"label\":\"DATA EXPIRARE CAT. D\",\"type\":\"date\",\"value\":\"2029-03-01\"}}', '2026-06-12 11:40:39', '2026-06-12 11:40:39'),
(306, 18, 'BULETIN (C.I.)', '1880513324807', NULL, '2027-05-13', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"SB\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"789470\"}}', '2026-06-12 11:44:23', '2026-06-12 11:44:23'),
(307, 18, 'PERMIS', 'S00437207B', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2032-02-18\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2027-02-18\"},\"dcf_98323d0c811f\":{\"key\":\"dcf_98323d0c811f\",\"label\":\"CATEGORIA CE\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_73d0790ad0e5\":{\"key\":\"dcf_73d0790ad0e5\",\"label\":\"DATA EXPIRARE CAT. CE\",\"type\":\"date\",\"value\":\"2027-02-18\"}}', '2026-06-12 11:45:58', '2026-06-12 11:45:58'),
(308, 18, 'CERTIFICAT COMPETENTA PROFESIONALA', '0129131002', NULL, '2027-05-17', NULL, NULL, NULL, NULL, '2026-06-12 11:46:41', '2026-06-12 11:46:41'),
(309, 18, 'ADR', '188158', NULL, '2027-04-21', NULL, NULL, NULL, NULL, '2026-06-12 11:47:12', '2026-06-12 11:47:12'),
(310, 18, 'CARTELA CONDUCATOR AUTO', '0000000003W5X002', NULL, '2027-03-29', NULL, NULL, NULL, NULL, '2026-06-12 11:47:40', '2026-06-12 11:47:40'),
(311, 18, 'MEDICINA MUNCII', NULL, NULL, '2027-07-21', NULL, NULL, NULL, NULL, '2026-06-12 11:48:44', '2026-06-12 11:48:44'),
(312, 18, 'AVIZ MEDICAL', NULL, NULL, '2027-01-21', NULL, NULL, NULL, NULL, '2026-06-12 11:49:01', '2026-06-12 11:49:01'),
(313, 18, 'AVIZ PSIHOLOGIC', NULL, NULL, '2027-01-21', NULL, NULL, NULL, NULL, '2026-06-12 11:49:10', '2026-06-12 11:49:10'),
(314, 19, 'BULETIN (C.I.)', '1770813293090', NULL, '2028-08-13', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"PX\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"590984\"}}', '2026-06-12 11:52:05', '2026-06-12 11:52:05'),
(315, 19, 'MEDICINA MUNCII', NULL, NULL, '2027-03-02', NULL, NULL, NULL, NULL, '2026-06-12 11:53:17', '2026-06-12 11:53:17'),
(316, 19, 'CERTIFICAT COMPETENTA PROFESIONALA', '0198325002', NULL, '2028-06-14', NULL, NULL, NULL, NULL, '2026-06-12 11:53:44', '2026-06-12 11:53:44'),
(317, 19, 'ADR', '184606', NULL, '2027-01-07', NULL, NULL, NULL, NULL, '2026-06-12 11:54:09', '2026-06-12 11:54:09'),
(318, 19, 'CARTELA CONDUCATOR AUTO', '0000000005ZWA002', NULL, '2029-06-03', NULL, NULL, NULL, NULL, '2026-06-12 11:54:41', '2026-06-12 11:54:41'),
(319, 19, 'PERMIS', 'P00793992H', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2033-04-11\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2028-04-11\"},\"dcf_98323d0c811f\":{\"key\":\"dcf_98323d0c811f\",\"label\":\"CATEGORIA CE\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_73d0790ad0e5\":{\"key\":\"dcf_73d0790ad0e5\",\"label\":\"DATA EXPIRARE CAT. CE\",\"type\":\"date\",\"value\":\"2028-04-11\"},\"dcf_fd6539744e62\":{\"key\":\"dcf_fd6539744e62\",\"label\":\"CATEGORIA D\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_8add67c2d8a8\":{\"key\":\"dcf_8add67c2d8a8\",\"label\":\"DATA EXPIRARE CAT. D\",\"type\":\"date\",\"value\":\"2028-04-11\"}}', '2026-06-12 11:58:19', '2026-06-12 12:17:04'),
(320, 19, 'AVIZ MEDICAL', NULL, NULL, '2027-03-03', NULL, NULL, NULL, NULL, '2026-06-12 11:58:45', '2026-06-12 11:58:45'),
(321, 19, 'AVIZ PSIHOLOGIC', NULL, NULL, '2027-03-03', NULL, NULL, NULL, NULL, '2026-06-12 11:58:55', '2026-06-12 11:58:55'),
(322, 17, 'BULETIN (C.I.)', '1710825062989', NULL, '2035-08-25', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"TM\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"1022188\"}}', '2026-06-12 12:19:24', '2026-06-12 12:19:24'),
(323, 17, 'AVIZ PSIHOLOGIC', NULL, NULL, '2027-03-04', NULL, NULL, NULL, NULL, '2026-06-12 12:19:48', '2026-06-12 12:19:48'),
(324, 17, 'AVIZ MEDICAL', NULL, NULL, '2026-09-04', NULL, NULL, NULL, NULL, '2026-06-12 12:20:18', '2026-06-12 12:20:18'),
(325, 17, 'MEDICINA MUNCII', NULL, NULL, '2026-09-04', NULL, NULL, NULL, NULL, '2026-06-12 12:20:35', '2026-06-12 12:20:35'),
(326, 17, 'PERMIS', 'T00768087N', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2032-07-11\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2027-07-11\"},\"dcf_98323d0c811f\":{\"key\":\"dcf_98323d0c811f\",\"label\":\"CATEGORIA CE\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_73d0790ad0e5\":{\"key\":\"dcf_73d0790ad0e5\",\"label\":\"DATA EXPIRARE CAT. CE\",\"type\":\"date\",\"value\":\"2027-07-11\"},\"dcf_fd6539744e62\":{\"key\":\"dcf_fd6539744e62\",\"label\":\"CATEGORIA D\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_8add67c2d8a8\":{\"key\":\"dcf_8add67c2d8a8\",\"label\":\"DATA EXPIRARE CAT. D\",\"type\":\"date\",\"value\":\"2027-07-11\"}}', '2026-06-12 12:22:22', '2026-06-12 12:22:22'),
(327, 17, 'CARTELA CONDUCATOR AUTO', '0000000004H5M002', NULL, '2028-01-10', NULL, NULL, NULL, NULL, '2026-06-12 12:23:21', '2026-06-12 12:23:21'),
(328, 17, 'ADR', '193755', NULL, '2027-10-05', NULL, NULL, NULL, NULL, '2026-06-12 12:23:55', '2026-06-12 12:23:55'),
(329, 17, 'CERTIFICAT COMPETENTA PROFESIONALA', '0152585002', NULL, '2027-09-01', NULL, NULL, NULL, NULL, '2026-06-12 12:24:19', '2026-06-12 12:24:19'),
(330, 16, 'BULETIN (C.I.)', '1741209433032', NULL, '2031-08-03', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"RZ\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"275196\"}}', '2026-06-12 12:27:18', '2026-06-12 12:27:18'),
(331, 16, 'AVIZ MEDICAL', NULL, NULL, '2027-02-13', NULL, NULL, NULL, NULL, '2026-06-12 12:28:20', '2026-06-12 12:28:20'),
(332, 16, 'AVIZ PSIHOLOGIC', NULL, NULL, '2027-02-13', NULL, NULL, NULL, NULL, '2026-06-12 12:28:36', '2026-06-12 12:28:36'),
(333, 16, 'MEDICINA MUNCII', NULL, NULL, '2027-02-13', NULL, NULL, NULL, NULL, '2026-06-12 12:28:54', '2026-06-12 12:28:54'),
(334, 16, 'CARTELA CONDUCATOR AUTO', '000000000THV003', NULL, '2028-04-06', NULL, NULL, NULL, NULL, '2026-06-12 12:29:24', '2026-06-12 12:29:24'),
(335, 16, 'CERTIFICAT COMPETENTA PROFESIONALA', '0185050002', NULL, '2028-04-27', NULL, NULL, NULL, NULL, '2026-06-12 12:29:54', '2026-06-12 12:29:54'),
(336, 16, 'PERMIS', 'B02583374', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2033-03-17\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2028-03-17\"},\"dcf_98323d0c811f\":{\"key\":\"dcf_98323d0c811f\",\"label\":\"CATEGORIA CE\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_73d0790ad0e5\":{\"key\":\"dcf_73d0790ad0e5\",\"label\":\"DATA EXPIRARE CAT. CE\",\"type\":\"date\",\"value\":\"2028-03-17\"}}', '2026-06-12 12:30:51', '2026-06-12 12:30:51'),
(337, 16, 'ADR', '207838', NULL, '2028-07-20', NULL, NULL, NULL, NULL, '2026-06-12 12:31:20', '2026-06-12 12:31:20'),
(338, 13, 'BULETIN (C.I.)', '1750929293161', NULL, '2026-09-29', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"PX\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"434871\"}}', '2026-06-12 12:33:49', '2026-06-12 12:33:49'),
(339, 13, 'MEDICINA MUNCII', NULL, NULL, '2027-04-02', NULL, NULL, NULL, NULL, '2026-06-12 12:34:07', '2026-06-12 12:34:07'),
(340, 13, 'CARTELA CONDUCATOR AUTO', '0000000005HPM002', NULL, '2029-01-07', NULL, NULL, NULL, NULL, '2026-06-12 12:34:40', '2026-06-12 12:34:40'),
(341, 13, 'CERTIFICAT COMPETENTA PROFESIONALA', '0144497002', NULL, '2027-08-12', NULL, NULL, NULL, NULL, '2026-06-12 12:35:13', '2026-06-12 12:35:13'),
(342, 13, 'ADR', '230788', NULL, '2030-01-10', NULL, NULL, NULL, NULL, '2026-06-12 12:35:37', '2026-06-12 12:35:37');
INSERT INTO `documente_soferi` (`id`, `driver_id`, `tip_document`, `numar_document`, `data_emitere`, `data_expirare`, `fisier_original`, `fisier_stocat`, `observatii`, `custom_fields_json`, `created_at`, `updated_at`) VALUES
(343, 13, 'PERMIS', 'P00848440h', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2035-04-03\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2030-04-03\"},\"dcf_98323d0c811f\":{\"key\":\"dcf_98323d0c811f\",\"label\":\"CATEGORIA CE\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_73d0790ad0e5\":{\"key\":\"dcf_73d0790ad0e5\",\"label\":\"DATA EXPIRARE CAT. CE\",\"type\":\"date\",\"value\":\"2030-04-03\"}}', '2026-06-12 12:38:18', '2026-06-12 12:38:18'),
(344, 35, 'BULETIN (C.I.)', '1660906057067', NULL, '2026-09-06', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"ZH\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"084879\"}}', '2026-06-12 12:41:12', '2026-06-12 12:41:12'),
(345, 35, 'MEDICINA MUNCII', NULL, NULL, '2027-03-02', NULL, NULL, NULL, NULL, '2026-06-12 12:41:44', '2026-06-12 12:41:44'),
(346, 35, 'AVIZ MEDICAL', NULL, NULL, '2026-08-25', NULL, NULL, NULL, NULL, '2026-06-12 12:42:11', '2026-06-12 12:42:11'),
(347, 35, 'AVIZ PSIHOLOGIC', NULL, NULL, '2026-08-25', NULL, NULL, NULL, NULL, '2026-06-12 12:42:25', '2026-06-12 12:42:25'),
(348, 35, 'ADR', '230906', NULL, '2030-02-19', NULL, NULL, NULL, NULL, '2026-06-12 12:42:43', '2026-06-12 12:42:43'),
(349, 35, 'CARTELA CONDUCATOR AUTO', '000000000CPCK001', NULL, '2029-11-18', NULL, NULL, NULL, NULL, '2026-06-12 12:43:23', '2026-06-12 12:43:23'),
(350, 35, 'CERTIFICAT COMPETENTA PROFESIONALA', '0196091002', NULL, '2028-06-07', NULL, NULL, NULL, NULL, '2026-06-12 12:44:02', '2026-06-12 12:44:02'),
(351, 35, 'PERMIS', 'B00591244H', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2031-09-28\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2026-09-28\"},\"dcf_98323d0c811f\":{\"key\":\"dcf_98323d0c811f\",\"label\":\"CATEGORIA CE\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_73d0790ad0e5\":{\"key\":\"dcf_73d0790ad0e5\",\"label\":\"DATA EXPIRARE CAT. CE\",\"type\":\"date\",\"value\":\"2026-09-28\"}}', '2026-06-12 12:48:08', '2026-06-12 13:03:08'),
(352, 30, 'BULETIN (C.I.)', '183031035583', NULL, '2031-08-03', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"TZ\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"795642\"}}', '2026-06-12 13:05:43', '2026-06-12 13:05:43'),
(353, 30, 'CARTELA CONDUCATOR AUTO', '0000000001B1T003', NULL, '2028-08-23', NULL, NULL, NULL, NULL, '2026-06-12 13:06:14', '2026-06-12 13:06:14'),
(354, 30, 'ADR', '222415', NULL, '2029-05-24', NULL, NULL, NULL, NULL, '2026-06-12 13:06:46', '2026-06-12 13:06:46'),
(355, 30, 'PERMIS', 'T00799495M', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2033-04-05\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2028-04-05\"},\"dcf_98323d0c811f\":{\"key\":\"dcf_98323d0c811f\",\"label\":\"CATEGORIA CE\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_73d0790ad0e5\":{\"key\":\"dcf_73d0790ad0e5\",\"label\":\"DATA EXPIRARE CAT. CE\",\"type\":\"date\",\"value\":\"2028-04-05\"}}', '2026-06-12 13:09:32', '2026-06-12 13:09:32'),
(356, 30, 'CERTIFICAT COMPETENTA PROFESIONALA', '0121519003', NULL, '2027-03-10', NULL, NULL, NULL, NULL, '2026-06-12 13:10:05', '2026-06-12 13:10:05'),
(357, 30, 'MEDICINA MUNCII', NULL, NULL, '2027-02-12', NULL, NULL, NULL, NULL, '2026-06-12 13:10:32', '2026-06-12 13:10:32'),
(358, 30, 'AVIZ PSIHOLOGIC', NULL, NULL, '2027-02-11', NULL, NULL, NULL, NULL, '2026-06-12 13:10:46', '2026-06-12 13:10:46'),
(359, 30, 'AVIZ MEDICAL', NULL, NULL, '2027-02-12', NULL, NULL, NULL, NULL, '2026-06-12 13:10:58', '2026-06-12 13:10:58'),
(360, 20, 'BULETIN (C.I.)', '1900525297325', NULL, '2031-05-25', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"PX\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"815818\"}}', '2026-06-12 13:15:04', '2026-06-12 13:28:12'),
(361, 20, 'AVIZ PSIHOLOGIC', NULL, NULL, '2027-03-05', NULL, NULL, NULL, NULL, '2026-06-12 13:15:20', '2026-06-12 13:15:20'),
(362, 20, 'AVIZ MEDICAL', NULL, NULL, '2027-03-05', NULL, NULL, NULL, NULL, '2026-06-12 13:15:32', '2026-06-12 13:15:32'),
(363, 20, 'MEDICINA MUNCII', NULL, NULL, '2027-03-02', NULL, NULL, NULL, NULL, '2026-06-12 13:15:45', '2026-06-12 13:15:45'),
(364, 20, 'CARTELA CONDUCATOR AUTO', '000000000PDA9001', NULL, '2027-07-07', NULL, NULL, NULL, NULL, '2026-06-12 13:16:14', '2026-06-12 13:16:14'),
(365, 20, 'CERTIFICAT COMPETENTA PROFESIONALA', '0114869002', NULL, '2027-07-01', NULL, NULL, NULL, NULL, '2026-06-12 13:16:47', '2026-06-12 13:16:47'),
(366, 20, 'ADR', '192056', NULL, '2027-07-27', NULL, NULL, NULL, NULL, '2026-06-12 13:17:10', '2026-06-12 13:17:10'),
(367, 20, 'PERMIS', 'P00861476H', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2035-09-09\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2030-09-09\"},\"dcf_98323d0c811f\":{\"key\":\"dcf_98323d0c811f\",\"label\":\"CATEGORIA CE\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_73d0790ad0e5\":{\"key\":\"dcf_73d0790ad0e5\",\"label\":\"DATA EXPIRARE CAT. CE\",\"type\":\"date\",\"value\":\"2030-09-09\"}}', '2026-06-12 13:29:35', '2026-06-12 13:29:35'),
(368, 37, 'BULETIN (C.I.)', '1760118155226', NULL, '2036-01-14', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"DB\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"1028786\"}}', '2026-06-12 13:32:35', '2026-06-12 13:32:35'),
(369, 37, 'ADR', '229515', NULL, '2029-12-06', NULL, NULL, NULL, NULL, '2026-06-12 13:33:13', '2026-06-12 13:33:13'),
(370, 37, 'CERTIFICAT COMPETENTA PROFESIONALA', '0062306002', NULL, '2027-07-29', NULL, NULL, NULL, NULL, '2026-06-12 13:34:08', '2026-06-12 13:34:08'),
(371, 37, 'CARTELA CONDUCATOR AUTO', '0000000009V8F001', NULL, '2028-01-18', NULL, NULL, NULL, NULL, '2026-06-12 13:34:53', '2026-06-12 13:34:53'),
(372, 37, 'PERMIS', 'D00430821B', NULL, NULL, NULL, NULL, NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2032-07-26\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2027-07-26\"},\"dcf_98323d0c811f\":{\"key\":\"dcf_98323d0c811f\",\"label\":\"CATEGORIA CE\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_73d0790ad0e5\":{\"key\":\"dcf_73d0790ad0e5\",\"label\":\"DATA EXPIRARE CAT. CE\",\"type\":\"date\",\"value\":\"2027-07-26\"}}', '2026-06-12 13:36:26', '2026-06-12 13:36:26'),
(373, 37, 'AVIZ MEDICAL', NULL, NULL, '2027-03-02', NULL, NULL, NULL, NULL, '2026-06-12 13:36:48', '2026-06-12 13:36:48'),
(374, 37, 'AVIZ PSIHOLOGIC', NULL, NULL, '2027-03-02', NULL, NULL, NULL, NULL, '2026-06-12 13:37:05', '2026-06-12 13:37:05'),
(375, 37, 'MEDICINA MUNCII', NULL, NULL, '2027-03-02', NULL, NULL, NULL, NULL, '2026-06-12 13:37:19', '2026-06-12 13:37:19'),
(376, 40, 'BULETIN (C.I.)', '1640714293160', NULL, '2028-07-14', NULL, NULL, NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"PX\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"583134\"}}', '2026-06-12 13:47:05', '2026-06-12 13:47:05'),
(377, 40, 'AVIZ MEDICAL', NULL, NULL, '2027-03-05', NULL, NULL, NULL, NULL, '2026-06-12 13:47:22', '2026-06-12 13:47:22'),
(378, 26, 'CARTELA CONDUCATOR AUTO', '000000000KXSR000', NULL, '2031-05-17', NULL, NULL, NULL, NULL, '2026-06-12 14:59:52', '2026-06-12 14:59:52'),
(379, 46, 'BULETIN (C.I.)', '1700103340036', NULL, '2029-01-03', 'CI_PAUL-15062026145554.pdf', 'document_20260615_151756_61f810d9b3fcff36.pdf', NULL, '{\"dcf_a79c753ef100\":{\"key\":\"dcf_a79c753ef100\",\"label\":\"SERIE\",\"type\":\"text\",\"value\":\"RK\"},\"dcf_7b1a94a318e9\":{\"key\":\"dcf_7b1a94a318e9\",\"label\":\"NUMAR\",\"type\":\"text\",\"value\":\"472679\"}}', '2026-06-15 15:17:56', '2026-06-15 15:17:56'),
(380, 46, 'CARTELA CONDUCATOR AUTO', '0000000002G2X003', NULL, '2030-06-09', 'CARTELE_PAUL-15062026145501.pdf', 'document_20260615_151912_efc20cc18b81c770.pdf', NULL, NULL, '2026-06-15 15:19:12', '2026-06-15 15:19:12'),
(381, 46, 'PERMIS', 'B02738669', NULL, NULL, 'PERMIS_PAUL-15062026145625.pdf', 'document_20260615_152121_2afe6817438b1db3.pdf', NULL, '{\"dcf_fd4e64dac509\":{\"key\":\"dcf_fd4e64dac509\",\"label\":\"CATEGORIA B\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_f4b37111b996\":{\"key\":\"dcf_f4b37111b996\",\"label\":\"DATA EXPIRARE CAT. B\",\"type\":\"date\",\"value\":\"2035-01-09\"},\"dcf_0e4d9bc43730\":{\"key\":\"dcf_0e4d9bc43730\",\"label\":\"CATEGORIA C\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_a1c56d38dca7\":{\"key\":\"dcf_a1c56d38dca7\",\"label\":\"DATA EXPIRARE CAT. C\",\"type\":\"date\",\"value\":\"2030-01-09\"},\"dcf_98323d0c811f\":{\"key\":\"dcf_98323d0c811f\",\"label\":\"CATEGORIA CE\",\"type\":\"checkbox\",\"value\":\"1\"},\"dcf_73d0790ad0e5\":{\"key\":\"dcf_73d0790ad0e5\",\"label\":\"DATA EXPIRARE CAT. CE\",\"type\":\"date\",\"value\":\"2035-01-09\"}}', '2026-06-15 15:21:21', '2026-06-15 15:21:21'),
(382, 46, 'ADR', '200539', NULL, '2028-01-26', 'ADR_PAUL-15062026145724.pdf', 'document_20260615_152202_02a6ed66f9020dcd.pdf', NULL, NULL, '2026-06-15 15:21:47', '2026-06-15 15:22:02'),
(383, 46, 'CERTIFICAT COMPETENTA PROFESIONALA', '024568002', NULL, '2029-02-09', 'CERTIF_PREG_PROF-15062026145810.pdf', 'document_20260615_152231_f9711f382abe717e.pdf', NULL, NULL, '2026-06-15 15:22:31', '2026-06-15 15:22:31'),
(384, 46, 'AVIZ MEDICAL', NULL, NULL, '2027-03-03', NULL, NULL, NULL, NULL, '2026-06-15 15:23:55', '2026-06-15 15:23:55'),
(385, 46, 'MEDICINA MUNCII', NULL, NULL, '2027-05-15', NULL, NULL, NULL, NULL, '2026-06-15 15:24:14', '2026-06-15 15:24:45'),
(386, 46, 'AVIZ PSIHOLOGIC', NULL, NULL, '2027-03-03', NULL, NULL, NULL, NULL, '2026-06-15 15:24:32', '2026-06-15 15:24:32');

-- --------------------------------------------------------

--
-- Table structure for table `inventar_dotari_catalog`
--

CREATE TABLE `inventar_dotari_catalog` (
  `id` int UNSIGNED NOT NULL,
  `nume` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `categorie` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `equipment_type` enum('mandatory','optional') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mandatory',
  `poza_original` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `poza_stocata` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cost_implicit` decimal(10,2) NOT NULL DEFAULT '0.00',
  `necesita_data_fabricatie` tinyint(1) NOT NULL DEFAULT '0',
  `necesita_inspectie` tinyint(1) NOT NULL DEFAULT '0',
  `interval_implicit_inspectie_luni` int UNSIGNED DEFAULT NULL,
  `necesita_data_expirarii` tinyint(1) NOT NULL DEFAULT '0',
  `activ` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `inventar_dotari_catalog`
--

INSERT INTO `inventar_dotari_catalog` (`id`, `nume`, `categorie`, `equipment_type`, `poza_original`, `poza_stocata`, `cost_implicit`, `necesita_data_fabricatie`, `necesita_inspectie`, `interval_implicit_inspectie_luni`, `necesita_data_expirarii`, `activ`, `created_at`, `updated_at`) VALUES
(1, 'Extinctor', 'Siguranță', 'mandatory', NULL, NULL, 120.00, 1, 1, 12, 1, 1, '2026-06-05 14:14:53', '2026-06-05 14:14:53'),
(2, 'Trusă ADR', 'ADR', 'mandatory', NULL, NULL, 250.00, 0, 1, 12, 1, 1, '2026-06-05 14:14:53', '2026-06-05 14:14:53'),
(3, 'Trusă Medicală', 'Siguranță', 'mandatory', NULL, NULL, 90.00, 0, 0, NULL, 1, 1, '2026-06-05 14:14:53', '2026-06-05 14:14:53'),
(4, 'Apă Ochi', 'ADR', 'mandatory', NULL, NULL, 45.00, 0, 0, NULL, 1, 1, '2026-06-05 14:14:53', '2026-06-05 14:14:53'),
(5, 'Mască', 'Protecție', 'mandatory', NULL, NULL, 80.00, 0, 0, NULL, 0, 1, '2026-06-05 14:14:53', '2026-06-05 14:14:53'),
(6, 'Filtru Mască', 'Protecție', 'mandatory', NULL, NULL, 35.00, 0, 0, NULL, 1, 1, '2026-06-05 14:14:53', '2026-06-05 14:14:53'),
(7, 'Baterii', 'Consumabile', 'mandatory', NULL, NULL, 20.00, 0, 0, NULL, 1, 1, '2026-06-05 14:14:53', '2026-06-05 14:14:53'),
(8, 'Lanternă', 'Siguranță', 'mandatory', NULL, NULL, 65.00, 0, 1, 12, 0, 1, '2026-06-05 14:14:53', '2026-06-05 14:14:53'),
(9, 'Vestă reflectorizantă', 'Siguranță', 'mandatory', NULL, NULL, 30.00, 0, 0, NULL, 0, 1, '2026-06-05 14:14:53', '2026-06-05 14:14:53');

-- --------------------------------------------------------

--
-- Table structure for table `inventar_dotari_reguli`
--

CREATE TABLE `inventar_dotari_reguli` (
  `id` int UNSIGNED NOT NULL,
  `vehicle_type` enum('autovehicul','autoutilitara','camion','cap_tractor','semiremorca','semiremorca_primar','semiremorca_distributie') COLLATE utf8mb4_unicode_ci NOT NULL,
  `catalog_id` int UNSIGNED NOT NULL,
  `activ` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `inventar_dotari_reguli`
--

INSERT INTO `inventar_dotari_reguli` (`id`, `vehicle_type`, `catalog_id`, `activ`, `created_at`, `updated_at`) VALUES
(1, 'cap_tractor', 1, 1, '2026-06-05 14:14:53', '2026-06-05 14:14:53'),
(2, 'cap_tractor', 3, 1, '2026-06-05 14:14:53', '2026-06-05 14:14:53'),
(3, 'cap_tractor', 2, 1, '2026-06-05 14:14:53', '2026-06-05 14:14:53'),
(4, 'semiremorca_primar', 1, 1, '2026-06-05 14:14:53', '2026-06-05 14:14:53'),
(5, 'semiremorca_primar', 2, 1, '2026-06-05 14:14:53', '2026-06-05 14:14:53'),
(6, 'semiremorca_distributie', 1, 1, '2026-06-05 14:14:53', '2026-06-05 14:14:53'),
(7, 'semiremorca_distributie', 2, 1, '2026-06-05 14:14:53', '2026-06-05 14:14:53'),
(8, 'camion', 1, 1, '2026-06-05 14:14:53', '2026-06-05 14:14:53'),
(9, 'camion', 3, 1, '2026-06-05 14:14:53', '2026-06-05 14:14:53'),
(10, 'camion', 2, 1, '2026-06-05 14:14:53', '2026-06-05 14:14:53'),
(11, 'camion', 4, 1, '2026-06-05 14:14:53', '2026-06-05 14:14:53');

-- --------------------------------------------------------

--
-- Table structure for table `inventar_dotari_vehicule`
--

CREATE TABLE `inventar_dotari_vehicule` (
  `id` int UNSIGNED NOT NULL,
  `vehicle_id` int UNSIGNED NOT NULL,
  `catalog_id` int UNSIGNED NOT NULL,
  `poza_original` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `poza_stocata` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cost` decimal(10,2) NOT NULL DEFAULT '0.00',
  `data_achizitiei` date DEFAULT NULL,
  `data_fabricatiei` date DEFAULT NULL,
  `data_ultimei_inspectii` date DEFAULT NULL,
  `interval_inspectie_luni` int UNSIGNED DEFAULT NULL,
  `data_urmatoarei_inspectii` date DEFAULT NULL,
  `data_expirarii` date DEFAULT NULL,
  `serie_cod_produs` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `observatii` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `inventar_dotari_vehicule`
--

INSERT INTO `inventar_dotari_vehicule` (`id`, `vehicle_id`, `catalog_id`, `poza_original`, `poza_stocata`, `cost`, `data_achizitiei`, `data_fabricatiei`, `data_ultimei_inspectii`, `interval_inspectie_luni`, `data_urmatoarei_inspectii`, `data_expirarii`, `serie_cod_produs`, `observatii`, `created_at`, `updated_at`) VALUES
(7, 41, 5, NULL, NULL, 80.00, NULL, NULL, NULL, NULL, NULL, '2026-06-16', NULL, NULL, '2026-06-16 09:49:29', '2026-06-16 09:49:29');

-- --------------------------------------------------------

--
-- Table structure for table `login_email_codes`
--

CREATE TABLE `login_email_codes` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `sent_at` datetime NOT NULL,
  `attempts` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `max_attempts` tinyint UNSIGNED NOT NULL DEFAULT '5',
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `login_email_codes`
--

INSERT INTO `login_email_codes` (`id`, `user_id`, `email`, `code_hash`, `expires_at`, `sent_at`, `attempts`, `max_attempts`, `used_at`, `created_at`, `updated_at`) VALUES
(1, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$KippPxsmUMziiY9BqUPzLOd2LIo09HHVEGwM5hBZiq1o2Ki.Lrufm', '2026-05-15 15:04:49', '2026-05-15 14:54:49', 1, 5, '2026-05-15 14:54:50', '2026-05-15 14:54:49', '2026-05-15 14:54:50'),
(2, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$0vlG69IgawNr3qtg49KVZub4HDQeJMArAJ1iZOD4HZA0Jwf2bOLqu', '2026-05-15 15:08:03', '2026-05-15 14:58:03', 0, 5, '2026-05-15 14:59:04', '2026-05-15 14:58:03', '2026-05-15 14:59:04'),
(3, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$ofbFAx3DzELlZw71x/s3R.7IiGhPjod5SHK.qetRwsvLO5m7kf9Ty', '2026-05-15 15:10:36', '2026-05-15 15:00:36', 0, 5, '2026-05-15 15:02:09', '2026-05-15 15:00:36', '2026-05-15 15:02:09'),
(4, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$AhDHnz01jp/.mXBLiYb77e.h4B47EJiXENBGNKK3mMOFeWdOK.3P.', '2026-05-15 15:13:49', '2026-05-15 15:03:49', 0, 5, '2026-05-15 15:06:15', '2026-05-15 15:03:49', '2026-05-15 15:06:15'),
(5, 3, 'alexandra.iordache@lpg-auto.ro', '$2y$10$F/m0RuGQFtpl2zSpjITSuuOO4xqHFnEDE88gYezIHWeCAKelOp8Ie', '2026-05-15 15:17:02', '2026-05-15 15:07:02', 0, 5, '2026-05-15 15:08:06', '2026-05-15 15:07:02', '2026-05-15 15:08:06'),
(6, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$hKpMfeD2pBTJMqotP42eQuV8xU7KDb26NI5JeXAtDXoahWAaovZ7i', '2026-05-15 15:19:14', '2026-05-15 15:09:14', 0, 5, '2026-05-15 15:09:47', '2026-05-15 15:09:14', '2026-05-15 15:09:47'),
(7, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$ISdxQMkKLX6JaAo1Tk/GdOrcm68V6Ejy2Nur/Kxr.gC9vQ2EmrPSi', '2026-05-18 09:48:32', '2026-05-18 09:38:32', 0, 5, '2026-05-18 09:38:32', '2026-05-18 09:38:32', '2026-05-18 09:38:32'),
(8, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$GAs709isORnZg5186sFQKO6Zs6FhVQ.uSnawYCiEd/4PsFb.rDwa.', '2026-05-18 09:50:51', '2026-05-18 09:40:51', 0, 5, '2026-05-18 09:40:51', '2026-05-18 09:40:51', '2026-05-18 09:40:51'),
(9, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$p7XVaHKqrlXWE7QaNL/xvOTCTRBJWAX8.auhoeHTXhwXc2tSDNV.6', '2026-05-18 09:51:26', '2026-05-18 09:41:26', 0, 5, '2026-05-18 09:41:26', '2026-05-18 09:41:26', '2026-05-18 09:41:26'),
(10, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$x5hbh8/SmJ6Xd4vtEtRY4OWFpUTwd./w1McVRkhXGNw8.lRQdvOTS', '2026-05-18 09:56:37', '2026-05-18 09:46:37', 0, 5, '2026-05-18 09:48:38', '2026-05-18 09:46:37', '2026-05-18 09:48:38'),
(11, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$fReDfjggAj.48C54KXPcoek6gpWYVgM.oaEvC3PbMH3zqls93J21G', '2026-05-18 09:58:38', '2026-05-18 09:48:38', 0, 5, '2026-05-18 09:56:58', '2026-05-18 09:48:38', '2026-05-18 09:56:58'),
(12, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$UeG8N4ynMDgq4s9tnRiUPOSzlvTmtNSEAOvaqlgdrboanNuQ6ECze', '2026-05-18 10:06:58', '2026-05-18 09:56:58', 0, 5, '2026-05-18 09:57:23', '2026-05-18 09:56:58', '2026-05-18 09:57:23'),
(13, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$EzlOKi0GxFTmrez8J0RlyeJmscUVH0QJMIjw8RmRPSySZ.2Vym9Z6', '2026-05-18 11:50:13', '2026-05-18 11:40:13', 0, 5, '2026-05-18 11:40:28', '2026-05-18 11:40:13', '2026-05-18 11:40:28'),
(14, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$Mg8CElL4PFLuwcO4xcgbkufsZ1K0QcIeT2caeB6EJDPgcMgbXN90e', '2026-05-18 13:48:17', '2026-05-18 13:38:17', 0, 5, '2026-05-18 13:38:32', '2026-05-18 13:38:17', '2026-05-18 13:38:32'),
(15, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$BLqbAWAynKWo4omcgq/fNuye66X8Fyjngk0xcMRpPI8s.lR6D/rYO', '2026-05-19 09:04:06', '2026-05-19 08:54:06', 0, 5, '2026-05-19 08:54:23', '2026-05-19 08:54:06', '2026-05-19 08:54:23'),
(16, 3, 'alexandra.iordache@lpg-auto.ro', '$2y$10$RR.Z1KgmdeMPrkFfh9v8zOqlnQhdYO4P0fl7y23aipD6/DZPTQOb2', '2026-05-19 13:17:19', '2026-05-19 13:07:19', 0, 5, '2026-05-19 13:07:19', '2026-05-19 13:07:19', '2026-05-19 13:07:19'),
(17, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$XJ8AjRCKd15u9KqQFVLgSeYWaENw8knWu4eimGgrw79Kl9QcZKc2i', '2026-05-19 13:17:54', '2026-05-19 13:07:54', 0, 5, '2026-05-19 13:07:54', '2026-05-19 13:07:54', '2026-05-19 13:07:54'),
(18, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$RI1DQqheIiowmVgDGi4PyODM2sWbCFyuXQnvSSF9Bs8uVKOpEt4p6', '2026-05-19 13:18:13', '2026-05-19 13:08:13', 0, 5, '2026-05-19 13:08:13', '2026-05-19 13:08:13', '2026-05-19 13:08:13'),
(19, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$J1av1xOWNWECsjlqriN1YepTTTGD0xe4XVoTRQdYsgSJ8F6YyVfj2', '2026-05-19 13:19:16', '2026-05-19 13:09:16', 0, 5, '2026-05-19 13:09:16', '2026-05-19 13:09:16', '2026-05-19 13:09:16'),
(20, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$/kcUMLnYlgHSDfE1IXp5i.9vaob5S.FRt5Sw/xwNp5/2EWbySEHl.', '2026-05-19 13:20:27', '2026-05-19 13:10:27', 0, 5, '2026-05-19 13:10:27', '2026-05-19 13:10:27', '2026-05-19 13:10:27'),
(21, 3, 'alexandra.iordache@lpg-auto.ro', '$2y$10$d0VQN3Uq5SlB189NjTI8L.mEuGqZVjGRKuDnIfgR2RE3dT57pvJk.', '2026-05-19 13:50:04', '2026-05-19 13:40:04', 0, 5, '2026-05-19 13:41:24', '2026-05-19 13:40:04', '2026-05-19 13:41:24'),
(22, 3, 'alexandra.iordache@lpg-auto.ro', '$2y$10$cXx5we4sNWrphEzzp7O9cOb/ckEPnVIDvJbGidsJVbS1mAtIMvZRa', '2026-05-19 13:55:34', '2026-05-19 13:45:34', 0, 5, '2026-05-19 13:46:04', '2026-05-19 13:45:34', '2026-05-19 13:46:04'),
(23, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$5kWRvDZ9HhM7Jr964ELHjOyb4/HObCS01BxWstyrfH.fzWd88bcAi', '2026-05-19 14:34:47', '2026-05-19 14:24:47', 1, 5, '2026-05-19 14:25:53', '2026-05-19 14:24:47', '2026-05-19 14:25:53'),
(24, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$3Tm9wWv1jSkTii0mM14Xi.nfrT8QBedCqA.kxwxaqcot3V4J9snLy', '2026-05-19 14:35:49', '2026-05-19 14:25:49', 0, 5, '2026-05-19 14:26:15', '2026-05-19 14:25:49', '2026-05-19 14:26:15'),
(25, 3, 'alexandra.iordache@lpg-auto.ro', '$2y$10$BF1qNKC3z4kRQyfpPbGbKuiqWqL01rr3YyBidwzad/EnfgGbfuxf2', '2026-05-19 14:36:35', '2026-05-19 14:26:35', 1, 5, '2026-05-19 14:27:38', '2026-05-19 14:26:35', '2026-05-19 14:27:38'),
(26, 3, 'alexandra.iordache@lpg-auto.ro', '$2y$10$VLXU5UKJCWd44Sz48tuzguxokrvemDtwpqWUD5gsPXy.I8UouUy3G', '2026-05-19 14:37:35', '2026-05-19 14:27:35', 0, 5, '2026-05-19 14:28:44', '2026-05-19 14:27:35', '2026-05-19 14:28:44'),
(27, 3, 'alexandra.iordache@lpg-auto.ro', '$2y$10$OlzznveutF.jIfHmD7VF9ObB5OEWs5uEwNsQAIejK/dc7GtSGVrCC', '2026-05-19 15:55:42', '2026-05-19 15:45:42', 1, 5, '2026-05-19 15:46:52', '2026-05-19 15:45:42', '2026-05-19 15:46:52'),
(28, 3, 'alexandra.iordache@lpg-auto.ro', '$2y$10$HYbMuFnhln.V4C4e2oHB8uumnHD3raMPPpbG0A22A9XtuyvRslYXG', '2026-05-19 15:56:49', '2026-05-19 15:46:49', 0, 5, '2026-05-19 15:48:22', '2026-05-19 15:46:49', '2026-05-19 15:48:22'),
(29, 3, 'alexandra.iordache@lpg-auto.ro', '$2y$10$urtstZMMsmFiP8O7g7HVp.hj3g5RsGOporOPsOywbCnFHRKrAT2aq', '2026-05-19 15:58:22', '2026-05-19 15:48:22', 1, 5, '2026-05-19 15:49:04', '2026-05-19 15:48:22', '2026-05-19 15:49:04'),
(30, 3, 'alexandra.iordache@lpg-auto.ro', '$2y$10$Xf88I8jhTG8Dt2b8/eLPTeUX1JYnvWRPvGbqnYsXl3WZyowst6Ocq', '2026-05-19 15:59:01', '2026-05-19 15:49:01', 0, 5, '2026-05-19 15:53:30', '2026-05-19 15:49:01', '2026-05-19 15:53:30'),
(31, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$Hiz/JlRynZVCb3fUPRcIiuot6l3V2gSTHq8Q9Xpx5cN29LbQPCJDW', '2026-05-20 08:46:14', '2026-05-20 08:36:14', 0, 5, '2026-05-20 08:36:33', '2026-05-20 08:36:14', '2026-05-20 08:36:33'),
(32, 3, 'alexandra.iordache@lpg-auto.ro', '$2y$10$ZJYpv7BZ0src34//EpdJ8.US8qWXrK2FvfIhaNbQRP4QD0KmkOj.O', '2026-05-20 09:02:28', '2026-05-20 08:52:28', 0, 5, '2026-05-20 08:52:58', '2026-05-20 08:52:28', '2026-05-20 08:52:58'),
(33, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$nSf9tK36UGMPC49B5E5Kauom.39MnCZ6Ypgk7xzbh7Lm3rl3.ErTi', '2026-05-21 09:31:35', '2026-05-21 09:21:35', 0, 5, '2026-05-21 09:21:52', '2026-05-21 09:21:35', '2026-05-21 09:21:52'),
(34, 3, 'alexandra.iordache@lpg-auto.ro', '$2y$10$sqhHOAGcxP8YmIUJLRIPt.JUyN.G7QzR6UDo4MIdPyHDME21qpy2C', '2026-05-21 12:19:19', '2026-05-21 12:09:19', 0, 5, '2026-05-21 12:09:53', '2026-05-21 12:09:19', '2026-05-21 12:09:53'),
(35, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$4suRQEdg3zQaToT.joP.9.s6cMSVO2.lzWqIfUXGlF9pPA.wb3wnq', '2026-05-22 09:03:15', '2026-05-22 08:53:15', 0, 5, '2026-05-22 08:53:40', '2026-05-22 08:53:15', '2026-05-22 08:53:40'),
(36, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$AyicBo.Ngk.wC4uN9lfVzemRiUJR6uvluEundn9fTXNe5BOCld1GO', '2026-05-25 08:52:12', '2026-05-25 08:42:12', 0, 5, '2026-05-25 08:43:46', '2026-05-25 08:42:12', '2026-05-25 08:43:46'),
(37, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$atU8D07EcI73tiNJQGnA/O6Xk6BEObcqkwPeMyPFBZrlh0vtNKJ9.', '2026-05-25 09:06:07', '2026-05-25 08:56:07', 0, 5, '2026-05-25 14:24:34', '2026-05-25 08:56:07', '2026-05-25 14:24:34'),
(38, 3, 'alexandra.iordache@lpg-auto.ro', '$2y$10$ryWpY6gxZdAksv3plST7xeT5fx3aTeqJNoti30xjZzipW1tVBT6rC', '2026-05-25 11:39:32', '2026-05-25 11:29:32', 0, 5, '2026-05-25 11:30:17', '2026-05-25 11:29:32', '2026-05-25 11:30:17'),
(39, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$bXXHsDnNTCuFiuUGBYsDjeww4WPTYiPt6BnDgDWZWcJfn2UHnyK.C', '2026-05-25 14:34:34', '2026-05-25 14:24:34', 0, 5, '2026-05-25 14:25:00', '2026-05-25 14:24:34', '2026-05-25 14:25:00'),
(40, 3, 'alexandra.iordache@lpg-auto.ro', '$2y$10$H0XupueItSb7mS6rgxPJze9s/TuNeixjOrcXW/K8oQqKLhDxd9K2u', '2026-05-26 09:32:11', '2026-05-26 09:22:11', 0, 5, '2026-05-26 09:24:59', '2026-05-26 09:22:11', '2026-05-26 09:24:59'),
(41, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$8.SBw4OFkFEI4ryZGAXrpOIblgazbbbo8yZyZOJU79qlVUIPxiOJK', '2026-05-27 08:58:25', '2026-05-27 08:48:25', 0, 5, '2026-05-27 08:48:41', '2026-05-27 08:48:25', '2026-05-27 08:48:41'),
(42, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$eKpEsU143JH5EqFewsS5Dege2FMk61gr4Ocs0nNWfqXUzuvoBXdwe', '2026-05-27 12:34:54', '2026-05-27 12:24:54', 0, 5, '2026-05-27 12:25:25', '2026-05-27 12:24:54', '2026-05-27 12:25:25'),
(43, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$0Zszx7DWUpHJlKF7.W4gLO8BEtcVSCUsSImiHtJPTwb/UBWTmLMr2', '2026-05-28 10:04:16', '2026-05-28 09:54:16', 0, 5, '2026-05-28 09:55:15', '2026-05-28 09:54:16', '2026-05-28 09:55:15'),
(44, 3, 'alexandra.iordache@lpg-auto.ro', '$2y$10$lz57A1Sbi.2eNdoyEzzeAe12DyINa7zmB0SxZtX393TDU0/tgPbwm', '2026-05-28 12:53:49', '2026-05-28 12:43:49', 0, 5, '2026-05-28 12:47:56', '2026-05-28 12:43:49', '2026-05-28 12:47:56'),
(45, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$tISgi8z87zK5yLqJfh0EFeYRzjqTWniGU1MymCu1UETM6ujgHuvTy', '2026-05-29 08:46:40', '2026-05-29 08:36:40', 0, 5, '2026-05-29 08:37:08', '2026-05-29 08:36:40', '2026-05-29 08:37:08'),
(46, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$Pl.7FBBX66GSjwIvjxcCq.Cid8zaMvmuvo7T/PKlppsFgziIfcJ6i', '2026-06-02 08:43:59', '2026-06-02 08:33:59', 0, 5, '2026-06-02 08:34:23', '2026-06-02 08:33:59', '2026-06-02 08:34:23'),
(47, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$SigF7hQf1FEw3q3lJp3HV.jsqQqUspUrn8.djXl4IRF3fWB3Fmj1i', '2026-06-08 15:21:17', '2026-06-08 15:11:17', 0, 5, '2026-06-08 15:11:43', '2026-06-08 15:11:17', '2026-06-08 15:11:43'),
(48, 3, 'alexandra.iordache@lpg-auto.ro', '$2y$10$HCrwM2Q3EtUQtoScHz8VBOdXUPxnAYbiLYLxtZ51gWrrlr/jekgwK', '2026-06-08 15:22:33', '2026-06-08 15:12:33', 0, 5, '2026-06-08 15:13:01', '2026-06-08 15:12:33', '2026-06-08 15:13:01'),
(49, 5, 'office@lpg-auto.ro', '$2y$10$vNILmzhbZ6WNYimeDiDeWO3ZQvEOkt5AqjDPmcf3n7QaDGmbgrV0S', '2026-06-08 15:22:38', '2026-06-08 15:12:38', 0, 5, '2026-06-08 15:12:58', '2026-06-08 15:12:38', '2026-06-08 15:12:58'),
(50, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$Kva9bk1rqIFyA2h5HBk73.aAeAsG5mY778li7JL6luNO32Z38GMxG', '2026-06-09 08:46:47', '2026-06-09 08:36:47', 1, 5, '2026-06-09 08:38:37', '2026-06-09 08:36:47', '2026-06-09 08:38:37'),
(51, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$M1ue4yAy4xDxib/0iDZBv.93ai8GVaA..GMAt8clFO4B.AULN/KmG', '2026-06-09 08:48:31', '2026-06-09 08:38:31', 0, 5, '2026-06-09 08:38:48', '2026-06-09 08:38:31', '2026-06-09 08:38:48'),
(52, 3, 'alexandra.iordache@lpg-auto.ro', '$2y$10$wQuWUv/4/fI8UkTdiKmAn.7cKVwJjA25cIChleHpVgk4tYAdLxwli', '2026-06-09 08:58:41', '2026-06-09 08:48:41', 0, 5, '2026-06-09 08:48:59', '2026-06-09 08:48:41', '2026-06-09 08:48:59'),
(53, 5, 'office@lpg-auto.ro', '$2y$10$wsMoaZAwNtWRxTPoajWN6.xbLELWR/eKbe/0KVXMYYVrJms5g5KRG', '2026-06-09 09:03:59', '2026-06-09 08:53:59', 0, 5, '2026-06-09 08:54:55', '2026-06-09 08:53:59', '2026-06-09 08:54:55'),
(54, 5, 'office@lpg-auto.ro', '$2y$10$dDHvf2ui1awx0sh4JiGrf.cW1DXz2BhayS5J8tRjSXGHawi.QCEX6', '2026-06-09 09:04:52', '2026-06-09 08:54:52', 0, 5, '2026-06-09 08:55:35', '2026-06-09 08:54:52', '2026-06-09 08:55:35'),
(55, 5, 'office@lpg-auto.ro', '$2y$10$sn4cnXW5rVtmcQk4yze4luR3i1nHfzMKwNr/KUg6LjF359Cov23bG', '2026-06-09 11:00:04', '2026-06-09 10:50:04', 0, 5, '2026-06-09 10:50:59', '2026-06-09 10:50:04', '2026-06-09 10:50:59'),
(56, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$WlL9/Rfw5yATvN/Xd5Y53uIOjd0t6H9q93m2oB64a5spHKzWy4OQe', '2026-06-09 13:54:24', '2026-06-09 13:44:24', 0, 5, '2026-06-09 13:44:42', '2026-06-09 13:44:24', '2026-06-09 13:44:42'),
(57, 5, 'office@lpg-auto.ro', '$2y$10$USdDtwQTomxclF4v9CibSO.xdbv.SFLJDcpXTzP.ViHczQyO5PZrG', '2026-06-09 15:15:32', '2026-06-09 15:05:32', 0, 5, '2026-06-09 15:05:57', '2026-06-09 15:05:32', '2026-06-09 15:05:57'),
(58, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$xGb.Db8jyJRJcThyg851wexSUjeQoikZTMvqs2cJAboNky1Xg4evm', '2026-06-10 08:55:43', '2026-06-10 08:45:43', 0, 5, '2026-06-10 08:46:30', '2026-06-10 08:45:43', '2026-06-10 08:46:30'),
(59, 3, 'alexandra.iordache@lpg-auto.ro', '$2y$10$xTryDoyi4DESNE1yEwwK8.BkSV0l/HAhK1ZkXyAjrnTEyggyNu95q', '2026-06-10 10:22:34', '2026-06-10 10:12:34', 0, 5, '2026-06-10 10:13:31', '2026-06-10 10:12:34', '2026-06-10 10:13:31'),
(60, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$3Kc.RZfnJJL/iI8/afKxQ.gJEKvHi9cZe1eCS6Q2C5AFGFAnOrxpm', '2026-06-11 09:17:25', '2026-06-11 09:07:25', 0, 5, '2026-06-11 09:08:03', '2026-06-11 09:07:25', '2026-06-11 09:08:03'),
(61, 5, 'office@lpg-auto.ro', '$2y$10$F4wmPnskMaxkcbRl4QEYQOKLByHQcHcOnn1QBBbix6lGIzeDIxPfC', '2026-06-11 10:19:08', '2026-06-11 10:09:08', 0, 5, '2026-06-11 10:09:38', '2026-06-11 10:09:08', '2026-06-11 10:09:38'),
(62, 3, 'alexandra.iordache@lpg-auto.ro', '$2y$10$pYEF2h3aVCmIBhHoAuQvOOGyS5rNTSDfAwpLLJxkW76b6S926uZjq', '2026-06-11 10:26:52', '2026-06-11 10:16:52', 0, 5, '2026-06-11 10:18:51', '2026-06-11 10:16:52', '2026-06-11 10:18:51'),
(63, 3, 'alexandra.iordache@lpg-auto.ro', '$2y$10$Mby67F.3g2W/78cRpHWIzuia/RLgJ.ukaXRFZTecECUq0w51nRIa6', '2026-06-11 14:25:18', '2026-06-11 14:15:18', 1, 5, '2026-06-11 14:16:23', '2026-06-11 14:15:18', '2026-06-11 14:16:23'),
(64, 3, 'alexandra.iordache@lpg-auto.ro', '$2y$10$SdFqTPjJMrH7dKQdNMCmmuOe8WOdQQcqh.SK1AXxGFfyKyIwuKSWu', '2026-06-11 14:26:20', '2026-06-11 14:16:20', 0, 5, '2026-06-11 14:16:33', '2026-06-11 14:16:20', '2026-06-11 14:16:33'),
(65, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$2VXOYXdDVvLYctTcOp4tGu85v232hsQNR1fZzOfsaeS8yq2S26H3G', '2026-06-12 09:07:26', '2026-06-12 08:57:26', 0, 5, '2026-06-12 08:57:44', '2026-06-12 08:57:26', '2026-06-12 08:57:44'),
(66, 3, 'alexandra.iordache@lpg-auto.ro', '$2y$10$EBXhhqu2ItoxfjkzKogon.5SFDV.QgNb6en5rWWNE4sOUL8D5NwDm', '2026-06-12 10:08:51', '2026-06-12 09:58:51', 0, 5, '2026-06-12 09:59:17', '2026-06-12 09:58:51', '2026-06-12 09:59:17'),
(67, 5, 'office@lpg-auto.ro', '$2y$10$lwfNItZrKWibRdB5dQkbz.ZZ/r6jB8ZhVpCQl6eTyF/1Jnw3tupw6', '2026-06-12 10:46:47', '2026-06-12 10:36:47', 0, 5, '2026-06-12 10:37:23', '2026-06-12 10:36:47', '2026-06-12 10:37:23'),
(68, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$49Fzwhuou6FyRE7Sy5FGBO9p9QxFYCcuLLaKeKb9ak3LJ8HIqQevG', '2026-06-15 08:42:10', '2026-06-15 08:32:10', 0, 5, '2026-06-15 08:32:23', '2026-06-15 08:32:10', '2026-06-15 08:32:23'),
(69, 3, 'alexandra.iordache@lpg-auto.ro', '$2y$10$pWA6NvdqxwFV9Zaau/BCduBMUW.cAABMfAzhQULhvhit.H0VKVJ3i', '2026-06-15 10:37:00', '2026-06-15 10:27:00', 0, 5, '2026-06-15 10:58:30', '2026-06-15 10:27:00', '2026-06-15 10:58:30'),
(70, 3, 'alexandra.iordache@lpg-auto.ro', '$2y$10$FwSYZkZVqiKp1BkjB2IAqedFyYZHovQoHyhu11tLiqc67/BsgJn7e', '2026-06-15 15:12:55', '2026-06-15 15:02:55', 0, 5, '2026-06-15 15:04:25', '2026-06-15 15:02:55', '2026-06-15 15:04:25'),
(71, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$vilYS3Au6mCnKmtLy1LlDOnsux/u9pkbRp.MPUt75KKN7sm0MCwcK', '2026-06-15 15:19:32', '2026-06-15 15:09:32', 0, 5, '2026-06-15 15:19:08', '2026-06-15 15:09:32', '2026-06-15 15:19:08'),
(72, 5, 'office@lpg-auto.ro', '$2y$10$AZmXINyviCpXQwq0BJz1GOX0I4bcdykkGK7WdjoDgxgcuGRhg10Ye', '2026-06-15 15:24:27', '2026-06-15 15:14:27', 0, 5, '2026-06-15 15:14:48', '2026-06-15 15:14:27', '2026-06-15 15:14:48'),
(74, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$pj3dcFWJQbAS9KEKQvF4e.Eeg3MnH5nV.4dz0vEk48dhH/AQ8UXva', '2026-06-15 15:31:53', '2026-06-15 15:21:53', 0, 5, '2026-06-15 15:35:55', '2026-06-15 15:21:53', '2026-06-15 15:35:55'),
(75, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$Gx5vOOjbyHZ8yqRo7PQPwucwlg.27QxBHy/1KftQgFrBRTCsjO9q.', '2026-06-15 15:45:55', '2026-06-15 15:35:55', 0, 5, '2026-06-15 15:37:38', '2026-06-15 15:35:55', '2026-06-15 15:37:38'),
(76, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$Xw2u.t/BrIlFABaJtv4Gx.KK/m8Pcy5PqlVPnVkZt414JG3911PlK', '2026-06-15 15:47:38', '2026-06-15 15:37:38', 0, 5, '2026-06-15 15:37:56', '2026-06-15 15:37:38', '2026-06-15 15:37:56'),
(77, 1, 'gigel.trandafir@lpg-auto.ro', '$2y$10$OSL.VGwNGOXjzuRmgZlrEed.2fqHwm3U.7IoxZZ/tuGxEKZsvx1yy', '2026-06-16 08:59:46', '2026-06-16 08:49:46', 0, 5, '2026-06-16 08:51:07', '2026-06-16 08:49:46', '2026-06-16 08:51:07'),
(78, 3, 'alexandra.iordache@lpg-auto.ro', '$2y$10$YYYH6eEeyKUBZGTstC.VWOxCUWGIqnLSoBnhHC/zJsEhtD9zYPQh.', '2026-06-16 09:02:10', '2026-06-16 08:52:10', 0, 5, '2026-06-16 08:52:34', '2026-06-16 08:52:10', '2026-06-16 08:52:34');

-- --------------------------------------------------------

--
-- Table structure for table `mentenanta`
--

CREATE TABLE `mentenanta` (
  `id` int UNSIGNED NOT NULL,
  `vehicle_id` int UNSIGNED NOT NULL,
  `tip_interventie` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_interventie` date NOT NULL,
  `cost` decimal(10,2) NOT NULL,
  `atelier` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `furnizor_piesa` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fisier_original` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fisier_stocat` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `observatii` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `mentenanta`
--

INSERT INTO `mentenanta` (`id`, `vehicle_id`, `tip_interventie`, `data_interventie`, `cost`, `atelier`, `furnizor_piesa`, `fisier_original`, `fisier_stocat`, `observatii`, `created_at`, `updated_at`) VALUES
(1, 1, 'Schimb ulei si filtre', '2026-03-22', 780.00, 'Service Rapid SRL', NULL, NULL, NULL, 'Revizie periodica', '2026-04-03 15:08:04', '2026-04-03 15:08:04'),
(2, 2, 'Placute frana fata', '2026-03-29', 540.00, 'AutoFix', 'Auto Partener SRL', 'test3.pdf', 'document_20260417_121651_af0c7ad0793f5d4d.pdf', 'Uzura normala', '2026-04-03 15:08:04', '2026-04-17 12:16:51'),
(3, 3, 'Diagnoza electrica', '2026-03-03', 320.00, 'ElectroCar', NULL, NULL, NULL, 'Investigatie martor bord', '2026-04-03 15:08:04', '2026-04-03 15:08:04'),
(4, 2, 'Placute frana spate', '2026-04-17', 230.00, 'AUTO TOTAL', 'AUGSBURG', 'test2.pdf', 'document_20260417_124152_375718672de40876.pdf', NULL, '2026-04-17 12:41:52', '2026-04-17 12:41:52'),
(5, 28, 'Anvelopa - Montata', '2026-05-12', 0.00, 'Michelin Axinte1', NULL, NULL, NULL, 'Serie anvelopa: 53214151\r\nDimensiune: 315/80\r\nDOT: 3423\r\nCompatibil: Autoturism', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(6, 28, 'Anvelopa - Rezerva', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B605NET-20260512124328-001-5ADC\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(7, 28, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B605NET-20260512124328-002-9E40\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(8, 28, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B605NET-20260512124328-003-FF7E\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(9, 28, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B605NET-20260512124328-004-A5B5\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(10, 28, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B605NET-20260512124328-005-F8CD\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(11, 27, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B435NET-20260512124328-001-C421\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(12, 27, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B435NET-20260512124328-002-7588\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(13, 27, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B435NET-20260512124328-003-6857\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(14, 27, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B435NET-20260512124328-004-3015\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(15, 27, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B435NET-20260512124328-005-B5D6\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(16, 27, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B435NET-20260512124328-006-4647\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(17, 26, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B677NET-20260512124328-001-B508\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(18, 26, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B677NET-20260512124328-002-A7F4\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(19, 26, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B677NET-20260512124328-003-1E79\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(20, 26, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B677NET-20260512124328-004-D01D\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(21, 26, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B677NET-20260512124328-005-E4E0\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(22, 26, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B677NET-20260512124328-006-F742\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(23, 24, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B325NET-20260512124328-001-86AE\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(24, 24, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B325NET-20260512124328-002-ECDF\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(25, 24, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B325NET-20260512124328-003-4EB4\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(26, 24, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B325NET-20260512124328-004-B28C\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(27, 24, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B325NET-20260512124328-005-2B23\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(28, 24, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B325NET-20260512124328-006-F9F7\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(29, 23, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B275NET-20260512124328-001-5463\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(30, 23, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B275NET-20260512124328-002-C3EB\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(31, 23, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B275NET-20260512124328-003-5824\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(32, 23, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B275NET-20260512124328-004-68D4\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(33, 23, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B275NET-20260512124328-005-3BA4\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(34, 23, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B275NET-20260512124328-006-2614\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(35, 22, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B430NET-20260512124328-001-B89F\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(36, 22, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B430NET-20260512124328-002-DD59\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(37, 22, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B430NET-20260512124328-003-77AB\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(38, 22, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B430NET-20260512124328-004-9879\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(39, 22, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B430NET-20260512124328-005-58C4\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(40, 22, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B430NET-20260512124328-006-65AB\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(41, 21, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B311NET-20260512124328-001-232D\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(42, 21, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B311NET-20260512124328-002-083E\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(43, 21, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B311NET-20260512124328-003-07DF\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(44, 21, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B311NET-20260512124328-004-697C\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(45, 21, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B311NET-20260512124328-005-E284\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(46, 21, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B311NET-20260512124328-006-078C\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(47, 20, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B345NET-20260512124328-001-22D7\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(48, 20, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B345NET-20260512124328-002-DB99\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(49, 20, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B345NET-20260512124328-003-D1EF\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(50, 20, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B345NET-20260512124328-004-E9F2\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(51, 20, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B345NET-20260512124328-005-46DB\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(52, 20, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B345NET-20260512124328-006-89C6\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(53, 19, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B219NET-20260512124328-001-B5A5\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(54, 19, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B219NET-20260512124328-002-955C\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(55, 19, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B219NET-20260512124328-003-B5F7\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(56, 19, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B219NET-20260512124328-004-C37D\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(57, 19, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B219NET-20260512124328-005-67F7\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(58, 19, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B219NET-20260512124328-006-FF14\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(59, 18, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B437NET-20260512124328-001-D0AB\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(60, 18, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B437NET-20260512124328-002-56D2\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(61, 18, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B437NET-20260512124328-003-1B69\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(62, 18, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B437NET-20260512124328-004-33DC\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(63, 18, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B437NET-20260512124328-005-4C63\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(64, 18, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B437NET-20260512124328-006-E6E7\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(65, 17, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B385NET-20260512124328-001-DB8B\r\nCompatibil: Autoturism\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(66, 17, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B385NET-20260512124328-002-B030\r\nCompatibil: Autoturism\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(67, 17, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B385NET-20260512124328-003-925D\r\nCompatibil: Autoturism\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(68, 17, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B385NET-20260512124328-004-DB5E\r\nCompatibil: Autoturism\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(69, 16, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B375NET-20260512124329-001-8B2F\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(70, 16, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B375NET-20260512124329-002-5930\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(71, 16, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B375NET-20260512124329-003-0358\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(72, 16, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B375NET-20260512124329-004-CF2C\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(73, 16, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B375NET-20260512124329-005-9E6C\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(74, 16, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B375NET-20260512124329-006-CDF2\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(75, 15, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B285NET-20260512124329-001-A860\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(76, 15, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B285NET-20260512124329-002-A8AF\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(77, 15, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B285NET-20260512124329-003-E189\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(78, 15, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B285NET-20260512124329-004-41EE\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(79, 15, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B285NET-20260512124329-005-6AEE\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(80, 15, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B285NET-20260512124329-006-DC3F\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(81, 12, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B405NET-20260512124329-001-BDCC\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(82, 12, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B405NET-20260512124329-002-CE0D\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(83, 12, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B405NET-20260512124329-003-E91F\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(84, 12, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B405NET-20260512124329-004-1EA6\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(85, 12, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B405NET-20260512124329-005-1A06\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(86, 12, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B405NET-20260512124329-006-1310\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(87, 12, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B405NET-20260512124329-007-6E04\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(88, 12, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B405NET-20260512124329-008-C4CE\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(89, 12, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B405NET-20260512124329-009-4FD5\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(90, 12, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B405NET-20260512124329-010-C4DD\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(91, 12, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B405NET-20260512124329-011-44BE\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(92, 12, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B405NET-20260512124329-012-A134\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(93, 11, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B665NET-20260512124329-001-B698\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(94, 11, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B665NET-20260512124329-002-118B\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(95, 11, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B665NET-20260512124329-003-F71D\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(96, 11, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B665NET-20260512124329-004-0937\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(97, 11, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B665NET-20260512124329-005-C30D\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(98, 11, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B665NET-20260512124329-006-2F65\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(99, 9, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B218NET-20260512124329-001-29E7\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(100, 9, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B218NET-20260512124329-002-473B\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(101, 9, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B218NET-20260512124329-003-6EA2\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(102, 9, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B218NET-20260512124329-004-6265\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(103, 9, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B218NET-20260512124329-005-F518\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(104, 9, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B218NET-20260512124329-006-66A6\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(105, 9, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B218NET-20260512124329-007-0398\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(106, 9, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B218NET-20260512124329-008-B420\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(107, 9, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B218NET-20260512124329-009-F954\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(108, 9, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B218NET-20260512124329-010-13F8\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(109, 9, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B218NET-20260512124329-011-581D\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(110, 9, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B218NET-20260512124329-012-0486\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(111, 6, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B235NET-20260512124329-001-D8D0\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(112, 6, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B235NET-20260512124329-002-0A54\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(113, 6, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B235NET-20260512124329-003-6883\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(114, 6, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B235NET-20260512124329-004-95B5\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(115, 6, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B235NET-20260512124329-005-7E24\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(116, 6, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B235NET-20260512124329-006-89D1\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(117, 6, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B235NET-20260512124329-007-C269\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(118, 6, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B235NET-20260512124329-008-F413\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(119, 6, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B235NET-20260512124329-009-3AB7\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(120, 6, 'Anvelopa - Montata', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B235NET-20260512124329-010-982A\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-29 09:48:03'),
(175, 31, 'Anvelopa - Montata', '2026-05-19', 0.00, 'SEASON-STOCK ALL-SEASON 2026', NULL, NULL, NULL, 'Serie anvelopa: SEASON-CAPTRACTOR-20260512125113-0D5261-0001\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Stoc sezon creat automat (2026-05-12)', '2026-05-19 12:42:09', '2026-05-29 09:48:03'),
(176, 31, 'Anvelopa - Montata', '2026-05-19', 0.00, 'SEASON-STOCK ALL-SEASON 2026', NULL, NULL, NULL, 'Serie anvelopa: SEASON-CAPTRACTOR-20260512125113-1491A1-0002\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Stoc sezon creat automat (2026-05-12)', '2026-05-19 12:42:09', '2026-05-29 09:48:03'),
(177, 31, 'Anvelopa - Montata', '2026-05-19', 0.00, 'SEASON-STOCK ALL-SEASON 2026', NULL, NULL, NULL, 'Serie anvelopa: SEASON-CAPTRACTOR-20260512125113-36BE8C-0003\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Stoc sezon creat automat (2026-05-12)', '2026-05-19 12:42:09', '2026-05-29 09:48:03'),
(178, 31, 'Anvelopa - Montata', '2026-05-19', 0.00, 'SEASON-STOCK ALL-SEASON 2026', NULL, NULL, NULL, 'Serie anvelopa: SEASON-CAPTRACTOR-20260512125113-46D2D2-0004\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Stoc sezon creat automat (2026-05-12)', '2026-05-19 12:42:09', '2026-05-29 09:48:03'),
(199, 32, 'Anvelopa - Montata', '2026-05-19', 0.00, 'SEASON-STOCK ALL-SEASON 2026', NULL, NULL, NULL, 'Serie anvelopa: SEASON-SEMIREMORCA-20260512125113-9B81D9-0003\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Stoc sezon creat automat (2026-05-12)', '2026-05-19 16:44:10', '2026-05-29 09:48:03'),
(200, 32, 'Anvelopa - Montata', '2026-05-19', 0.00, 'SEASON-STOCK ALL-SEASON 2026', NULL, NULL, NULL, 'Serie anvelopa: SEASON-SEMIREMORCA-20260512125113-9902DE-0004\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Stoc sezon creat automat (2026-05-12)', '2026-05-19 16:44:10', '2026-05-29 09:48:03'),
(227, 52, 'Anvelopa - Montata', '2026-05-26', 0.00, 'SEASON-STOCK ALL-SEASON 2026', NULL, NULL, NULL, 'Serie anvelopa: SEASON-CAMION-20260512125113-BAA38D-0009\r\nCompatibil: Camion\r\nObservatii anvelopa: Stoc sezon creat automat (2026-05-12)', '2026-05-29 09:48:03', '2026-05-29 09:48:03'),
(228, 52, 'Anvelopa - Montata', '2026-05-26', 0.00, 'SEASON-STOCK ALL-SEASON 2026', NULL, NULL, NULL, 'Serie anvelopa: SEASON-CAMION-20260512125113-8B9117-0010\r\nCompatibil: Camion\r\nObservatii anvelopa: Stoc sezon creat automat (2026-05-12)', '2026-05-29 09:48:03', '2026-05-29 09:48:03');

-- --------------------------------------------------------

--
-- Table structure for table `notification_deliveries`
--

CREATE TABLE `notification_deliveries` (
  `id` bigint UNSIGNED NOT NULL,
  `context` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `context_id` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `channel` enum('email') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'email',
  `recipient_email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_name` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','sent','failed','skipped') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `provider` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'smtp',
  `provider_response` text COLLATE utf8mb4_unicode_ci,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `diagnostics_json` longtext COLLATE utf8mb4_unicode_ci,
  `metadata_json` longtext COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL,
  `sent_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notification_deliveries`
--

INSERT INTO `notification_deliveries` (`id`, `context`, `context_id`, `channel`, `recipient_email`, `recipient_name`, `subject`, `message`, `status`, `provider`, `provider_response`, `error_message`, `diagnostics_json`, `metadata_json`, `created_at`, `sent_at`) VALUES
(38, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:100:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 1 zile: RCA - B 635 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 635 NET\nDetalii: DAF XF\nTip document: RCA\nSerie / numar: -\nData expirare: 16.06.2026\nStatus: Expira in 1 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=100\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 100, \"document_id\": 100}', '2026-06-15 15:57:02', '2026-06-15 15:57:05'),
(39, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:439:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 4 zile: Rovinieta - B 218 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 218 NET\nDetalii: SCANIA R380\nTip document: Rovinieta\nSerie / numar: -\nData expirare: 19.06.2026\nStatus: Expira in 4 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=439\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 439, \"document_id\": 439}', '2026-06-15 15:57:02', '2026-06-15 15:57:07'),
(40, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:343:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 11 zile: ADR - B 430 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 430 NET\nDetalii: VOLVO FM400\nTip document: ADR\nSerie / numar: -\nData expirare: 26.06.2026\nStatus: Expira in 11 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=343\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 343, \"document_id\": 343}', '2026-06-15 15:57:02', '2026-06-15 15:57:10'),
(41, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:349:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 11 zile: IPROCHIM - B 430 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 430 NET\nDetalii: VOLVO FM400\nTip document: IPROCHIM\nSerie / numar: -\nData expirare: 26.06.2026\nStatus: Expira in 11 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=349\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 349, \"document_id\": 349}', '2026-06-15 15:57:02', '2026-06-15 15:57:12'),
(42, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:350:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 11 zile: ITP - B 430 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 430 NET\nDetalii: VOLVO FM400\nTip document: ITP\nSerie / numar: -\nData expirare: 26.06.2026\nStatus: Expira in 11 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=350\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 350, \"document_id\": 350}', '2026-06-15 15:57:02', '2026-06-15 15:57:15'),
(43, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:311:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 14 zile: Rovinieta - B 435 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 435 NET\nDetalii: MAN TGA\nTip document: Rovinieta\nSerie / numar: -\nData expirare: 29.06.2026\nStatus: Expira in 14 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=311\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 311, \"document_id\": 311}', '2026-06-15 15:57:02', '2026-06-15 15:57:17'),
(44, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:43:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 15 zile: RCA - B 285 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 285 NET\nDetalii: Mercedes ATEGO\nTip document: RCA\nSerie / numar: -\nData expirare: 30.06.2026\nStatus: Expira in 15 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=43\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 43, \"document_id\": 43}', '2026-06-15 15:57:02', '2026-06-15 15:57:20'),
(45, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:48:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 19 zile: ITP - B 375 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 375 NET\nDetalii: VOLVO FH440\nTip document: ITP\nSerie / numar: -\nData expirare: 04.07.2026\nStatus: Expira in 19 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=48\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 48, \"document_id\": 48}', '2026-06-15 15:57:02', '2026-06-15 15:57:23'),
(46, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:529:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 19 zile: ADR - B 375 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 375 NET\nDetalii: VOLVO FH440\nTip document: ADR\nSerie / numar: -\nData expirare: 04.07.2026\nStatus: Expira in 19 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=529\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 529, \"document_id\": 529}', '2026-06-15 15:57:02', '2026-06-15 15:57:25'),
(47, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:441:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 22 zile: ADR - B 325 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 325 NET\nDetalii: DAF CF\nTip document: ADR\nSerie / numar: -\nData expirare: 07.07.2026\nStatus: Expira in 22 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=441\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 441, \"document_id\": 441}', '2026-06-15 15:57:02', '2026-06-15 15:57:27'),
(48, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:444:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 22 zile: IPROCHIM - B 325 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 325 NET\nDetalii: DAF CF\nTip document: IPROCHIM\nSerie / numar: -\nData expirare: 07.07.2026\nStatus: Expira in 22 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=444\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 444, \"document_id\": 444}', '2026-06-15 15:57:02', '2026-06-15 15:57:30'),
(49, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:445:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 22 zile: ITP - B 325 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 325 NET\nDetalii: DAF CF\nTip document: ITP\nSerie / numar: -\nData expirare: 07.07.2026\nStatus: Expira in 22 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=445\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 445, \"document_id\": 445}', '2026-06-15 15:57:02', '2026-06-15 15:57:32'),
(50, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:555:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 22 zile: Rovinieta - B 72 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 72 NET\nDetalii: DACIA SANDERO\nTip document: Rovinieta\nSerie / numar: -\nData expirare: 07.07.2026\nStatus: Expira in 22 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=555\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 555, \"document_id\": 555}', '2026-06-15 15:57:02', '2026-06-15 15:57:35'),
(51, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:76:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 26 zile: Carte - B 835 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 835 NET\nDetalii: LDS NCX\nTip document: Carte\nSerie / numar: -\nData expirare: 11.07.2026\nStatus: Expira in 26 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=76\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 76, \"document_id\": 76}', '2026-06-15 15:57:02', '2026-06-15 15:57:38'),
(52, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:84:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 26 zile: Carte - B 645 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 645 NET\nDetalii: DAF XF\nTip document: Carte\nSerie / numar: -\nData expirare: 11.07.2026\nStatus: Expira in 26 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=84\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 84, \"document_id\": 84}', '2026-06-15 15:57:02', '2026-06-15 15:57:40'),
(53, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:91:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 26 zile: Carte - B 915 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 915 NET\nDetalii: VPS VPSCN\nTip document: Carte\nSerie / numar: -\nData expirare: 11.07.2026\nStatus: Expira in 26 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=91\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 91, \"document_id\": 91}', '2026-06-15 15:57:02', '2026-06-15 15:57:43'),
(54, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:97:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 26 zile: Carte - B 635 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 635 NET\nDetalii: DAF XF\nTip document: Carte\nSerie / numar: -\nData expirare: 11.07.2026\nStatus: Expira in 26 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=97\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 97, \"document_id\": 97}', '2026-06-15 15:57:02', '2026-06-15 15:57:45'),
(55, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:103:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 26 zile: Carte - B 845 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 845 NET\nDetalii: VPS VPSCN\nTip document: Carte\nSerie / numar: -\nData expirare: 11.07.2026\nStatus: Expira in 26 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=103\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 103, \"document_id\": 103}', '2026-06-15 15:57:02', '2026-06-15 15:57:48'),
(56, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:109:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 26 zile: Carte - B 625 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 625 NET\nDetalii: DAF CF\nTip document: Carte\nSerie / numar: -\nData expirare: 11.07.2026\nStatus: Expira in 26 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=109\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 109, \"document_id\": 109}', '2026-06-15 15:57:02', '2026-06-15 15:57:50'),
(57, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:116:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 26 zile: Carte - B 825 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 825 NET\nDetalii: VPS VPSCN\nTip document: Carte\nSerie / numar: -\nData expirare: 11.07.2026\nStatus: Expira in 26 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=116\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 116, \"document_id\": 116}', '2026-06-15 15:57:02', '2026-06-15 15:57:53'),
(58, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:123:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 26 zile: Carte - B 615 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 615 NET\nDetalii: DAF XF\nTip document: Carte\nSerie / numar: -\nData expirare: 11.07.2026\nStatus: Expira in 26 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=123\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 123, \"document_id\": 123}', '2026-06-15 15:57:02', '2026-06-15 15:57:55'),
(59, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:131:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 26 zile: Carte - B 705 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 705 NET\nDetalii: VPS VPSCN\nTip document: Carte\nSerie / numar: -\nData expirare: 11.07.2026\nStatus: Expira in 26 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=131\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 131, \"document_id\": 131}', '2026-06-15 15:57:02', '2026-06-15 15:57:58'),
(60, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:139:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 26 zile: Carte - B 905 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 905 NET\nDetalii: VPS VPSCN\nTip document: Carte\nSerie / numar: -\nData expirare: 11.07.2026\nStatus: Expira in 26 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=139\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 139, \"document_id\": 139}', '2026-06-15 15:57:02', '2026-06-15 15:58:00'),
(61, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:145:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 26 zile: Carte - B 402 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 402 NET\nDetalii: DAF CF\nTip document: Carte\nSerie / numar: -\nData expirare: 11.07.2026\nStatus: Expira in 26 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=145\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 145, \"document_id\": 145}', '2026-06-15 15:57:02', '2026-06-15 15:58:03'),
(62, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:160:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 26 zile: Carte - B 401 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 401 NET\nDetalii: DAF CF\nTip document: Carte\nSerie / numar: -\nData expirare: 11.07.2026\nStatus: Expira in 26 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=160\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 160, \"document_id\": 160}', '2026-06-15 15:57:02', '2026-06-15 15:58:05'),
(63, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:166:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 26 zile: Carte - B 925 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 925 NET\nDetalii: VPS VPSCN\nTip document: Carte\nSerie / numar: -\nData expirare: 11.07.2026\nStatus: Expira in 26 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=166\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 166, \"document_id\": 166}', '2026-06-15 15:57:02', '2026-06-15 15:59:05'),
(64, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:159:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 27 zile: ADR - B 401 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 401 NET\nDetalii: DAF CF\nTip document: ADR\nSerie / numar: -\nData expirare: 12.07.2026\nStatus: Expira in 27 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=159\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 159, \"document_id\": 159}', '2026-06-15 15:57:02', '2026-06-15 15:59:07'),
(65, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:162:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 27 zile: ITP - B 401 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 401 NET\nDetalii: DAF CF\nTip document: ITP\nSerie / numar: -\nData expirare: 12.07.2026\nStatus: Expira in 27 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=162\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 162, \"document_id\": 162}', '2026-06-15 15:57:02', '2026-06-15 15:59:10'),
(66, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:493:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 30 zile: Rovinieta - B 635 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 635 NET\nDetalii: DAF XF\nTip document: Rovinieta\nSerie / numar: -\nData expirare: 15.07.2026\nStatus: Expira in 30 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=493\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 493, \"document_id\": 493}', '2026-06-15 15:57:02', '2026-06-15 15:59:12'),
(67, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:569:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 30 zile: RCA - B 875 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 875 NET\nDetalii: MERCEDES 212\nTip document: RCA\nSerie / numar: -\nData expirare: 15.07.2026\nStatus: Expira in 30 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=569\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 569, \"document_id\": 569}', '2026-06-15 15:57:02', '2026-06-15 15:59:15'),
(68, 'fleet_rule', 'rule:2:driver_document_expiry:driver:221:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 2 zile: ADR - Brie-Bonchis Marius', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nSofer: Brie-Bonchis Marius\nDetalii: B 385 NET\nTip document: ADR\nSerie / numar: 176372\nData expirare: 17.06.2026\nStatus: Expira in 2 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=221\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 2, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 221, \"document_id\": 221}', '2026-06-15 15:57:02', '2026-06-15 15:59:19'),
(69, 'fleet_rule', 'rule:2:driver_document_expiry:driver:216:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 10 zile: AVIZ MEDICAL - Brie-Bonchis Marius', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nSofer: Brie-Bonchis Marius\nDetalii: B 385 NET\nTip document: AVIZ MEDICAL\nSerie / numar: -\nData expirare: 25.06.2026\nStatus: Expira in 10 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=216\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 2, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 216, \"document_id\": 216}', '2026-06-15 15:57:02', '2026-06-15 15:59:21'),
(70, 'fleet_rule', 'rule:2:driver_document_expiry:driver:217:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 10 zile: AVIZ PSIHOLOGIC - Brie-Bonchis Marius', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nSofer: Brie-Bonchis Marius\nDetalii: B 385 NET\nTip document: AVIZ PSIHOLOGIC\nSerie / numar: -\nData expirare: 25.06.2026\nStatus: Expira in 10 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=217\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 2, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 217, \"document_id\": 217}', '2026-06-15 15:57:02', '2026-06-15 15:59:24'),
(71, 'fleet_rule', 'rule:2:driver_document_expiry:driver:218:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 10 zile: MEDICINA MUNCII - Brie-Bonchis Marius', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nSofer: Brie-Bonchis Marius\nDetalii: B 385 NET\nTip document: MEDICINA MUNCII\nSerie / numar: -\nData expirare: 25.06.2026\nStatus: Expira in 10 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=218\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 2, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 218, \"document_id\": 218}', '2026-06-15 15:57:02', '2026-06-15 15:59:26'),
(72, 'fleet_rule', 'rule:2:driver_document_expiry:driver:184:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 12 zile: AVIZ PSIHOLOGIC - Erdos Zoltan', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nSofer: Erdos Zoltan\nDetalii: B 677 NET\nTip document: AVIZ PSIHOLOGIC\nSerie / numar: -\nData expirare: 27.06.2026\nStatus: Expira in 12 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=184\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 2, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 184, \"document_id\": 184}', '2026-06-15 15:57:02', '2026-06-15 15:59:29'),
(73, 'fleet_rule', 'rule:2:driver_document_expiry:driver:185:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 12 zile: AVIZ MEDICAL - Erdos Zoltan', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nSofer: Erdos Zoltan\nDetalii: B 677 NET\nTip document: AVIZ MEDICAL\nSerie / numar: -\nData expirare: 27.06.2026\nStatus: Expira in 12 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=185\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 2, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 185, \"document_id\": 185}', '2026-06-15 15:57:02', '2026-06-15 15:59:31'),
(74, 'fleet_rule', 'rule:2:driver_document_expiry:driver:223:2026-06-15', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 27 zile: BULETIN (C.I.) - Nicolae Florin', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nSofer: Nicolae Florin\nDetalii: B 652 NET\nTip document: BULETIN (C.I.)\nSerie / numar: 1940712297251\nData expirare: 12.07.2026\nStatus: Expira in 27 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=223\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 2, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 223, \"document_id\": 223}', '2026-06-15 15:57:02', '2026-06-15 15:59:34'),
(76, 'auth_login_code', 'user:1', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', 'Cod verificare autentificare - Fleet Management MVP', 'Cod de verificare autentificare redactat pentru securitate.', 'sent', 'gmail', 'Email accepted by gmail SMTP on attempt 1/2.', NULL, '{\"smtp_host\":\"smtp.gmail.com\",\"smtp_port\":587,\"smtp_encryption\":\"tls\",\"smtp_username\":\"lp**********@gmail.com\",\"from_address\":\"gi*************@lpg-auto.ro\",\"return_path\":\"gi*************@lpg-auto.ro\",\"recipient_domain\":\"lpg-auto.ro\",\"warnings\":[\"Gmail poate respinge MAIL_FROM_ADDRESS daca nu este configurat ca alias pentru MAIL_USERNAME.\"],\"attempts\":1,\"duration_ms\":3793}', '{\"user_id\":1,\"ttl_seconds\":600,\"purpose\":\"login_verification\"}', '2026-06-16 08:49:50', '2026-06-16 08:49:50'),
(77, 'auth_login_code', 'user:3', 'email', 'alexandra.iordache@lpg-auto.ro', 'Alexandra Iordache', 'Cod verificare autentificare - Fleet Management MVP', 'Cod de verificare autentificare redactat pentru securitate.', 'sent', 'gmail', 'Email accepted by gmail SMTP on attempt 1/2.', NULL, '{\"smtp_host\":\"smtp.gmail.com\",\"smtp_port\":587,\"smtp_encryption\":\"tls\",\"smtp_username\":\"lp**********@gmail.com\",\"from_address\":\"gi*************@lpg-auto.ro\",\"return_path\":\"gi*************@lpg-auto.ro\",\"recipient_domain\":\"lpg-auto.ro\",\"warnings\":[\"Gmail poate respinge MAIL_FROM_ADDRESS daca nu este configurat ca alias pentru MAIL_USERNAME.\"],\"attempts\":1,\"duration_ms\":3231}', '{\"user_id\":3,\"ttl_seconds\":600,\"purpose\":\"login_verification\"}', '2026-06-16 08:52:14', '2026-06-16 08:52:14'),
(78, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:100:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira astazi: RCA - B 635 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 635 NET\nDetalii: DAF XF\nTip document: RCA\nSerie / numar: -\nData expirare: 16.06.2026\nStatus: Expira astazi\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=100\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 100, \"document_id\": 100}', '2026-06-16 08:57:47', '2026-06-16 08:57:50'),
(79, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:439:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 3 zile: Rovinieta - B 218 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 218 NET\nDetalii: SCANIA R380\nTip document: Rovinieta\nSerie / numar: -\nData expirare: 19.06.2026\nStatus: Expira in 3 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=439\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 439, \"document_id\": 439}', '2026-06-16 08:57:47', '2026-06-16 08:57:52'),
(80, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:343:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 10 zile: ADR - B 430 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 430 NET\nDetalii: VOLVO FM400\nTip document: ADR\nSerie / numar: -\nData expirare: 26.06.2026\nStatus: Expira in 10 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=343\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 343, \"document_id\": 343}', '2026-06-16 08:57:47', '2026-06-16 08:57:55'),
(81, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:349:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 10 zile: IPROCHIM - B 430 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 430 NET\nDetalii: VOLVO FM400\nTip document: IPROCHIM\nSerie / numar: -\nData expirare: 26.06.2026\nStatus: Expira in 10 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=349\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 349, \"document_id\": 349}', '2026-06-16 08:57:47', '2026-06-16 08:57:58'),
(82, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:350:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 10 zile: ITP - B 430 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 430 NET\nDetalii: VOLVO FM400\nTip document: ITP\nSerie / numar: -\nData expirare: 26.06.2026\nStatus: Expira in 10 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=350\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 350, \"document_id\": 350}', '2026-06-16 08:57:47', '2026-06-16 08:58:00'),
(83, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:311:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 13 zile: Rovinieta - B 435 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 435 NET\nDetalii: MAN TGA\nTip document: Rovinieta\nSerie / numar: -\nData expirare: 29.06.2026\nStatus: Expira in 13 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=311\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 311, \"document_id\": 311}', '2026-06-16 08:57:47', '2026-06-16 08:58:03'),
(84, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:43:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 14 zile: RCA - B 285 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 285 NET\nDetalii: Mercedes ATEGO\nTip document: RCA\nSerie / numar: -\nData expirare: 30.06.2026\nStatus: Expira in 14 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=43\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 43, \"document_id\": 43}', '2026-06-16 08:57:47', '2026-06-16 08:58:06'),
(85, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:48:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 18 zile: ITP - B 375 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 375 NET\nDetalii: VOLVO FH440\nTip document: ITP\nSerie / numar: -\nData expirare: 04.07.2026\nStatus: Expira in 18 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=48\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 48, \"document_id\": 48}', '2026-06-16 08:57:47', '2026-06-16 08:58:08'),
(86, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:529:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 18 zile: ADR - B 375 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 375 NET\nDetalii: VOLVO FH440\nTip document: ADR\nSerie / numar: -\nData expirare: 04.07.2026\nStatus: Expira in 18 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=529\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 529, \"document_id\": 529}', '2026-06-16 08:57:47', '2026-06-16 08:58:12'),
(87, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:441:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 21 zile: ADR - B 325 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 325 NET\nDetalii: DAF CF\nTip document: ADR\nSerie / numar: -\nData expirare: 07.07.2026\nStatus: Expira in 21 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=441\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 441, \"document_id\": 441}', '2026-06-16 08:57:47', '2026-06-16 08:58:15'),
(88, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:444:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 21 zile: IPROCHIM - B 325 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 325 NET\nDetalii: DAF CF\nTip document: IPROCHIM\nSerie / numar: -\nData expirare: 07.07.2026\nStatus: Expira in 21 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=444\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 444, \"document_id\": 444}', '2026-06-16 08:57:47', '2026-06-16 08:58:17'),
(89, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:445:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 21 zile: ITP - B 325 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 325 NET\nDetalii: DAF CF\nTip document: ITP\nSerie / numar: -\nData expirare: 07.07.2026\nStatus: Expira in 21 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=445\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 445, \"document_id\": 445}', '2026-06-16 08:57:47', '2026-06-16 08:58:19'),
(90, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:555:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 21 zile: Rovinieta - B 72 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 72 NET\nDetalii: DACIA SANDERO\nTip document: Rovinieta\nSerie / numar: -\nData expirare: 07.07.2026\nStatus: Expira in 21 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=555\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 555, \"document_id\": 555}', '2026-06-16 08:57:47', '2026-06-16 08:58:22'),
(91, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:76:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 25 zile: Carte - B 835 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 835 NET\nDetalii: LDS NCX\nTip document: Carte\nSerie / numar: -\nData expirare: 11.07.2026\nStatus: Expira in 25 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=76\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 76, \"document_id\": 76}', '2026-06-16 08:57:47', '2026-06-16 08:58:25'),
(92, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:84:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 25 zile: Carte - B 645 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 645 NET\nDetalii: DAF XF\nTip document: Carte\nSerie / numar: -\nData expirare: 11.07.2026\nStatus: Expira in 25 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=84\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 84, \"document_id\": 84}', '2026-06-16 08:57:47', '2026-06-16 08:58:27'),
(93, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:91:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 25 zile: Carte - B 915 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 915 NET\nDetalii: VPS VPSCN\nTip document: Carte\nSerie / numar: -\nData expirare: 11.07.2026\nStatus: Expira in 25 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=91\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 91, \"document_id\": 91}', '2026-06-16 08:57:47', '2026-06-16 09:14:05'),
(94, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:97:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 25 zile: Carte - B 635 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 635 NET\nDetalii: DAF XF\nTip document: Carte\nSerie / numar: -\nData expirare: 11.07.2026\nStatus: Expira in 25 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=97\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 97, \"document_id\": 97}', '2026-06-16 08:57:47', '2026-06-16 08:59:05'),
(95, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:103:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 25 zile: Carte - B 845 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 845 NET\nDetalii: VPS VPSCN\nTip document: Carte\nSerie / numar: -\nData expirare: 11.07.2026\nStatus: Expira in 25 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=103\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 103, \"document_id\": 103}', '2026-06-16 08:57:47', '2026-06-16 08:59:07'),
(96, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:109:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 25 zile: Carte - B 625 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 625 NET\nDetalii: DAF CF\nTip document: Carte\nSerie / numar: -\nData expirare: 11.07.2026\nStatus: Expira in 25 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=109\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 109, \"document_id\": 109}', '2026-06-16 08:57:47', '2026-06-16 08:59:10'),
(97, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:116:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 25 zile: Carte - B 825 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 825 NET\nDetalii: VPS VPSCN\nTip document: Carte\nSerie / numar: -\nData expirare: 11.07.2026\nStatus: Expira in 25 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=116\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 116, \"document_id\": 116}', '2026-06-16 08:57:47', '2026-06-16 08:59:12'),
(98, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:123:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 25 zile: Carte - B 615 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 615 NET\nDetalii: DAF XF\nTip document: Carte\nSerie / numar: -\nData expirare: 11.07.2026\nStatus: Expira in 25 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=123\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 123, \"document_id\": 123}', '2026-06-16 08:57:47', '2026-06-16 08:59:15'),
(99, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:131:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 25 zile: Carte - B 705 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 705 NET\nDetalii: VPS VPSCN\nTip document: Carte\nSerie / numar: -\nData expirare: 11.07.2026\nStatus: Expira in 25 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=131\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 131, \"document_id\": 131}', '2026-06-16 08:57:47', '2026-06-16 08:59:17'),
(100, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:139:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 25 zile: Carte - B 905 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 905 NET\nDetalii: VPS VPSCN\nTip document: Carte\nSerie / numar: -\nData expirare: 11.07.2026\nStatus: Expira in 25 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=139\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 139, \"document_id\": 139}', '2026-06-16 08:57:47', '2026-06-16 08:59:20'),
(101, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:145:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 25 zile: Carte - B 402 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 402 NET\nDetalii: DAF CF\nTip document: Carte\nSerie / numar: -\nData expirare: 11.07.2026\nStatus: Expira in 25 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=145\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 145, \"document_id\": 145}', '2026-06-16 08:57:47', '2026-06-16 08:59:22');
INSERT INTO `notification_deliveries` (`id`, `context`, `context_id`, `channel`, `recipient_email`, `recipient_name`, `subject`, `message`, `status`, `provider`, `provider_response`, `error_message`, `diagnostics_json`, `metadata_json`, `created_at`, `sent_at`) VALUES
(102, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:160:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 25 zile: Carte - B 401 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 401 NET\nDetalii: DAF CF\nTip document: Carte\nSerie / numar: -\nData expirare: 11.07.2026\nStatus: Expira in 25 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=160\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 160, \"document_id\": 160}', '2026-06-16 08:57:47', '2026-06-16 08:59:25'),
(103, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:166:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 25 zile: Carte - B 925 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 925 NET\nDetalii: VPS VPSCN\nTip document: Carte\nSerie / numar: -\nData expirare: 11.07.2026\nStatus: Expira in 25 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=166\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 166, \"document_id\": 166}', '2026-06-16 08:57:47', '2026-06-16 08:59:28'),
(104, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:159:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 26 zile: ADR - B 401 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 401 NET\nDetalii: DAF CF\nTip document: ADR\nSerie / numar: -\nData expirare: 12.07.2026\nStatus: Expira in 26 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=159\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 159, \"document_id\": 159}', '2026-06-16 08:57:47', '2026-06-16 08:59:30'),
(105, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:162:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 26 zile: ITP - B 401 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 401 NET\nDetalii: DAF CF\nTip document: ITP\nSerie / numar: -\nData expirare: 12.07.2026\nStatus: Expira in 26 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=162\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 162, \"document_id\": 162}', '2026-06-16 08:57:47', '2026-06-16 08:59:33'),
(106, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:493:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 29 zile: Rovinieta - B 635 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 635 NET\nDetalii: DAF XF\nTip document: Rovinieta\nSerie / numar: -\nData expirare: 15.07.2026\nStatus: Expira in 29 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=493\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 493, \"document_id\": 493}', '2026-06-16 08:57:47', '2026-06-16 08:59:35'),
(107, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:569:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 29 zile: RCA - B 875 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 875 NET\nDetalii: MERCEDES 212\nTip document: RCA\nSerie / numar: -\nData expirare: 15.07.2026\nStatus: Expira in 29 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=569\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 569, \"document_id\": 569}', '2026-06-16 08:57:47', '2026-06-16 08:59:38'),
(108, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:129:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 30 zile: ADR - B 705 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 705 NET\nDetalii: VPS VPSCN\nTip document: ADR\nSerie / numar: -\nData expirare: 16.07.2026\nStatus: Expira in 30 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=129\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 129, \"document_id\": 129}', '2026-06-16 08:57:47', '2026-06-16 08:59:40'),
(109, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:133:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 30 zile: IPROCHIM - B 705 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 705 NET\nDetalii: VPS VPSCN\nTip document: IPROCHIM\nSerie / numar: -\nData expirare: 16.07.2026\nStatus: Expira in 30 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=133\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 133, \"document_id\": 133}', '2026-06-16 08:57:47', '2026-06-16 08:59:43'),
(110, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:134:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 30 zile: ITP - B 705 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 705 NET\nDetalii: VPS VPSCN\nTip document: ITP\nSerie / numar: -\nData expirare: 16.07.2026\nStatus: Expira in 30 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=134\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 134, \"document_id\": 134}', '2026-06-16 08:57:47', '2026-06-16 08:59:45'),
(111, 'fleet_rule', 'rule:2:driver_document_expiry:driver:221:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 1 zile: ADR - Brie-Bonchis Marius', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nSofer: Brie-Bonchis Marius\nDetalii: B 385 NET\nTip document: ADR\nSerie / numar: 176372\nData expirare: 17.06.2026\nStatus: Expira in 1 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=221\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 2, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 221, \"document_id\": 221}', '2026-06-16 08:57:47', '2026-06-16 08:59:48'),
(112, 'fleet_rule', 'rule:2:driver_document_expiry:driver:216:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 9 zile: AVIZ MEDICAL - Brie-Bonchis Marius', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nSofer: Brie-Bonchis Marius\nDetalii: B 385 NET\nTip document: AVIZ MEDICAL\nSerie / numar: -\nData expirare: 25.06.2026\nStatus: Expira in 9 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=216\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 2, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 216, \"document_id\": 216}', '2026-06-16 08:57:47', '2026-06-16 08:59:50'),
(113, 'fleet_rule', 'rule:2:driver_document_expiry:driver:217:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 9 zile: AVIZ PSIHOLOGIC - Brie-Bonchis Marius', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nSofer: Brie-Bonchis Marius\nDetalii: B 385 NET\nTip document: AVIZ PSIHOLOGIC\nSerie / numar: -\nData expirare: 25.06.2026\nStatus: Expira in 9 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=217\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 2, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 217, \"document_id\": 217}', '2026-06-16 08:57:47', '2026-06-16 08:59:54'),
(114, 'fleet_rule', 'rule:2:driver_document_expiry:driver:218:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 9 zile: MEDICINA MUNCII - Brie-Bonchis Marius', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nSofer: Brie-Bonchis Marius\nDetalii: B 385 NET\nTip document: MEDICINA MUNCII\nSerie / numar: -\nData expirare: 25.06.2026\nStatus: Expira in 9 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=218\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 2, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 218, \"document_id\": 218}', '2026-06-16 08:57:47', '2026-06-16 08:59:56'),
(115, 'fleet_rule', 'rule:2:driver_document_expiry:driver:184:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 11 zile: AVIZ PSIHOLOGIC - Erdos Zoltan', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nSofer: Erdos Zoltan\nDetalii: B 677 NET\nTip document: AVIZ PSIHOLOGIC\nSerie / numar: -\nData expirare: 27.06.2026\nStatus: Expira in 11 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=184\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 2, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 184, \"document_id\": 184}', '2026-06-16 08:57:47', '2026-06-16 08:59:59'),
(116, 'fleet_rule', 'rule:2:driver_document_expiry:driver:185:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 11 zile: AVIZ MEDICAL - Erdos Zoltan', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nSofer: Erdos Zoltan\nDetalii: B 677 NET\nTip document: AVIZ MEDICAL\nSerie / numar: -\nData expirare: 27.06.2026\nStatus: Expira in 11 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=185\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 2, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 185, \"document_id\": 185}', '2026-06-16 08:57:47', '2026-06-16 09:00:02'),
(117, 'fleet_rule', 'rule:2:driver_document_expiry:driver:223:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 26 zile: BULETIN (C.I.) - Nicolae Florin', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nSofer: Nicolae Florin\nDetalii: B 652 NET\nTip document: BULETIN (C.I.)\nSerie / numar: 1940712297251\nData expirare: 12.07.2026\nStatus: Expira in 26 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=223\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 2, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 223, \"document_id\": 223}', '2026-06-16 08:57:47', '2026-06-16 09:00:04'),
(118, 'notification_test', 'manual:20260616095453', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', 'Test notificari - Fleet Management MVP', 'Salut,\n\nAcesta este un test al noului sistem de notificari.\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"triggered_by_user_id\":1}', '2026-06-16 09:54:53', '2026-06-16 09:55:04'),
(119, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:301:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 29 zile: Rovinieta - B 605 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 605 NET\nDetalii: DAF XF\nTip document: Rovinieta\nSerie / numar: -\nData expirare: 15.07.2026\nStatus: Expira in 29 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=301\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 301, \"document_id\": 301}', '2026-06-16 10:10:02', '2026-06-16 10:10:04'),
(120, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:152:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 25 zile: Carte - B 815 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 815 NET\nDetalii: VPS VPSCN\nTip document: Carte\nSerie / numar: -\nData expirare: 11.07.2026\nStatus: Expira in 25 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=152\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 152, \"document_id\": 152}', '2026-06-16 10:16:02', '2026-06-16 10:16:04'),
(121, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:572:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 16 zile: ITP - B 669 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 669 NET\nDetalii: DACIA LOGAN\nTip document: ITP\nSerie / numar: -\nData expirare: 02.07.2026\nStatus: Expira in 16 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=572\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 572, \"document_id\": 572}', '2026-06-16 10:19:02', '2026-06-16 10:19:04'),
(122, 'fleet_rule', 'rule:1:vehicle_document_expiry:vehicle:581:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 28 zile: RCA - B 230 NET', 'Salut, Administrator Sistem,\n\nExista o notificare pentru un document din flota.\n\nVehicul: B 230 NET\nDetalii: DACIA DBG\nTip document: RCA\nSerie / numar: -\nData expirare: 14.07.2026\nStatus: Expira in 28 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=581\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 1, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 581, \"document_id\": 581}', '2026-06-16 10:19:02', '2026-06-16 10:19:07'),
(123, 'fleet_rule', 'rule:3:vehicle_document_expiry:vehicle:43:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 14 zile: RCA - B 285 NET', 'Salut, Administrator Sistem,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 285 NET\nDetalii: Mercedes ATEGO\nTip: RCA\nData tinta: 30.06.2026\nStatus: Expira in 14 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=43\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 3, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 43, \"document_id\": 43}', '2026-06-16 11:35:02', '2026-06-16 11:35:05'),
(124, 'fleet_rule', 'rule:3:vehicle_document_expiry:vehicle:43:2026-06-16', 'email', 'office@lpg-auto.ro', 'Adriana Ungurean', '[Fleet Management MVP] Expira in 14 zile: RCA - B 285 NET', 'Salut, Adriana Ungurean,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 285 NET\nDetalii: Mercedes ATEGO\nTip: RCA\nData tinta: 30.06.2026\nStatus: Expira in 14 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=43\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 3, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 43, \"document_id\": 43}', '2026-06-16 11:35:02', '2026-06-16 11:35:08'),
(125, 'fleet_rule', 'rule:3:vehicle_document_expiry:vehicle:43:2026-06-16', 'email', 'alexandra.iordache@lpg-auto.ro', 'Alexandra Iordache', '[Fleet Management MVP] Expira in 14 zile: RCA - B 285 NET', 'Salut, Alexandra Iordache,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 285 NET\nDetalii: Mercedes ATEGO\nTip: RCA\nData tinta: 30.06.2026\nStatus: Expira in 14 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=43\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 3, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 43, \"document_id\": 43}', '2026-06-16 11:35:02', '2026-06-16 11:35:10'),
(126, 'fleet_rule', 'rule:3:vehicle_document_expiry:vehicle:581:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 28 zile: RCA - B 230 NET', 'Salut, Administrator Sistem,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 230 NET\nDetalii: DACIA DBG\nTip: RCA\nData tinta: 14.07.2026\nStatus: Expira in 28 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=581\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 3, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 581, \"document_id\": 581}', '2026-06-16 11:35:02', '2026-06-16 11:35:13'),
(127, 'fleet_rule', 'rule:3:vehicle_document_expiry:vehicle:581:2026-06-16', 'email', 'office@lpg-auto.ro', 'Adriana Ungurean', '[Fleet Management MVP] Expira in 28 zile: RCA - B 230 NET', 'Salut, Adriana Ungurean,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 230 NET\nDetalii: DACIA DBG\nTip: RCA\nData tinta: 14.07.2026\nStatus: Expira in 28 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=581\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 3, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 581, \"document_id\": 581}', '2026-06-16 11:35:02', '2026-06-16 11:35:15'),
(128, 'fleet_rule', 'rule:3:vehicle_document_expiry:vehicle:581:2026-06-16', 'email', 'alexandra.iordache@lpg-auto.ro', 'Alexandra Iordache', '[Fleet Management MVP] Expira in 28 zile: RCA - B 230 NET', 'Salut, Alexandra Iordache,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 230 NET\nDetalii: DACIA DBG\nTip: RCA\nData tinta: 14.07.2026\nStatus: Expira in 28 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=581\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 3, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 581, \"document_id\": 581}', '2026-06-16 11:35:02', '2026-06-16 11:35:18'),
(129, 'fleet_rule', 'rule:3:vehicle_document_expiry:vehicle:569:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 29 zile: RCA - B 875 NET', 'Salut, Administrator Sistem,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 875 NET\nDetalii: MERCEDES 212\nTip: RCA\nData tinta: 15.07.2026\nStatus: Expira in 29 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=569\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 3, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 569, \"document_id\": 569}', '2026-06-16 11:35:02', '2026-06-16 11:35:20'),
(130, 'fleet_rule', 'rule:3:vehicle_document_expiry:vehicle:569:2026-06-16', 'email', 'office@lpg-auto.ro', 'Adriana Ungurean', '[Fleet Management MVP] Expira in 29 zile: RCA - B 875 NET', 'Salut, Adriana Ungurean,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 875 NET\nDetalii: MERCEDES 212\nTip: RCA\nData tinta: 15.07.2026\nStatus: Expira in 29 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=569\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 3, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 569, \"document_id\": 569}', '2026-06-16 11:35:02', '2026-06-16 11:35:23'),
(131, 'fleet_rule', 'rule:3:vehicle_document_expiry:vehicle:569:2026-06-16', 'email', 'alexandra.iordache@lpg-auto.ro', 'Alexandra Iordache', '[Fleet Management MVP] Expira in 29 zile: RCA - B 875 NET', 'Salut, Alexandra Iordache,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 875 NET\nDetalii: MERCEDES 212\nTip: RCA\nData tinta: 15.07.2026\nStatus: Expira in 29 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=569\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 3, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 569, \"document_id\": 569}', '2026-06-16 11:35:02', '2026-06-16 11:35:25'),
(132, 'fleet_rule', 'rule:5:vehicle_document_expiry:vehicle:350:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 10 zile: ITP - B 430 NET', 'Salut, Administrator Sistem,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 430 NET\nDetalii: VOLVO FM400\nTip: ITP\nData tinta: 26.06.2026\nStatus: Expira in 10 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=350\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 5, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 350, \"document_id\": 350}', '2026-06-16 11:36:02', '2026-06-16 11:36:05'),
(133, 'fleet_rule', 'rule:5:vehicle_document_expiry:vehicle:350:2026-06-16', 'email', 'office@lpg-auto.ro', 'Adriana Ungurean', '[Fleet Management MVP] Expira in 10 zile: ITP - B 430 NET', 'Salut, Adriana Ungurean,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 430 NET\nDetalii: VOLVO FM400\nTip: ITP\nData tinta: 26.06.2026\nStatus: Expira in 10 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=350\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 5, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 350, \"document_id\": 350}', '2026-06-16 11:36:02', '2026-06-16 11:36:08'),
(134, 'fleet_rule', 'rule:5:vehicle_document_expiry:vehicle:350:2026-06-16', 'email', 'alexandra.iordache@lpg-auto.ro', 'Alexandra Iordache', '[Fleet Management MVP] Expira in 10 zile: ITP - B 430 NET', 'Salut, Alexandra Iordache,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 430 NET\nDetalii: VOLVO FM400\nTip: ITP\nData tinta: 26.06.2026\nStatus: Expira in 10 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=350\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 5, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 350, \"document_id\": 350}', '2026-06-16 11:36:02', '2026-06-16 11:36:11'),
(135, 'fleet_rule', 'rule:5:vehicle_document_expiry:vehicle:572:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 16 zile: ITP - B 669 NET', 'Salut, Administrator Sistem,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 669 NET\nDetalii: DACIA LOGAN\nTip: ITP\nData tinta: 02.07.2026\nStatus: Expira in 16 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=572\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 5, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 572, \"document_id\": 572}', '2026-06-16 11:36:02', '2026-06-16 11:36:13'),
(136, 'fleet_rule', 'rule:5:vehicle_document_expiry:vehicle:572:2026-06-16', 'email', 'office@lpg-auto.ro', 'Adriana Ungurean', '[Fleet Management MVP] Expira in 16 zile: ITP - B 669 NET', 'Salut, Adriana Ungurean,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 669 NET\nDetalii: DACIA LOGAN\nTip: ITP\nData tinta: 02.07.2026\nStatus: Expira in 16 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=572\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 5, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 572, \"document_id\": 572}', '2026-06-16 11:36:02', '2026-06-16 11:36:16'),
(137, 'fleet_rule', 'rule:5:vehicle_document_expiry:vehicle:572:2026-06-16', 'email', 'alexandra.iordache@lpg-auto.ro', 'Alexandra Iordache', '[Fleet Management MVP] Expira in 16 zile: ITP - B 669 NET', 'Salut, Alexandra Iordache,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 669 NET\nDetalii: DACIA LOGAN\nTip: ITP\nData tinta: 02.07.2026\nStatus: Expira in 16 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=572\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 5, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 572, \"document_id\": 572}', '2026-06-16 11:36:02', '2026-06-16 11:36:19'),
(138, 'fleet_rule', 'rule:5:vehicle_document_expiry:vehicle:48:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 18 zile: ITP - B 375 NET', 'Salut, Administrator Sistem,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 375 NET\nDetalii: VOLVO FH440\nTip: ITP\nData tinta: 04.07.2026\nStatus: Expira in 18 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=48\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 5, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 48, \"document_id\": 48}', '2026-06-16 11:36:02', '2026-06-16 11:36:22'),
(139, 'fleet_rule', 'rule:5:vehicle_document_expiry:vehicle:48:2026-06-16', 'email', 'office@lpg-auto.ro', 'Adriana Ungurean', '[Fleet Management MVP] Expira in 18 zile: ITP - B 375 NET', 'Salut, Adriana Ungurean,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 375 NET\nDetalii: VOLVO FH440\nTip: ITP\nData tinta: 04.07.2026\nStatus: Expira in 18 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=48\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 5, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 48, \"document_id\": 48}', '2026-06-16 11:36:02', '2026-06-16 11:36:24'),
(140, 'fleet_rule', 'rule:5:vehicle_document_expiry:vehicle:48:2026-06-16', 'email', 'alexandra.iordache@lpg-auto.ro', 'Alexandra Iordache', '[Fleet Management MVP] Expira in 18 zile: ITP - B 375 NET', 'Salut, Alexandra Iordache,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 375 NET\nDetalii: VOLVO FH440\nTip: ITP\nData tinta: 04.07.2026\nStatus: Expira in 18 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=48\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 5, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 48, \"document_id\": 48}', '2026-06-16 11:36:02', '2026-06-16 11:36:27'),
(141, 'fleet_rule', 'rule:5:vehicle_document_expiry:vehicle:445:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 21 zile: ITP - B 325 NET', 'Salut, Administrator Sistem,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 325 NET\nDetalii: DAF CF\nTip: ITP\nData tinta: 07.07.2026\nStatus: Expira in 21 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=445\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 5, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 445, \"document_id\": 445}', '2026-06-16 11:36:02', '2026-06-16 11:36:30'),
(142, 'fleet_rule', 'rule:5:vehicle_document_expiry:vehicle:445:2026-06-16', 'email', 'office@lpg-auto.ro', 'Adriana Ungurean', '[Fleet Management MVP] Expira in 21 zile: ITP - B 325 NET', 'Salut, Adriana Ungurean,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 325 NET\nDetalii: DAF CF\nTip: ITP\nData tinta: 07.07.2026\nStatus: Expira in 21 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=445\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 5, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 445, \"document_id\": 445}', '2026-06-16 11:36:02', '2026-06-16 11:36:33'),
(143, 'fleet_rule', 'rule:5:vehicle_document_expiry:vehicle:445:2026-06-16', 'email', 'alexandra.iordache@lpg-auto.ro', 'Alexandra Iordache', '[Fleet Management MVP] Expira in 21 zile: ITP - B 325 NET', 'Salut, Alexandra Iordache,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 325 NET\nDetalii: DAF CF\nTip: ITP\nData tinta: 07.07.2026\nStatus: Expira in 21 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=445\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 5, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 445, \"document_id\": 445}', '2026-06-16 11:36:02', '2026-06-16 11:36:35'),
(144, 'fleet_rule', 'rule:5:vehicle_document_expiry:vehicle:162:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 26 zile: ITP - B 401 NET', 'Salut, Administrator Sistem,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 401 NET\nDetalii: DAF CF\nTip: ITP\nData tinta: 12.07.2026\nStatus: Expira in 26 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=162\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 5, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 162, \"document_id\": 162}', '2026-06-16 11:36:02', '2026-06-16 11:36:38'),
(145, 'fleet_rule', 'rule:5:vehicle_document_expiry:vehicle:162:2026-06-16', 'email', 'office@lpg-auto.ro', 'Adriana Ungurean', '[Fleet Management MVP] Expira in 26 zile: ITP - B 401 NET', 'Salut, Adriana Ungurean,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 401 NET\nDetalii: DAF CF\nTip: ITP\nData tinta: 12.07.2026\nStatus: Expira in 26 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=162\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 5, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 162, \"document_id\": 162}', '2026-06-16 11:36:02', '2026-06-16 11:36:40'),
(146, 'fleet_rule', 'rule:5:vehicle_document_expiry:vehicle:162:2026-06-16', 'email', 'alexandra.iordache@lpg-auto.ro', 'Alexandra Iordache', '[Fleet Management MVP] Expira in 26 zile: ITP - B 401 NET', 'Salut, Alexandra Iordache,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 401 NET\nDetalii: DAF CF\nTip: ITP\nData tinta: 12.07.2026\nStatus: Expira in 26 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=162\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 5, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 162, \"document_id\": 162}', '2026-06-16 11:36:02', '2026-06-16 11:36:43'),
(147, 'fleet_rule', 'rule:5:vehicle_document_expiry:vehicle:134:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 30 zile: ITP - B 705 NET', 'Salut, Administrator Sistem,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 705 NET\nDetalii: VPS VPSCN\nTip: ITP\nData tinta: 16.07.2026\nStatus: Expira in 30 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=134\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 5, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 134, \"document_id\": 134}', '2026-06-16 11:36:02', '2026-06-16 11:36:45'),
(148, 'fleet_rule', 'rule:5:vehicle_document_expiry:vehicle:134:2026-06-16', 'email', 'office@lpg-auto.ro', 'Adriana Ungurean', '[Fleet Management MVP] Expira in 30 zile: ITP - B 705 NET', 'Salut, Adriana Ungurean,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 705 NET\nDetalii: VPS VPSCN\nTip: ITP\nData tinta: 16.07.2026\nStatus: Expira in 30 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=134\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 5, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 134, \"document_id\": 134}', '2026-06-16 11:36:02', '2026-06-16 11:36:48'),
(149, 'fleet_rule', 'rule:5:vehicle_document_expiry:vehicle:134:2026-06-16', 'email', 'alexandra.iordache@lpg-auto.ro', 'Alexandra Iordache', '[Fleet Management MVP] Expira in 30 zile: ITP - B 705 NET', 'Salut, Alexandra Iordache,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 705 NET\nDetalii: VPS VPSCN\nTip: ITP\nData tinta: 16.07.2026\nStatus: Expira in 30 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=134\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 5, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 134, \"document_id\": 134}', '2026-06-16 11:36:02', '2026-06-16 11:36:51'),
(150, 'fleet_rule', 'rule:5:vehicle_document_expiry:vehicle:217:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 31 zile: ITP - B 199 NET', 'Salut, Administrator Sistem,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 199 NET\nDetalii: SCANIA G400\nTip: ITP\nData tinta: 17.07.2026\nStatus: Expira in 31 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=217\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 5, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 217, \"document_id\": 217}', '2026-06-16 11:36:02', '2026-06-16 11:36:53'),
(151, 'fleet_rule', 'rule:5:vehicle_document_expiry:vehicle:217:2026-06-16', 'email', 'office@lpg-auto.ro', 'Adriana Ungurean', '[Fleet Management MVP] Expira in 31 zile: ITP - B 199 NET', 'Salut, Adriana Ungurean,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 199 NET\nDetalii: SCANIA G400\nTip: ITP\nData tinta: 17.07.2026\nStatus: Expira in 31 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=217\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 5, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 217, \"document_id\": 217}', '2026-06-16 11:36:02', '2026-06-16 11:36:56'),
(152, 'fleet_rule', 'rule:5:vehicle_document_expiry:vehicle:217:2026-06-16', 'email', 'alexandra.iordache@lpg-auto.ro', 'Alexandra Iordache', '[Fleet Management MVP] Expira in 31 zile: ITP - B 199 NET', 'Salut, Alexandra Iordache,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 199 NET\nDetalii: SCANIA G400\nTip: ITP\nData tinta: 17.07.2026\nStatus: Expira in 31 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=217\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 5, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 217, \"document_id\": 217}', '2026-06-16 11:36:02', '2026-06-16 11:36:58'),
(153, 'fleet_rule', 'rule:5:vehicle_document_expiry:vehicle:299:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 31 zile: ITP - B 605 NET', 'Salut, Administrator Sistem,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 605 NET\nDetalii: DAF XF\nTip: ITP\nData tinta: 17.07.2026\nStatus: Expira in 31 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=299\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 5, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 299, \"document_id\": 299}', '2026-06-16 11:36:02', '2026-06-16 11:37:01'),
(154, 'fleet_rule', 'rule:5:vehicle_document_expiry:vehicle:299:2026-06-16', 'email', 'office@lpg-auto.ro', 'Adriana Ungurean', '[Fleet Management MVP] Expira in 31 zile: ITP - B 605 NET', 'Salut, Adriana Ungurean,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 605 NET\nDetalii: DAF XF\nTip: ITP\nData tinta: 17.07.2026\nStatus: Expira in 31 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=299\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 5, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 299, \"document_id\": 299}', '2026-06-16 11:36:02', '2026-06-16 11:37:03'),
(155, 'fleet_rule', 'rule:5:vehicle_document_expiry:vehicle:299:2026-06-16', 'email', 'alexandra.iordache@lpg-auto.ro', 'Alexandra Iordache', '[Fleet Management MVP] Expira in 31 zile: ITP - B 605 NET', 'Salut, Alexandra Iordache,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 605 NET\nDetalii: DAF XF\nTip: ITP\nData tinta: 17.07.2026\nStatus: Expira in 31 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=299\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 5, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 299, \"document_id\": 299}', '2026-06-16 11:36:02', '2026-06-16 11:37:06'),
(156, 'fleet_rule', 'rule:6:vehicle_document_expiry:vehicle:439:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 3 zile: Rovinieta - B 218 NET', 'Salut, Administrator Sistem,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 218 NET\nDetalii: SCANIA R380\nTip: Rovinieta\nData tinta: 19.06.2026\nStatus: Expira in 3 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=439\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 6, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 439, \"document_id\": 439}', '2026-06-16 11:36:02', '2026-06-16 11:37:08'),
(157, 'fleet_rule', 'rule:6:vehicle_document_expiry:vehicle:439:2026-06-16', 'email', 'office@lpg-auto.ro', 'Adriana Ungurean', '[Fleet Management MVP] Expira in 3 zile: Rovinieta - B 218 NET', 'Salut, Adriana Ungurean,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 218 NET\nDetalii: SCANIA R380\nTip: Rovinieta\nData tinta: 19.06.2026\nStatus: Expira in 3 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=439\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 6, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 439, \"document_id\": 439}', '2026-06-16 11:36:02', '2026-06-16 11:38:05'),
(158, 'fleet_rule', 'rule:6:vehicle_document_expiry:vehicle:439:2026-06-16', 'email', 'alexandra.iordache@lpg-auto.ro', 'Alexandra Iordache', '[Fleet Management MVP] Expira in 3 zile: Rovinieta - B 218 NET', 'Salut, Alexandra Iordache,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 218 NET\nDetalii: SCANIA R380\nTip: Rovinieta\nData tinta: 19.06.2026\nStatus: Expira in 3 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=439\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 6, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 439, \"document_id\": 439}', '2026-06-16 11:36:02', '2026-06-16 11:38:08'),
(159, 'fleet_rule', 'rule:9:vehicle_document_expiry:vehicle:349:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 10 zile: IPROCHIM - B 430 NET', 'Salut, Administrator Sistem,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 430 NET\nDetalii: VOLVO FM400\nTip: IPROCHIM\nData tinta: 26.06.2026\nStatus: Expira in 10 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=349\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 9, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 349, \"document_id\": 349}', '2026-06-16 11:40:02', '2026-06-16 11:40:05'),
(160, 'fleet_rule', 'rule:9:vehicle_document_expiry:vehicle:349:2026-06-16', 'email', 'office@lpg-auto.ro', 'Adriana Ungurean', '[Fleet Management MVP] Expira in 10 zile: IPROCHIM - B 430 NET', 'Salut, Adriana Ungurean,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 430 NET\nDetalii: VOLVO FM400\nTip: IPROCHIM\nData tinta: 26.06.2026\nStatus: Expira in 10 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=349\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 9, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 349, \"document_id\": 349}', '2026-06-16 11:40:02', '2026-06-16 11:40:09'),
(161, 'fleet_rule', 'rule:9:vehicle_document_expiry:vehicle:349:2026-06-16', 'email', 'alexandra.iordache@lpg-auto.ro', 'Alexandra Iordache', '[Fleet Management MVP] Expira in 10 zile: IPROCHIM - B 430 NET', 'Salut, Alexandra Iordache,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 430 NET\nDetalii: VOLVO FM400\nTip: IPROCHIM\nData tinta: 26.06.2026\nStatus: Expira in 10 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=349\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 9, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 349, \"document_id\": 349}', '2026-06-16 11:40:02', '2026-06-16 11:40:11'),
(162, 'fleet_rule', 'rule:9:vehicle_document_expiry:vehicle:444:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 21 zile: IPROCHIM - B 325 NET', 'Salut, Administrator Sistem,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 325 NET\nDetalii: DAF CF\nTip: IPROCHIM\nData tinta: 07.07.2026\nStatus: Expira in 21 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=444\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 9, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 444, \"document_id\": 444}', '2026-06-16 11:40:02', '2026-06-16 11:40:14'),
(163, 'fleet_rule', 'rule:9:vehicle_document_expiry:vehicle:444:2026-06-16', 'email', 'office@lpg-auto.ro', 'Adriana Ungurean', '[Fleet Management MVP] Expira in 21 zile: IPROCHIM - B 325 NET', 'Salut, Adriana Ungurean,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 325 NET\nDetalii: DAF CF\nTip: IPROCHIM\nData tinta: 07.07.2026\nStatus: Expira in 21 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=444\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 9, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 444, \"document_id\": 444}', '2026-06-16 11:40:02', '2026-06-16 11:40:16'),
(164, 'fleet_rule', 'rule:9:vehicle_document_expiry:vehicle:444:2026-06-16', 'email', 'alexandra.iordache@lpg-auto.ro', 'Alexandra Iordache', '[Fleet Management MVP] Expira in 21 zile: IPROCHIM - B 325 NET', 'Salut, Alexandra Iordache,\n\nExista o notificare configurata in aplicatia de flota.\n\nVehicul: B 325 NET\nDetalii: DAF CF\nTip: IPROCHIM\nData tinta: 07.07.2026\nStatus: Expira in 21 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=444\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 9, \"event_type\": \"vehicle_document_expiry\", \"entity_type\": \"vehicle\", \"entity_id\": 444, \"document_id\": 444}', '2026-06-16 11:40:02', '2026-06-16 11:40:19'),
(165, 'fleet_rule', 'rule:12:driver_document_expiry:driver:223:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 26 zile: BULETIN (C.I.) - Nicolae Florin', 'Salut, Administrator Sistem,\n\nExista o notificare configurata in aplicatia de flota.\n\nSofer: Nicolae Florin\nDetalii: B 652 NET\nTip: BULETIN (C.I.)\nSerie / numar: 1940712297251\nData tinta: 12.07.2026\nStatus: Expira in 26 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=223\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 12, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 223, \"document_id\": 223}', '2026-06-16 11:46:02', '2026-06-16 11:46:05'),
(166, 'fleet_rule', 'rule:12:driver_document_expiry:driver:223:2026-06-16', 'email', 'office@lpg-auto.ro', 'Adriana Ungurean', '[Fleet Management MVP] Expira in 26 zile: BULETIN (C.I.) - Nicolae Florin', 'Salut, Adriana Ungurean,\n\nExista o notificare configurata in aplicatia de flota.\n\nSofer: Nicolae Florin\nDetalii: B 652 NET\nTip: BULETIN (C.I.)\nSerie / numar: 1940712297251\nData tinta: 12.07.2026\nStatus: Expira in 26 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=223\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 12, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 223, \"document_id\": 223}', '2026-06-16 11:46:02', '2026-06-16 11:46:08'),
(167, 'fleet_rule', 'rule:12:driver_document_expiry:driver:223:2026-06-16', 'email', 'alexandra.iordache@lpg-auto.ro', 'Alexandra Iordache', '[Fleet Management MVP] Expira in 26 zile: BULETIN (C.I.) - Nicolae Florin', 'Salut, Alexandra Iordache,\n\nExista o notificare configurata in aplicatia de flota.\n\nSofer: Nicolae Florin\nDetalii: B 652 NET\nTip: BULETIN (C.I.)\nSerie / numar: 1940712297251\nData tinta: 12.07.2026\nStatus: Expira in 26 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=223\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 12, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 223, \"document_id\": 223}', '2026-06-16 11:46:02', '2026-06-16 11:46:10');
INSERT INTO `notification_deliveries` (`id`, `context`, `context_id`, `channel`, `recipient_email`, `recipient_name`, `subject`, `message`, `status`, `provider`, `provider_response`, `error_message`, `diagnostics_json`, `metadata_json`, `created_at`, `sent_at`) VALUES
(168, 'fleet_rule', 'rule:14:driver_document_expiry:driver:218:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 9 zile: MEDICINA MUNCII - Brie-Bonchis Marius', 'Salut, Administrator Sistem,\n\nExista o notificare configurata in aplicatia de flota.\n\nSofer: Brie-Bonchis Marius\nDetalii: B 385 NET\nTip: MEDICINA MUNCII\nData tinta: 25.06.2026\nStatus: Expira in 9 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=218\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 14, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 218, \"document_id\": 218}', '2026-06-16 11:47:02', '2026-06-16 11:47:05'),
(169, 'fleet_rule', 'rule:14:driver_document_expiry:driver:218:2026-06-16', 'email', 'office@lpg-auto.ro', 'Adriana Ungurean', '[Fleet Management MVP] Expira in 9 zile: MEDICINA MUNCII - Brie-Bonchis Marius', 'Salut, Adriana Ungurean,\n\nExista o notificare configurata in aplicatia de flota.\n\nSofer: Brie-Bonchis Marius\nDetalii: B 385 NET\nTip: MEDICINA MUNCII\nData tinta: 25.06.2026\nStatus: Expira in 9 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=218\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 14, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 218, \"document_id\": 218}', '2026-06-16 11:47:02', '2026-06-16 11:47:08'),
(170, 'fleet_rule', 'rule:14:driver_document_expiry:driver:218:2026-06-16', 'email', 'alexandra.iordache@lpg-auto.ro', 'Alexandra Iordache', '[Fleet Management MVP] Expira in 9 zile: MEDICINA MUNCII - Brie-Bonchis Marius', 'Salut, Alexandra Iordache,\n\nExista o notificare configurata in aplicatia de flota.\n\nSofer: Brie-Bonchis Marius\nDetalii: B 385 NET\nTip: MEDICINA MUNCII\nData tinta: 25.06.2026\nStatus: Expira in 9 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=218\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 14, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 218, \"document_id\": 218}', '2026-06-16 11:47:02', '2026-06-16 11:47:10'),
(171, 'fleet_rule', 'rule:15:driver_document_expiry:driver:216:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 9 zile: AVIZ MEDICAL - Brie-Bonchis Marius', 'Salut, Administrator Sistem,\n\nExista o notificare configurata in aplicatia de flota.\n\nSofer: Brie-Bonchis Marius\nDetalii: B 385 NET\nTip: AVIZ MEDICAL\nData tinta: 25.06.2026\nStatus: Expira in 9 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=216\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 15, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 216, \"document_id\": 216}', '2026-06-16 11:48:03', '2026-06-16 11:48:05'),
(172, 'fleet_rule', 'rule:15:driver_document_expiry:driver:216:2026-06-16', 'email', 'office@lpg-auto.ro', 'Adriana Ungurean', '[Fleet Management MVP] Expira in 9 zile: AVIZ MEDICAL - Brie-Bonchis Marius', 'Salut, Adriana Ungurean,\n\nExista o notificare configurata in aplicatia de flota.\n\nSofer: Brie-Bonchis Marius\nDetalii: B 385 NET\nTip: AVIZ MEDICAL\nData tinta: 25.06.2026\nStatus: Expira in 9 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=216\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 15, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 216, \"document_id\": 216}', '2026-06-16 11:48:03', '2026-06-16 11:48:08'),
(173, 'fleet_rule', 'rule:15:driver_document_expiry:driver:216:2026-06-16', 'email', 'alexandra.iordache@lpg-auto.ro', 'Alexandra Iordache', '[Fleet Management MVP] Expira in 9 zile: AVIZ MEDICAL - Brie-Bonchis Marius', 'Salut, Alexandra Iordache,\n\nExista o notificare configurata in aplicatia de flota.\n\nSofer: Brie-Bonchis Marius\nDetalii: B 385 NET\nTip: AVIZ MEDICAL\nData tinta: 25.06.2026\nStatus: Expira in 9 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=216\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 15, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 216, \"document_id\": 216}', '2026-06-16 11:48:03', '2026-06-16 11:48:10'),
(174, 'fleet_rule', 'rule:15:driver_document_expiry:driver:185:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 11 zile: AVIZ MEDICAL - Erdos Zoltan', 'Salut, Administrator Sistem,\n\nExista o notificare configurata in aplicatia de flota.\n\nSofer: Erdos Zoltan\nDetalii: B 677 NET\nTip: AVIZ MEDICAL\nData tinta: 27.06.2026\nStatus: Expira in 11 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=185\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 15, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 185, \"document_id\": 185}', '2026-06-16 11:48:03', '2026-06-16 11:48:14'),
(175, 'fleet_rule', 'rule:15:driver_document_expiry:driver:185:2026-06-16', 'email', 'office@lpg-auto.ro', 'Adriana Ungurean', '[Fleet Management MVP] Expira in 11 zile: AVIZ MEDICAL - Erdos Zoltan', 'Salut, Adriana Ungurean,\n\nExista o notificare configurata in aplicatia de flota.\n\nSofer: Erdos Zoltan\nDetalii: B 677 NET\nTip: AVIZ MEDICAL\nData tinta: 27.06.2026\nStatus: Expira in 11 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=185\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 15, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 185, \"document_id\": 185}', '2026-06-16 11:48:03', '2026-06-16 11:48:16'),
(176, 'fleet_rule', 'rule:15:driver_document_expiry:driver:185:2026-06-16', 'email', 'alexandra.iordache@lpg-auto.ro', 'Alexandra Iordache', '[Fleet Management MVP] Expira in 11 zile: AVIZ MEDICAL - Erdos Zoltan', 'Salut, Alexandra Iordache,\n\nExista o notificare configurata in aplicatia de flota.\n\nSofer: Erdos Zoltan\nDetalii: B 677 NET\nTip: AVIZ MEDICAL\nData tinta: 27.06.2026\nStatus: Expira in 11 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=185\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 15, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 185, \"document_id\": 185}', '2026-06-16 11:48:03', '2026-06-16 11:48:19'),
(177, 'fleet_rule', 'rule:16:driver_document_expiry:driver:217:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 9 zile: AVIZ PSIHOLOGIC - Brie-Bonchis Marius', 'Salut, Administrator Sistem,\n\nExista o notificare configurata in aplicatia de flota.\n\nSofer: Brie-Bonchis Marius\nDetalii: B 385 NET\nTip: AVIZ PSIHOLOGIC\nData tinta: 25.06.2026\nStatus: Expira in 9 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=217\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 16, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 217, \"document_id\": 217}', '2026-06-16 11:48:03', '2026-06-16 11:48:21'),
(178, 'fleet_rule', 'rule:16:driver_document_expiry:driver:217:2026-06-16', 'email', 'office@lpg-auto.ro', 'Adriana Ungurean', '[Fleet Management MVP] Expira in 9 zile: AVIZ PSIHOLOGIC - Brie-Bonchis Marius', 'Salut, Adriana Ungurean,\n\nExista o notificare configurata in aplicatia de flota.\n\nSofer: Brie-Bonchis Marius\nDetalii: B 385 NET\nTip: AVIZ PSIHOLOGIC\nData tinta: 25.06.2026\nStatus: Expira in 9 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=217\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 16, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 217, \"document_id\": 217}', '2026-06-16 11:48:03', '2026-06-16 11:48:24'),
(179, 'fleet_rule', 'rule:16:driver_document_expiry:driver:217:2026-06-16', 'email', 'alexandra.iordache@lpg-auto.ro', 'Alexandra Iordache', '[Fleet Management MVP] Expira in 9 zile: AVIZ PSIHOLOGIC - Brie-Bonchis Marius', 'Salut, Alexandra Iordache,\n\nExista o notificare configurata in aplicatia de flota.\n\nSofer: Brie-Bonchis Marius\nDetalii: B 385 NET\nTip: AVIZ PSIHOLOGIC\nData tinta: 25.06.2026\nStatus: Expira in 9 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=217\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 16, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 217, \"document_id\": 217}', '2026-06-16 11:48:03', '2026-06-16 11:48:27'),
(180, 'fleet_rule', 'rule:16:driver_document_expiry:driver:184:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 11 zile: AVIZ PSIHOLOGIC - Erdos Zoltan', 'Salut, Administrator Sistem,\n\nExista o notificare configurata in aplicatia de flota.\n\nSofer: Erdos Zoltan\nDetalii: B 677 NET\nTip: AVIZ PSIHOLOGIC\nData tinta: 27.06.2026\nStatus: Expira in 11 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=184\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 16, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 184, \"document_id\": 184}', '2026-06-16 11:48:03', '2026-06-16 11:48:29'),
(181, 'fleet_rule', 'rule:16:driver_document_expiry:driver:184:2026-06-16', 'email', 'office@lpg-auto.ro', 'Adriana Ungurean', '[Fleet Management MVP] Expira in 11 zile: AVIZ PSIHOLOGIC - Erdos Zoltan', 'Salut, Adriana Ungurean,\n\nExista o notificare configurata in aplicatia de flota.\n\nSofer: Erdos Zoltan\nDetalii: B 677 NET\nTip: AVIZ PSIHOLOGIC\nData tinta: 27.06.2026\nStatus: Expira in 11 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=184\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 16, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 184, \"document_id\": 184}', '2026-06-16 11:48:03', '2026-06-16 11:48:32'),
(182, 'fleet_rule', 'rule:16:driver_document_expiry:driver:184:2026-06-16', 'email', 'alexandra.iordache@lpg-auto.ro', 'Alexandra Iordache', '[Fleet Management MVP] Expira in 11 zile: AVIZ PSIHOLOGIC - Erdos Zoltan', 'Salut, Alexandra Iordache,\n\nExista o notificare configurata in aplicatia de flota.\n\nSofer: Erdos Zoltan\nDetalii: B 677 NET\nTip: AVIZ PSIHOLOGIC\nData tinta: 27.06.2026\nStatus: Expira in 11 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=184\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 16, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 184, \"document_id\": 184}', '2026-06-16 11:48:03', '2026-06-16 11:48:34'),
(183, 'fleet_rule', 'rule:17:driver_document_expiry:driver:221:2026-06-16', 'email', 'gigel.trandafir@lpg-auto.ro', 'Administrator Sistem', '[Fleet Management MVP] Expira in 1 zile: ADR - Brie-Bonchis Marius', 'Salut, Administrator Sistem,\n\nExista o notificare configurata in aplicatia de flota.\n\nSofer: Brie-Bonchis Marius\nDetalii: B 385 NET\nTip: ADR\nSerie / numar: 176372\nData tinta: 17.06.2026\nStatus: Expira in 1 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=221\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 17, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 221, \"document_id\": 221}', '2026-06-16 11:49:02', '2026-06-16 11:49:05'),
(184, 'fleet_rule', 'rule:17:driver_document_expiry:driver:221:2026-06-16', 'email', 'office@lpg-auto.ro', 'Adriana Ungurean', '[Fleet Management MVP] Expira in 1 zile: ADR - Brie-Bonchis Marius', 'Salut, Adriana Ungurean,\n\nExista o notificare configurata in aplicatia de flota.\n\nSofer: Brie-Bonchis Marius\nDetalii: B 385 NET\nTip: ADR\nSerie / numar: 176372\nData tinta: 17.06.2026\nStatus: Expira in 1 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=221\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 17, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 221, \"document_id\": 221}', '2026-06-16 11:49:02', '2026-06-16 11:49:07'),
(185, 'fleet_rule', 'rule:17:driver_document_expiry:driver:221:2026-06-16', 'email', 'alexandra.iordache@lpg-auto.ro', 'Alexandra Iordache', '[Fleet Management MVP] Expira in 1 zile: ADR - Brie-Bonchis Marius', 'Salut, Alexandra Iordache,\n\nExista o notificare configurata in aplicatia de flota.\n\nSofer: Brie-Bonchis Marius\nDetalii: B 385 NET\nTip: ADR\nSerie / numar: 176372\nData tinta: 17.06.2026\nStatus: Expira in 1 zile\n\nDeschide in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente_soferi&action=show&id=221\n\nFleet Management MVP', 'sent', 'smtp', 'SMTP accepted message', NULL, NULL, '{\"rule_id\": 17, \"event_type\": \"driver_document_expiry\", \"entity_type\": \"driver\", \"entity_id\": 221, \"document_id\": 221}', '2026-06-16 11:49:02', '2026-06-16 11:49:10');

-- --------------------------------------------------------

--
-- Table structure for table `notification_queue`
--

CREATE TABLE `notification_queue` (
  `id` bigint UNSIGNED NOT NULL,
  `delivery_id` bigint UNSIGNED NOT NULL,
  `dedupe_key` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','processing','sent','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `attempts` int UNSIGNED NOT NULL DEFAULT '0',
  `max_attempts` int UNSIGNED NOT NULL DEFAULT '3',
  `scheduled_for` datetime NOT NULL,
  `locked_at` datetime DEFAULT NULL,
  `last_error` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notification_queue`
--

INSERT INTO `notification_queue` (`id`, `delivery_id`, `dedupe_key`, `status`, `attempts`, `max_attempts`, `scheduled_for`, `locked_at`, `last_error`, `created_at`, `updated_at`) VALUES
(38, 38, '2ea344f5489ba91d5e99db039b8c2aadd2a5c76447637e0ed404581cb2ed23d9', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:57:05'),
(39, 39, 'f2855c3f735aab1b238a50f82b4913b9b71f3b09c67cf88689507a41547cb86a', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:57:07'),
(40, 40, '8033d4fda1019f0fd167c9231737190ade8a7236073fb646c408c2c5b5b0a8f9', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:57:10'),
(41, 41, '3afceaad094f7af91bcda8cb6512399cb54098f532e411a6e6ed73022363423f', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:57:12'),
(42, 42, '4d3383f07a200bf9fc934a10e8c8c660f97269ddcd9a8ae5e2a2cee8d46eefa4', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:57:15'),
(43, 43, '540b9f11e8366e02af449132d231b12dd8c5a014b18c79bc2f3d4c04ba0ce3c5', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:57:17'),
(44, 44, '2cafda67687c035b08f9a2d4272d35aa54d30bbfe805df259e9ff719fc30568f', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:57:20'),
(45, 45, '8d0a43c676bda80dda32630ee4fae77bda9bd6a399402b164ac5ebfdf73b6a60', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:57:23'),
(46, 46, 'faec39cd4d844b81c2c6b66cf7c45bd2bf7ad2d1cb91b6264a38d9819f12e8b0', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:57:25'),
(47, 47, 'dddd28ca74a4eff4ef5f33f9803654d918917c418d9d06e7e6bb4bc60ad0249c', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:57:27'),
(48, 48, '809bc9bf2578e1fb1b4141fd6056d74a56d377ba5b593a4fe1a8a048b152e1b2', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:57:30'),
(49, 49, '179178105811c4eea59e2340c500c641a9331fbf9610690fa4f55d6aeea5f966', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:57:32'),
(50, 50, '5c9a35ed98d5627c62330ce3df1271f9cc7ba7f5d01543a9d643e557f64d04da', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:57:35'),
(51, 51, 'e0953b179c685caf94754b909b8d534deb2efbe6130e3d5d62cc259e6905e8b3', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:57:38'),
(52, 52, '92c28d979c3a246ed3753844319d3524c4b3df52eac4afcdb21a64cdff21a626', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:57:40'),
(53, 53, 'fa819badd41b1078be0ce74e6c3adaf5295325526febdada9e0e888a1eec4d8e', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:57:43'),
(54, 54, '8959afc063c546841616c1a1cc6a8943555e48df614e310d00eded391f08389f', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:57:45'),
(55, 55, '2fa79c2d31b6065702b3670db531a8f79b85a1d864ef84436b4b5bf81a8bde27', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:57:48'),
(56, 56, '8fbf603240a8567ed8470f3e67eb29b10b38d1a80d5bdf11c93528f60d722236', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:57:50'),
(57, 57, '2752b911e949ce522a542773654708118d35272a8b3dfc50351b7e5ee6b2c81d', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:57:53'),
(58, 58, '6d698f529249ff92dda7969c67a0ab4d09bbe3b528165eeb28c72357a83ae0bd', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:57:55'),
(59, 59, '3bc440a37142e3ea125d33191edf219f6b08fdb04e03a004096b8cde334b55d5', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:57:58'),
(60, 60, '9a54e78c7fe823d257897fceb64a4ad1d97dfddee817b47dd72edf04e8c20ac4', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:58:00'),
(61, 61, '09921813c1120d3ab3c8df77a3ad560d5d5d275e6a202a3d0820ff130bf1a92d', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:58:03'),
(62, 62, '07b8b376613372b954e639b3f60ebeabc5a91800795d0318e32a975cd629d97d', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:58:05'),
(63, 63, 'c0f2343c08d9083b5781c32d0261c279645f9e4b2545b0c2200c24ebed62bb63', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:59:05'),
(64, 64, 'dd6ee8e60198f3d61e67433199888cc40bd6bc2b539dbaf6dda5422a37ede40c', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:59:07'),
(65, 65, 'e878c9bba00451f55e5e5f5dc08c0df7e1a202fc07577374929106fed2d19297', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:59:10'),
(66, 66, 'f1e912d161fd848e81eb14042ef8ad84c365c3c5e5b2b8a3cc4642bbb4b38062', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:59:12'),
(67, 67, 'b4a416c3fdfa6e7ecb32b12e46988557454d27948a24db361a6520dac5e3c412', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:59:15'),
(68, 68, 'cba966959739267b11f6bba6e4d64a5e9c3647c9d6680572233b2710f0ecdfd9', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:59:19'),
(69, 69, '0d69e0ccf977f04b0306648c3c40a7beb1c5d5e5b6b95766e361f86772be7310', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:59:21'),
(70, 70, '6d0f1b9adc7a51862d8c0a96c36ee78ac98f9d3367965c5628e05e307c8c5780', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:59:24'),
(71, 71, '88a30b8079b8f5b08cff779a4c1c3986bda3b43a60a4c30592de3752e382ee36', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:59:26'),
(72, 72, 'a7a8c8e6d805a195270cb24fb18dde731ccfe1c03fea58a507086f8c872f243d', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:59:29'),
(73, 73, 'e1acb61c6ea14e3bcb5939d5d42ec90dff90f7fa20fd13aa4478e3ead4056862', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:59:31'),
(74, 74, 'c67d242c6a55521e8cceb7f6e340b697ebc0150147ea040bc2c1fe56ff70ea32', 'sent', 1, 3, '2026-06-15 15:57:02', NULL, NULL, '2026-06-15 15:57:02', '2026-06-15 15:59:34'),
(76, 78, '4f7f9c9ef85fbb6b2dc564bc3faef1410111f06b1c739d7b31af794158acbe6a', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:57:50'),
(77, 79, '06c4f6f530da31bb836a151a35f686a43696e45f8d55726965af6cf7d9b9e0ac', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:57:52'),
(78, 80, '0181ebf9f6ea44dcb9f2f43a7ef3bc3f160d01c54c796fffc7405e5dd4e4f07e', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:57:55'),
(79, 81, 'e4eacdcf269ed432011721a41c17a2219f01b916726ae6923c7a011479efead2', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:57:58'),
(80, 82, '55721efd9e0a63754d172a63e18bcb3aed277810253eac850e68506132ef1402', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:58:00'),
(81, 83, '2cb7e6025a5949e484c0547d9d3d5a9ce7f5990a0c028153c863077051c7e676', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:58:03'),
(82, 84, '408ae1e52b3625564e6efe975484e926d266a65fdd74bdc6d8ee66781c30b0ca', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:58:06'),
(83, 85, '5abfb853476e1bf10720eafb22b586553cac3e347fe2a81903143f1d7074c497', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:58:08'),
(84, 86, 'd434fbb88dc5611e941d7c9ceb5c96419aed9aa7970b0d20ff047e08390e15c4', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:58:12'),
(85, 87, '4089dd70749054ff81cd97f1c6d0313cc533b789a261d500ffccbad67e8020f6', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:58:15'),
(86, 88, '19c7e41d1d39b89fc3e8dd4056790eb603ef158d162c19c6f5cf3279d13c6675', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:58:17'),
(87, 89, '25af4daa7c5b9f1e0638cb982df6db323f58537683269adaae29519f2500115e', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:58:19'),
(88, 90, '9ae27878facb8624e4f5d79ee57f4a792876a84b8b5629b802bdb9ee35484d25', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:58:22'),
(89, 91, '090928402c1b2fa7af933a21c95311163a18b4cbccdd57ba8600c090b5f7b501', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:58:25'),
(90, 92, '1011adc2ec4cbba6d8b878a7a81f5cdea576327f09a871cb87eecd0752fc2581', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:58:27'),
(91, 93, '446ebe0f965743d3e5dd817905cba39fbd776b3d4e4d265aa02c04fb22ffed73', 'sent', 2, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 09:14:05'),
(92, 94, '3342c9c014b9a2240cdfc8c951b508aca73e2590761e79d41121f742a5ae7589', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:59:05'),
(93, 95, '0bb751d7eea74d2846d1a15d7f49d83e5bf1b7b9fd61aa8f348b58d73e8a14d1', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:59:07'),
(94, 96, 'ff565ac78dd172f533d5d5de79dce12ce6324c542fb4436a89c67ceefb075c92', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:59:10'),
(95, 97, 'ce5ebc22e339121e58992b52b14ed4b43ba97706d7cc55589b891d0ff79fb183', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:59:12'),
(96, 98, '027c8121431919c9604bda06075a676d30f6f6d0243a92e0d6ba845563540b18', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:59:15'),
(97, 99, '1f777a9d46f1ebc6eaef426bf2c4a086a85bc797635c30f7e059619bd76d1e1e', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:59:17'),
(98, 100, 'f9189b176998103eed7deda65e845e5906f8003639759da6204e33aff6b07c2d', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:59:20'),
(99, 101, '2730772e85c461e779df50c1f802d061aec417555def77fc699c709748691a3e', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:59:22'),
(100, 102, '83a1e5637a5d65d76556660fd784e2ddb369c355e17b5a9295a7d86675c5fd4b', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:59:25'),
(101, 103, '5feb2559bc3673f689943ba116f7e66bdc5f1610fd94d87ae6287aef3a8e3b5f', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:59:28'),
(102, 104, '6add5a9f5d6f07cb047361e9858016c4b812c604743dfcde1eee36a899d50ba3', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:59:30'),
(103, 105, 'e7355e7a0f992c1f734e82dcab19f3d95ee56bf948dfffa4ed163d64b377cfb4', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:59:33'),
(104, 106, '48d5426b0ed47d93555dffb36659899a72edb328dcb42d8cc0e74570a6e5ec1b', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:59:35'),
(105, 107, '400a3fa322a8f8cb7f9621e3448011d129bd2b711ba37a850c1d4d1136acd065', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:59:38'),
(106, 108, '382ca022dc3ec8d2163f570b65601249ce1678bf05beb740ac5885249793a6e9', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:59:40'),
(107, 109, '6f192540d6f395e2931a505cfab8df19b3a3e2ecaa943abe03dbc22cd0e3aa37', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:59:43'),
(108, 110, 'ede1dc23d47a3171e6d6679a5801ed3bcaf1d0adf48e8749b2a7a7ac52d4f932', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:59:45'),
(109, 111, '904e2454b876a74165850cf6740109bcd4696ea4647664d77b29b0a010029435', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:59:48'),
(110, 112, 'b9bd7484e932db9912f6404028a88b20d57ee28af255607af094ab3b60124646', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:59:50'),
(111, 113, '23152c7760f748c23abf1d9a7cfe81095c0137d569fb59f47a36d120b115b5c8', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:59:54'),
(112, 114, '1a553533d2cb97193b66610ce00965bccda36ba148e54dc1938b61af9df6928c', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:59:56'),
(113, 115, '1e62d50b9e16b7e6881244d23afe85ea76371c5c8f39a4a265ab5d7fdd490d04', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 08:59:59'),
(114, 116, '50ef6300cb95db195590199642a623d319888d3e953bde92a7bcaf9e74e30b0a', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 09:00:02'),
(115, 117, '386e23b59de1ead6ac9b6eb7f74342ecc155bc77063188f0c7889920c15ae0cb', 'sent', 1, 3, '2026-06-16 08:57:47', NULL, NULL, '2026-06-16 08:57:47', '2026-06-16 09:00:04'),
(116, 118, '8a79b1aaaf963f42bc45ac36219dd43d641e5cdd11eb7c6cbaea6c515cebf4a1', 'sent', 1, 3, '2026-06-16 09:54:53', NULL, NULL, '2026-06-16 09:54:53', '2026-06-16 09:55:04'),
(117, 119, 'e23076beb2ee97022bfb1830fed1c512e69189cbb05acbf6dec4fddcc80032ac', 'sent', 1, 3, '2026-06-16 10:10:02', NULL, NULL, '2026-06-16 10:10:02', '2026-06-16 10:10:04'),
(118, 120, 'aeb51a7909ea22b72c7ca51caf1189e76d68fdeb9f36ae192d6b90f2b7c40635', 'sent', 1, 3, '2026-06-16 10:16:02', NULL, NULL, '2026-06-16 10:16:02', '2026-06-16 10:16:04'),
(119, 121, '51d45d705f6d5d97ff0a379d3bfc87951538657f2ed091a40b79a695c491e429', 'sent', 1, 3, '2026-06-16 10:19:02', NULL, NULL, '2026-06-16 10:19:02', '2026-06-16 10:19:04'),
(120, 122, '4f5ffee38c335e950c91fd4193f1fbe3d380c05e5650209cf9ded8d326cefa0d', 'sent', 1, 3, '2026-06-16 10:19:02', NULL, NULL, '2026-06-16 10:19:02', '2026-06-16 10:19:07'),
(121, 123, 'fe4e49753a4b0b29a79069b351b809436d3485b93527126077a213550e981beb', 'sent', 1, 3, '2026-06-16 11:35:02', NULL, NULL, '2026-06-16 11:35:02', '2026-06-16 11:35:05'),
(122, 124, '2a04bdd8899dfa9b836f1d00d67de6da6c0dfa90f591321d8e020c617deedf97', 'sent', 1, 3, '2026-06-16 11:35:02', NULL, NULL, '2026-06-16 11:35:02', '2026-06-16 11:35:08'),
(123, 125, '9520944c445c3e4ee0abc98eb5ff976da683ffed160a41fa43dc5ec4ae2dde34', 'sent', 1, 3, '2026-06-16 11:35:02', NULL, NULL, '2026-06-16 11:35:02', '2026-06-16 11:35:10'),
(124, 126, '3aa67f58c1d03124050563c67bd5fd030cb03e18d42b55cbaf424a9cff365c8f', 'sent', 1, 3, '2026-06-16 11:35:02', NULL, NULL, '2026-06-16 11:35:02', '2026-06-16 11:35:13'),
(125, 127, 'c5ba9481b2ddbdbdfb1a8c03e231de7ba2f1ad4189edbe0802a71d468bc5b5e9', 'sent', 1, 3, '2026-06-16 11:35:02', NULL, NULL, '2026-06-16 11:35:02', '2026-06-16 11:35:15'),
(126, 128, '8c77ed079382d2b1068086ea499ae43c2412132a052341e14e9967c71f92b6ab', 'sent', 1, 3, '2026-06-16 11:35:02', NULL, NULL, '2026-06-16 11:35:02', '2026-06-16 11:35:18'),
(127, 129, '301eed9924e1df4bfea559260c5230dd0712515b3ee5ba708bbd49f3fb4432c9', 'sent', 1, 3, '2026-06-16 11:35:02', NULL, NULL, '2026-06-16 11:35:02', '2026-06-16 11:35:20'),
(128, 130, 'f3bd13c468c659ba2fba164f6d9918ce7296f06e4c470b9159a3461ea7f0cd12', 'sent', 1, 3, '2026-06-16 11:35:02', NULL, NULL, '2026-06-16 11:35:02', '2026-06-16 11:35:23'),
(129, 131, '8f3b1aece7004112bc8e37af64edbeb411091403570d6d4095e37d66ab225b27', 'sent', 1, 3, '2026-06-16 11:35:02', NULL, NULL, '2026-06-16 11:35:02', '2026-06-16 11:35:25'),
(130, 132, '5ee621654c8d526eab448908b50d12946af236046ca3539801ce8ca523be6c72', 'sent', 1, 3, '2026-06-16 11:36:02', NULL, NULL, '2026-06-16 11:36:02', '2026-06-16 11:36:05'),
(131, 133, '6cc0c300f4af9b62fb05fb4f2962da20e0823f4c754d32c0e9769e6fac388bfd', 'sent', 1, 3, '2026-06-16 11:36:02', NULL, NULL, '2026-06-16 11:36:02', '2026-06-16 11:36:08'),
(132, 134, '4845a8310562f66b1d60129db23ba3c866262367d95cf8cebe294992848fbdb6', 'sent', 1, 3, '2026-06-16 11:36:02', NULL, NULL, '2026-06-16 11:36:02', '2026-06-16 11:36:11'),
(133, 135, '040de5b542743363dd890c45f512e4af01a093ea67f68f1bdd4d0999c60c8df0', 'sent', 1, 3, '2026-06-16 11:36:02', NULL, NULL, '2026-06-16 11:36:02', '2026-06-16 11:36:13'),
(134, 136, '19b5cfb5f8c84370bd4394ecb1118f23e1d016ded0ca2f2dfe91174610e879d4', 'sent', 1, 3, '2026-06-16 11:36:02', NULL, NULL, '2026-06-16 11:36:02', '2026-06-16 11:36:16'),
(135, 137, '6887b77dbd178622a4e480f49b40a01cb0b3b164b400e68ae1a35acd26b2036c', 'sent', 1, 3, '2026-06-16 11:36:02', NULL, NULL, '2026-06-16 11:36:02', '2026-06-16 11:36:19'),
(136, 138, '13b94685433563be392d8500cea4a00dd3c44be83b8b6bae54467bdc610b4085', 'sent', 1, 3, '2026-06-16 11:36:02', NULL, NULL, '2026-06-16 11:36:02', '2026-06-16 11:36:22'),
(137, 139, '1d3a1efca7be6d3ad3f96486858af5a9f44a9c38af9959aebd3a9781cd767696', 'sent', 1, 3, '2026-06-16 11:36:02', NULL, NULL, '2026-06-16 11:36:02', '2026-06-16 11:36:24'),
(138, 140, '2d8255c793ad2ef8e4087a179876cf71b056cb156c8d0a9b811a3b35d10ebc16', 'sent', 1, 3, '2026-06-16 11:36:02', NULL, NULL, '2026-06-16 11:36:02', '2026-06-16 11:36:27'),
(139, 141, '7aef13fc3e99afe59055237a0ed8f1f730373593b184f25849e165893d4da323', 'sent', 1, 3, '2026-06-16 11:36:02', NULL, NULL, '2026-06-16 11:36:02', '2026-06-16 11:36:30'),
(140, 142, 'f686d036bed230b687f878a091042142735c3d362ccf00e37e1eed4dc95bec4d', 'sent', 1, 3, '2026-06-16 11:36:02', NULL, NULL, '2026-06-16 11:36:02', '2026-06-16 11:36:33'),
(141, 143, '5e32b3aae3f3ed15499db7b38cda7a0f0ac65ac3c62b8f15230d6060fb43339f', 'sent', 1, 3, '2026-06-16 11:36:02', NULL, NULL, '2026-06-16 11:36:02', '2026-06-16 11:36:35'),
(142, 144, 'e9c360468c86dce3af276e545630c7b8573d8429dbd4d9b26aaff31bd0bc0937', 'sent', 1, 3, '2026-06-16 11:36:02', NULL, NULL, '2026-06-16 11:36:02', '2026-06-16 11:36:38'),
(143, 145, '95156ed02e5760cc9657600632f2838c5444d0f38fdf46d17d1add4c8bc7403f', 'sent', 1, 3, '2026-06-16 11:36:02', NULL, NULL, '2026-06-16 11:36:02', '2026-06-16 11:36:40'),
(144, 146, 'cf70a75e0d2a33caa574660c7f57e1b7c1b9ebdb73fbd831af86da9ce9866ed5', 'sent', 1, 3, '2026-06-16 11:36:02', NULL, NULL, '2026-06-16 11:36:02', '2026-06-16 11:36:43'),
(145, 147, 'ca0815f6c04c8bfde02855143b807c9e7d969e84540031fe4010fbd5a30ef7cf', 'sent', 1, 3, '2026-06-16 11:36:02', NULL, NULL, '2026-06-16 11:36:02', '2026-06-16 11:36:45'),
(146, 148, '6ee3ffef2e8e84d1526705f38784628975c07adca8340c3253993e7342893859', 'sent', 1, 3, '2026-06-16 11:36:02', NULL, NULL, '2026-06-16 11:36:02', '2026-06-16 11:36:48'),
(147, 149, 'eceb6e5e72ce2f561f3e945b093c819c9d642677d1402244cd290ae626979e0d', 'sent', 1, 3, '2026-06-16 11:36:02', NULL, NULL, '2026-06-16 11:36:02', '2026-06-16 11:36:51'),
(148, 150, 'b9ca30c57bdfedb0d00a0598a76ba9929935dd121839113c554797c2da9bec3b', 'sent', 1, 3, '2026-06-16 11:36:02', NULL, NULL, '2026-06-16 11:36:02', '2026-06-16 11:36:53'),
(149, 151, '2acd523fcd95684f38e4211668b368a86f29c400b376764d0599cd1284e1646b', 'sent', 1, 3, '2026-06-16 11:36:02', NULL, NULL, '2026-06-16 11:36:02', '2026-06-16 11:36:56'),
(150, 152, '7d440b16cbce71746649425db675d9c2b3e768d8f8134f68f5595b6e9caed9fb', 'sent', 1, 3, '2026-06-16 11:36:02', NULL, NULL, '2026-06-16 11:36:02', '2026-06-16 11:36:58'),
(151, 153, '13b7071615d8086921252793c70696fea100834bd32238799b7065035de6d6a6', 'sent', 1, 3, '2026-06-16 11:36:02', NULL, NULL, '2026-06-16 11:36:02', '2026-06-16 11:37:01'),
(152, 154, '3632d71b3d1ff2306bc157061849f523ef55b11db9a62b5d3ef0baa3104afa4b', 'sent', 1, 3, '2026-06-16 11:36:02', NULL, NULL, '2026-06-16 11:36:02', '2026-06-16 11:37:03'),
(153, 155, 'bc7aaa780f049b751bfbbba5e2c7ee36e5b9e136e4a89b651deebe9b91cf487a', 'sent', 1, 3, '2026-06-16 11:36:02', NULL, NULL, '2026-06-16 11:36:02', '2026-06-16 11:37:06'),
(154, 156, '16fc8b327c189ee755c19910de0313beb235da77b739217ac528493481fcd622', 'sent', 1, 3, '2026-06-16 11:36:02', NULL, NULL, '2026-06-16 11:36:02', '2026-06-16 11:37:08'),
(155, 157, 'd0a9c6a893cf99e7c9d4dbcd0d63883c7967c2e82d2da4bcc24565efedeec5ae', 'sent', 1, 3, '2026-06-16 11:36:02', NULL, NULL, '2026-06-16 11:36:02', '2026-06-16 11:38:05'),
(156, 158, 'c278d81ff5573c3c9fe01a5b76f79c3ec63a4c567720fb8716845126eb14d361', 'sent', 1, 3, '2026-06-16 11:36:02', NULL, NULL, '2026-06-16 11:36:02', '2026-06-16 11:38:08'),
(157, 159, '0ddb4f4c20e72e985c311d66f8f9990b0112fe2fe3db1e5affe072e987c0caa4', 'sent', 1, 3, '2026-06-16 11:40:02', NULL, NULL, '2026-06-16 11:40:02', '2026-06-16 11:40:05'),
(158, 160, '7066eaab58f6d0a14b083574a3569dbe9053a845bd3a1125a469038b22efe54b', 'sent', 1, 3, '2026-06-16 11:40:02', NULL, NULL, '2026-06-16 11:40:02', '2026-06-16 11:40:09'),
(159, 161, '6d103f942e36f66436a94ba82331e0bee8e3c6941bb2fab3df81989b9f181f07', 'sent', 1, 3, '2026-06-16 11:40:02', NULL, NULL, '2026-06-16 11:40:02', '2026-06-16 11:40:11'),
(160, 162, '0edf6da8b91b0c694537f4bf4f18446de499bc71cb6abad9a9f848695f2dc2f7', 'sent', 1, 3, '2026-06-16 11:40:02', NULL, NULL, '2026-06-16 11:40:02', '2026-06-16 11:40:14'),
(161, 163, '7112b38704a539d609bd990785c08fba771839ddb7f465d8cb51faa1d631cf44', 'sent', 1, 3, '2026-06-16 11:40:02', NULL, NULL, '2026-06-16 11:40:02', '2026-06-16 11:40:16'),
(162, 164, '5c461cfd4091b0c9701566e26a121bea82067ffbc7fd9a57fe34dd2562e6451e', 'sent', 1, 3, '2026-06-16 11:40:02', NULL, NULL, '2026-06-16 11:40:02', '2026-06-16 11:40:19'),
(163, 165, 'fd2339de388304321fd6d166340dbaf439fb502d827d49bc2a5bdbabff5aebf5', 'sent', 1, 3, '2026-06-16 11:46:02', NULL, NULL, '2026-06-16 11:46:02', '2026-06-16 11:46:05'),
(164, 166, '8c827ca2df2bd0b81e18d77c70a3646b141d3923a16ce02078bd38ff5395776d', 'sent', 1, 3, '2026-06-16 11:46:02', NULL, NULL, '2026-06-16 11:46:02', '2026-06-16 11:46:08'),
(165, 167, 'f3bf884c03947f866ca9d669b982b44fff518ba7d2540fafa649d85146aa944c', 'sent', 1, 3, '2026-06-16 11:46:02', NULL, NULL, '2026-06-16 11:46:02', '2026-06-16 11:46:10'),
(166, 168, 'c9bf0e7296512c112eeddd924bdbeaf58f8de58df011fe21090dc77c99cbf591', 'sent', 1, 3, '2026-06-16 11:47:02', NULL, NULL, '2026-06-16 11:47:02', '2026-06-16 11:47:05'),
(167, 169, '2e8bc0d9cd54c96d1901e9a897394af3e51e5088b725219cef5de76eb10ea380', 'sent', 1, 3, '2026-06-16 11:47:02', NULL, NULL, '2026-06-16 11:47:02', '2026-06-16 11:47:08'),
(168, 170, 'a871edca78dc15e4dd279dd3830490267de1dae9b01295de7cfe8fbf5ae44079', 'sent', 1, 3, '2026-06-16 11:47:02', NULL, NULL, '2026-06-16 11:47:02', '2026-06-16 11:47:10'),
(169, 171, 'e0b927c9566203f98be4a020062d897105d2b90981fac31f731a720ff9f38a8e', 'sent', 1, 3, '2026-06-16 11:48:03', NULL, NULL, '2026-06-16 11:48:03', '2026-06-16 11:48:05'),
(170, 172, 'cb5fe4558bc40b4e3770ee7b505eb0a5e25f69b6684dd07465d3b9a756b7723d', 'sent', 1, 3, '2026-06-16 11:48:03', NULL, NULL, '2026-06-16 11:48:03', '2026-06-16 11:48:08'),
(171, 173, 'cea19b97bcea324983844b59da43ab4c85a4d99fa2521ebe783dcf2d7d110a52', 'sent', 1, 3, '2026-06-16 11:48:03', NULL, NULL, '2026-06-16 11:48:03', '2026-06-16 11:48:10'),
(172, 174, '9d0473606c6a05e775082a199eab060fe44525be0129633aeaa5a70e0b55a54e', 'sent', 1, 3, '2026-06-16 11:48:03', NULL, NULL, '2026-06-16 11:48:03', '2026-06-16 11:48:14'),
(173, 175, 'd60ccff0c4a0345168c84dd997a840d707038adeb69b5f0b844d2a2ed14a5a3c', 'sent', 1, 3, '2026-06-16 11:48:03', NULL, NULL, '2026-06-16 11:48:03', '2026-06-16 11:48:16'),
(174, 176, 'bdb056c8155587488b5c61ad74c9291711768df6436b3a8075fd651a1528df1d', 'sent', 1, 3, '2026-06-16 11:48:03', NULL, NULL, '2026-06-16 11:48:03', '2026-06-16 11:48:19'),
(175, 177, 'c3575901b3b32b01eed12b0f79c9b0be49efd9af1774d78b70f191c0e23c2cc3', 'sent', 1, 3, '2026-06-16 11:48:03', NULL, NULL, '2026-06-16 11:48:03', '2026-06-16 11:48:21'),
(176, 178, '3c7d16857b309e920e2f2f881710f2aacb97330a6fe4b6aa92bb099e199cd1ed', 'sent', 1, 3, '2026-06-16 11:48:03', NULL, NULL, '2026-06-16 11:48:03', '2026-06-16 11:48:24'),
(177, 179, 'ca6f40e6586f8117854ad095d14fb73e738569a89421f5340d0dd14bea721df6', 'sent', 1, 3, '2026-06-16 11:48:03', NULL, NULL, '2026-06-16 11:48:03', '2026-06-16 11:48:27'),
(178, 180, '044a39a9e955ad0fe25b173f9c57036b546d33c222abdb5ddc0f95e5a6038222', 'sent', 1, 3, '2026-06-16 11:48:03', NULL, NULL, '2026-06-16 11:48:03', '2026-06-16 11:48:29'),
(179, 181, '737a700d9453370ea744b4f952eb112aaaf79050e373e26e72921c26cb193592', 'sent', 1, 3, '2026-06-16 11:48:03', NULL, NULL, '2026-06-16 11:48:03', '2026-06-16 11:48:32'),
(180, 182, 'a9fe8dd262e4e58f4bfd82c902223c4e95fc8332eab475e1c8e53b18c5f28801', 'sent', 1, 3, '2026-06-16 11:48:03', NULL, NULL, '2026-06-16 11:48:03', '2026-06-16 11:48:34'),
(181, 183, 'c02377be94bdf09c089c75f567f24c845eccecdf33e24bb94951d9eca9960275', 'sent', 1, 3, '2026-06-16 11:49:02', NULL, NULL, '2026-06-16 11:49:02', '2026-06-16 11:49:05'),
(182, 184, 'd38cde15325977e52864571de3d3baaa0e6789530ad7490ff09e7baf19fe26d4', 'sent', 1, 3, '2026-06-16 11:49:02', NULL, NULL, '2026-06-16 11:49:02', '2026-06-16 11:49:07'),
(183, 185, '5bc33dc1384f2e5f15e9670409d61bc26217fe880e319e565da1e621e5ac5a89', 'sent', 1, 3, '2026-06-16 11:49:02', NULL, NULL, '2026-06-16 11:49:02', '2026-06-16 11:49:10');

-- --------------------------------------------------------

--
-- Table structure for table `notification_rules`
--

CREATE TABLE `notification_rules` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'vehicle',
  `document_type` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `days_before` int UNSIGNED NOT NULL DEFAULT '30',
  `threshold_km` int UNSIGNED DEFAULT NULL,
  `threshold_tread_depth` decimal(5,2) DEFAULT NULL,
  `channel` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'email',
  `recipient_mode` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'admins',
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `repeat_until_resolved` tinyint(1) NOT NULL DEFAULT '1',
  `daily_limit_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `metadata_json` longtext COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notification_rules`
--

INSERT INTO `notification_rules` (`id`, `name`, `event_type`, `entity_type`, `document_type`, `days_before`, `threshold_km`, `threshold_tread_depth`, `channel`, `recipient_mode`, `enabled`, `repeat_until_resolved`, `daily_limit_enabled`, `metadata_json`, `created_at`, `updated_at`) VALUES
(3, 'RCA', 'vehicle_document_expiry', 'vehicle', 'RCA', 30, NULL, NULL, 'email', 'specific_users', 1, 1, 1, NULL, '2026-06-16 11:34:05', '2026-06-16 11:34:05'),
(4, 'CASCO', 'vehicle_document_expiry', 'vehicle', 'CASCO', 20, NULL, NULL, 'email', 'specific_users', 1, 1, 1, NULL, '2026-06-16 11:34:44', '2026-06-16 11:34:44'),
(5, 'ITP', 'vehicle_document_expiry', 'vehicle', 'ITP', 31, NULL, NULL, 'email', 'specific_users', 1, 1, 1, NULL, '2026-06-16 11:35:23', '2026-06-16 11:35:23'),
(6, 'ROV', 'vehicle_document_expiry', 'vehicle', 'Rovinieta', 3, NULL, NULL, 'email', 'specific_users', 1, 1, 1, NULL, '2026-06-16 11:36:01', '2026-06-16 11:36:01'),
(7, 'TAHOGRAF', 'vehicle_document_expiry', 'vehicle', 'Tahograf', 14, NULL, NULL, 'email', 'specific_users', 1, 1, 1, NULL, '2026-06-16 11:36:50', '2026-06-16 11:36:50'),
(8, 'COPIECONF', 'vehicle_document_expiry', 'vehicle', 'Copie conforma', 21, NULL, NULL, 'email', 'specific_users', 1, 1, 1, NULL, '2026-06-16 11:37:26', '2026-06-16 11:37:26'),
(9, 'IPROCHIM', 'vehicle_document_expiry', 'vehicle', 'IPROCHIM', 21, NULL, NULL, 'email', 'specific_users', 1, 1, 1, NULL, '2026-06-16 11:39:21', '2026-06-16 11:39:21'),
(10, 'METROLOGIE', 'vehicle_document_expiry', 'vehicle', 'METROLOGIE', 30, NULL, NULL, 'email', 'specific_users', 1, 1, 1, NULL, '2026-06-16 11:42:36', '2026-06-16 11:42:36'),
(11, 'ORGANISM NOTIFICAT', 'vehicle_document_expiry', 'vehicle', 'ORGANISM NOTIFICAT', 30, NULL, NULL, 'email', 'specific_users', 1, 1, 1, NULL, '2026-06-16 11:43:45', '2026-06-16 11:43:45'),
(12, 'BULETIN (C.I.)', 'driver_document_expiry', 'driver', 'BULETIN (C.I.)', 30, NULL, NULL, 'email', 'specific_users', 1, 1, 1, NULL, '2026-06-16 11:45:23', '2026-06-16 11:45:23'),
(13, 'PERMIS', 'driver_document_expiry', 'driver', 'PERMIS', 30, NULL, NULL, 'email', 'specific_users', 1, 1, 1, NULL, '2026-06-16 11:45:49', '2026-06-16 11:45:49'),
(14, 'MEDICINA MUNCII', 'driver_document_expiry', 'driver', 'MEDICINA MUNCII', 20, NULL, NULL, 'email', 'specific_users', 1, 1, 1, NULL, '2026-06-16 11:46:29', '2026-06-16 11:46:29'),
(15, 'AVIZ MEDICAL', 'driver_document_expiry', 'driver', 'AVIZ MEDICAL', 30, NULL, NULL, 'email', 'specific_users', 1, 1, 1, NULL, '2026-06-16 11:47:13', '2026-06-16 11:47:13'),
(16, 'AVIZ PSIHOLOGIC', 'driver_document_expiry', 'driver', 'AVIZ PSIHOLOGIC', 30, NULL, NULL, 'email', 'specific_users', 1, 1, 1, NULL, '2026-06-16 11:47:41', '2026-06-16 11:47:41'),
(17, 'ADR', 'driver_document_expiry', 'driver', 'ADR', 30, NULL, NULL, 'email', 'specific_users', 1, 1, 1, NULL, '2026-06-16 11:48:10', '2026-06-16 11:48:10'),
(18, 'CARTELA CONDUCATOR AUTO', 'driver_document_expiry', 'driver', 'CARTELA CONDUCATOR AUTO', 30, NULL, NULL, 'email', 'specific_users', 1, 1, 1, NULL, '2026-06-16 11:50:14', '2026-06-16 11:50:14'),
(19, 'CERTIFICAT COMPETENTA PROFESIONALA', 'driver_document_expiry', 'driver', 'CERTIFICAT COMPETENTA PROFESIONALA', 30, NULL, NULL, 'email', 'specific_users', 1, 1, 1, NULL, '2026-06-16 11:50:53', '2026-06-16 11:50:53');

-- --------------------------------------------------------

--
-- Table structure for table `notification_rule_recipients`
--

CREATE TABLE `notification_rule_recipients` (
  `rule_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notification_rule_recipients`
--

INSERT INTO `notification_rule_recipients` (`rule_id`, `user_id`, `created_at`) VALUES
(3, 1, '2026-06-16 11:34:05'),
(3, 3, '2026-06-16 11:34:05'),
(3, 5, '2026-06-16 11:34:05'),
(4, 1, '2026-06-16 11:34:44'),
(4, 3, '2026-06-16 11:34:44'),
(4, 5, '2026-06-16 11:34:44'),
(5, 1, '2026-06-16 11:35:23'),
(5, 3, '2026-06-16 11:35:23'),
(5, 5, '2026-06-16 11:35:23'),
(6, 1, '2026-06-16 11:36:01'),
(6, 3, '2026-06-16 11:36:01'),
(6, 5, '2026-06-16 11:36:01'),
(7, 1, '2026-06-16 11:36:50'),
(7, 3, '2026-06-16 11:36:50'),
(7, 5, '2026-06-16 11:36:50'),
(8, 1, '2026-06-16 11:37:26'),
(8, 3, '2026-06-16 11:37:26'),
(8, 5, '2026-06-16 11:37:26'),
(9, 1, '2026-06-16 11:39:21'),
(9, 3, '2026-06-16 11:39:21'),
(9, 5, '2026-06-16 11:39:21'),
(10, 1, '2026-06-16 11:42:36'),
(10, 3, '2026-06-16 11:42:36'),
(10, 5, '2026-06-16 11:42:36'),
(11, 1, '2026-06-16 11:43:45'),
(11, 3, '2026-06-16 11:43:45'),
(11, 5, '2026-06-16 11:43:45'),
(12, 1, '2026-06-16 11:45:23'),
(12, 3, '2026-06-16 11:45:23'),
(12, 5, '2026-06-16 11:45:23'),
(13, 1, '2026-06-16 11:45:49'),
(13, 3, '2026-06-16 11:45:49'),
(13, 5, '2026-06-16 11:45:49'),
(14, 1, '2026-06-16 11:46:29'),
(14, 3, '2026-06-16 11:46:29'),
(14, 5, '2026-06-16 11:46:29'),
(15, 1, '2026-06-16 11:47:13'),
(15, 3, '2026-06-16 11:47:13'),
(15, 5, '2026-06-16 11:47:13'),
(16, 1, '2026-06-16 11:47:41'),
(16, 3, '2026-06-16 11:47:41'),
(16, 5, '2026-06-16 11:47:41'),
(17, 1, '2026-06-16 11:48:10'),
(17, 3, '2026-06-16 11:48:10'),
(17, 5, '2026-06-16 11:48:10'),
(18, 1, '2026-06-16 11:50:14'),
(18, 3, '2026-06-16 11:50:14'),
(18, 5, '2026-06-16 11:50:14'),
(19, 1, '2026-06-16 11:50:53'),
(19, 3, '2026-06-16 11:50:53'),
(19, 5, '2026-06-16 11:50:53');

-- --------------------------------------------------------

--
-- Table structure for table `office_expenses`
--

CREATE TABLE `office_expenses` (
  `id` int UNSIGNED NOT NULL,
  `category_id` int UNSIGNED NOT NULL,
  `expense_date` date NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `supplier` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount_net` decimal(12,2) NOT NULL DEFAULT '0.00',
  `vat_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `amount_total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payment_method` enum('cash','card','transfer_bancar','alte') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'transfer_bancar',
  `invoice_number` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `monthly_rent_amount` decimal(12,2) DEFAULT NULL,
  `contract_number` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rent_period_start` date DEFAULT NULL,
  `rent_period_end` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `payment_status` enum('platit','neplatit','intarziat') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `landlord_name` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `added_by` int UNSIGNED DEFAULT NULL,
  `updated_by` int UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `office_expense_categories`
--

CREATE TABLE `office_expense_categories` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(140) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expense_scope` enum('administrative','operational') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'administrative',
  `is_automatic` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('activ','inactiv') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'activ',
  `color` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` smallint UNSIGNED NOT NULL DEFAULT '100',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `office_expense_categories`
--

INSERT INTO `office_expense_categories` (`id`, `name`, `slug`, `expense_scope`, `is_automatic`, `status`, `color`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 'Chirie birou', 'chirie-birou', 'administrative', 0, 'activ', '#3b82f6', 10, '2026-06-12 10:12:34', '2026-06-12 10:12:34'),
(2, 'Utilități', 'utilitati', 'administrative', 0, 'activ', '#22c55e', 20, '2026-06-12 10:12:34', '2026-06-12 10:12:34'),
(3, 'Internet / telefonie', 'internet-telefonie', 'administrative', 0, 'activ', '#8b5cf6', 30, '2026-06-12 10:12:34', '2026-06-12 10:12:34'),
(4, 'Consumabile birou', 'consumabile-birou', 'administrative', 0, 'activ', '#f59e0b', 40, '2026-06-12 10:12:34', '2026-06-12 10:12:34'),
(5, 'Cafea / apă / protocol', 'cafea-apa-protocol', 'administrative', 0, 'activ', '#fb923c', 50, '2026-06-12 10:12:34', '2026-06-12 10:12:34'),
(6, 'Produse curățenie', 'produse-curatenie', 'administrative', 0, 'activ', '#60a5fa', 60, '2026-06-12 10:12:34', '2026-06-12 10:12:34'),
(7, 'IT și software', 'it-si-software', 'administrative', 0, 'activ', '#14b8a6', 70, '2026-06-12 10:12:34', '2026-06-12 10:12:34'),
(8, 'Servicii externe', 'servicii-externe', 'administrative', 0, 'activ', '#ef4444', 80, '2026-06-12 10:12:34', '2026-06-12 10:12:34'),
(9, 'Mobilier și echipamente', 'mobilier-echipamente', 'administrative', 0, 'activ', '#64748b', 90, '2026-06-12 10:12:34', '2026-06-12 10:12:34'),
(10, 'Comisioane bancare', 'comisioane-bancare', 'administrative', 0, 'activ', '#a855f7', 100, '2026-06-12 10:12:34', '2026-06-12 10:12:34'),
(11, 'Alte cheltuieli', 'alte-cheltuieli', 'administrative', 0, 'activ', '#94a3b8', 110, '2026-06-12 10:12:34', '2026-06-12 10:12:34'),
(12, 'Salarii birou', 'salarii-birou', 'administrative', 1, 'activ', '#fbbf24', 25, '2026-06-12 10:12:34', '2026-06-12 10:12:34');

-- --------------------------------------------------------

--
-- Table structure for table `office_expense_documents`
--

CREATE TABLE `office_expense_documents` (
  `id` int UNSIGNED NOT NULL,
  `expense_id` int UNSIGNED NOT NULL,
  `document_type` enum('factura','bon_fiscal','chitanta','contract','alt_document') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'factura',
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stored_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_by` int UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `salary_history`
--

CREATE TABLE `salary_history` (
  `id` bigint UNSIGNED NOT NULL,
  `subject_type` enum('driver','staff') COLLATE utf8mb4_unicode_ci NOT NULL,
  `driver_id` int UNSIGNED DEFAULT NULL,
  `staff_member_id` int UNSIGNED DEFAULT NULL,
  `previous_salary` decimal(10,2) DEFAULT NULL,
  `current_salary` decimal(10,2) NOT NULL DEFAULT '0.00',
  `effective_date` date NOT NULL,
  `updated_by` int UNSIGNED DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `salary_history`
--

INSERT INTO `salary_history` (`id`, `subject_type`, `driver_id`, `staff_member_id`, `previous_salary`, `current_salary`, `effective_date`, `updated_by`, `notes`, `created_at`) VALUES
(6, 'staff', NULL, 4, NULL, 3000.00, '2026-06-12', 1, 'Salariu initial.', '2026-06-12 13:48:36');

-- --------------------------------------------------------

--
-- Table structure for table `soferi`
--

CREATE TABLE `soferi` (
  `id` int UNSIGNED NOT NULL,
  `nume` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_nasterii` date DEFAULT NULL,
  `data_angajare` date DEFAULT NULL,
  `poza_original` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `poza_stocata` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefon` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `salariu` decimal(10,2) DEFAULT NULL,
  `vehicle_id` int UNSIGNED DEFAULT NULL,
  `permis_expira_la` date NOT NULL,
  `status` enum('activ','inactiv') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'activ',
  `observatii` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `soferi`
--

INSERT INTO `soferi` (`id`, `nume`, `data_nasterii`, `data_angajare`, `poza_original`, `poza_stocata`, `telefon`, `salariu`, `vehicle_id`, `permis_expira_la`, `status`, `observatii`, `created_at`, `updated_at`) VALUES
(13, 'Andreias Iulian', '1975-09-29', NULL, NULL, NULL, '0726741456', 5000.00, 31, '2026-05-23', 'inactiv', NULL, '2026-05-19 11:50:14', '2026-06-12 12:32:55'),
(14, 'Causanu Mihai Nicusor', '2000-02-28', NULL, NULL, NULL, '0732225057', 5000.00, 48, '2029-02-18', 'activ', NULL, '2026-05-19 11:50:44', '2026-06-12 13:51:31'),
(15, 'Macovei Ion', '1967-07-16', NULL, NULL, NULL, '0722391123', 5000.00, 43, '2026-08-21', 'activ', NULL, '2026-05-19 11:51:07', '2026-06-12 10:47:29'),
(16, 'Don Tudorel', '1974-12-09', NULL, NULL, NULL, '0734566250', 5000.00, 9, '2026-10-22', 'activ', NULL, '2026-05-19 11:51:32', '2026-06-12 12:31:20'),
(17, 'Jarda Vasile', '1971-08-25', NULL, NULL, NULL, '0756829711', 5000.00, 19, '2026-07-29', 'activ', NULL, '2026-05-19 11:51:57', '2026-06-12 12:24:19'),
(18, 'Bara Nicolae-Constantin', '1988-05-13', NULL, NULL, NULL, '0746210878', 5000.00, 37, '2026-07-29', 'activ', NULL, '2026-05-19 11:52:21', '2026-06-12 11:49:10'),
(19, 'Serban Marian', '1977-08-13', NULL, NULL, NULL, '0732951946', 5000.00, 24, '2026-08-27', 'activ', NULL, '2026-05-19 11:52:46', '2026-06-12 11:58:55'),
(20, 'Beznea Cristian-Gheorghe', '1990-05-25', NULL, NULL, NULL, '0728678824', 5000.00, 23, '2026-08-27', 'activ', NULL, '2026-05-19 11:53:10', '2026-06-12 13:29:35'),
(21, 'Pantis Ghita', '1977-03-31', NULL, NULL, NULL, '0766623885', 5000.00, 15, '2026-07-28', 'activ', NULL, '2026-05-19 11:53:40', '2026-06-11 13:10:16'),
(22, 'Andreias Catalin', '1972-06-03', NULL, NULL, NULL, '0724220849', 5000.00, 40, '2026-07-23', 'activ', NULL, '2026-05-19 11:54:13', '2026-06-12 13:55:10'),
(23, 'Curca Viorel', '1968-09-07', NULL, NULL, NULL, '0722785226', 5000.00, 21, '2026-07-30', 'activ', NULL, '2026-05-19 11:54:49', '2026-06-12 11:31:45'),
(24, 'Beznea Ion', '1986-07-21', NULL, NULL, NULL, '0728151079', 5000.00, 1, '2026-07-22', 'activ', NULL, '2026-05-19 11:55:08', '2026-06-12 11:40:39'),
(25, 'Voinea Beniamin', '1996-09-06', NULL, NULL, NULL, '0752561539', 5000.00, 6, '2026-08-26', 'activ', NULL, '2026-05-19 11:55:44', '2026-06-12 14:43:23'),
(26, 'Savulescu Catalin', '1976-10-10', NULL, NULL, NULL, '0734388556', 5000.00, 2, '2026-08-26', 'activ', NULL, '2026-05-19 11:56:05', '2026-06-12 14:59:52'),
(27, 'Dobrin Nicolae', '1967-01-02', NULL, NULL, NULL, '0722366148', 5000.00, 20, '2026-06-30', 'activ', NULL, '2026-05-19 11:56:30', '2026-06-11 13:09:04'),
(28, 'Bodiu Sorin', '1973-01-04', NULL, NULL, NULL, '0761607604', 5000.00, 16, '2026-07-29', 'activ', NULL, '2026-05-19 11:56:50', '2026-06-12 14:41:17'),
(29, 'Brie-Bonchis Marius', '1984-11-21', NULL, NULL, NULL, '0784865373', 5000.00, 17, '2026-07-28', 'activ', NULL, '2026-05-19 11:57:10', '2026-06-12 14:35:49'),
(30, 'Dragan Marius-Vasile', '1983-03-10', NULL, NULL, NULL, '0731210012', 5000.00, 3, '2026-07-28', 'activ', NULL, '2026-05-19 11:57:30', '2026-06-12 13:10:58'),
(31, 'Gaie Marius-Mihai', '1997-06-19', NULL, NULL, NULL, '0733706224', 5000.00, 50, '2033-12-04', 'activ', NULL, '2026-05-19 11:57:56', '2026-06-12 13:52:22'),
(32, 'Neacsu Adrian', '1967-08-01', NULL, NULL, NULL, '0722205460', 5000.00, 52, '2026-08-27', 'inactiv', NULL, '2026-05-19 11:58:17', '2026-06-12 14:36:51'),
(35, 'Lengyel Jozsef-Zoltan', '1966-09-06', NULL, NULL, NULL, '0740255285', 5000.00, 27, '2026-10-29', 'activ', NULL, '2026-05-19 12:00:47', '2026-06-12 13:03:08'),
(36, 'Pienar Petru Costel', '1986-02-13', NULL, NULL, NULL, '0722574574', 5000.00, 63, '2026-08-26', 'activ', NULL, '2026-05-19 12:01:38', '2026-06-12 14:26:52'),
(37, 'Sandu Valentin', '1976-01-18', NULL, NULL, NULL, '0721117499', 5000.00, 61, '2026-07-29', 'activ', NULL, '2026-05-19 12:02:28', '2026-06-12 13:37:19'),
(38, 'Burican Alexandru Daniel', '1983-11-02', NULL, NULL, NULL, '0732805826', 5000.00, 59, '2034-05-06', 'activ', NULL, '2026-05-19 12:03:07', '2026-06-12 13:52:00'),
(39, 'Nicolae Florin', '1994-07-12', NULL, NULL, NULL, '0736422067', 5000.00, 64, '2026-08-26', 'activ', NULL, '2026-05-19 12:03:35', '2026-06-12 14:33:41'),
(40, 'Voinescu Gheorghe', '1964-07-14', NULL, NULL, NULL, '0721655078', 5000.00, 57, '2032-05-23', 'activ', NULL, '2026-05-19 12:04:00', '2026-06-12 13:48:04'),
(41, 'Ene Daniel', '1977-12-01', NULL, NULL, NULL, '0768309601', 5000.00, 25, '2026-07-29', 'activ', NULL, '2026-05-19 12:04:47', '2026-06-12 14:07:49'),
(42, 'Erdos Zoltan', '1974-09-13', NULL, NULL, NULL, '0724506373', 5000.00, 26, '2026-05-27', 'activ', NULL, '2026-05-19 12:05:15', '2026-06-12 13:58:09'),
(43, 'Erdos Istvan', '1978-03-08', NULL, NULL, NULL, '0727339493', 5000.00, 45, '2026-09-30', 'activ', NULL, '2026-05-19 12:05:38', '2026-06-12 14:17:16'),
(44, 'Jula Olimpiu', '1973-09-15', NULL, NULL, NULL, '0757303339', 5000.00, 11, '2026-09-30', 'inactiv', NULL, '2026-05-19 12:06:12', '2026-06-11 14:21:00'),
(46, 'GAIE PAUL', '1970-01-03', NULL, NULL, NULL, '0726713375', NULL, 54, '9999-12-31', 'activ', NULL, '2026-06-15 15:16:49', '2026-06-15 15:24:32');

-- --------------------------------------------------------

--
-- Table structure for table `staff_documents`
--

CREATE TABLE `staff_documents` (
  `id` int UNSIGNED NOT NULL,
  `staff_member_id` int UNSIGNED NOT NULL,
  `tip_document` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `numar_document` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_emitere` date DEFAULT NULL,
  `data_expirare` date DEFAULT NULL,
  `fisier_original` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fisier_stocat` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `observatii` text COLLATE utf8mb4_unicode_ci,
  `created_by` int UNSIGNED DEFAULT NULL,
  `updated_by` int UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `staff_document_requirements`
--

CREATE TABLE `staff_document_requirements` (
  `id` int UNSIGNED NOT NULL,
  `staff_type_id` int UNSIGNED NOT NULL,
  `document_type` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `requires_expiry` tinyint(1) NOT NULL DEFAULT '1',
  `warning_days` smallint UNSIGNED NOT NULL DEFAULT '30',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `staff_document_requirements`
--

INSERT INTO `staff_document_requirements` (`id`, `staff_type_id`, `document_type`, `requires_expiry`, `warning_days`, `created_at`, `updated_at`) VALUES
(1, 1, 'CI / Buletin', 1, 30, '2026-06-11 12:24:35', '2026-06-11 12:24:35'),
(2, 1, 'Permis conducere', 1, 30, '2026-06-11 12:24:35', '2026-06-11 12:24:35'),
(3, 1, 'Medicina muncii', 1, 30, '2026-06-11 12:24:35', '2026-06-11 12:24:35'),
(4, 1, 'Aviz medical', 1, 30, '2026-06-11 12:24:35', '2026-06-11 12:24:35'),
(5, 1, 'Contract de muncă', 0, 30, '2026-06-11 12:24:35', '2026-06-11 12:24:35'),
(6, 6, 'CI / Buletin', 1, 30, '2026-06-11 12:24:35', '2026-06-11 12:24:35'),
(7, 6, 'Contract de muncă', 0, 30, '2026-06-11 12:24:35', '2026-06-11 12:24:35'),
(8, 6, 'Act adițional', 0, 30, '2026-06-11 12:24:35', '2026-06-11 12:24:35'),
(9, 9, 'CI / Buletin', 1, 30, '2026-06-11 12:24:35', '2026-06-11 12:24:35'),
(10, 9, 'Contract de muncă', 0, 30, '2026-06-11 12:24:35', '2026-06-11 12:24:35'),
(11, 3, 'CI / Buletin', 1, 30, '2026-06-11 12:24:35', '2026-06-11 12:24:35'),
(12, 3, 'Medicina muncii', 1, 30, '2026-06-11 12:24:35', '2026-06-11 12:24:35');

-- --------------------------------------------------------

--
-- Table structure for table `staff_members`
--

CREATE TABLE `staff_members` (
  `id` int UNSIGNED NOT NULL,
  `staff_type_id` int UNSIGNED NOT NULL,
  `nume_complet` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefon` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `functie` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `salariu` decimal(10,2) DEFAULT NULL,
  `data_angajare` date DEFAULT NULL,
  `status` enum('activ','inactiv') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'activ',
  `observatii` text COLLATE utf8mb4_unicode_ci,
  `created_by` int UNSIGNED DEFAULT NULL,
  `updated_by` int UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `staff_members`
--

INSERT INTO `staff_members` (`id`, `staff_type_id`, `nume_complet`, `telefon`, `email`, `functie`, `salariu`, `data_angajare`, `status`, `observatii`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES
(4, 6, 'Jianu', '-', 'gifefefl@gmail.com', 'Contabil', 3000.00, '2026-06-12', 'activ', NULL, 1, 1, '2026-06-12 13:48:36', '2026-06-12 13:48:36');

-- --------------------------------------------------------

--
-- Table structure for table `staff_types`
--

CREATE TABLE `staff_types` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(140) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` enum('operational','office') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'operational',
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` enum('activ','inactiv') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'activ',
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `is_driver_linked` tinyint(1) NOT NULL DEFAULT '0',
  `salary_required` tinyint(1) NOT NULL DEFAULT '0',
  `vehicle_required` tinyint(1) NOT NULL DEFAULT '0',
  `mandatory_documents_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `can_create_employees` tinyint(1) NOT NULL DEFAULT '1',
  `can_delete_employees` tinyint(1) NOT NULL DEFAULT '1',
  `document_warning_days` smallint UNSIGNED NOT NULL DEFAULT '30',
  `created_by` int UNSIGNED DEFAULT NULL,
  `updated_by` int UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `staff_types`
--

INSERT INTO `staff_types` (`id`, `name`, `slug`, `category`, `description`, `status`, `is_system`, `is_driver_linked`, `salary_required`, `vehicle_required`, `mandatory_documents_enabled`, `can_create_employees`, `can_delete_employees`, `document_warning_days`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, 'Șofer', 'sofer', 'operational', 'Conectat la modulul Șoferi. Importă automat șoferii existenți.', 'activ', 1, 1, 1, 1, 1, 0, 0, 30, NULL, NULL, '2026-06-11 12:24:35', '2026-06-11 12:24:35'),
(2, 'Ajutor Șofer', 'ajutor-sofer', 'operational', 'Personal operațional auxiliar.', 'activ', 0, 0, 0, 0, 1, 1, 1, 30, NULL, NULL, '2026-06-11 12:24:35', '2026-06-11 12:24:35'),
(3, 'Mecanic', 'mecanic', 'operational', 'Personal operațional pentru mentenanță.', 'activ', 0, 0, 0, 0, 1, 1, 1, 30, NULL, NULL, '2026-06-11 12:24:35', '2026-06-11 12:24:35'),
(4, 'Dispecer', 'dispecer', 'operational', 'Personal operațional de coordonare curse.', 'activ', 0, 0, 0, 0, 1, 1, 1, 30, NULL, NULL, '2026-06-11 12:24:35', '2026-06-11 12:24:35'),
(5, 'Spălător', 'spalator', 'operational', 'Personal operațional de curățenie vehicule.', 'activ', 0, 0, 0, 0, 1, 1, 1, 30, NULL, NULL, '2026-06-11 12:24:35', '2026-06-11 12:24:35'),
(6, 'Contabil', 'contabil', 'office', 'Personal birou pentru contabilitate.', 'activ', 0, 0, 0, 0, 1, 1, 1, 30, NULL, NULL, '2026-06-11 12:24:35', '2026-06-11 12:24:35'),
(7, 'Administrator', 'administrator', 'office', 'Personal birou administrativ.', 'activ', 0, 0, 0, 0, 1, 1, 1, 30, NULL, NULL, '2026-06-11 12:24:35', '2026-06-11 12:24:35'),
(8, 'Manager', 'manager', 'office', 'Personal birou management.', 'activ', 0, 0, 0, 0, 1, 1, 1, 30, NULL, NULL, '2026-06-11 12:24:35', '2026-06-11 12:24:35'),
(9, 'HR', 'hr', 'office', 'Personal birou resurse umane.', 'activ', 0, 0, 0, 0, 1, 1, 1, 30, NULL, NULL, '2026-06-11 12:24:35', '2026-06-11 12:24:35'),
(10, 'Operator', 'operator', 'office', 'Personal birou operațional.', 'activ', 0, 0, 0, 0, 1, 1, 1, 30, NULL, NULL, '2026-06-11 12:24:35', '2026-06-11 12:24:35'),
(11, 'Personal Curățenie', 'personal-curatenie', 'office', 'Personal birou curățenie.', 'activ', 0, 0, 0, 0, 1, 1, 1, 30, NULL, NULL, '2026-06-11 12:24:35', '2026-06-11 12:24:35');

-- --------------------------------------------------------

--
-- Table structure for table `utilizatori`
--

CREATE TABLE `utilizatori` (
  `id` int UNSIGNED NOT NULL,
  `nume` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefon` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parola` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `rol` enum('admin','contabilitate','utilizator') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'utilizator',
  `status` enum('activ','inactiv') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'activ',
  `notificari_email` tinyint(1) NOT NULL DEFAULT '1',
  `notificari_sms` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `utilizatori`
--

INSERT INTO `utilizatori` (`id`, `nume`, `email`, `telefon`, `parola`, `rol`, `status`, `notificari_email`, `notificari_sms`, `created_at`, `updated_at`) VALUES
(1, 'Administrator Sistem', 'gigel.trandafir@lpg-auto.ro', '0774420199', '$2y$10$GWugLSIk7.dnwlxTjcT0Dec4JrE0QSLHSsW59JvT2sBIw9YFlUzhu', 'admin', 'activ', 1, 1, '2026-04-03 15:08:04', '2026-04-06 14:58:38'),
(2, 'Sofer', 'test_utilizator@gmail.com', '0771342736', '$2y$10$8bj3jRbgGwEvKBd62Pf3aeEWKAnv9gEMMQf5i3BFCv5xo6By3VyXy', 'utilizator', 'activ', 1, 1, '2026-04-03 15:08:04', '2026-04-21 13:05:59'),
(3, 'Alexandra Iordache', 'alexandra.iordache@lpg-auto.ro', NULL, '$2y$10$He./C0G98U8x80UFHQ13UeHvGg8a7GQ.E/JYvq5x1MeJPUC09CEei', 'utilizator', 'activ', 1, 0, '2026-05-15 14:59:54', '2026-05-19 13:06:44'),
(5, 'Adriana Ungurean', 'office@lpg-auto.ro', '0723793937', '$2y$10$mrBipF.snQg.urUhbdlV7.X/VRDnh3EzhnlQiHncD4qvPe1J.SVmG', 'utilizator', 'activ', 1, 0, '2026-06-08 13:17:51', '2026-06-09 12:03:23');

-- --------------------------------------------------------

--
-- Table structure for table `vehicule`
--

CREATE TABLE `vehicule` (
  `id` int UNSIGNED NOT NULL,
  `nr_inmatriculare` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `marca` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tip_vehicul` enum('autovehicul','autoutilitara','camion','cap_tractor','semiremorca','semiremorca_primar','semiremorca_distributie') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'autovehicul',
  `an_fabricatie` smallint UNSIGNED NOT NULL,
  `km_bord` int UNSIGNED NOT NULL DEFAULT '0',
  `km_revizie` int UNSIGNED NOT NULL DEFAULT '0',
  `serie_sasiu` varchar(17) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nr_fabricatie` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `capacitate_transport` decimal(10,2) DEFAULT NULL,
  `formula_axelor` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `capacitate_rezervor` decimal(10,2) DEFAULT NULL,
  `mma` decimal(10,2) DEFAULT NULL,
  `organism_notificat` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `poza_original` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `poza_stocata` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `consum_mediu` decimal(5,2) DEFAULT NULL,
  `status` enum('activ','inactiv') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'activ',
  `observatii` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `garaj` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `anvelope_model` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `anvelope_km_durata` int UNSIGNED DEFAULT NULL,
  `anvelope_km_montaj` int UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `vehicule`
--

INSERT INTO `vehicule` (`id`, `nr_inmatriculare`, `marca`, `model`, `tip_vehicul`, `an_fabricatie`, `km_bord`, `km_revizie`, `serie_sasiu`, `nr_fabricatie`, `capacitate_transport`, `formula_axelor`, `capacitate_rezervor`, `mma`, `organism_notificat`, `poza_original`, `poza_stocata`, `consum_mediu`, `status`, `observatii`, `created_at`, `updated_at`, `garaj`, `anvelope_model`, `anvelope_km_durata`, `anvelope_km_montaj`) VALUES
(1, 'B 315 NET', 'DAF', 'CF', 'camion', 2008, 12345, 12345, 'XLRAS85MC0E827841', '1249', 10.00, '4x2', 390.00, 24000.00, 'CNCIR', NULL, NULL, 7.30, 'activ', 'Vehicul pentru livrari locale', '2026-04-03 15:08:04', '2026-06-10 11:12:12', 'PLOIESTI', NULL, NULL, NULL),
(2, 'B 335 NET', 'MAN', 'TGA', 'camion', 2008, 12345, 12345, 'WMAH18ZZ46W073221', '331', 10.00, '4x2', 400.00, 24000.00, 'TUV', NULL, NULL, 9.10, 'activ', 'Autoutilitara transport marfa', '2026-04-03 15:08:04', '2026-06-10 11:14:50', 'PLOIESTI', NULL, NULL, NULL),
(3, 'B 395 NET', 'VOLVO', 'FH', 'camion', 2014, 12345, 12345, 'WMAH20ZZ74W052424', '67421', 10.00, '4x2', 590.00, 24000.00, 'TUV', NULL, NULL, 6.20, 'activ', 'In service prelungit', '2026-04-03 15:08:04', '2026-06-11 10:24:53', 'LUGOJ', NULL, NULL, NULL),
(6, 'B 325 NET', 'DAF', 'CF', 'camion', 2008, 12345, 12505, 'XLRAS85MC0E827789', '1250', 24.00, '4x2', 390.00, 24000.00, NULL, 'download_1.jpg', 'vehicul_20260416_105133_9a44137160bd3dc5.jpg', NULL, 'activ', NULL, '2026-04-16 10:51:33', '2026-06-12 15:52:24', 'CONTESTI', NULL, NULL, NULL),
(9, 'B 218 NET', 'SCANIA', 'R380', 'camion', 2008, 12345, 12345, 'XLER6X20005204888', '29410', 10.00, '4x2', 350.00, 24000.00, 'EUROCERT', 'tank-transport-gas-tank-semi-trailer-VPS-CN50---1776080545104524884_big--26033115022303912300.jpg', 'vehicul_20260420_092951_11225154f0966084.jpg', NULL, 'activ', NULL, '2026-04-20 09:29:51', '2026-06-10 10:57:59', 'SUD-BUCUREST', NULL, NULL, NULL),
(10, 'B 805 NET', 'VPS', 'VPSCN', 'semiremorca_primar', 2018, 12345, 12345, 'TN9VPSCN3JRVP5675', '74124', 20.00, '3 axe', NULL, 40000.00, 'TUV', 'tank-transport-gas-tank-semi-trailer-VPS-CN50---1776080545104524884_big--26033115022303912300.jpg', 'vehicul_20260420_095109_3212d38400bf0aad.jpg', NULL, 'activ', NULL, '2026-04-20 09:51:09', '2026-06-10 11:44:28', 'SUD-BUCUREST', NULL, NULL, NULL),
(11, 'B 665 NET', 'DAF', 'XF', 'cap_tractor', 2019, 12345, 12345, 'XLRTEH4100G274734', NULL, NULL, '4x2', 544.00, 40000.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-04-21 09:15:42', '2026-06-09 09:04:20', 'ORADEA', NULL, NULL, NULL),
(12, 'B 405 NET', 'VPS', 'VPSCN', 'semiremorca_distributie', 2016, 12345, 12345, 'TN9VPSCN3GRVP5512', '61331', 18.00, '3 axe', NULL, 38000.00, 'TUV', NULL, NULL, NULL, 'activ', NULL, '2026-04-21 09:20:12', '2026-06-12 14:35:53', 'ORADEA', NULL, NULL, NULL),
(13, 'B 655 NET', 'DAF', 'XF', 'cap_tractor', 2019, 12345, 12345, 'XLRTEH4100G250526', NULL, NULL, '4x2', 544.00, 40000.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-04-21 09:33:26', '2026-06-08 15:51:01', 'Oradea', NULL, NULL, NULL),
(14, 'B 305 NET', 'VPS', 'VPSCN', 'semiremorca_distributie', 2014, 12345, 12345, 'TN9VPSCN3ERVP5377', NULL, 18.00, '3 axe', NULL, 38000.00, 'EUROCERT', NULL, NULL, NULL, 'activ', NULL, '2026-04-21 12:06:27', '2026-06-08 15:35:58', 'ORADEA', NULL, NULL, NULL),
(15, 'B 285 NET', 'Mercedes', 'ATEGO', 'camion', 2011, 12345, 12345, 'WDB9505311L583496', '787', 7.00, '4x2', 400.00, 18000.00, 'CNCIR', NULL, NULL, NULL, 'activ', NULL, '2026-04-24 14:16:58', '2026-06-10 11:09:49', 'Oradea', NULL, NULL, NULL),
(16, 'B 375 NET', 'VOLVO', 'FH440', 'camion', 2007, 12345, 12345, 'YV2AS02CX7B455853', '26405', 10.00, '4x2', 400.00, 24000.00, 'TUV', NULL, NULL, NULL, 'activ', NULL, '2026-04-24 14:17:42', '2026-06-10 11:25:28', 'Oradea', NULL, NULL, NULL),
(17, 'B 385 NET', 'VOLVO', 'FH440', 'camion', 2007, 12345, 12345, 'YV2AS02C97B455861', '26406', 10.00, '4x2', 400.00, 24000.00, 'TUV', NULL, NULL, NULL, 'activ', NULL, '2026-04-24 14:18:30', '2026-06-10 11:27:10', 'ORADEA', NULL, NULL, NULL),
(18, 'B 437 NET', 'MAN', 'TGA', 'camion', 2004, 12345, 12345, 'WMAH20ZZ44WO52509', NULL, 10.00, '4x2', 500.00, 24000.00, 'TUV', NULL, NULL, NULL, 'activ', NULL, '2026-04-24 14:19:11', '2026-06-11 11:45:38', 'ORADEA', NULL, NULL, NULL),
(19, 'B 219 NET', 'SCANIA', 'R400', 'camion', 2010, 12345, 12345, 'XLER6X20005255463', '35559', 10.00, '4x2', 350.00, 24000.00, 'TUV', NULL, NULL, NULL, 'activ', NULL, '2026-04-24 14:20:00', '2026-06-10 11:01:18', 'Lugoj', NULL, NULL, NULL),
(20, 'B 345 NET', 'MERCEDES', 'AXOR', 'camion', 2005, 12345, 12345, 'WDB9506031L059142', NULL, 10.00, '4x2', 300.00, 24000.00, 'TUV', NULL, NULL, NULL, 'activ', NULL, '2026-04-24 14:20:45', '2026-06-10 11:27:19', 'Lugoj', NULL, NULL, NULL),
(21, 'B 311 NET', 'DAF', 'XF', 'camion', 2009, 12345, 12345, 'XLRAS47MS0E854628', '71836', 10.00, '4x2', 430.00, 24000.00, 'TUV', NULL, NULL, NULL, 'activ', NULL, '2026-04-24 14:21:36', '2026-06-10 11:11:05', 'Lugoj', NULL, NULL, NULL),
(22, 'B 430 NET', 'VOLVO', 'FM400', 'camion', 2007, 12345, 12345, 'YV2JSGOCX7B471336', '351', 10.00, '4x2', 600.00, 24000.00, 'TUV', NULL, NULL, NULL, 'activ', NULL, '2026-04-24 14:22:08', '2026-06-10 11:28:52', 'LUGOJ', NULL, NULL, NULL),
(23, 'B 275 NET', 'DAF', 'CF', 'camion', 1999, 12345, 12345, 'XLRAE75PC0E989781', '21378', 7.00, '4x2', 314.00, 18000.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-04-24 14:23:11', '2026-06-10 11:09:02', 'CONTESTI', NULL, NULL, NULL),
(24, 'B 235 NET', 'MERCEDES', 'ATEGO', 'camion', 2003, 12345, 12345, 'WDB9505011K846854', 'N717', 7.00, '4x2', 300.00, 18000.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-04-24 14:23:48', '2026-06-10 11:08:05', 'PLOIESTI', NULL, NULL, NULL),
(25, 'B 775 NET', 'MERCEDES', 'ACTROS', 'camion', 2016, 12345, 12345, 'WDB96302010051081', '102864', 10.00, '4x2', 500.00, 24000.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-04-24 14:25:20', '2026-06-10 11:25:41', 'CONTESTI', NULL, NULL, NULL),
(26, 'B 677 NET', 'MAN', 'TGX', 'cap_tractor', 2014, 20000, 12345, 'WMA06XZZ0FM667342', NULL, NULL, '4x2', 500.00, 35000.00, '-', 'file.enc.jpeg', 'vehicul_20260615_154957_1c4cae57193c9c3e.jpeg', NULL, 'activ', NULL, '2026-05-05 12:40:12', '2026-06-15 15:49:57', 'Salonta', NULL, NULL, NULL),
(27, 'B 435 NET', 'MAN', 'TGA', 'camion', 2003, 200000, 12345, 'WMAH20ZZZ3M367987', '16260', 10.00, '4x2', 600.00, 24000.00, '-', NULL, NULL, NULL, 'activ', NULL, '2026-05-05 12:52:38', '2026-06-10 11:24:36', 'Salonta', NULL, NULL, NULL),
(28, 'B 605 NET', 'DAF', 'XF', 'cap_tractor', 2011, 323232, 323932, 'XLRTE47MS0E897272', '32131', 0.00, '4x2', NULL, 40000.00, '-', NULL, NULL, NULL, 'activ', 'CUPLAT CU SEMIREMORCA B705NET', '2026-05-07 12:28:17', '2026-06-16 10:09:58', 'SUD', NULL, NULL, NULL),
(31, 'B 105 NET', 'DAF', 'XF', 'cap_tractor', 2008, 250000, 250000, 'XLRTE47MS0E839468', '74124', NULL, '4x2', 780.00, 40000.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-05-19 12:27:19', '2026-06-10 10:53:04', 'SUD-BUCUREST', NULL, NULL, NULL),
(32, 'B 935 NET', 'VPS', 'VPSCN', 'semiremorca_primar', 2009, 250000, 250000, 'TN9VPS0619RVP5132', '31710', 18.00, '3 axe', NULL, 38000.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-05-19 12:38:42', '2026-06-12 13:44:23', 'SUD-BUCUREST', NULL, NULL, NULL),
(37, 'B 232 NET', 'MERCEDES', 'AXOR', 'camion', 2005, 12345, 12345, 'WDB9505321L058480', '21476', 0.00, '4x2', 300.00, 18.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-05-20 14:14:29', '2026-06-10 11:23:45', 'SIBIU', NULL, NULL, NULL),
(40, 'B 295 NET', 'MERCEDES', 'ATEGO', 'camion', 2011, 12344, 12344, 'WDB9505311L558334', '790', 7.00, '4x2', 400.00, 18.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-05-21 13:29:54', '2026-06-10 11:10:25', 'LUGOJ', NULL, NULL, NULL),
(41, 'B 433 NET', 'MAN', 'TGA', 'camion', 2003, 12345, 12345, 'WMAH20ZZZ3M366391', '17754', 10.00, '4x2', 700.00, 24000.00, 'TUV', NULL, NULL, NULL, 'activ', NULL, '2026-05-26 10:12:21', '2026-06-10 11:23:09', 'SIBIU', NULL, NULL, NULL),
(42, 'B 439 NET', 'MAN', 'TGA', 'camion', 2004, 12345, 12345, 'WMAH20ZZ74W052424', '727', 9.50, '4x2', 300.00, 24000.00, 'EUROCERT', NULL, NULL, NULL, 'activ', NULL, '2026-05-26 10:14:19', '2026-06-16 10:07:06', 'Salonta', NULL, NULL, NULL),
(43, 'B 189 NET', 'SCANIA', 'R114', 'camion', 2003, 12345, 12345, 'XLER8X20004502456', '14840', 12.00, '4x2', 350.00, 32000.00, 'TUV', NULL, NULL, NULL, 'activ', NULL, '2026-05-26 10:15:57', '2026-06-10 10:55:26', 'CONTESTI', NULL, NULL, NULL),
(44, 'B 199 NET', 'SCANIA', 'G400', 'camion', 2012, 12345, 12345, 'XLEG8X20005287743', '14841', 12.00, '8x2', 300.00, 32000.00, 'EUROCERT', NULL, NULL, NULL, 'activ', NULL, '2026-05-26 10:46:02', '2026-06-10 10:57:23', NULL, NULL, NULL, NULL),
(45, 'B 678 NET', 'MAN', 'TGZ', 'cap_tractor', 2014, 12345, 12345, 'WMA06XZZ5FM667370', NULL, NULL, '4x2', 500.00, 35000.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-05-26 10:48:24', '2026-06-10 11:07:32', 'Salonta', NULL, NULL, NULL),
(46, 'B 680 NET', 'EUROTANK', 'GTA36', 'semiremorca_primar', 2004, 12344, 12345, '0000000TP4T210083', '18192', 16.00, '2 axe', NULL, 35000.00, 'CNCIR', NULL, NULL, NULL, 'activ', NULL, '2026-05-26 10:52:19', '2026-06-10 11:05:45', 'Salonta', NULL, NULL, NULL),
(47, 'B 679 NET', 'EUROTANK', 'GT', 'semiremorca_primar', 2004, 12345, 12345, '0000000TP4T210082', '18191', 16.00, '2 axe', NULL, 35000.00, 'EUROCERT', NULL, NULL, NULL, 'activ', NULL, '2026-05-26 11:00:33', '2026-06-10 11:05:13', 'Salonta', NULL, NULL, NULL),
(48, 'B 165 NET', 'DAF', 'XF', 'cap_tractor', 2011, 12345, 12345, 'XLRTE47MS0E900514', NULL, NULL, '4x2', 850.00, 20500.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-05-26 11:23:58', '2026-06-10 10:53:42', 'SUD-BUCUREST', NULL, NULL, NULL),
(49, 'B 945 NET', 'VPS', 'VPSCN', 'semiremorca_distributie', 2026, 12345, 12345, 'TN9VPSCN3SRVP6013', '128551', NULL, '3 axe', NULL, 38000.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-05-26 11:40:21', '2026-06-10 14:24:44', NULL, NULL, NULL, NULL),
(50, 'B 400 NET', 'DAF', 'CF', 'cap_tractor', 2010, 13007, 11683, 'XLRTE85MC0E890378', NULL, NULL, '4x2', 545.00, 20.50, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-05-26 11:54:53', '2026-06-16 12:38:18', NULL, NULL, NULL, NULL),
(51, 'B 925 NET', 'VPS', 'VPSCN', 'semiremorca_distributie', 2010, 12345, 12345, 'TN9VPS091ARVP5246', '36068', 18.00, '3 axe', NULL, 40000.00, 'TUV', NULL, NULL, NULL, 'activ', NULL, '2026-05-26 11:59:05', '2026-06-10 14:08:59', NULL, NULL, NULL, NULL),
(52, 'B 401 NET', 'DAF', 'CF', 'cap_tractor', 2010, 13445, 11245, 'XLRTE85MC0E890391', NULL, NULL, '4x2', 545.00, 20500.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-05-26 12:07:25', '2026-06-16 12:51:32', NULL, NULL, NULL, NULL),
(53, 'B 815 NET', 'VPS', 'VPSCN', 'semiremorca_primar', 2021, 12345, 12344, 'TN9VPSCN3MRVP5789', '101364', 20.00, '3 axe', NULL, 38000.00, 'EUROCERT', NULL, NULL, NULL, 'activ', NULL, '2026-05-26 13:12:15', '2026-06-16 10:15:53', NULL, NULL, NULL, NULL),
(54, 'B 402 NET', 'DAF', 'CF', 'cap_tractor', 2010, 12345, 12345, 'XLRTE85MC0E899311', NULL, NULL, '4x2', 545.00, 40000.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-05-28 13:17:03', '2026-06-10 11:32:24', NULL, NULL, NULL, NULL),
(55, 'B 905 NET', 'VPS', 'VPSCN', 'semiremorca_distributie', 2021, 12345, 12345, 'TN9VPSCN3MRVP5786', '91269', 18.00, '3 axe', NULL, 38000.00, 'EUROCERT', NULL, NULL, NULL, 'activ', NULL, '2026-05-28 13:24:17', '2026-06-10 10:58:38', NULL, NULL, NULL, NULL),
(56, 'B 705 NET', 'VPS', 'VPSCN', 'semiremorca_distributie', 2017, 12345, 12345, 'TN9VPSCN3HRVP5583', '68527', 18.00, '3 axe', NULL, 38000.00, 'TUV', NULL, NULL, NULL, 'activ', NULL, '2026-05-28 13:26:27', '2026-06-10 10:48:25', NULL, NULL, NULL, NULL),
(57, 'B 615 NET', 'DAF', 'XF', 'cap_tractor', 2015, 13545, 11145, 'XLRTEH4300G088440', NULL, NULL, '4x2', 620.00, 25000.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-05-28 13:30:12', '2026-06-16 12:57:52', NULL, NULL, NULL, NULL),
(58, 'B 825 NET', 'VPS', 'VPSCN', 'semiremorca_primar', 2021, 13545, 11145, 'TN9VPSCN3MRVP5797', '2021', 20.00, '3 axe', NULL, 38000.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-05-28 13:33:04', '2026-06-16 12:57:52', NULL, NULL, NULL, NULL),
(59, 'B 625 NET', 'DAF', 'CF', 'cap_tractor', 2016, 12345, 12345, 'XLRTEM4100G107575', NULL, NULL, '4x2', 500.00, 19500.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-05-28 13:38:11', '2026-06-10 11:42:36', NULL, NULL, NULL, NULL),
(60, 'B 845 NET', 'VPS', 'VPSCN', 'semiremorca_primar', 2025, 12345, 12345, 'TN9VPSCN3RRVP6008', '123700', 20.00, '3 axe', NULL, 38000.00, 'TUV', NULL, NULL, NULL, 'activ', NULL, '2026-05-28 13:40:48', '2026-06-10 14:22:23', NULL, NULL, NULL, NULL),
(61, 'B 635 NET', 'DAF', 'XF', 'cap_tractor', 2013, 13319, 11371, 'XLRTE47MS0E984196', NULL, NULL, '4x2', 850.00, 20500.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-05-28 13:42:44', '2026-06-16 13:23:19', NULL, NULL, NULL, NULL),
(62, 'B 915 NET', 'VPS', 'VPSCN', 'semiremorca_distributie', 2025, 12345, 12345, 'TN9VPSCN3RRVP6007', '121524', 18.00, '3 axe', NULL, 38000.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-05-28 13:59:31', '2026-06-12 13:20:10', NULL, NULL, NULL, NULL),
(63, 'B 645 NET', 'DAF', 'XF', 'cap_tractor', 2018, 12345, 12345, 'XLRTEH4100G245794', NULL, NULL, '4x2', 845.00, 20500.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-05-28 14:05:51', '2026-06-10 10:25:53', NULL, NULL, NULL, NULL),
(64, 'B 652 NET', 'DAF', 'CF', 'cap_tractor', 2012, 13545, 11145, 'XLRTE85MC0E960322', NULL, NULL, '4x2', 580.00, 20500.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-05-28 14:08:28', '2026-06-16 13:01:52', NULL, NULL, NULL, NULL),
(65, 'B 835 NET', 'LDS', 'NCX', 'semiremorca_primar', 2016, 13545, 11145, 'SV9NCG500G0BC2940', 'NG940', 20.00, '3 axe', NULL, 37000.00, 'TUV', NULL, NULL, NULL, 'activ', NULL, '2026-05-28 14:10:35', '2026-06-16 13:01:52', NULL, NULL, NULL, NULL),
(66, 'B 888 NET', 'FIAT', '250', 'autoutilitara', 2021, 12345, 12345, 'ZFA25000002T30883', NULL, NULL, '4x2', 90.00, 3500.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-06-03 09:15:19', '2026-06-12 14:21:21', 'SUD-BUCUREST', NULL, NULL, NULL),
(67, 'B 875 NET', 'MERCEDES', '212', 'autovehicul', 2020, 12345, 12345, 'W1K2130531A854089', NULL, NULL, '4x2', 60.00, 2600.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-06-03 09:18:40', '2026-06-12 14:19:44', NULL, NULL, NULL, NULL),
(68, 'B 230 NET', 'DACIA', 'DBG', 'autovehicul', 2022, 12345, 12345, 'UU1DBG00XNU048513', NULL, NULL, '4x2', NULL, 1300.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-06-03 09:20:16', '2026-06-16 10:18:33', NULL, NULL, NULL, NULL),
(70, 'B 184 DFA', 'FORD', 'J2K', 'autovehicul', 2023, 12345, 12345, 'WF02XXERK2PU24694', NULL, 42.00, '4x2', NULL, 1835.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-06-03 09:22:57', '2026-06-12 14:18:45', NULL, NULL, NULL, NULL),
(71, 'B 177 NET', 'AUDI', 'F2', 'autovehicul', 2019, 12345, 12345, 'WAUZZZF20KN108938', NULL, NULL, '4x2', 73.00, 2475.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-06-03 09:24:31', '2026-06-11 14:35:33', NULL, NULL, NULL, NULL),
(72, 'B 34 NET', 'DACIA', 'LSDAB', 'autovehicul', 2004, 12345, 12345, 'UUJLSDABH32335359', NULL, NULL, '4x2', 50.00, 1540.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-06-03 09:26:40', '2026-06-12 14:14:05', NULL, NULL, NULL, NULL),
(73, 'B 72 NET', 'DACIA', 'SANDERO', 'autovehicul', 2009, 12345, 12345, 'UU1BSDAFF41670469', NULL, NULL, '4x2', 50.00, 1536.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-06-03 09:33:38', '2026-06-12 14:16:01', NULL, NULL, NULL, NULL),
(74, 'B 82 NET', 'DACIA', 'LOGAN', 'autovehicul', 2016, 12345, 12345, 'UU14SDE3456254740', NULL, NULL, '4x2', 50.00, 1505.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-06-03 09:36:29', '2026-06-12 14:27:32', NULL, NULL, NULL, NULL),
(76, 'B 112 NET', 'FIAT', 'DUCATO', 'autoutilitara', 2009, 12345, 12345, 'ZFA25000001614180', NULL, NULL, '4x2', 90.00, 3500.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-06-12 10:25:40', '2026-06-12 14:17:45', NULL, NULL, NULL, NULL),
(77, 'B 669 NET', 'DACIA', 'LOGAN', 'autovehicul', 2012, 12345, 12345, 'UU1LSDA3P47003940', NULL, NULL, '4x2', 50.00, 1540.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-06-12 11:41:29', '2026-06-16 10:18:28', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `vehicule_anvelope_pozitii`
--

CREATE TABLE `vehicule_anvelope_pozitii` (
  `id` int UNSIGNED NOT NULL,
  `vehicle_id` int UNSIGNED NOT NULL,
  `position_code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `position_label` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `axle_no` tinyint UNSIGNED NOT NULL,
  `side_code` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL,
  `wheel_kind` enum('single','dual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'single',
  `position_order` smallint UNSIGNED NOT NULL DEFAULT '1',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `vehicule_anvelope_pozitii`
--

INSERT INTO `vehicule_anvelope_pozitii` (`id`, `vehicle_id`, `position_code`, `position_label`, `axle_no`, `side_code`, `wheel_kind`, `position_order`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-06-10 11:12:14'),
(2, 1, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-06-10 11:12:14'),
(3, 1, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 0, '2026-05-12 11:25:27', '2026-06-10 11:12:14'),
(4, 1, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 0, '2026-05-12 11:25:27', '2026-06-10 11:12:14'),
(5, 2, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-06-15 15:45:03'),
(6, 2, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-06-15 15:45:03'),
(7, 2, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 0, '2026-05-12 11:25:27', '2026-06-15 15:45:03'),
(8, 2, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 0, '2026-05-12 11:25:27', '2026-06-15 15:45:03'),
(9, 3, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-06-11 10:24:28'),
(10, 3, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-06-11 10:24:28'),
(11, 3, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 0, '2026-05-12 11:25:27', '2026-06-11 10:24:28'),
(12, 3, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 0, '2026-05-12 11:25:27', '2026-06-11 10:24:28'),
(17, 6, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-06-15 10:44:44'),
(18, 6, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-06-15 10:44:44'),
(19, 6, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-06-15 10:44:44'),
(20, 6, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-06-15 10:44:44'),
(21, 6, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-06-15 10:44:44'),
(22, 6, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-06-15 10:44:44'),
(23, 6, 'A3-LO', 'Axa 3 - Stanga exterior', 3, 'LO', 'dual', 7, 0, '2026-05-12 11:25:27', '2026-06-15 10:44:44'),
(24, 6, 'A3-LI', 'Axa 3 - Stanga interior', 3, 'LI', 'dual', 8, 0, '2026-05-12 11:25:27', '2026-06-15 10:44:44'),
(25, 6, 'A3-RI', 'Axa 3 - Dreapta interior', 3, 'RI', 'dual', 9, 0, '2026-05-12 11:25:27', '2026-06-15 10:44:44'),
(26, 6, 'A3-RO', 'Axa 3 - Dreapta exterior', 3, 'RO', 'dual', 10, 0, '2026-05-12 11:25:27', '2026-06-15 10:44:44'),
(27, 9, 'A1-LO', 'Axa 1 - Stanga exterior', 1, 'LO', 'dual', 1, 1, '2026-05-12 11:25:27', '2026-05-12 13:20:04'),
(28, 9, 'A1-LI', 'Axa 1 - Stanga interior', 1, 'LI', 'dual', 2, 0, '2026-05-12 11:25:27', '2026-06-10 10:58:01'),
(29, 9, 'A1-RI', 'Axa 1 - Dreapta interior', 1, 'RI', 'dual', 3, 0, '2026-05-12 11:25:27', '2026-06-10 10:58:01'),
(30, 9, 'A1-RO', 'Axa 1 - Dreapta exterior', 1, 'RO', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-05-12 13:20:04'),
(31, 9, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-06-10 10:58:01'),
(32, 9, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-06-10 10:58:01'),
(33, 9, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-06-10 10:58:01'),
(34, 9, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-06-10 10:58:01'),
(35, 9, 'A3-LO', 'Axa 3 - Stanga exterior', 3, 'LO', 'dual', 9, 1, '2026-05-12 11:25:27', '2026-05-12 13:20:04'),
(36, 9, 'A3-LI', 'Axa 3 - Stanga interior', 3, 'LI', 'dual', 10, 1, '2026-05-12 11:25:27', '2026-05-12 13:20:04'),
(37, 9, 'A3-RI', 'Axa 3 - Dreapta interior', 3, 'RI', 'dual', 11, 1, '2026-05-12 11:25:27', '2026-05-12 13:20:04'),
(38, 9, 'A3-RO', 'Axa 3 - Dreapta exterior', 3, 'RO', 'dual', 12, 1, '2026-05-12 11:25:27', '2026-05-12 13:20:04'),
(39, 10, 'A1-LO', 'Axa 1 - Stanga exterior', 1, 'LO', 'dual', 1, 0, '2026-05-12 11:25:27', '2026-06-10 11:44:29'),
(40, 10, 'A1-LI', 'Axa 1 - Stanga interior', 1, 'LI', 'dual', 2, 0, '2026-05-12 11:25:27', '2026-06-10 11:44:29'),
(41, 10, 'A1-RI', 'Axa 1 - Dreapta interior', 1, 'RI', 'dual', 3, 0, '2026-05-12 11:25:27', '2026-06-10 11:44:29'),
(42, 10, 'A1-RO', 'Axa 1 - Dreapta exterior', 1, 'RO', 'dual', 4, 0, '2026-05-12 11:25:27', '2026-06-10 11:44:29'),
(43, 10, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 5, 0, '2026-05-12 11:25:27', '2026-06-10 11:44:29'),
(44, 10, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 6, 0, '2026-05-12 11:25:27', '2026-06-10 11:44:29'),
(45, 10, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 7, 0, '2026-05-12 11:25:27', '2026-06-10 11:44:29'),
(46, 10, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 8, 0, '2026-05-12 11:25:27', '2026-06-10 11:44:29'),
(47, 10, 'A3-LO', 'Axa 3 - Stanga exterior', 3, 'LO', 'dual', 9, 0, '2026-05-12 11:25:27', '2026-06-10 11:44:29'),
(48, 10, 'A3-LI', 'Axa 3 - Stanga interior', 3, 'LI', 'dual', 10, 0, '2026-05-12 11:25:27', '2026-06-10 11:44:29'),
(49, 10, 'A3-RI', 'Axa 3 - Dreapta interior', 3, 'RI', 'dual', 11, 0, '2026-05-12 11:25:27', '2026-06-10 11:44:29'),
(50, 10, 'A3-RO', 'Axa 3 - Dreapta exterior', 3, 'RO', 'dual', 12, 0, '2026-05-12 11:25:27', '2026-06-10 11:44:29'),
(51, 11, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-06-10 12:00:49'),
(52, 11, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-06-10 12:00:49'),
(53, 11, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-06-10 12:00:49'),
(54, 11, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-06-10 12:00:49'),
(55, 11, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-06-10 12:00:49'),
(56, 11, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-06-10 12:00:49'),
(57, 12, 'A1-LO', 'Axa 1 - Stanga exterior', 1, 'LO', 'dual', 1, 0, '2026-05-12 11:25:27', '2026-06-12 14:48:31'),
(58, 12, 'A1-LI', 'Axa 1 - Stanga interior', 1, 'LI', 'dual', 2, 0, '2026-05-12 11:25:27', '2026-06-12 14:48:31'),
(59, 12, 'A1-RI', 'Axa 1 - Dreapta interior', 1, 'RI', 'dual', 3, 0, '2026-05-12 11:25:27', '2026-06-12 14:48:31'),
(60, 12, 'A1-RO', 'Axa 1 - Dreapta exterior', 1, 'RO', 'dual', 4, 0, '2026-05-12 11:25:27', '2026-06-12 14:48:31'),
(61, 12, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 5, 0, '2026-05-12 11:25:27', '2026-06-12 14:48:31'),
(62, 12, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 6, 0, '2026-05-12 11:25:27', '2026-06-12 14:48:31'),
(63, 12, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 7, 0, '2026-05-12 11:25:27', '2026-06-12 14:48:31'),
(64, 12, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 8, 0, '2026-05-12 11:25:27', '2026-06-12 14:48:31'),
(65, 12, 'A3-LO', 'Axa 3 - Stanga exterior', 3, 'LO', 'dual', 9, 0, '2026-05-12 11:25:27', '2026-06-12 14:48:31'),
(66, 12, 'A3-LI', 'Axa 3 - Stanga interior', 3, 'LI', 'dual', 10, 0, '2026-05-12 11:25:27', '2026-06-12 14:48:31'),
(67, 12, 'A3-RI', 'Axa 3 - Dreapta interior', 3, 'RI', 'dual', 11, 0, '2026-05-12 11:25:27', '2026-06-12 14:48:31'),
(68, 12, 'A3-RO', 'Axa 3 - Dreapta exterior', 3, 'RO', 'dual', 12, 0, '2026-05-12 11:25:27', '2026-06-12 14:48:31'),
(69, 13, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-06-10 11:59:39'),
(70, 13, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-06-10 11:59:39'),
(71, 13, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-06-10 11:59:39'),
(72, 13, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-06-10 11:59:39'),
(73, 13, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-06-10 11:59:39'),
(74, 13, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-06-10 11:59:39'),
(75, 14, 'A1-LO', 'Axa 1 - Stanga exterior', 1, 'LO', 'dual', 1, 0, '2026-05-12 11:25:27', '2026-06-12 14:34:58'),
(76, 14, 'A1-LI', 'Axa 1 - Stanga interior', 1, 'LI', 'dual', 2, 0, '2026-05-12 11:25:27', '2026-06-12 14:34:58'),
(77, 14, 'A1-RI', 'Axa 1 - Dreapta interior', 1, 'RI', 'dual', 3, 0, '2026-05-12 11:25:27', '2026-06-12 14:34:58'),
(78, 14, 'A1-RO', 'Axa 1 - Dreapta exterior', 1, 'RO', 'dual', 4, 0, '2026-05-12 11:25:27', '2026-06-12 14:34:58'),
(79, 14, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 5, 0, '2026-05-12 11:25:27', '2026-06-12 14:34:58'),
(80, 14, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 6, 0, '2026-05-12 11:25:27', '2026-06-12 14:34:58'),
(81, 14, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 7, 0, '2026-05-12 11:25:27', '2026-06-12 14:34:58'),
(82, 14, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 8, 0, '2026-05-12 11:25:27', '2026-06-12 14:34:58'),
(83, 14, 'A3-LO', 'Axa 3 - Stanga exterior', 3, 'LO', 'dual', 9, 0, '2026-05-12 11:25:27', '2026-06-12 14:34:58'),
(84, 14, 'A3-LI', 'Axa 3 - Stanga interior', 3, 'LI', 'dual', 10, 0, '2026-05-12 11:25:27', '2026-06-12 14:34:58'),
(85, 14, 'A3-RI', 'Axa 3 - Dreapta interior', 3, 'RI', 'dual', 11, 0, '2026-05-12 11:25:27', '2026-06-12 14:34:58'),
(86, 14, 'A3-RO', 'Axa 3 - Dreapta exterior', 3, 'RO', 'dual', 12, 0, '2026-05-12 11:25:27', '2026-06-12 14:34:58'),
(87, 15, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-06-10 11:09:52'),
(88, 15, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-06-10 11:09:52'),
(89, 15, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-06-10 11:09:52'),
(90, 15, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-06-10 11:09:52'),
(91, 15, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-06-10 11:09:52'),
(92, 15, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-06-10 11:09:52'),
(93, 16, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-06-10 11:25:39'),
(94, 16, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-06-10 11:25:39'),
(95, 16, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-06-10 11:25:39'),
(96, 16, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-06-10 11:25:39'),
(97, 16, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-06-10 11:25:39'),
(98, 16, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-06-10 11:25:39'),
(99, 17, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-06-10 11:27:12'),
(100, 17, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-06-10 11:27:12'),
(101, 17, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 0, '2026-05-12 11:25:27', '2026-06-10 11:27:12'),
(102, 17, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 0, '2026-05-12 11:25:27', '2026-06-10 11:27:12'),
(103, 18, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-06-11 11:45:42'),
(104, 18, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-06-11 11:45:42'),
(105, 18, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-06-11 11:45:42'),
(106, 18, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-06-11 11:45:42'),
(107, 18, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-06-11 11:45:42'),
(108, 18, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-06-11 11:45:42'),
(109, 19, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-06-10 11:01:20'),
(110, 19, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-06-10 11:01:20'),
(111, 19, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-06-10 11:01:20'),
(112, 19, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-06-10 11:01:20'),
(113, 19, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-06-10 11:01:20'),
(114, 19, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-06-10 11:01:20'),
(115, 20, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-06-10 11:27:21'),
(116, 20, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-06-10 11:27:21'),
(117, 20, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-06-10 11:27:21'),
(118, 20, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-06-10 11:27:21'),
(119, 20, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-06-10 11:27:21'),
(120, 20, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-06-10 11:27:21'),
(121, 21, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-06-10 11:11:07'),
(122, 21, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-06-10 11:11:07'),
(123, 21, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-06-10 11:11:07'),
(124, 21, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-06-10 11:11:07'),
(125, 21, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-06-10 11:11:07'),
(126, 21, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-06-10 11:11:07'),
(127, 22, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-06-10 11:32:36'),
(128, 22, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-06-10 11:32:36'),
(129, 22, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-06-10 11:32:36'),
(130, 22, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-06-10 11:32:36'),
(131, 22, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-06-10 11:32:36'),
(132, 22, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-06-10 11:32:36'),
(133, 23, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-06-12 15:23:00'),
(134, 23, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-06-12 15:23:00'),
(135, 23, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-06-12 15:23:00'),
(136, 23, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-06-12 15:23:00'),
(137, 23, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-06-12 15:23:00'),
(138, 23, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-06-12 15:23:00'),
(139, 24, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-06-12 15:00:11'),
(140, 24, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-06-12 15:00:11'),
(141, 24, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-06-12 15:00:11'),
(142, 24, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-06-12 15:00:11'),
(143, 24, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-06-12 15:00:11'),
(144, 24, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-06-12 15:00:11'),
(145, 25, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-06-10 11:25:46'),
(146, 25, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-06-10 11:25:46'),
(147, 25, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-06-10 11:25:46'),
(148, 25, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-06-10 11:25:46'),
(149, 25, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-06-10 11:25:46'),
(150, 25, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-06-10 11:25:46'),
(151, 26, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-06-15 15:49:57'),
(152, 26, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-06-15 15:49:57'),
(153, 26, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-06-15 15:49:57'),
(154, 26, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-06-15 15:49:57'),
(155, 26, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-06-15 15:49:57'),
(156, 26, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-06-15 15:49:57'),
(157, 27, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-06-10 11:24:40'),
(158, 27, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-06-10 11:24:40'),
(159, 27, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-06-10 11:24:40'),
(160, 27, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-06-10 11:24:40'),
(161, 27, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-06-10 11:24:40'),
(162, 27, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-06-10 11:24:40'),
(163, 28, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-06-16 10:07:20'),
(164, 28, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-06-16 10:07:20'),
(165, 28, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-06-16 10:07:20'),
(166, 28, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-06-16 10:07:20'),
(167, 28, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-06-16 10:07:20'),
(168, 28, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-06-16 10:07:20'),
(173, 31, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-19 12:27:19', '2026-06-12 14:14:36'),
(174, 31, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-19 12:27:19', '2026-06-12 14:14:36'),
(175, 31, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-19 12:27:19', '2026-06-12 14:14:36'),
(176, 31, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-19 12:27:19', '2026-06-12 14:14:36'),
(177, 31, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-19 12:27:19', '2026-06-12 14:14:36'),
(178, 31, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-19 12:27:19', '2026-06-12 14:14:36'),
(179, 32, 'A1-LO', 'Axa 1 - Stanga exterior', 1, 'LO', 'dual', 1, 0, '2026-05-19 12:38:42', '2026-06-12 13:44:32'),
(180, 32, 'A1-LI', 'Axa 1 - Stanga interior', 1, 'LI', 'dual', 2, 0, '2026-05-19 12:38:42', '2026-06-12 13:44:32'),
(181, 32, 'A1-RI', 'Axa 1 - Dreapta interior', 1, 'RI', 'dual', 3, 0, '2026-05-19 12:38:42', '2026-06-12 13:44:32'),
(182, 32, 'A1-RO', 'Axa 1 - Dreapta exterior', 1, 'RO', 'dual', 4, 0, '2026-05-19 12:38:42', '2026-06-12 13:44:32'),
(183, 32, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 5, 0, '2026-05-19 12:38:42', '2026-06-12 13:44:32'),
(184, 32, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 6, 0, '2026-05-19 12:38:42', '2026-06-12 13:44:32'),
(185, 32, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 7, 0, '2026-05-19 12:38:42', '2026-06-12 13:44:32'),
(186, 32, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 8, 0, '2026-05-19 12:38:42', '2026-06-12 13:44:32'),
(187, 32, 'A3-LO', 'Axa 3 - Stanga exterior', 3, 'LO', 'dual', 9, 0, '2026-05-19 12:38:42', '2026-06-12 13:44:32'),
(188, 32, 'A3-LI', 'Axa 3 - Stanga interior', 3, 'LI', 'dual', 10, 0, '2026-05-19 12:38:42', '2026-06-12 13:44:32'),
(189, 32, 'A3-RI', 'Axa 3 - Dreapta interior', 3, 'RI', 'dual', 11, 0, '2026-05-19 12:38:42', '2026-06-12 13:44:32'),
(190, 32, 'A3-RO', 'Axa 3 - Dreapta exterior', 3, 'RO', 'dual', 12, 0, '2026-05-19 12:38:42', '2026-06-12 13:44:32'),
(197, 37, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-20 14:14:29', '2026-06-12 14:50:17'),
(198, 37, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-20 14:14:29', '2026-06-12 14:50:17'),
(199, 37, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-20 14:14:29', '2026-06-12 14:50:17'),
(200, 37, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-20 14:14:29', '2026-06-12 14:50:17'),
(201, 37, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-20 14:14:29', '2026-06-12 14:50:17'),
(202, 37, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-20 14:14:29', '2026-06-12 14:50:17'),
(215, 40, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-21 13:29:54', '2026-06-12 15:52:02'),
(216, 40, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-21 13:29:54', '2026-06-12 15:52:02'),
(217, 40, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-21 13:29:54', '2026-06-12 15:52:02'),
(218, 40, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-21 13:29:54', '2026-06-12 15:52:02'),
(219, 40, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-21 13:29:54', '2026-06-12 15:52:02'),
(220, 40, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-21 13:29:54', '2026-06-12 15:52:02'),
(221, 17, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-25 14:39:51', '2026-06-10 11:27:12'),
(222, 17, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-25 14:39:51', '2026-06-10 11:27:12'),
(223, 17, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-25 14:39:51', '2026-06-10 11:27:12'),
(224, 17, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-25 14:39:51', '2026-06-10 11:27:12'),
(225, 9, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-26 09:56:38', '2026-06-10 10:58:01'),
(226, 9, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-26 09:56:38', '2026-06-10 10:58:01'),
(231, 1, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-26 10:05:18', '2026-06-10 11:12:14'),
(232, 1, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-26 10:05:18', '2026-06-10 11:12:14'),
(233, 1, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-26 10:05:18', '2026-06-10 11:12:14'),
(234, 1, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-26 10:05:18', '2026-06-10 11:12:14'),
(235, 2, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-26 10:08:49', '2026-06-15 15:45:03'),
(236, 2, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-26 10:08:49', '2026-06-15 15:45:03'),
(237, 2, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-26 10:08:49', '2026-06-15 15:45:03'),
(238, 2, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-26 10:08:49', '2026-06-15 15:45:03'),
(239, 3, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-26 10:10:50', '2026-06-11 10:24:28'),
(240, 3, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-26 10:10:50', '2026-06-11 10:24:28'),
(241, 3, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-26 10:10:50', '2026-06-11 10:24:28'),
(242, 3, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-26 10:10:50', '2026-06-11 10:24:28'),
(243, 41, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-26 10:12:21', '2026-06-10 11:23:10'),
(244, 41, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-26 10:12:21', '2026-06-10 11:23:10'),
(245, 41, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-26 10:12:21', '2026-06-10 11:23:10'),
(246, 41, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-26 10:12:21', '2026-06-10 11:23:10'),
(247, 41, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-26 10:12:21', '2026-06-10 11:23:10'),
(248, 41, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-26 10:12:21', '2026-06-10 11:23:10'),
(249, 42, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-26 10:14:19', '2026-06-16 10:11:53'),
(250, 42, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-26 10:14:19', '2026-06-16 10:11:53'),
(251, 42, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-26 10:14:19', '2026-06-16 10:11:53'),
(252, 42, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-26 10:14:19', '2026-06-16 10:11:53'),
(253, 42, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-26 10:14:19', '2026-06-16 10:11:53'),
(254, 42, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-26 10:14:19', '2026-06-16 10:11:53'),
(255, 43, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-26 10:15:57', '2026-06-10 10:55:15'),
(256, 43, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-26 10:15:57', '2026-06-10 10:55:15'),
(257, 43, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-26 10:15:57', '2026-06-10 10:55:15'),
(258, 43, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-26 10:15:57', '2026-06-10 10:55:15'),
(259, 43, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-26 10:15:57', '2026-06-10 10:55:15'),
(260, 43, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-26 10:15:57', '2026-06-10 10:55:15'),
(261, 44, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-26 10:46:02', '2026-06-11 12:23:47'),
(262, 44, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-26 10:46:02', '2026-06-11 12:23:47'),
(263, 44, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 0, '2026-05-26 10:46:02', '2026-06-11 12:23:47'),
(264, 44, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 0, '2026-05-26 10:46:02', '2026-06-11 12:23:47'),
(265, 44, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 0, '2026-05-26 10:46:02', '2026-06-11 12:23:47'),
(266, 44, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 0, '2026-05-26 10:46:02', '2026-06-11 12:23:47'),
(267, 45, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-26 10:48:24', '2026-06-10 11:59:02'),
(268, 45, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-26 10:48:24', '2026-06-10 11:59:02'),
(269, 45, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-26 10:48:24', '2026-06-10 11:59:02'),
(270, 45, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-26 10:48:24', '2026-06-10 11:59:02'),
(271, 45, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-26 10:48:24', '2026-06-10 11:59:02'),
(272, 45, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-26 10:48:24', '2026-06-10 11:59:02'),
(273, 46, 'A1-LO', 'Axa 1 - Stanga exterior', 1, 'LO', 'dual', 1, 0, '2026-05-26 10:52:19', '2026-06-16 10:12:43'),
(274, 46, 'A1-LI', 'Axa 1 - Stanga interior', 1, 'LI', 'dual', 2, 0, '2026-05-26 10:52:19', '2026-06-16 10:12:43'),
(275, 46, 'A1-RI', 'Axa 1 - Dreapta interior', 1, 'RI', 'dual', 3, 0, '2026-05-26 10:52:19', '2026-06-16 10:12:43'),
(276, 46, 'A1-RO', 'Axa 1 - Dreapta exterior', 1, 'RO', 'dual', 4, 0, '2026-05-26 10:52:19', '2026-06-16 10:12:43'),
(277, 46, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 5, 0, '2026-05-26 10:52:19', '2026-06-16 10:12:43'),
(278, 46, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 6, 0, '2026-05-26 10:52:19', '2026-06-16 10:12:43'),
(279, 46, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 7, 0, '2026-05-26 10:52:19', '2026-06-16 10:12:43'),
(280, 46, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 8, 0, '2026-05-26 10:52:19', '2026-06-16 10:12:43'),
(281, 47, 'A1-LO', 'Axa 1 - Stanga exterior', 1, 'LO', 'dual', 1, 0, '2026-05-26 11:00:33', '2026-06-10 11:05:19'),
(282, 47, 'A1-LI', 'Axa 1 - Stanga interior', 1, 'LI', 'dual', 2, 0, '2026-05-26 11:00:33', '2026-06-10 11:05:19'),
(283, 47, 'A1-RI', 'Axa 1 - Dreapta interior', 1, 'RI', 'dual', 3, 0, '2026-05-26 11:00:33', '2026-06-10 11:05:19'),
(284, 47, 'A1-RO', 'Axa 1 - Dreapta exterior', 1, 'RO', 'dual', 4, 0, '2026-05-26 11:00:33', '2026-06-10 11:05:19'),
(285, 47, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 5, 0, '2026-05-26 11:00:33', '2026-06-10 11:05:19'),
(286, 47, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 6, 0, '2026-05-26 11:00:33', '2026-06-10 11:05:19'),
(287, 47, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 7, 0, '2026-05-26 11:00:33', '2026-06-10 11:05:19'),
(288, 47, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 8, 0, '2026-05-26 11:00:33', '2026-06-10 11:05:19'),
(289, 48, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-26 11:23:58', '2026-06-10 11:50:17'),
(290, 48, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-26 11:23:58', '2026-06-10 11:50:17'),
(291, 48, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-26 11:23:58', '2026-06-10 11:50:17'),
(292, 48, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-26 11:23:58', '2026-06-10 11:50:17'),
(293, 48, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-26 11:23:58', '2026-06-10 11:50:17'),
(294, 48, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-26 11:23:58', '2026-06-10 11:50:17'),
(295, 49, 'A1-LO', 'Axa 1 - Stanga exterior', 1, 'LO', 'dual', 1, 0, '2026-05-26 11:40:21', '2026-06-12 14:27:53'),
(296, 49, 'A1-LI', 'Axa 1 - Stanga interior', 1, 'LI', 'dual', 2, 0, '2026-05-26 11:40:21', '2026-06-12 14:27:53'),
(297, 49, 'A1-RI', 'Axa 1 - Dreapta interior', 1, 'RI', 'dual', 3, 0, '2026-05-26 11:40:21', '2026-06-12 14:27:53'),
(298, 49, 'A1-RO', 'Axa 1 - Dreapta exterior', 1, 'RO', 'dual', 4, 0, '2026-05-26 11:40:21', '2026-06-12 14:27:53'),
(299, 49, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 5, 0, '2026-05-26 11:40:21', '2026-06-12 14:27:53'),
(300, 49, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 6, 0, '2026-05-26 11:40:21', '2026-06-12 14:27:53'),
(301, 49, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 7, 0, '2026-05-26 11:40:21', '2026-06-12 14:27:53'),
(302, 49, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 8, 0, '2026-05-26 11:40:21', '2026-06-12 14:27:53'),
(303, 49, 'A3-LO', 'Axa 3 - Stanga exterior', 3, 'LO', 'dual', 9, 0, '2026-05-26 11:40:21', '2026-06-12 14:27:53'),
(304, 49, 'A3-LI', 'Axa 3 - Stanga interior', 3, 'LI', 'dual', 10, 0, '2026-05-26 11:40:21', '2026-06-12 14:27:53'),
(305, 49, 'A3-RI', 'Axa 3 - Dreapta interior', 3, 'RI', 'dual', 11, 0, '2026-05-26 11:40:21', '2026-06-12 14:27:53'),
(306, 49, 'A3-RO', 'Axa 3 - Dreapta exterior', 3, 'RO', 'dual', 12, 0, '2026-05-26 11:40:21', '2026-06-12 14:27:53'),
(307, 50, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-26 11:54:53', '2026-06-10 11:50:55'),
(308, 50, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-26 11:54:53', '2026-06-10 11:50:55'),
(309, 50, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-26 11:54:53', '2026-06-10 11:50:55'),
(310, 50, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-26 11:54:53', '2026-06-10 11:50:55'),
(311, 50, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-26 11:54:53', '2026-06-10 11:50:55'),
(312, 50, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-26 11:54:53', '2026-06-10 11:50:55'),
(313, 51, 'A1-LO', 'Axa 1 - Stanga exterior', 1, 'LO', 'dual', 1, 0, '2026-05-26 11:59:05', '2026-06-16 10:05:32'),
(314, 51, 'A1-LI', 'Axa 1 - Stanga interior', 1, 'LI', 'dual', 2, 0, '2026-05-26 11:59:05', '2026-06-16 10:05:32'),
(315, 51, 'A1-RI', 'Axa 1 - Dreapta interior', 1, 'RI', 'dual', 3, 0, '2026-05-26 11:59:05', '2026-06-16 10:05:32'),
(316, 51, 'A1-RO', 'Axa 1 - Dreapta exterior', 1, 'RO', 'dual', 4, 0, '2026-05-26 11:59:05', '2026-06-16 10:05:32'),
(317, 51, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 5, 0, '2026-05-26 11:59:05', '2026-06-16 10:05:32'),
(318, 51, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 6, 0, '2026-05-26 11:59:05', '2026-06-16 10:05:32'),
(319, 51, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 7, 0, '2026-05-26 11:59:05', '2026-06-16 10:05:32'),
(320, 51, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 8, 0, '2026-05-26 11:59:05', '2026-06-16 10:05:32'),
(321, 51, 'A3-LO', 'Axa 3 - Stanga exterior', 3, 'LO', 'dual', 9, 0, '2026-05-26 11:59:05', '2026-06-16 10:05:32'),
(322, 51, 'A3-LI', 'Axa 3 - Stanga interior', 3, 'LI', 'dual', 10, 0, '2026-05-26 11:59:05', '2026-06-16 10:05:32'),
(323, 51, 'A3-RI', 'Axa 3 - Dreapta interior', 3, 'RI', 'dual', 11, 0, '2026-05-26 11:59:05', '2026-06-16 10:05:32'),
(324, 51, 'A3-RO', 'Axa 3 - Dreapta exterior', 3, 'RO', 'dual', 12, 0, '2026-05-26 11:59:05', '2026-06-16 10:05:32'),
(325, 52, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-26 12:07:25', '2026-06-10 11:51:27'),
(326, 52, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-26 12:07:25', '2026-06-10 11:51:27'),
(327, 52, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-26 12:07:25', '2026-06-10 11:51:27'),
(328, 52, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-26 12:07:25', '2026-06-10 11:51:27'),
(329, 52, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-26 12:07:25', '2026-06-10 11:51:27'),
(330, 52, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-26 12:07:25', '2026-06-10 11:51:27'),
(331, 52, 'A3-LO', 'Axa 3 - Stanga exterior', 3, 'LO', 'dual', 5, 0, '2026-05-26 13:06:01', '2026-06-10 11:51:27'),
(332, 52, 'A3-LI', 'Axa 3 - Stanga interior', 3, 'LI', 'dual', 6, 0, '2026-05-26 13:06:01', '2026-06-10 11:51:27'),
(333, 52, 'A3-RI', 'Axa 3 - Dreapta interior', 3, 'RI', 'dual', 7, 0, '2026-05-26 13:06:01', '2026-06-10 11:51:27'),
(334, 52, 'A3-RO', 'Axa 3 - Dreapta exterior', 3, 'RO', 'dual', 8, 0, '2026-05-26 13:06:01', '2026-06-10 11:51:27'),
(335, 53, 'A1-LO', 'Axa 1 - Stanga exterior', 1, 'LO', 'dual', 1, 0, '2026-05-26 13:12:15', '2026-06-16 10:14:33'),
(336, 53, 'A1-LI', 'Axa 1 - Stanga interior', 1, 'LI', 'dual', 2, 0, '2026-05-26 13:12:15', '2026-06-16 10:14:33'),
(337, 53, 'A1-RI', 'Axa 1 - Dreapta interior', 1, 'RI', 'dual', 3, 0, '2026-05-26 13:12:15', '2026-06-16 10:14:33'),
(338, 53, 'A1-RO', 'Axa 1 - Dreapta exterior', 1, 'RO', 'dual', 4, 0, '2026-05-26 13:12:15', '2026-06-16 10:14:33'),
(339, 53, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 5, 0, '2026-05-26 13:12:15', '2026-06-16 10:14:33'),
(340, 53, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 6, 0, '2026-05-26 13:12:15', '2026-06-16 10:14:33'),
(341, 53, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 7, 0, '2026-05-26 13:12:15', '2026-06-16 10:14:33'),
(342, 53, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 8, 0, '2026-05-26 13:12:15', '2026-06-16 10:14:33'),
(343, 53, 'A3-LO', 'Axa 3 - Stanga exterior', 3, 'LO', 'dual', 9, 0, '2026-05-26 13:12:15', '2026-06-16 10:14:33'),
(344, 53, 'A3-LI', 'Axa 3 - Stanga interior', 3, 'LI', 'dual', 10, 0, '2026-05-26 13:12:15', '2026-06-16 10:14:33'),
(345, 53, 'A3-RI', 'Axa 3 - Dreapta interior', 3, 'RI', 'dual', 11, 0, '2026-05-26 13:12:15', '2026-06-16 10:14:33'),
(346, 53, 'A3-RO', 'Axa 3 - Dreapta exterior', 3, 'RO', 'dual', 12, 0, '2026-05-26 13:12:15', '2026-06-16 10:14:33'),
(347, 52, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 0, '2026-05-26 13:13:13', '2026-06-10 11:51:27'),
(348, 52, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 0, '2026-05-26 13:13:13', '2026-06-10 11:51:27'),
(349, 53, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-26 16:27:16', '2026-06-16 10:14:33'),
(350, 53, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-26 16:27:16', '2026-06-16 10:14:33'),
(351, 53, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-05-26 16:27:16', '2026-06-16 10:14:33'),
(352, 53, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-05-26 16:27:16', '2026-06-16 10:14:33'),
(353, 53, 'A3-L', 'Axa 3 - Stanga', 3, 'L', 'single', 5, 1, '2026-05-26 16:27:16', '2026-06-16 10:14:33'),
(354, 53, 'A3-R', 'Axa 3 - Dreapta', 3, 'R', 'single', 6, 1, '2026-05-26 16:27:16', '2026-06-16 10:14:33'),
(355, 44, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-05-26 16:31:01', '2026-06-11 12:23:47'),
(356, 44, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-05-26 16:31:01', '2026-06-11 12:23:47'),
(357, 44, 'A3-L', 'Axa 3 - Stanga', 3, 'L', 'single', 5, 1, '2026-05-26 16:31:01', '2026-06-11 12:23:47'),
(358, 44, 'A3-R', 'Axa 3 - Dreapta', 3, 'R', 'single', 6, 1, '2026-05-26 16:31:01', '2026-06-11 12:23:47'),
(359, 44, 'A4-LO', 'Axa 4 - Stanga exterior', 4, 'LO', 'dual', 7, 1, '2026-05-26 16:31:01', '2026-06-11 12:23:47'),
(360, 44, 'A4-LI', 'Axa 4 - Stanga interior', 4, 'LI', 'dual', 8, 1, '2026-05-26 16:31:01', '2026-06-11 12:23:47'),
(361, 44, 'A4-RI', 'Axa 4 - Dreapta interior', 4, 'RI', 'dual', 9, 1, '2026-05-26 16:31:01', '2026-06-11 12:23:47'),
(362, 44, 'A4-RO', 'Axa 4 - Dreapta exterior', 4, 'RO', 'dual', 10, 1, '2026-05-26 16:31:01', '2026-06-11 12:23:47'),
(363, 54, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-28 13:17:03', '2026-06-10 11:52:38'),
(364, 54, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-28 13:17:03', '2026-06-10 11:52:38'),
(365, 54, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-28 13:17:03', '2026-06-10 11:52:38'),
(366, 54, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-28 13:17:03', '2026-06-10 11:52:38'),
(367, 54, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-28 13:17:03', '2026-06-10 11:52:38'),
(368, 54, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-28 13:17:03', '2026-06-10 11:52:38'),
(369, 55, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-28 13:24:17', '2026-06-12 14:27:47'),
(370, 55, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-28 13:24:17', '2026-06-12 14:27:47'),
(371, 55, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-05-28 13:24:17', '2026-06-12 14:27:47'),
(372, 55, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-05-28 13:24:17', '2026-06-12 14:27:47'),
(373, 55, 'A3-L', 'Axa 3 - Stanga', 3, 'L', 'single', 5, 1, '2026-05-28 13:24:17', '2026-06-12 14:27:47'),
(374, 55, 'A3-R', 'Axa 3 - Dreapta', 3, 'R', 'single', 6, 1, '2026-05-28 13:24:17', '2026-06-12 14:27:47'),
(375, 56, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-28 13:26:27', '2026-06-12 14:27:32'),
(376, 56, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-28 13:26:27', '2026-06-12 14:27:32'),
(377, 56, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-05-28 13:26:27', '2026-06-12 14:27:32'),
(378, 56, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-05-28 13:26:27', '2026-06-12 14:27:32'),
(379, 56, 'A3-L', 'Axa 3 - Stanga', 3, 'L', 'single', 5, 1, '2026-05-28 13:26:27', '2026-06-12 14:27:32'),
(380, 56, 'A3-R', 'Axa 3 - Dreapta', 3, 'R', 'single', 6, 1, '2026-05-28 13:26:27', '2026-06-12 14:27:32'),
(381, 57, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-28 13:30:12', '2026-06-10 11:54:21'),
(382, 57, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-28 13:30:12', '2026-06-10 11:54:21'),
(383, 57, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-28 13:30:12', '2026-06-10 11:54:21'),
(384, 57, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-28 13:30:12', '2026-06-10 11:54:21'),
(385, 57, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-28 13:30:12', '2026-06-10 11:54:21'),
(386, 57, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-28 13:30:12', '2026-06-10 11:54:21'),
(387, 58, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-28 13:33:04', '2026-06-15 10:44:34'),
(388, 58, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-28 13:33:04', '2026-06-15 10:44:34'),
(389, 58, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-05-28 13:33:04', '2026-06-15 10:44:34'),
(390, 58, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-05-28 13:33:04', '2026-06-15 10:44:34'),
(391, 58, 'A3-L', 'Axa 3 - Stanga', 3, 'L', 'single', 5, 1, '2026-05-28 13:33:04', '2026-06-15 10:44:34'),
(392, 58, 'A3-R', 'Axa 3 - Dreapta', 3, 'R', 'single', 6, 1, '2026-05-28 13:33:04', '2026-06-15 10:44:34'),
(393, 59, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-28 13:38:11', '2026-06-10 11:54:38'),
(394, 59, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-28 13:38:11', '2026-06-10 11:54:38'),
(395, 59, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-28 13:38:11', '2026-06-10 11:54:38'),
(396, 59, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-28 13:38:11', '2026-06-10 11:54:38'),
(397, 59, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-28 13:38:11', '2026-06-10 11:54:38'),
(398, 59, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-28 13:38:11', '2026-06-10 11:54:38'),
(399, 60, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-28 13:40:48', '2026-06-10 14:22:25'),
(400, 60, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-28 13:40:48', '2026-06-10 14:22:25'),
(401, 60, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-05-28 13:40:48', '2026-06-10 14:22:25'),
(402, 60, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-05-28 13:40:48', '2026-06-10 14:22:25'),
(403, 60, 'A3-L', 'Axa 3 - Stanga', 3, 'L', 'single', 5, 1, '2026-05-28 13:40:48', '2026-06-10 14:22:25'),
(404, 60, 'A3-R', 'Axa 3 - Dreapta', 3, 'R', 'single', 6, 1, '2026-05-28 13:40:48', '2026-06-10 14:22:25'),
(405, 61, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-28 13:42:44', '2026-06-16 09:09:29'),
(406, 61, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-28 13:42:44', '2026-06-16 09:09:29'),
(407, 61, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-28 13:42:44', '2026-06-16 09:09:29'),
(408, 61, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-28 13:42:44', '2026-06-16 09:09:29'),
(409, 61, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-28 13:42:44', '2026-06-16 09:09:29'),
(410, 61, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-28 13:42:44', '2026-06-16 09:09:29'),
(411, 62, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-28 13:59:31', '2026-06-12 14:27:37'),
(412, 62, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-28 13:59:31', '2026-06-12 14:27:37'),
(413, 62, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-05-28 13:59:31', '2026-06-12 14:27:37'),
(414, 62, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-05-28 13:59:31', '2026-06-12 14:27:37'),
(415, 62, 'A3-L', 'Axa 3 - Stanga', 3, 'L', 'single', 5, 1, '2026-05-28 13:59:31', '2026-06-12 14:27:37'),
(416, 62, 'A3-R', 'Axa 3 - Dreapta', 3, 'R', 'single', 6, 1, '2026-05-28 13:59:31', '2026-06-12 14:27:37'),
(417, 63, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-28 14:05:51', '2026-06-10 11:56:37'),
(418, 63, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-28 14:05:51', '2026-06-10 11:56:37'),
(419, 63, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-28 14:05:51', '2026-06-10 11:56:37'),
(420, 63, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-28 14:05:51', '2026-06-10 11:56:37'),
(421, 63, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-28 14:05:51', '2026-06-10 11:56:37'),
(422, 63, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-28 14:05:51', '2026-06-10 11:56:37'),
(423, 64, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-28 14:08:28', '2026-06-15 10:44:28'),
(424, 64, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-28 14:08:28', '2026-06-15 10:44:28'),
(425, 64, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-28 14:08:28', '2026-06-15 10:44:28'),
(426, 64, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-28 14:08:28', '2026-06-15 10:44:28'),
(427, 64, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-28 14:08:28', '2026-06-15 10:44:28'),
(428, 64, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-28 14:08:28', '2026-06-15 10:44:28'),
(429, 65, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-28 14:10:35', '2026-06-11 11:59:48'),
(430, 65, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-28 14:10:35', '2026-06-11 11:59:48'),
(431, 65, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-05-28 14:10:35', '2026-06-11 11:59:48'),
(432, 65, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-05-28 14:10:35', '2026-06-11 11:59:48'),
(433, 65, 'A3-L', 'Axa 3 - Stanga', 3, 'L', 'single', 5, 1, '2026-05-28 14:10:35', '2026-06-11 11:59:48'),
(434, 65, 'A3-R', 'Axa 3 - Dreapta', 3, 'R', 'single', 6, 1, '2026-05-28 14:10:35', '2026-06-11 11:59:48'),
(435, 66, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-06-03 09:15:19', '2026-06-12 14:21:23'),
(436, 66, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-06-03 09:15:19', '2026-06-12 14:21:23'),
(437, 66, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-06-03 09:15:19', '2026-06-12 14:21:23'),
(438, 66, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-06-03 09:15:19', '2026-06-12 14:21:23'),
(439, 67, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-06-03 09:18:40', '2026-06-12 14:20:30'),
(440, 67, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-06-03 09:18:40', '2026-06-12 14:20:30'),
(441, 67, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-06-03 09:18:40', '2026-06-12 14:20:30'),
(442, 67, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-06-03 09:18:40', '2026-06-12 14:20:30'),
(443, 68, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-06-03 09:20:16', '2026-06-16 10:17:55'),
(444, 68, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-06-03 09:20:16', '2026-06-16 10:17:55'),
(445, 68, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-06-03 09:20:16', '2026-06-16 10:17:55'),
(446, 68, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-06-03 09:20:16', '2026-06-16 10:17:55'),
(451, 70, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-06-03 09:22:57', '2026-06-12 14:18:47'),
(452, 70, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-06-03 09:22:57', '2026-06-12 14:18:47'),
(453, 70, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-06-03 09:22:57', '2026-06-12 14:18:47'),
(454, 70, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-06-03 09:22:57', '2026-06-12 14:18:47'),
(455, 71, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-06-03 09:24:31', '2026-06-12 14:25:06'),
(456, 71, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-06-03 09:24:31', '2026-06-12 14:25:06'),
(457, 71, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-06-03 09:24:31', '2026-06-12 14:25:06'),
(458, 71, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-06-03 09:24:31', '2026-06-12 14:25:06'),
(459, 72, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-06-03 09:26:40', '2026-06-16 10:17:31'),
(460, 72, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-06-03 09:26:40', '2026-06-16 10:17:31'),
(461, 72, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-06-03 09:26:40', '2026-06-16 10:17:31'),
(462, 72, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-06-03 09:26:40', '2026-06-16 10:17:31'),
(463, 73, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-06-03 09:33:38', '2026-06-16 10:18:58'),
(464, 73, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-06-03 09:33:38', '2026-06-16 10:18:58'),
(465, 73, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-06-03 09:33:38', '2026-06-16 10:18:58'),
(466, 73, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-06-03 09:33:38', '2026-06-16 10:18:58'),
(467, 74, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-06-03 09:36:29', '2026-06-12 14:26:34'),
(468, 74, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-06-03 09:36:29', '2026-06-12 14:26:34'),
(469, 74, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-06-03 09:36:29', '2026-06-12 14:26:34'),
(470, 74, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-06-03 09:36:29', '2026-06-12 14:26:34'),
(477, 32, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-06-03 14:40:00', '2026-06-12 13:44:32'),
(478, 32, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-06-03 14:40:00', '2026-06-12 13:44:32'),
(479, 32, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-06-03 14:40:00', '2026-06-12 13:44:32'),
(480, 32, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-06-03 14:40:00', '2026-06-12 13:44:32'),
(481, 32, 'A3-L', 'Axa 3 - Stanga', 3, 'L', 'single', 5, 1, '2026-06-03 14:40:00', '2026-06-12 13:44:32'),
(482, 32, 'A3-R', 'Axa 3 - Dreapta', 3, 'R', 'single', 6, 1, '2026-06-03 14:40:00', '2026-06-12 13:44:32'),
(483, 51, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-06-04 13:59:16', '2026-06-16 10:05:32'),
(484, 51, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-06-04 13:59:16', '2026-06-16 10:05:32'),
(485, 51, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-06-04 13:59:16', '2026-06-16 10:05:32'),
(486, 51, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-06-04 13:59:16', '2026-06-16 10:05:32'),
(487, 51, 'A3-L', 'Axa 3 - Stanga', 3, 'L', 'single', 5, 1, '2026-06-04 13:59:16', '2026-06-16 10:05:32'),
(488, 51, 'A3-R', 'Axa 3 - Dreapta', 3, 'R', 'single', 6, 1, '2026-06-04 13:59:16', '2026-06-16 10:05:32'),
(489, 49, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-06-04 14:47:28', '2026-06-12 14:27:53'),
(490, 49, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-06-04 14:47:28', '2026-06-12 14:27:53');
INSERT INTO `vehicule_anvelope_pozitii` (`id`, `vehicle_id`, `position_code`, `position_label`, `axle_no`, `side_code`, `wheel_kind`, `position_order`, `is_active`, `created_at`, `updated_at`) VALUES
(491, 49, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-06-04 14:47:28', '2026-06-12 14:27:53'),
(492, 49, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-06-04 14:47:28', '2026-06-12 14:27:53'),
(493, 49, 'A3-L', 'Axa 3 - Stanga', 3, 'L', 'single', 5, 1, '2026-06-04 14:47:28', '2026-06-12 14:27:53'),
(494, 49, 'A3-R', 'Axa 3 - Dreapta', 3, 'R', 'single', 6, 1, '2026-06-04 14:47:28', '2026-06-12 14:27:53'),
(495, 47, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-06-05 09:45:51', '2026-06-10 11:05:19'),
(496, 47, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-06-05 09:45:51', '2026-06-10 11:05:19'),
(497, 47, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-06-05 09:45:51', '2026-06-10 11:05:19'),
(498, 47, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-06-05 09:45:51', '2026-06-10 11:05:19'),
(499, 46, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-06-05 09:52:09', '2026-06-16 10:12:43'),
(500, 46, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-06-05 09:52:09', '2026-06-16 10:12:43'),
(501, 46, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-06-05 09:52:09', '2026-06-16 10:12:43'),
(502, 46, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-06-05 09:52:09', '2026-06-16 10:12:43'),
(503, 14, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-06-08 15:29:17', '2026-06-12 14:34:58'),
(504, 14, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-06-08 15:29:17', '2026-06-12 14:34:58'),
(505, 14, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-06-08 15:29:17', '2026-06-12 14:34:58'),
(506, 14, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-06-08 15:29:17', '2026-06-12 14:34:58'),
(507, 14, 'A3-L', 'Axa 3 - Stanga', 3, 'L', 'single', 5, 1, '2026-06-08 15:29:17', '2026-06-12 14:34:58'),
(508, 14, 'A3-R', 'Axa 3 - Dreapta', 3, 'R', 'single', 6, 1, '2026-06-08 15:29:17', '2026-06-12 14:34:58'),
(509, 12, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-06-08 15:35:28', '2026-06-12 14:48:31'),
(510, 12, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-06-08 15:35:28', '2026-06-12 14:48:31'),
(511, 12, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-06-08 15:35:28', '2026-06-12 14:48:31'),
(512, 12, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-06-08 15:35:28', '2026-06-12 14:48:31'),
(513, 12, 'A3-L', 'Axa 3 - Stanga', 3, 'L', 'single', 5, 1, '2026-06-08 15:35:28', '2026-06-12 14:48:31'),
(514, 12, 'A3-R', 'Axa 3 - Dreapta', 3, 'R', 'single', 6, 1, '2026-06-08 15:35:28', '2026-06-12 14:48:31'),
(515, 10, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-06-09 09:04:42', '2026-06-10 11:44:29'),
(516, 10, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-06-09 09:04:42', '2026-06-10 11:44:29'),
(517, 10, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-06-09 09:04:42', '2026-06-10 11:44:29'),
(518, 10, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-06-09 09:04:42', '2026-06-10 11:44:29'),
(519, 10, 'A3-L', 'Axa 3 - Stanga', 3, 'L', 'single', 5, 1, '2026-06-09 09:04:42', '2026-06-10 11:44:29'),
(520, 10, 'A3-R', 'Axa 3 - Dreapta', 3, 'R', 'single', 6, 1, '2026-06-09 09:04:42', '2026-06-10 11:44:29'),
(521, 76, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-06-12 10:25:40', '2026-06-15 09:01:47'),
(522, 76, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-06-12 10:25:40', '2026-06-15 09:01:47'),
(523, 76, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-06-12 10:25:40', '2026-06-15 09:01:47'),
(524, 76, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-06-12 10:25:40', '2026-06-15 09:01:47'),
(525, 77, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-06-12 11:41:29', '2026-06-16 10:18:13'),
(526, 77, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-06-12 11:41:29', '2026-06-16 10:18:13'),
(527, 77, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-06-12 11:41:29', '2026-06-16 10:18:13'),
(528, 77, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-06-12 11:41:29', '2026-06-16 10:18:13'),
(535, 12, 'A4-L', 'Axa 4 - Stanga', 4, 'L', 'single', 7, 0, '2026-06-12 14:30:04', '2026-06-12 14:48:31'),
(536, 12, 'A4-R', 'Axa 4 - Dreapta', 4, 'R', 'single', 8, 0, '2026-06-12 14:30:04', '2026-06-12 14:48:31');

-- --------------------------------------------------------

--
-- Table structure for table `vehicule_cuplaje`
--

CREATE TABLE `vehicule_cuplaje` (
  `id` int UNSIGNED NOT NULL,
  `tractor_id` int UNSIGNED NOT NULL,
  `semiremorca_id` int UNSIGNED NOT NULL,
  `activ` tinyint(1) NOT NULL DEFAULT '1',
  `data_start` datetime NOT NULL,
  `data_end` datetime DEFAULT NULL,
  `created_by` int UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `vehicule_cuplaje`
--

INSERT INTO `vehicule_cuplaje` (`id`, `tractor_id`, `semiremorca_id`, `activ`, `data_start`, `data_end`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 6, 9, 0, '2026-04-20 09:43:07', '2026-04-20 09:48:32', 1, '2026-04-20 09:43:07', '2026-04-20 09:48:32'),
(2, 6, 9, 0, '2026-04-20 09:48:32', '2026-04-20 09:49:29', 1, '2026-04-20 09:48:32', '2026-04-20 09:49:29'),
(3, 6, 9, 0, '2026-04-20 09:49:35', '2026-04-20 09:49:54', 1, '2026-04-20 09:49:35', '2026-04-20 09:49:54'),
(4, 6, 10, 0, '2026-04-20 09:51:29', '2026-04-20 09:52:02', 1, '2026-04-20 09:51:29', '2026-04-20 09:52:02'),
(5, 6, 9, 0, '2026-04-20 09:52:02', '2026-04-20 09:52:12', 1, '2026-04-20 09:52:02', '2026-04-20 09:52:12'),
(6, 6, 10, 0, '2026-04-20 09:52:12', '2026-04-20 09:55:41', 1, '2026-04-20 09:52:12', '2026-04-20 09:55:41'),
(7, 6, 9, 0, '2026-04-20 09:55:41', '2026-04-20 10:32:10', 1, '2026-04-20 09:55:41', '2026-04-20 10:32:10'),
(8, 6, 9, 0, '2026-04-20 10:57:39', '2026-04-20 11:11:07', 1, '2026-04-20 10:57:39', '2026-04-20 11:11:07'),
(9, 6, 9, 0, '2026-04-20 11:11:58', '2026-04-20 11:18:13', 1, '2026-04-20 11:11:58', '2026-04-20 11:18:13'),
(10, 6, 9, 0, '2026-04-20 11:18:28', '2026-05-05 14:51:03', 1, '2026-04-20 11:18:28', '2026-05-05 14:51:03'),
(11, 11, 12, 0, '2026-04-21 12:07:04', '2026-05-05 12:44:03', 1, '2026-04-21 12:07:04', '2026-05-05 12:44:03'),
(12, 13, 14, 0, '2026-04-21 12:08:30', '2026-05-05 12:44:41', 1, '2026-04-21 12:08:30', '2026-05-05 12:44:41'),
(13, 26, 14, 0, '2026-05-05 12:44:41', '2026-05-05 12:45:11', 1, '2026-05-05 12:44:41', '2026-05-05 12:45:11'),
(14, 26, 12, 0, '2026-05-05 12:45:11', '2026-05-05 14:51:03', 1, '2026-05-05 12:45:11', '2026-05-05 14:51:03'),
(15, 26, 9, 0, '2026-05-05 14:51:03', '2026-05-05 15:22:10', 1, '2026-05-05 14:51:03', '2026-05-05 15:22:10'),
(16, 11, 9, 0, '2026-05-05 15:23:08', '2026-05-05 15:40:42', 1, '2026-05-05 15:23:08', '2026-05-05 15:40:42'),
(17, 26, 12, 0, '2026-05-05 15:23:27', '2026-05-05 15:35:40', 1, '2026-05-05 15:23:27', '2026-05-05 15:35:40'),
(18, 11, 12, 1, '2026-05-05 15:40:52', NULL, 1, '2026-05-05 15:40:52', '2026-05-05 15:40:52'),
(19, 26, 9, 0, '2026-05-05 15:41:12', '2026-06-10 11:58:29', 1, '2026-05-05 15:41:12', '2026-06-10 11:58:29'),
(20, 31, 32, 1, '2026-05-19 12:45:04', NULL, 1, '2026-05-19 12:45:04', '2026-05-19 12:45:04'),
(21, 54, 55, 1, '2026-06-10 11:52:38', NULL, 1, '2026-06-10 11:52:38', '2026-06-10 11:52:38'),
(22, 57, 58, 1, '2026-06-10 11:54:21', NULL, 1, '2026-06-10 11:54:21', '2026-06-10 11:54:21'),
(23, 63, 10, 1, '2026-06-10 11:56:36', NULL, 1, '2026-06-10 11:56:36', '2026-06-10 11:56:36'),
(24, 64, 65, 1, '2026-06-10 11:57:04', NULL, 1, '2026-06-10 11:57:04', '2026-06-10 11:57:04'),
(25, 13, 14, 1, '2026-06-10 11:57:42', NULL, 1, '2026-06-10 11:57:42', '2026-06-10 11:57:42'),
(26, 26, 47, 1, '2026-06-10 11:58:29', NULL, 1, '2026-06-10 11:58:29', '2026-06-10 11:58:29'),
(27, 45, 46, 1, '2026-06-10 11:59:02', NULL, 1, '2026-06-10 11:59:02', '2026-06-10 11:59:02');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `alimentari`
--
ALTER TABLE `alimentari`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_alimentari_vehicle` (`vehicle_id`),
  ADD KEY `idx_alimentari_driver` (`driver_id`),
  ADD KEY `idx_alimentari_data` (`data_alimentare`);

--
-- Indexes for table `anvelope`
--
ALTER TABLE `anvelope`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `serial_number` (`serial_number`),
  ADD KEY `idx_anvelope_status` (`status`),
  ADD KEY `idx_anvelope_dot` (`dot_code`),
  ADD KEY `idx_anvelope_mentenanta` (`mentenanta_id`);

--
-- Indexes for table `anvelope_alocari`
--
ALTER TABLE `anvelope_alocari`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_anvelope_alocari_tire` (`tire_id`),
  ADD KEY `idx_anvelope_alocari_vehicle` (`vehicle_id`),
  ADD KEY `idx_anvelope_alocari_position` (`position_id`),
  ADD KEY `idx_anvelope_alocari_active` (`data_end`),
  ADD KEY `fk_anvelope_alocari_created_by` (`created_by`);

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_modul_record` (`modul`,`record_id`),
  ADD KEY `idx_audit_created_at` (`created_at`),
  ADD KEY `fk_audit_log_user` (`user_id`);

--
-- Indexes for table `concedii`
--
ALTER TABLE `concedii`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_concedii_driver` (`driver_id`),
  ADD KEY `idx_concedii_inlocuitor` (`inlocuitor_id`),
  ADD KEY `idx_concedii_status` (`status`),
  ADD KEY `idx_concedii_perioada` (`data_inceput`,`data_sfarsit`),
  ADD KEY `fk_concedii_created_by` (`created_by`);

--
-- Indexes for table `configurare_beneficiari_transport`
--
ALTER TABLE `configurare_beneficiari_transport`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_config_beneficiari_nume` (`nume`),
  ADD KEY `idx_config_beneficiari_activ` (`activ`);

--
-- Indexes for table `configurare_compresor_vehicule`
--
ALTER TABLE `configurare_compresor_vehicule`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_config_compresor_beneficiar_vehicle` (`beneficiar_id`,`vehicle_id`),
  ADD KEY `idx_config_compresor_beneficiar` (`beneficiar_id`),
  ADD KEY `idx_config_compresor_vehicle` (`vehicle_id`);

--
-- Indexes for table `configurare_costuri_documente_soferi`
--
ALTER TABLE `configurare_costuri_documente_soferi`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_config_doc_driver_document` (`driver_id`,`document_type`),
  ADD KEY `idx_config_doc_driver_type` (`document_type`),
  ADD KEY `idx_config_doc_driver_driver` (`driver_id`);

--
-- Indexes for table `configurare_costuri_documente_vehicule`
--
ALTER TABLE `configurare_costuri_documente_vehicule`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_config_doc_vehicle_type_document` (`vehicle_type`,`document_type`),
  ADD KEY `idx_config_doc_vehicle_type` (`vehicle_type`),
  ADD KEY `idx_config_doc_document_type` (`document_type`);

--
-- Indexes for table `configurare_costuri_documente_vehicule_override`
--
ALTER TABLE `configurare_costuri_documente_vehicule_override`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_config_doc_vehicle_override` (`vehicle_id`,`document_type`),
  ADD KEY `idx_config_doc_override_vehicle` (`vehicle_id`),
  ADD KEY `idx_config_doc_override_type` (`document_type`);

--
-- Indexes for table `configurare_documente_obligatorii_soferi`
--
ALTER TABLE `configurare_documente_obligatorii_soferi`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_required_driver_document_type` (`document_type`);

--
-- Indexes for table `configurare_locuri_incarcare`
--
ALTER TABLE `configurare_locuri_incarcare`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_config_locuri_beneficiar_nume` (`beneficiar_id`,`nume`),
  ADD KEY `idx_config_locuri_activ` (`activ`);

--
-- Indexes for table `configurare_locuri_incarcare_vehicule`
--
ALTER TABLE `configurare_locuri_incarcare_vehicule`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_config_locuri_beneficiar_vehicle` (`beneficiar_id`,`vehicle_id`),
  ADD KEY `idx_config_locuri_vehicle_loc` (`loc_incarcare_id`),
  ADD KEY `idx_config_locuri_vehicle_vehicle` (`vehicle_id`);

--
-- Indexes for table `configurare_rute_distributie`
--
ALTER TABLE `configurare_rute_distributie`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_config_rute_beneficiar_loc_zona_scope` (`beneficiar_id`,`loc_incarcare_id`,`zona_distributie_id`,`transport_scope`),
  ADD KEY `idx_config_rute_beneficiar` (`beneficiar_id`),
  ADD KEY `idx_config_rute_loc` (`loc_incarcare_id`),
  ADD KEY `idx_config_rute_zona` (`zona_distributie_id`),
  ADD KEY `idx_config_rute_activ` (`activ`);

--
-- Indexes for table `configurare_rute_primar`
--
ALTER TABLE `configurare_rute_primar`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_config_rute_primar_beneficiar_loc_zona` (`beneficiar_id`,`loc_incarcare_id`,`zona_distributie_id`),
  ADD KEY `idx_config_rute_primar_beneficiar` (`beneficiar_id`),
  ADD KEY `idx_config_rute_primar_loc` (`loc_incarcare_id`),
  ADD KEY `idx_config_rute_primar_zona` (`zona_distributie_id`),
  ADD KEY `idx_config_rute_primar_activ` (`activ`);

--
-- Indexes for table `configurare_zone_distributie`
--
ALTER TABLE `configurare_zone_distributie`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_config_zone_beneficiar_nume` (`beneficiar_id`,`nume`),
  ADD KEY `idx_config_zone_activ` (`activ`);

--
-- Indexes for table `configurare_zone_distributie_vehicule`
--
ALTER TABLE `configurare_zone_distributie_vehicule`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_config_zone_beneficiar_vehicle` (`beneficiar_id`,`vehicle_id`),
  ADD KEY `idx_config_zone_vehicle_zona` (`zona_distributie_id`),
  ADD KEY `idx_config_zone_vehicle_vehicle` (`vehicle_id`);

--
-- Indexes for table `curse_cheltuieli`
--
ALTER TABLE `curse_cheltuieli`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_curse_cheltuieli_cursa` (`cursa_id`),
  ADD KEY `idx_curse_cheltuieli_data` (`data_cheltuiala`);

--
-- Indexes for table `curse_cheltuieli_documente`
--
ALTER TABLE `curse_cheltuieli_documente`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_curse_doc_cheltuiala` (`cheltuiala_id`);

--
-- Indexes for table `curse_dispecer`
--
ALTER TABLE `curse_dispecer`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_curse_vehicle` (`vehicle_id`),
  ADD KEY `idx_curse_tip_transport` (`tip_transport`),
  ADD KEY `idx_curse_data` (`data_cursa`),
  ADD KEY `idx_curse_loc` (`loc_incarcare_id`),
  ADD KEY `idx_curse_zona` (`zona_distributie_id`),
  ADD KEY `idx_curse_beneficiar` (`beneficiar_id`),
  ADD KEY `idx_curse_data_inceput` (`data_inceput`),
  ADD KEY `idx_curse_data_sfarsit` (`data_sfarsit`),
  ADD KEY `idx_curse_driver` (`driver_id`),
  ADD KEY `idx_curse_created_by` (`created_by`);

--
-- Indexes for table `documente`
--
ALTER TABLE `documente`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_documente_vehicle` (`vehicle_id`),
  ADD KEY `idx_documente_expirare` (`data_expirare`);

--
-- Indexes for table `documente_soferi`
--
ALTER TABLE `documente_soferi`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_documente_soferi_driver_type` (`driver_id`,`tip_document`),
  ADD KEY `idx_documente_soferi_driver` (`driver_id`),
  ADD KEY `idx_documente_soferi_expirare` (`data_expirare`);

--
-- Indexes for table `inventar_dotari_catalog`
--
ALTER TABLE `inventar_dotari_catalog`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_inventar_catalog_nume` (`nume`),
  ADD KEY `idx_inventar_catalog_activ` (`activ`),
  ADD KEY `idx_inventar_catalog_categorie` (`categorie`),
  ADD KEY `idx_inventar_catalog_equipment_type` (`equipment_type`);

--
-- Indexes for table `inventar_dotari_reguli`
--
ALTER TABLE `inventar_dotari_reguli`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_inventar_reguli_type_catalog` (`vehicle_type`,`catalog_id`),
  ADD KEY `idx_inventar_reguli_type_active` (`vehicle_type`,`activ`),
  ADD KEY `idx_inventar_reguli_catalog` (`catalog_id`);

--
-- Indexes for table `inventar_dotari_vehicule`
--
ALTER TABLE `inventar_dotari_vehicule`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_inventar_dotari_vehicle` (`vehicle_id`),
  ADD KEY `idx_inventar_dotari_catalog` (`catalog_id`),
  ADD KEY `idx_inventar_dotari_expirare` (`data_expirarii`),
  ADD KEY `idx_inventar_dotari_inspectie` (`data_urmatoarei_inspectii`);

--
-- Indexes for table `login_email_codes`
--
ALTER TABLE `login_email_codes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_login_email_codes_user_active` (`user_id`,`used_at`,`expires_at`),
  ADD KEY `idx_login_email_codes_email_active` (`email`,`used_at`,`expires_at`),
  ADD KEY `idx_login_email_codes_sent_at` (`sent_at`);

--
-- Indexes for table `mentenanta`
--
ALTER TABLE `mentenanta`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_mentenanta_vehicle` (`vehicle_id`),
  ADD KEY `idx_mentenanta_data` (`data_interventie`);

--
-- Indexes for table `notification_deliveries`
--
ALTER TABLE `notification_deliveries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notification_deliveries_context` (`context`,`context_id`),
  ADD KEY `idx_notification_deliveries_status` (`status`,`created_at`),
  ADD KEY `idx_notification_deliveries_recipient` (`recipient_email`,`created_at`),
  ADD KEY `idx_notification_deliveries_created_at` (`created_at`);

--
-- Indexes for table `notification_queue`
--
ALTER TABLE `notification_queue`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_notification_queue_dedupe` (`dedupe_key`),
  ADD KEY `idx_notification_queue_pending` (`status`,`scheduled_for`,`id`),
  ADD KEY `idx_notification_queue_delivery` (`delivery_id`);

--
-- Indexes for table `notification_rules`
--
ALTER TABLE `notification_rules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notification_rules_enabled` (`enabled`,`event_type`),
  ADD KEY `idx_notification_rules_document_type` (`document_type`);

--
-- Indexes for table `notification_rule_recipients`
--
ALTER TABLE `notification_rule_recipients`
  ADD PRIMARY KEY (`rule_id`,`user_id`),
  ADD KEY `idx_notification_rule_recipients_user` (`user_id`);

--
-- Indexes for table `office_expenses`
--
ALTER TABLE `office_expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_office_expenses_date` (`expense_date`),
  ADD KEY `idx_office_expenses_category_date` (`category_id`,`expense_date`),
  ADD KEY `idx_office_expenses_payment_method` (`payment_method`),
  ADD KEY `idx_office_expenses_added_by` (`added_by`),
  ADD KEY `fk_office_expenses_updated_by` (`updated_by`);

--
-- Indexes for table `office_expense_categories`
--
ALTER TABLE `office_expense_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_office_expense_categories_slug` (`slug`),
  ADD KEY `idx_office_expense_categories_status` (`status`),
  ADD KEY `idx_office_expense_categories_automatic` (`is_automatic`);

--
-- Indexes for table `office_expense_documents`
--
ALTER TABLE `office_expense_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_office_expense_documents_expense` (`expense_id`),
  ADD KEY `fk_office_expense_documents_uploaded_by` (`uploaded_by`);

--
-- Indexes for table `salary_history`
--
ALTER TABLE `salary_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_salary_history_driver` (`driver_id`,`effective_date`),
  ADD KEY `idx_salary_history_staff` (`staff_member_id`,`effective_date`),
  ADD KEY `idx_salary_history_subject` (`subject_type`,`effective_date`),
  ADD KEY `fk_salary_history_user` (`updated_by`);

--
-- Indexes for table `soferi`
--
ALTER TABLE `soferi`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_soferi_status` (`status`),
  ADD KEY `idx_soferi_vehicle` (`vehicle_id`);

--
-- Indexes for table `staff_documents`
--
ALTER TABLE `staff_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_staff_documents_member` (`staff_member_id`),
  ADD KEY `idx_staff_documents_expirare` (`data_expirare`),
  ADD KEY `idx_staff_documents_type` (`tip_document`),
  ADD KEY `fk_staff_documents_created_by` (`created_by`),
  ADD KEY `fk_staff_documents_updated_by` (`updated_by`);

--
-- Indexes for table `staff_document_requirements`
--
ALTER TABLE `staff_document_requirements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_staff_doc_req_type_document` (`staff_type_id`,`document_type`),
  ADD KEY `idx_staff_doc_req_type` (`staff_type_id`);

--
-- Indexes for table `staff_members`
--
ALTER TABLE `staff_members`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_staff_members_type_status` (`staff_type_id`,`status`),
  ADD KEY `idx_staff_members_name` (`nume_complet`),
  ADD KEY `fk_staff_members_created_by` (`created_by`),
  ADD KEY `fk_staff_members_updated_by` (`updated_by`);

--
-- Indexes for table `staff_types`
--
ALTER TABLE `staff_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_staff_types_slug` (`slug`),
  ADD KEY `idx_staff_types_category_status` (`category`,`status`),
  ADD KEY `idx_staff_types_driver_linked` (`is_driver_linked`),
  ADD KEY `fk_staff_types_created_by` (`created_by`),
  ADD KEY `fk_staff_types_updated_by` (`updated_by`);

--
-- Indexes for table `utilizatori`
--
ALTER TABLE `utilizatori`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_utilizatori_notificari` (`status`,`notificari_email`,`notificari_sms`);

--
-- Indexes for table `vehicule`
--
ALTER TABLE `vehicule`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nr_inmatriculare` (`nr_inmatriculare`),
  ADD KEY `idx_vehicule_status` (`status`);

--
-- Indexes for table `vehicule_anvelope_pozitii`
--
ALTER TABLE `vehicule_anvelope_pozitii`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_vehicule_anvelope_pozitii_vehicle_code` (`vehicle_id`,`position_code`),
  ADD KEY `idx_vehicule_anvelope_pozitii_vehicle_active` (`vehicle_id`,`is_active`);

--
-- Indexes for table `vehicule_cuplaje`
--
ALTER TABLE `vehicule_cuplaje`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_vehicule_cuplaje_tractor_activ` (`tractor_id`,`activ`),
  ADD KEY `idx_vehicule_cuplaje_semiremorca_activ` (`semiremorca_id`,`activ`),
  ADD KEY `idx_vehicule_cuplaje_start` (`data_start`),
  ADD KEY `fk_vehicule_cuplaje_created_by` (`created_by`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `alimentari`
--
ALTER TABLE `alimentari`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `anvelope`
--
ALTER TABLE `anvelope`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=151;

--
-- AUTO_INCREMENT for table `anvelope_alocari`
--
ALTER TABLE `anvelope_alocari`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=125;

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=827;

--
-- AUTO_INCREMENT for table `concedii`
--
ALTER TABLE `concedii`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `configurare_beneficiari_transport`
--
ALTER TABLE `configurare_beneficiari_transport`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=54;

--
-- AUTO_INCREMENT for table `configurare_compresor_vehicule`
--
ALTER TABLE `configurare_compresor_vehicule`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=61;

--
-- AUTO_INCREMENT for table `configurare_costuri_documente_soferi`
--
ALTER TABLE `configurare_costuri_documente_soferi`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=132;

--
-- AUTO_INCREMENT for table `configurare_costuri_documente_vehicule`
--
ALTER TABLE `configurare_costuri_documente_vehicule`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=85;

--
-- AUTO_INCREMENT for table `configurare_costuri_documente_vehicule_override`
--
ALTER TABLE `configurare_costuri_documente_vehicule_override`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `configurare_documente_obligatorii_soferi`
--
ALTER TABLE `configurare_documente_obligatorii_soferi`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `configurare_locuri_incarcare`
--
ALTER TABLE `configurare_locuri_incarcare`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=84;

--
-- AUTO_INCREMENT for table `configurare_locuri_incarcare_vehicule`
--
ALTER TABLE `configurare_locuri_incarcare_vehicule`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=66;

--
-- AUTO_INCREMENT for table `configurare_rute_distributie`
--
ALTER TABLE `configurare_rute_distributie`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=65;

--
-- AUTO_INCREMENT for table `configurare_rute_primar`
--
ALTER TABLE `configurare_rute_primar`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `configurare_zone_distributie`
--
ALTER TABLE `configurare_zone_distributie`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=82;

--
-- AUTO_INCREMENT for table `configurare_zone_distributie_vehicule`
--
ALTER TABLE `configurare_zone_distributie_vehicule`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=65;

--
-- AUTO_INCREMENT for table `curse_cheltuieli`
--
ALTER TABLE `curse_cheltuieli`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=56;

--
-- AUTO_INCREMENT for table `curse_cheltuieli_documente`
--
ALTER TABLE `curse_cheltuieli_documente`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `curse_dispecer`
--
ALTER TABLE `curse_dispecer`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=128;

--
-- AUTO_INCREMENT for table `documente`
--
ALTER TABLE `documente`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=603;

--
-- AUTO_INCREMENT for table `documente_soferi`
--
ALTER TABLE `documente_soferi`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=387;

--
-- AUTO_INCREMENT for table `inventar_dotari_catalog`
--
ALTER TABLE `inventar_dotari_catalog`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `inventar_dotari_reguli`
--
ALTER TABLE `inventar_dotari_reguli`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `inventar_dotari_vehicule`
--
ALTER TABLE `inventar_dotari_vehicule`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `login_email_codes`
--
ALTER TABLE `login_email_codes`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=79;

--
-- AUTO_INCREMENT for table `mentenanta`
--
ALTER TABLE `mentenanta`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=229;

--
-- AUTO_INCREMENT for table `notification_deliveries`
--
ALTER TABLE `notification_deliveries`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=186;

--
-- AUTO_INCREMENT for table `notification_queue`
--
ALTER TABLE `notification_queue`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=184;

--
-- AUTO_INCREMENT for table `notification_rules`
--
ALTER TABLE `notification_rules`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `office_expenses`
--
ALTER TABLE `office_expenses`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `office_expense_categories`
--
ALTER TABLE `office_expense_categories`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `office_expense_documents`
--
ALTER TABLE `office_expense_documents`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `salary_history`
--
ALTER TABLE `salary_history`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `soferi`
--
ALTER TABLE `soferi`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `staff_documents`
--
ALTER TABLE `staff_documents`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `staff_document_requirements`
--
ALTER TABLE `staff_document_requirements`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `staff_members`
--
ALTER TABLE `staff_members`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `staff_types`
--
ALTER TABLE `staff_types`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `utilizatori`
--
ALTER TABLE `utilizatori`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `vehicule`
--
ALTER TABLE `vehicule`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=79;

--
-- AUTO_INCREMENT for table `vehicule_anvelope_pozitii`
--
ALTER TABLE `vehicule_anvelope_pozitii`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=537;

--
-- AUTO_INCREMENT for table `vehicule_cuplaje`
--
ALTER TABLE `vehicule_cuplaje`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `alimentari`
--
ALTER TABLE `alimentari`
  ADD CONSTRAINT `fk_alimentari_driver` FOREIGN KEY (`driver_id`) REFERENCES `soferi` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_alimentari_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicule` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `anvelope_alocari`
--
ALTER TABLE `anvelope_alocari`
  ADD CONSTRAINT `fk_anvelope_alocari_created_by` FOREIGN KEY (`created_by`) REFERENCES `utilizatori` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_anvelope_alocari_position` FOREIGN KEY (`position_id`) REFERENCES `vehicule_anvelope_pozitii` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_anvelope_alocari_tire` FOREIGN KEY (`tire_id`) REFERENCES `anvelope` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_anvelope_alocari_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicule` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD CONSTRAINT `fk_audit_log_user` FOREIGN KEY (`user_id`) REFERENCES `utilizatori` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `concedii`
--
ALTER TABLE `concedii`
  ADD CONSTRAINT `fk_concedii_created_by` FOREIGN KEY (`created_by`) REFERENCES `utilizatori` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_concedii_driver` FOREIGN KEY (`driver_id`) REFERENCES `soferi` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_concedii_inlocuitor` FOREIGN KEY (`inlocuitor_id`) REFERENCES `soferi` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `configurare_compresor_vehicule`
--
ALTER TABLE `configurare_compresor_vehicule`
  ADD CONSTRAINT `fk_config_compresor_beneficiar` FOREIGN KEY (`beneficiar_id`) REFERENCES `configurare_beneficiari_transport` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_config_compresor_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicule` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `configurare_costuri_documente_soferi`
--
ALTER TABLE `configurare_costuri_documente_soferi`
  ADD CONSTRAINT `fk_config_doc_driver` FOREIGN KEY (`driver_id`) REFERENCES `soferi` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `configurare_costuri_documente_vehicule_override`
--
ALTER TABLE `configurare_costuri_documente_vehicule_override`
  ADD CONSTRAINT `fk_config_doc_override_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicule` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `configurare_locuri_incarcare`
--
ALTER TABLE `configurare_locuri_incarcare`
  ADD CONSTRAINT `fk_config_locuri_beneficiar` FOREIGN KEY (`beneficiar_id`) REFERENCES `configurare_beneficiari_transport` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `configurare_locuri_incarcare_vehicule`
--
ALTER TABLE `configurare_locuri_incarcare_vehicule`
  ADD CONSTRAINT `fk_config_locuri_loc` FOREIGN KEY (`loc_incarcare_id`) REFERENCES `configurare_locuri_incarcare` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_config_locuri_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicule` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_config_locuri_vehicle_beneficiar` FOREIGN KEY (`beneficiar_id`) REFERENCES `configurare_beneficiari_transport` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `configurare_rute_distributie`
--
ALTER TABLE `configurare_rute_distributie`
  ADD CONSTRAINT `fk_config_rute_beneficiar` FOREIGN KEY (`beneficiar_id`) REFERENCES `configurare_beneficiari_transport` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_config_rute_loc` FOREIGN KEY (`loc_incarcare_id`) REFERENCES `configurare_locuri_incarcare` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_config_rute_zona` FOREIGN KEY (`zona_distributie_id`) REFERENCES `configurare_zone_distributie` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `configurare_rute_primar`
--
ALTER TABLE `configurare_rute_primar`
  ADD CONSTRAINT `fk_config_rute_primar_beneficiar` FOREIGN KEY (`beneficiar_id`) REFERENCES `configurare_beneficiari_transport` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_config_rute_primar_loc` FOREIGN KEY (`loc_incarcare_id`) REFERENCES `configurare_locuri_incarcare` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_config_rute_primar_zona` FOREIGN KEY (`zona_distributie_id`) REFERENCES `configurare_zone_distributie` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `configurare_zone_distributie`
--
ALTER TABLE `configurare_zone_distributie`
  ADD CONSTRAINT `fk_config_zone_beneficiar` FOREIGN KEY (`beneficiar_id`) REFERENCES `configurare_beneficiari_transport` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `configurare_zone_distributie_vehicule`
--
ALTER TABLE `configurare_zone_distributie_vehicule`
  ADD CONSTRAINT `fk_config_zone_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicule` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_config_zone_vehicle_beneficiar` FOREIGN KEY (`beneficiar_id`) REFERENCES `configurare_beneficiari_transport` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_config_zone_zona` FOREIGN KEY (`zona_distributie_id`) REFERENCES `configurare_zone_distributie` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `curse_cheltuieli`
--
ALTER TABLE `curse_cheltuieli`
  ADD CONSTRAINT `fk_curse_cheltuieli_cursa` FOREIGN KEY (`cursa_id`) REFERENCES `curse_dispecer` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `curse_cheltuieli_documente`
--
ALTER TABLE `curse_cheltuieli_documente`
  ADD CONSTRAINT `fk_curse_doc_cheltuiala` FOREIGN KEY (`cheltuiala_id`) REFERENCES `curse_cheltuieli` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `curse_dispecer`
--
ALTER TABLE `curse_dispecer`
  ADD CONSTRAINT `fk_curse_beneficiar` FOREIGN KEY (`beneficiar_id`) REFERENCES `configurare_beneficiari_transport` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_curse_created_by` FOREIGN KEY (`created_by`) REFERENCES `utilizatori` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_curse_driver` FOREIGN KEY (`driver_id`) REFERENCES `soferi` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_curse_loc` FOREIGN KEY (`loc_incarcare_id`) REFERENCES `configurare_locuri_incarcare` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_curse_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicule` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_curse_zona` FOREIGN KEY (`zona_distributie_id`) REFERENCES `configurare_zone_distributie` (`id`) ON DELETE RESTRICT;

--
-- Constraints for table `documente`
--
ALTER TABLE `documente`
  ADD CONSTRAINT `fk_documente_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicule` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `documente_soferi`
--
ALTER TABLE `documente_soferi`
  ADD CONSTRAINT `fk_documente_soferi_driver` FOREIGN KEY (`driver_id`) REFERENCES `soferi` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `inventar_dotari_reguli`
--
ALTER TABLE `inventar_dotari_reguli`
  ADD CONSTRAINT `fk_inventar_reguli_catalog` FOREIGN KEY (`catalog_id`) REFERENCES `inventar_dotari_catalog` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `inventar_dotari_vehicule`
--
ALTER TABLE `inventar_dotari_vehicule`
  ADD CONSTRAINT `fk_inventar_dotari_catalog` FOREIGN KEY (`catalog_id`) REFERENCES `inventar_dotari_catalog` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_inventar_dotari_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicule` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `mentenanta`
--
ALTER TABLE `mentenanta`
  ADD CONSTRAINT `fk_mentenanta_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicule` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notification_queue`
--
ALTER TABLE `notification_queue`
  ADD CONSTRAINT `fk_notification_queue_delivery` FOREIGN KEY (`delivery_id`) REFERENCES `notification_deliveries` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notification_rule_recipients`
--
ALTER TABLE `notification_rule_recipients`
  ADD CONSTRAINT `fk_notification_rule_recipients_rule` FOREIGN KEY (`rule_id`) REFERENCES `notification_rules` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_notification_rule_recipients_user` FOREIGN KEY (`user_id`) REFERENCES `utilizatori` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `office_expenses`
--
ALTER TABLE `office_expenses`
  ADD CONSTRAINT `fk_office_expenses_added_by` FOREIGN KEY (`added_by`) REFERENCES `utilizatori` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_office_expenses_category` FOREIGN KEY (`category_id`) REFERENCES `office_expense_categories` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_office_expenses_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `utilizatori` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `office_expense_documents`
--
ALTER TABLE `office_expense_documents`
  ADD CONSTRAINT `fk_office_expense_documents_expense` FOREIGN KEY (`expense_id`) REFERENCES `office_expenses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_office_expense_documents_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `utilizatori` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `salary_history`
--
ALTER TABLE `salary_history`
  ADD CONSTRAINT `fk_salary_history_driver` FOREIGN KEY (`driver_id`) REFERENCES `soferi` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_salary_history_staff` FOREIGN KEY (`staff_member_id`) REFERENCES `staff_members` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_salary_history_user` FOREIGN KEY (`updated_by`) REFERENCES `utilizatori` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `soferi`
--
ALTER TABLE `soferi`
  ADD CONSTRAINT `fk_soferi_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicule` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `staff_documents`
--
ALTER TABLE `staff_documents`
  ADD CONSTRAINT `fk_staff_documents_created_by` FOREIGN KEY (`created_by`) REFERENCES `utilizatori` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_staff_documents_member` FOREIGN KEY (`staff_member_id`) REFERENCES `staff_members` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_staff_documents_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `utilizatori` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `staff_document_requirements`
--
ALTER TABLE `staff_document_requirements`
  ADD CONSTRAINT `fk_staff_doc_req_type` FOREIGN KEY (`staff_type_id`) REFERENCES `staff_types` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `staff_members`
--
ALTER TABLE `staff_members`
  ADD CONSTRAINT `fk_staff_members_created_by` FOREIGN KEY (`created_by`) REFERENCES `utilizatori` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_staff_members_type` FOREIGN KEY (`staff_type_id`) REFERENCES `staff_types` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_staff_members_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `utilizatori` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `staff_types`
--
ALTER TABLE `staff_types`
  ADD CONSTRAINT `fk_staff_types_created_by` FOREIGN KEY (`created_by`) REFERENCES `utilizatori` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_staff_types_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `utilizatori` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `vehicule_anvelope_pozitii`
--
ALTER TABLE `vehicule_anvelope_pozitii`
  ADD CONSTRAINT `fk_vehicule_anvelope_pozitii_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicule` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vehicule_cuplaje`
--
ALTER TABLE `vehicule_cuplaje`
  ADD CONSTRAINT `fk_vehicule_cuplaje_created_by` FOREIGN KEY (`created_by`) REFERENCES `utilizatori` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_vehicule_cuplaje_semiremorca` FOREIGN KEY (`semiremorca_id`) REFERENCES `vehicule` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_vehicule_cuplaje_tractor` FOREIGN KEY (`tractor_id`) REFERENCES `vehicule` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
