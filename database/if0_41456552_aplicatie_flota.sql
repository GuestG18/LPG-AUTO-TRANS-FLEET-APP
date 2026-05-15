-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: May 15, 2026 at 08:31 AM
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
  `observatii` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `alimentari`
--

INSERT INTO `alimentari` (`id`, `vehicle_id`, `driver_id`, `data_alimentare`, `litri`, `cost_total`, `km_bord`, `observatii`, `created_at`, `updated_at`) VALUES
(1, 1, 1, '2026-03-31', 45.50, 355.40, 85420, 'Motorina statia A', '2026-04-03 15:08:04', '2026-04-03 15:08:04'),
(2, 2, 2, '2026-04-01', 60.00, 468.00, 120300, 'Motorina statia B', '2026-04-03 15:08:04', '2026-04-03 15:08:04'),
(3, 1, 1, '2026-03-19', 42.00, 327.60, 84920, 'Alimentare traseu Brasov', '2026-04-03 15:08:04', '2026-04-03 15:08:04'),
(4, 3, NULL, '2026-03-14', 30.00, 240.00, 142210, 'Alimentare inainte de intrare service', '2026-04-03 15:08:04', '2026-04-03 15:08:04');

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
  `target_vehicle_type` enum('autovehicul','camion','cap_tractor','semiremorca','universal') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'universal',
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
(2, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B605NET-20260512124328-001-5ADC', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 6, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
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
(77, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B405NET-20260512124329-001-BDCC', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 81, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(78, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B405NET-20260512124329-002-CE0D', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 82, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(79, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B405NET-20260512124329-003-E91F', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 83, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(80, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B405NET-20260512124329-004-1EA6', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 84, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(81, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B405NET-20260512124329-005-1A06', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 85, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(82, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B405NET-20260512124329-006-1310', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 86, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(83, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B405NET-20260512124329-007-6E04', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 87, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(84, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B405NET-20260512124329-008-C4CE', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 88, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(85, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B405NET-20260512124329-009-4FD5', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 89, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(86, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B405NET-20260512124329-010-C4DD', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 90, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(87, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B405NET-20260512124329-011-44BE', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 91, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(88, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B405NET-20260512124329-012-A134', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 92, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
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
(113, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B235NET-20260512124329-007-C269', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 117, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(114, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B235NET-20260512124329-008-F413', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 118, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(115, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B235NET-20260512124329-009-3AB7', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 119, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(116, 'AUTO-FLEET', 'AUTO-MOUNT', NULL, NULL, 'AUTO-B235NET-20260512124329-010-982A', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'active', 'Auto-completare anvelope pentru vehicule active (2026-05-12)', 120, '2026-05-12 12:43:28', '2026-05-12 14:09:05'),
(117, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-SEMIREMORCA-20260512125113-7AD63A-0001', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', 142, '2026-05-12 12:51:13', '2026-05-12 16:21:15'),
(118, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-SEMIREMORCA-20260512125113-80FD9C-0002', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', 143, '2026-05-12 12:51:13', '2026-05-12 16:21:15'),
(119, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-SEMIREMORCA-20260512125113-9B81D9-0003', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', 144, '2026-05-12 12:51:13', '2026-05-12 16:21:15'),
(120, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-SEMIREMORCA-20260512125113-9902DE-0004', 'semiremorca', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', 145, '2026-05-12 12:51:13', '2026-05-12 16:21:15'),
(121, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-CAMION-20260512125113-C14A0C-0001', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', 146, '2026-05-12 12:51:13', '2026-05-12 16:21:15'),
(122, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-CAMION-20260512125113-A10D5C-0002', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', 147, '2026-05-12 12:51:13', '2026-05-12 16:21:15'),
(123, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-CAMION-20260512125113-59A417-0003', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', 148, '2026-05-12 12:51:13', '2026-05-12 16:21:15'),
(124, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-CAMION-20260512125113-39B304-0004', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', 149, '2026-05-12 12:51:13', '2026-05-12 16:21:15'),
(125, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-CAMION-20260512125113-8F8D4C-0005', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', 150, '2026-05-12 12:51:13', '2026-05-12 16:21:15'),
(126, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-CAMION-20260512125113-E73F4F-0006', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', 151, '2026-05-12 12:51:13', '2026-05-12 16:21:15'),
(127, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-CAMION-20260512125113-250F38-0007', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', 152, '2026-05-12 12:51:13', '2026-05-12 16:21:15'),
(128, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-CAMION-20260512125113-DFEA99-0008', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', 153, '2026-05-12 12:51:13', '2026-05-12 16:21:15'),
(129, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-CAMION-20260512125113-BAA38D-0009', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', 154, '2026-05-12 12:51:13', '2026-05-12 16:21:15'),
(130, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-CAMION-20260512125113-8B9117-0010', 'camion', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', 155, '2026-05-12 12:51:13', '2026-05-12 16:21:15'),
(131, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-CAPTRACTOR-20260512125113-0D5261-0001', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', 156, '2026-05-12 12:51:13', '2026-05-12 16:21:15'),
(132, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-CAPTRACTOR-20260512125113-1491A1-0002', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', 157, '2026-05-12 12:51:13', '2026-05-12 16:21:15'),
(133, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-CAPTRACTOR-20260512125113-36BE8C-0003', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', 158, '2026-05-12 12:51:13', '2026-05-12 16:21:15'),
(134, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-CAPTRACTOR-20260512125113-46D2D2-0004', 'cap_tractor', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', 159, '2026-05-12 12:51:13', '2026-05-12 16:21:15'),
(135, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-AUTOVEHICUL-20260512125113-B73DBC-0001', 'autovehicul', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', 160, '2026-05-12 12:51:13', '2026-05-12 16:21:15'),
(136, 'SEASON-STOCK', 'ALL-SEASON 2026', NULL, NULL, 'SEASON-AUTOVEHICUL-20260512125113-066296-0002', 'autovehicul', '2026-05-12', 0, 180000, NULL, 2.00, 'spare', 'Stoc sezon creat automat (2026-05-12)', 141, '2026-05-12 12:51:13', '2026-05-12 16:21:15');

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
(2, 2, 28, 164, '2026-05-12', NULL, 323232, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
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
(63, 63, 17, 101, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(64, 64, 17, 102, '2026-05-12', NULL, 250, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
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
(77, 77, 12, 57, '2026-05-12', NULL, 198, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(78, 78, 12, 58, '2026-05-12', NULL, 198, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(79, 79, 12, 59, '2026-05-12', NULL, 198, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(80, 80, 12, 60, '2026-05-12', NULL, 198, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(81, 81, 12, 61, '2026-05-12', NULL, 198, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(82, 82, 12, 62, '2026-05-12', NULL, 198, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(83, 83, 12, 63, '2026-05-12', NULL, 198, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(84, 84, 12, 64, '2026-05-12', NULL, 198, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(85, 85, 12, 65, '2026-05-12', NULL, 198, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(86, 86, 12, 66, '2026-05-12', NULL, 198, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(87, 87, 12, 67, '2026-05-12', NULL, 198, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(88, 88, 12, 68, '2026-05-12', NULL, 198, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(89, 89, 11, 51, '2026-05-12', NULL, 710860, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(90, 90, 11, 52, '2026-05-12', NULL, 710860, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(91, 91, 11, 53, '2026-05-12', NULL, 710860, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(92, 92, 11, 54, '2026-05-12', NULL, 710860, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(93, 93, 11, 55, '2026-05-12', NULL, 710860, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(94, 94, 11, 56, '2026-05-12', NULL, 710860, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(95, 95, 9, 27, '2026-05-12', NULL, 500, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(96, 96, 9, 28, '2026-05-12', NULL, 500, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(97, 97, 9, 29, '2026-05-12', NULL, 500, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
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
(113, 113, 6, 23, '2026-05-12', NULL, 0, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(114, 114, 6, 24, '2026-05-12', NULL, 0, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(115, 115, 6, 25, '2026-05-12', NULL, 0, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28'),
(116, 116, 6, 26, '2026-05-12', NULL, 0, NULL, NULL, NULL, '2026-05-12 12:43:28', '2026-05-12 12:43:28');

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
(92, 'documente', 24, 'update', 'Document actualizat: ITP () pentru B 677 NET', '{\"vehicul\":\"B 677 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-08\",\"fisier_original\":null}', '{\"vehicul\":\"B 677 NET\",\"tip_document\":\"ITP\",\"numar_document\":\"\",\"data_expirare\":\"2026-05-27\",\"fisier_original\":null}', 1, '2026-05-11 10:32:30');

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

INSERT INTO `configurare_beneficiari_transport` (`id`, `nume`, `tip_marfa`, `pret_tarifare`, `suporta_primar`, `suporta_distributie`, `suporta_compresor`, `pret_km`, `pret_tona`, `pret_distributie_km`, `pret_distributie_tona`, `pret_ora_aspirare`, `pret_km_dislocare`, `pret_tona_livrata`, `pret_tona_aspirata_lichida`, `pret_tona_aspirata_gazoasa`, `activ`, `created_at`, `updated_at`) VALUES
(33, 'ButanGas', NULL, 0.00, 1, 1, 1, 1.50, 1.50, 0.00, 0.00, 1.50, 1.50, 1.50, 1.50, 0.00, 1, '2026-05-07 13:37:59', '2026-05-13 14:45:27'),
(42, 'LPG AUTO', 'gpl_vrac', 5.50, 1, 1, 0, 5.50, 2.85, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 1, '2026-05-11 12:27:53', '2026-05-11 12:27:53'),
(43, 'Retail Client SRL', 'butelii', 5.20, 1, 1, 0, 5.20, 2.60, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 1, '2026-05-11 12:27:53', '2026-05-11 12:27:53'),
(44, 'Distrib Logistic SA', 'carburant', 5.80, 1, 1, 0, 5.80, 3.20, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 1, '2026-05-11 12:27:53', '2026-05-11 12:27:53');

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
(22, 33, 6, '2026-05-13 14:45:28', '2026-05-13 14:45:28'),
(23, 33, 15, '2026-05-13 14:45:28', '2026-05-13 14:45:28'),
(24, 33, 19, '2026-05-13 14:45:28', '2026-05-13 14:45:28');

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
(72, 42, 'Depozit Central Bucuresti', 0.00, 1, '2026-05-11 12:27:53', '2026-05-11 12:27:53'),
(73, 43, 'Terminal Brasov', 0.00, 1, '2026-05-11 12:27:53', '2026-05-11 12:27:53'),
(74, 42, 'Hub Cluj', 0.00, 1, '2026-05-11 12:27:53', '2026-05-11 12:27:53');

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
  `tarif_tona` decimal(10,2) NOT NULL DEFAULT '0.00',
  `cost_extra_km` decimal(10,2) NOT NULL DEFAULT '0.00',
  `km_tarifare` int UNSIGNED NOT NULL DEFAULT '0',
  `vehicle_ids` text COLLATE utf8mb4_unicode_ci,
  `activ` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `configurare_rute_distributie`
--

INSERT INTO `configurare_rute_distributie` (`id`, `beneficiar_id`, `loc_incarcare_id`, `zona_distributie_id`, `tarif_tona`, `cost_extra_km`, `km_tarifare`, `vehicle_ids`, `activ`, `created_at`, `updated_at`) VALUES
(30, 33, 54, 55, 10.00, 5.00, 650, '15,16,17,18', 1, '2026-05-07 13:39:50', '2026-05-13 15:18:53'),
(31, 33, 54, 56, 15.00, 5.00, 385, '15,16,17,18', 1, '2026-05-07 13:40:31', '2026-05-13 15:17:43'),
(32, 33, 55, 55, 15.00, 5.00, 415, '15,16,17,18', 1, '2026-05-07 13:41:23', '2026-05-13 15:16:37'),
(33, 33, 55, 56, 15.00, 5.00, 320, '15,16,17,18', 1, '2026-05-07 13:41:53', '2026-05-13 15:16:04'),
(34, 33, 55, 56, 10.00, 5.00, 260, '19,20,21,22', 1, '2026-05-07 13:48:02', '2026-05-13 15:15:48'),
(35, 33, 55, 55, 15.00, 5.00, 350, '19,20,21,22', 1, '2026-05-07 13:48:36', '2026-05-13 15:16:22'),
(36, 33, 54, 56, 15.00, 5.00, 420, '19,20,21,22', 1, '2026-05-07 13:49:18', '2026-05-13 15:17:28'),
(37, 33, 54, 55, 15.00, 5.00, 420, '19,20,21,22', 1, '2026-05-07 13:49:50', '2026-05-13 15:18:37'),
(38, 33, 56, 57, 10.00, 5.00, 550, '6,23,24,28', 1, '2026-05-07 13:52:15', '2026-05-13 15:15:34'),
(39, 33, 56, 58, 15.00, 5.00, 400, '6,23,24,28', 1, '2026-05-07 13:52:58', '2026-05-13 15:15:01'),
(40, 33, 61, 57, 15.00, 5.00, 360, '6,23,24,28', 1, '2026-05-07 13:54:26', '2026-05-13 15:17:12'),
(41, 33, 61, 58, 15.00, 5.00, 340, '6,23,24,28', 1, '2026-05-07 13:55:10', '2026-05-13 15:16:56'),
(42, 33, 56, 55, 15.00, 5.00, 350, '6,23,24,28', 1, '2026-05-07 13:55:52', '2026-05-13 15:15:15'),
(43, 33, 56, 56, 15.00, 5.00, 240, '6,23,24,28', 1, '2026-05-07 13:56:30', '2026-05-13 15:14:48'),
(44, 33, 62, 60, 0.00, 5.00, 540, '26,27', 1, '2026-05-07 14:08:48', '2026-05-13 15:19:07');

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
  `activ` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `configurare_rute_primar`
--

INSERT INTO `configurare_rute_primar` (`id`, `beneficiar_id`, `loc_incarcare_id`, `zona_distributie_id`, `km_tarifare`, `activ`, `created_at`, `updated_at`) VALUES
(1, 33, 56, 59, 200, 1, '2026-05-08 08:53:54', '2026-05-08 08:53:54'),
(2, 33, 56, 55, 200, 1, '2026-05-08 08:56:25', '2026-05-08 08:56:25'),
(3, 33, 55, 58, 450, 1, '2026-05-08 08:59:26', '2026-05-08 08:59:26'),
(4, 33, 61, 59, 350, 1, '2026-05-08 08:59:40', '2026-05-08 08:59:40'),
(5, 33, 54, 57, 500, 1, '2026-05-08 09:00:08', '2026-05-08 09:00:08');

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
(69, 43, 'Bucuresti', 2.60, 0.00, 1, '2026-05-11 12:27:53', '2026-05-11 12:27:53'),
(70, 42, 'Ilfov', 2.85, 0.00, 1, '2026-05-11 12:27:53', '2026-05-11 12:27:53'),
(71, 42, 'Regional', 3.20, 0.00, 1, '2026-05-11 12:27:53', '2026-05-11 12:27:53');

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
  `suma` decimal(12,2) NOT NULL,
  `data_cheltuiala` date NOT NULL,
  `observatii` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `curse_dispecer`
--

INSERT INTO `curse_dispecer` (`id`, `vehicle_id`, `driver_id`, `tip_transport`, `data_cursa`, `data_inceput`, `data_sfarsit`, `ora_inceput`, `ora_sfarsit`, `durata_cursa_minute`, `tip_marfa`, `capacitate_transport`, `loc_incarcare_id`, `beneficiar_id`, `cantitate_incarcata`, `cantitate_prelevata`, `nr_clienti`, `km_cursa`, `ore_functionare`, `km_totali`, `ore_aspirare`, `km_dislocare`, `tona_livrata`, `tona_aspirata_lichida`, `tona_aspirata_gazoasa`, `zona_distributie_id`, `status_facturare`, `pret_tarifare`, `total_facturare`, `cost_km_primar`, `cost_km_distributie`, `cost_km_mixt`, `cost_km_compresor`, `observatii`, `created_at`, `updated_at`) VALUES
(88, 19, 10, 'primar', '2026-05-15', '2026-05-15', '2026-05-16', '09:46:00', '09:46:00', 1440, 'propan', 10.00, 56, 33, NULL, NULL, NULL, 200, NULL, 250, NULL, NULL, NULL, NULL, NULL, 59, 'in_curs_facturare', 1.50, 300.00, 1.50, 0.00, 1.50, 0.00, NULL, '2026-05-15 09:47:52', '2026-05-15 09:47:52'),
(89, 19, 10, 'primar_distributie', '2026-05-15', '2026-05-15', '2026-05-16', '09:52:00', NULL, NULL, 'propan', 10.00, 55, 33, 10.00, NULL, NULL, 260, NULL, 400, NULL, NULL, NULL, NULL, NULL, 56, 'in_curs_facturare', 10.00, 1400.00, 5.00, 0.71, 3.50, 0.00, NULL, '2026-05-15 09:53:22', '2026-05-15 09:53:22'),
(90, 19, 10, 'primar_tona', '2026-05-15', '2026-05-15', '2026-05-16', '10:12:00', '10:12:00', 1440, 'propan', 10.00, 56, 33, 10.00, NULL, NULL, 200, NULL, 250, NULL, NULL, NULL, NULL, NULL, 59, 'in_curs_facturare', 1.50, 15.00, 1.50, 0.00, 1.50, 0.00, NULL, '2026-05-15 10:13:45', '2026-05-15 10:13:45');

-- --------------------------------------------------------

--
-- Table structure for table `documente`
--

CREATE TABLE `documente` (
  `id` int UNSIGNED NOT NULL,
  `vehicle_id` int UNSIGNED NOT NULL,
  `tip_document` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `numar_document` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_expirare` date NOT NULL,
  `fisier_original` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fisier_stocat` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `observatii` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `documente`
--

INSERT INTO `documente` (`id`, `vehicle_id`, `tip_document`, `numar_document`, `data_expirare`, `fisier_original`, `fisier_stocat`, `observatii`, `created_at`, `updated_at`) VALUES
(1, 1, 'RCA', 'RCA-001-2026', '2026-04-18', NULL, NULL, 'Prioritate mare pentru reinnoire', '2026-04-03 15:08:04', '2026-04-03 15:08:04'),
(2, 1, 'ITP', 'ITP-001-2026', '2026-05-28', NULL, NULL, 'Programare deja facuta', '2026-04-03 15:08:04', '2026-04-03 15:08:04'),
(3, 2, 'Rovinieta', 'ROV-7788', '2026-04-11', NULL, NULL, 'Verificare online recomandata', '2026-04-03 15:08:04', '2026-04-03 15:08:04'),
(4, 3, 'RCA', 'RCA-003-2026', '2026-04-01', NULL, NULL, 'Expirat - nu circula', '2026-04-03 15:08:04', '2026-04-03 15:08:04'),
(7, 1, 'ROV', '', '2026-04-15', '430_Iprochim.pdf', 'document_20260406_110439_2fda4c4a8b188e5d.pdf', NULL, '2026-04-06 11:04:39', '2026-04-06 11:04:39'),
(8, 1, 'Rovinieta', '', '2026-04-14', NULL, NULL, NULL, '2026-04-15 10:38:10', '2026-04-15 10:38:53'),
(9, 6, 'RCA', '', '2027-01-14', '235_RCA.pdf', 'document_20260416_105345_1db4ad95a4944ac3.pdf', NULL, '2026-04-16 10:53:45', '2026-04-16 10:53:45'),
(10, 6, 'ITP', '', '2026-12-22', '235_Certificat_ITP.pdf', 'document_20260416_105713_5c77932edaf33b10.pdf', NULL, '2026-04-16 10:57:13', '2026-04-16 10:57:13'),
(11, 6, 'Rovinieta', '', '2026-12-16', '235_RCA.pdf', 'document_20260416_105956_f58b20674b2e719b.pdf', NULL, '2026-04-16 10:59:56', '2026-04-16 10:59:56'),
(12, 9, 'RCA', '', '2026-07-28', '805_Carte.pdf', 'document_20260420_093258_76f383603b273442.pdf', 'Test', '2026-04-20 09:32:58', '2026-04-20 09:32:58'),
(13, 9, 'ITP', '', '2026-06-20', '235_Certificat_ITP.pdf', 'document_20260420_093348_755fd011d1328e66.pdf', 'TEST', '2026-04-20 09:33:48', '2026-04-20 09:33:48'),
(14, 9, 'Rovinieta', '', '2026-05-27', '235_Carte.pdf', 'document_20260420_093423_11714b9c2c994c4a.pdf', 'Test', '2026-04-20 09:34:23', '2026-04-20 11:09:16'),
(16, 11, 'RCA', '', '2027-01-27', '665_RCA.pdf', 'document_20260421_121203_5c9c61048566d6b9.pdf', NULL, '2026-04-21 12:12:03', '2026-04-21 12:12:03'),
(17, 11, 'ITP', '', '2027-01-12', '665_ADR.pdf', 'document_20260421_121344_f02b88e26da55e4c.pdf', NULL, '2026-04-21 12:13:44', '2026-04-21 12:13:44'),
(18, 11, 'Rovinieta', '', '2027-02-12', '665_Casco.pdf', 'document_20260421_121523_8a87212c73e2bd29.pdf', NULL, '2026-04-21 12:15:23', '2026-04-21 12:15:23'),
(19, 12, 'RCA', '', '2026-05-24', '405_RCA.pdf', 'document_20260421_122231_db49cfef84502c78.pdf', NULL, '2026-04-21 12:22:31', '2026-04-21 12:22:31'),
(20, 12, 'Rovinieta', '', '2026-05-23', '405_Casco.pdf', 'document_20260421_123515_b228408aece0c1ee.pdf', NULL, '2026-04-21 12:35:15', '2026-04-21 12:35:15'),
(21, 12, 'ITP', '', '2026-05-12', '405_ADR.pdf', 'document_20260421_123904_dc3f96a16c945e62.pdf', NULL, '2026-04-21 12:39:04', '2026-04-21 12:39:04'),
(22, 26, 'RCA', '', '2026-05-27', NULL, NULL, NULL, '2026-05-05 12:46:08', '2026-05-05 12:46:08'),
(23, 26, 'Rovinieta', '', '2026-06-02', NULL, NULL, NULL, '2026-05-05 12:47:45', '2026-05-05 12:47:45'),
(24, 26, 'ITP', '', '2026-05-27', NULL, NULL, NULL, '2026-05-05 12:49:47', '2026-05-11 10:32:30'),
(25, 27, 'Rovinieta', '', '2026-06-25', NULL, NULL, NULL, '2026-05-05 13:01:20', '2026-05-05 13:01:20'),
(26, 27, 'RCA', '', '2026-07-20', NULL, NULL, NULL, '2026-05-05 13:01:58', '2026-05-05 13:01:58'),
(27, 27, 'ITP', '', '2026-05-25', NULL, NULL, NULL, '2026-05-05 13:02:26', '2026-05-05 13:02:26'),
(31, 19, 'RCA', '', '2026-06-24', NULL, NULL, NULL, '2026-05-06 11:51:34', '2026-05-06 11:51:34'),
(32, 19, 'ITP', '', '2026-06-17', NULL, NULL, NULL, '2026-05-06 11:51:44', '2026-05-06 11:51:44'),
(33, 19, 'Rovinieta', '', '2026-06-25', NULL, NULL, NULL, '2026-05-06 11:51:57', '2026-05-06 11:51:57'),
(34, 20, 'RCA', '', '2026-06-25', NULL, NULL, NULL, '2026-05-06 11:52:39', '2026-05-06 11:52:39'),
(35, 20, 'ITP', '', '2026-06-24', NULL, NULL, NULL, '2026-05-06 11:52:52', '2026-05-06 11:52:52'),
(36, 20, 'Rovinieta', '', '2026-06-17', NULL, NULL, NULL, '2026-05-06 11:53:00', '2026-05-06 11:53:00'),
(37, 22, 'RCA', '', '2026-06-23', NULL, NULL, NULL, '2026-05-06 11:53:38', '2026-05-06 11:53:38'),
(38, 22, 'ITP', '', '2026-06-23', NULL, NULL, NULL, '2026-05-06 11:53:51', '2026-05-06 11:53:51'),
(39, 22, 'Rovinieta', '', '2026-06-24', NULL, NULL, NULL, '2026-05-06 11:54:09', '2026-05-06 11:54:09'),
(40, 21, 'ITP', '', '2026-06-24', NULL, NULL, NULL, '2026-05-06 11:54:40', '2026-05-06 11:54:40'),
(41, 21, 'Rovinieta', '', '2026-05-26', NULL, NULL, NULL, '2026-05-06 11:54:50', '2026-05-06 11:54:50'),
(42, 21, 'RCA', '', '2026-05-28', NULL, NULL, NULL, '2026-05-06 11:54:57', '2026-05-06 11:54:57'),
(43, 15, 'RCA', '', '2026-05-27', NULL, NULL, NULL, '2026-05-06 11:55:26', '2026-05-06 11:55:26'),
(44, 15, 'ITP', '', '2026-06-23', NULL, NULL, NULL, '2026-05-06 11:55:35', '2026-05-06 11:55:35'),
(45, 15, 'Rovinieta', '', '2026-06-23', NULL, NULL, NULL, '2026-05-06 11:55:44', '2026-05-06 11:55:44'),
(46, 16, 'RCA', '', '2026-06-17', NULL, NULL, NULL, '2026-05-06 11:56:17', '2026-05-06 11:56:17'),
(47, 16, 'Rovinieta', '', '2026-06-24', NULL, NULL, NULL, '2026-05-06 11:56:28', '2026-05-06 11:56:28'),
(48, 16, 'ITP', '', '2026-06-24', NULL, NULL, NULL, '2026-05-06 11:56:47', '2026-05-06 11:56:47'),
(49, 23, 'RCA', '', '2026-07-01', NULL, NULL, NULL, '2026-05-07 11:13:23', '2026-05-07 11:13:23'),
(50, 23, 'Rovinieta', '', '2026-06-23', NULL, NULL, NULL, '2026-05-07 11:13:30', '2026-05-07 11:13:30'),
(51, 23, 'ITP', '', '2026-06-23', NULL, NULL, NULL, '2026-05-07 11:13:38', '2026-05-07 11:13:38'),
(52, 24, 'RCA', '', '2026-07-02', NULL, NULL, NULL, '2026-05-07 11:14:00', '2026-05-07 11:14:00'),
(53, 24, 'Rovinieta', '', '2026-06-10', NULL, NULL, NULL, '2026-05-07 11:14:08', '2026-05-07 11:14:08'),
(54, 24, 'ITP', '', '2026-06-23', NULL, NULL, NULL, '2026-05-07 11:14:14', '2026-05-07 11:14:14'),
(55, 18, 'Rovinieta', '', '2026-07-14', NULL, NULL, NULL, '2026-05-07 11:54:52', '2026-05-07 11:54:52'),
(56, 18, 'RCA', '', '2026-06-23', NULL, NULL, NULL, '2026-05-07 11:55:03', '2026-05-07 11:55:03'),
(57, 18, 'ITP', '', '2026-06-22', NULL, NULL, NULL, '2026-05-07 11:55:12', '2026-05-07 11:55:12'),
(58, 17, 'Rovinieta', '', '2026-06-23', NULL, NULL, NULL, '2026-05-07 11:55:34', '2026-05-07 11:55:34'),
(59, 17, 'ITP', '', '2026-06-15', NULL, NULL, NULL, '2026-05-07 11:55:42', '2026-05-07 11:55:42'),
(60, 17, 'RCA', '', '2026-05-26', NULL, NULL, NULL, '2026-05-07 11:55:47', '2026-05-07 11:55:47'),
(61, 28, 'Rovinieta', '', '2026-05-29', NULL, NULL, NULL, '2026-05-07 12:28:46', '2026-05-07 12:28:46'),
(62, 28, 'RCA', '', '2026-05-25', NULL, NULL, NULL, '2026-05-07 12:29:02', '2026-05-07 12:29:02'),
(63, 28, 'ITP', '', '2026-05-30', NULL, NULL, NULL, '2026-05-07 12:29:11', '2026-05-07 12:29:11');

-- --------------------------------------------------------

--
-- Table structure for table `documente_soferi`
--

CREATE TABLE `documente_soferi` (
  `id` int UNSIGNED NOT NULL,
  `driver_id` int UNSIGNED NOT NULL,
  `tip_document` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `numar_document` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_expirare` date NOT NULL,
  `fisier_original` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fisier_stocat` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `observatii` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `documente_soferi`
--

INSERT INTO `documente_soferi` (`id`, `driver_id`, `tip_document`, `numar_document`, `data_expirare`, `fisier_original`, `fisier_stocat`, `observatii`, `created_at`, `updated_at`) VALUES
(1, 1, 'Carte identitate', 'CI-ION-2026', '2027-06-02', 'Whatsapp_Scan_5_ianuarie_2026_at_09.42.17_1.pdf', 'document_20260408_114610_73a46a3f07d24937.pdf', 'Document personal incarcat pentru evidenta interna', '2026-04-08 11:37:58', '2026-04-08 11:46:10'),
(2, 1, 'Atestat profesional', 'ATP-101', '2026-04-26', NULL, NULL, 'Necesita verificare pentru reinnoire', '2026-04-08 11:37:58', '2026-04-08 11:37:58'),
(3, 2, 'Aviz medical', 'MED-2026-02', '2026-06-12', NULL, NULL, 'Valabil pentru cursele curente', '2026-04-08 11:37:58', '2026-04-08 11:37:58'),
(4, 1, 'Aviz medical', NULL, '2026-07-21', NULL, NULL, NULL, '2026-04-15 10:39:50', '2026-04-20 09:56:50'),
(5, 1, 'test', NULL, '2026-05-21', NULL, NULL, NULL, '2026-05-06 10:23:40', '2026-05-06 10:23:40'),
(6, 4, 'Carte identitate', NULL, '2026-10-28', NULL, NULL, NULL, '2026-05-06 12:00:01', '2026-05-06 12:00:01'),
(7, 4, 'Atestat profesional', NULL, '2026-09-22', NULL, NULL, NULL, '2026-05-06 12:00:17', '2026-05-06 12:00:17'),
(8, 4, 'Aviz medical', NULL, '2026-08-26', NULL, NULL, NULL, '2026-05-06 12:00:46', '2026-05-06 12:00:46'),
(9, 5, 'Carte identitate', NULL, '2026-06-23', NULL, NULL, NULL, '2026-05-06 12:01:38', '2026-05-06 12:01:38'),
(10, 5, 'Atestat profesional', NULL, '2026-06-16', NULL, NULL, NULL, '2026-05-06 12:01:52', '2026-05-06 12:01:52'),
(11, 5, 'Aviz medical', NULL, '2026-06-23', NULL, NULL, NULL, '2026-05-06 12:02:14', '2026-05-06 12:02:14'),
(12, 6, 'Carte identitate', NULL, '2026-07-29', NULL, NULL, NULL, '2026-05-06 12:03:01', '2026-05-06 12:03:01'),
(13, 6, 'Aviz medical', NULL, '2026-06-30', NULL, NULL, NULL, '2026-05-06 12:03:11', '2026-05-06 12:03:11'),
(14, 6, 'Atestat profesional', NULL, '2026-08-20', NULL, NULL, NULL, '2026-05-06 12:03:27', '2026-05-06 12:03:27'),
(15, 7, 'Carte identitate', NULL, '2026-09-21', NULL, NULL, NULL, '2026-05-06 12:04:11', '2026-05-06 12:04:11'),
(16, 7, 'Aviz medical', NULL, '2026-09-22', NULL, NULL, NULL, '2026-05-06 12:04:22', '2026-05-06 12:04:22'),
(17, 7, 'Atestat profesional', NULL, '2026-08-19', NULL, NULL, NULL, '2026-05-06 12:04:33', '2026-05-06 12:04:33'),
(18, 8, 'Carte identitate', NULL, '2026-09-30', NULL, NULL, NULL, '2026-05-06 12:05:29', '2026-05-06 12:05:29'),
(19, 8, 'Aviz medical', NULL, '2026-10-21', NULL, NULL, NULL, '2026-05-06 12:05:39', '2026-05-06 12:05:39'),
(20, 8, 'Atestat profesional', NULL, '2026-10-01', NULL, NULL, NULL, '2026-05-06 12:05:51', '2026-05-06 12:05:51'),
(21, 9, 'Carte identitate', NULL, '2026-08-26', NULL, NULL, NULL, '2026-05-06 12:06:28', '2026-05-06 12:06:41'),
(22, 9, 'Atestat profesional', NULL, '2026-09-28', NULL, NULL, NULL, '2026-05-06 12:06:56', '2026-05-06 12:06:56'),
(23, 9, 'Aviz medical', NULL, '2026-08-18', NULL, NULL, NULL, '2026-05-06 12:07:07', '2026-05-06 12:07:07'),
(24, 10, 'Carte identitate', NULL, '2026-08-19', NULL, NULL, NULL, '2026-05-06 12:07:57', '2026-05-06 12:07:57'),
(25, 10, 'Atestat profesional', NULL, '2026-09-29', NULL, NULL, NULL, '2026-05-06 12:08:06', '2026-05-06 12:08:27'),
(26, 10, 'Aviz medical', NULL, '2026-10-28', NULL, NULL, NULL, '2026-05-06 12:08:19', '2026-05-06 12:08:19'),
(27, 11, 'Carte identitate', NULL, '2026-09-22', NULL, NULL, NULL, '2026-05-06 12:09:16', '2026-05-06 12:09:16'),
(28, 11, 'Aviz medical', NULL, '2026-09-22', NULL, NULL, NULL, '2026-05-06 12:09:27', '2026-05-06 12:09:27'),
(29, 11, 'Atestat profesional', NULL, '2026-09-23', NULL, NULL, NULL, '2026-05-06 12:09:38', '2026-05-06 12:09:38'),
(30, 12, 'Carte identitate', NULL, '2026-05-26', NULL, NULL, NULL, '2026-05-11 12:42:40', '2026-05-11 12:42:40'),
(31, 12, 'Atestat profesional', NULL, '2026-05-28', NULL, NULL, NULL, '2026-05-11 12:42:56', '2026-05-11 12:42:56'),
(32, 12, 'Aviz medical', NULL, '2026-05-28', NULL, NULL, NULL, '2026-05-11 12:43:08', '2026-05-11 12:43:08');

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
(5, 28, 'Anvelopa - Active', '2026-05-12', 0.00, 'Michelin Axinte1', NULL, NULL, NULL, 'Serie anvelopa: 53214151\r\nDimensiune: 315/80\r\nDOT: 3423\r\nCompatibil: Autoturism', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(6, 28, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B605NET-20260512124328-001-5ADC\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(7, 28, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B605NET-20260512124328-002-9E40\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(8, 28, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B605NET-20260512124328-003-FF7E\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(9, 28, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B605NET-20260512124328-004-A5B5\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(10, 28, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B605NET-20260512124328-005-F8CD\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(11, 27, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B435NET-20260512124328-001-C421\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(12, 27, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B435NET-20260512124328-002-7588\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(13, 27, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B435NET-20260512124328-003-6857\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(14, 27, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B435NET-20260512124328-004-3015\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(15, 27, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B435NET-20260512124328-005-B5D6\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(16, 27, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B435NET-20260512124328-006-4647\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(17, 26, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B677NET-20260512124328-001-B508\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(18, 26, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B677NET-20260512124328-002-A7F4\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(19, 26, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B677NET-20260512124328-003-1E79\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(20, 26, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B677NET-20260512124328-004-D01D\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(21, 26, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B677NET-20260512124328-005-E4E0\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(22, 26, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B677NET-20260512124328-006-F742\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(23, 24, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B325NET-20260512124328-001-86AE\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(24, 24, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B325NET-20260512124328-002-ECDF\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(25, 24, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B325NET-20260512124328-003-4EB4\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(26, 24, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B325NET-20260512124328-004-B28C\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(27, 24, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B325NET-20260512124328-005-2B23\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(28, 24, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B325NET-20260512124328-006-F9F7\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(29, 23, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B275NET-20260512124328-001-5463\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(30, 23, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B275NET-20260512124328-002-C3EB\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(31, 23, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B275NET-20260512124328-003-5824\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(32, 23, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B275NET-20260512124328-004-68D4\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(33, 23, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B275NET-20260512124328-005-3BA4\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(34, 23, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B275NET-20260512124328-006-2614\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(35, 22, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B430NET-20260512124328-001-B89F\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(36, 22, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B430NET-20260512124328-002-DD59\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(37, 22, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B430NET-20260512124328-003-77AB\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(38, 22, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B430NET-20260512124328-004-9879\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(39, 22, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B430NET-20260512124328-005-58C4\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(40, 22, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B430NET-20260512124328-006-65AB\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(41, 21, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B311NET-20260512124328-001-232D\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(42, 21, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B311NET-20260512124328-002-083E\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(43, 21, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B311NET-20260512124328-003-07DF\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(44, 21, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B311NET-20260512124328-004-697C\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(45, 21, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B311NET-20260512124328-005-E284\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(46, 21, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B311NET-20260512124328-006-078C\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(47, 20, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B345NET-20260512124328-001-22D7\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(48, 20, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B345NET-20260512124328-002-DB99\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(49, 20, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B345NET-20260512124328-003-D1EF\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(50, 20, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B345NET-20260512124328-004-E9F2\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(51, 20, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B345NET-20260512124328-005-46DB\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(52, 20, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B345NET-20260512124328-006-89C6\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(53, 19, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B219NET-20260512124328-001-B5A5\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(54, 19, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B219NET-20260512124328-002-955C\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(55, 19, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B219NET-20260512124328-003-B5F7\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(56, 19, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B219NET-20260512124328-004-C37D\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(57, 19, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B219NET-20260512124328-005-67F7\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(58, 19, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B219NET-20260512124328-006-FF14\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(59, 18, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B437NET-20260512124328-001-D0AB\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(60, 18, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B437NET-20260512124328-002-56D2\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(61, 18, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B437NET-20260512124328-003-1B69\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(62, 18, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B437NET-20260512124328-004-33DC\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(63, 18, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B437NET-20260512124328-005-4C63\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(64, 18, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B437NET-20260512124328-006-E6E7\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(65, 17, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B385NET-20260512124328-001-DB8B\r\nCompatibil: Autoturism\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(66, 17, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B385NET-20260512124328-002-B030\r\nCompatibil: Autoturism\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(67, 17, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B385NET-20260512124328-003-925D\r\nCompatibil: Autoturism\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(68, 17, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B385NET-20260512124328-004-DB5E\r\nCompatibil: Autoturism\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(69, 16, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B375NET-20260512124329-001-8B2F\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(70, 16, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B375NET-20260512124329-002-5930\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(71, 16, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B375NET-20260512124329-003-0358\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(72, 16, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B375NET-20260512124329-004-CF2C\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(73, 16, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B375NET-20260512124329-005-9E6C\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(74, 16, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B375NET-20260512124329-006-CDF2\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(75, 15, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B285NET-20260512124329-001-A860\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(76, 15, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B285NET-20260512124329-002-A8AF\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(77, 15, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B285NET-20260512124329-003-E189\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(78, 15, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B285NET-20260512124329-004-41EE\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(79, 15, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B285NET-20260512124329-005-6AEE\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(80, 15, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B285NET-20260512124329-006-DC3F\r\nCompatibil: Camion\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(81, 12, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B405NET-20260512124329-001-BDCC\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(82, 12, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B405NET-20260512124329-002-CE0D\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(83, 12, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B405NET-20260512124329-003-E91F\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(84, 12, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B405NET-20260512124329-004-1EA6\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(85, 12, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B405NET-20260512124329-005-1A06\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(86, 12, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B405NET-20260512124329-006-1310\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(87, 12, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B405NET-20260512124329-007-6E04\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(88, 12, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B405NET-20260512124329-008-C4CE\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(89, 12, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B405NET-20260512124329-009-4FD5\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(90, 12, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B405NET-20260512124329-010-C4DD\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(91, 12, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B405NET-20260512124329-011-44BE\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(92, 12, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B405NET-20260512124329-012-A134\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(93, 11, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B665NET-20260512124329-001-B698\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(94, 11, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B665NET-20260512124329-002-118B\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(95, 11, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B665NET-20260512124329-003-F71D\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(96, 11, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B665NET-20260512124329-004-0937\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(97, 11, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B665NET-20260512124329-005-C30D\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(98, 11, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B665NET-20260512124329-006-2F65\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(99, 9, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B218NET-20260512124329-001-29E7\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(100, 9, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B218NET-20260512124329-002-473B\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(101, 9, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B218NET-20260512124329-003-6EA2\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(102, 9, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B218NET-20260512124329-004-6265\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(103, 9, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B218NET-20260512124329-005-F518\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(104, 9, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B218NET-20260512124329-006-66A6\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(105, 9, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B218NET-20260512124329-007-0398\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(106, 9, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B218NET-20260512124329-008-B420\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(107, 9, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B218NET-20260512124329-009-F954\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(108, 9, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B218NET-20260512124329-010-13F8\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(109, 9, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B218NET-20260512124329-011-581D\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(110, 9, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B218NET-20260512124329-012-0486\r\nCompatibil: Semi-remorca\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(111, 6, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B235NET-20260512124329-001-D8D0\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(112, 6, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B235NET-20260512124329-002-0A54\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(113, 6, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B235NET-20260512124329-003-6883\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(114, 6, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B235NET-20260512124329-004-95B5\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(115, 6, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B235NET-20260512124329-005-7E24\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(116, 6, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B235NET-20260512124329-006-89D1\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(117, 6, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B235NET-20260512124329-007-C269\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(118, 6, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B235NET-20260512124329-008-F413\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(119, 6, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B235NET-20260512124329-009-3AB7\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15'),
(120, 6, 'Anvelopa - Active', '2026-05-12', 0.00, 'AUTO-FLEET AUTO-MOUNT', NULL, NULL, NULL, 'Serie anvelopa: AUTO-B235NET-20260512124329-010-982A\r\nCompatibil: Cap tractor\r\nObservatii anvelopa: Auto-completare anvelope pentru vehicule active (2026-05-12)', '2026-05-12 14:09:05', '2026-05-12 16:21:15');

-- --------------------------------------------------------

--
-- Table structure for table `notificari_log`
--

CREATE TABLE `notificari_log` (
  `id` int UNSIGNED NOT NULL,
  `document_id` int UNSIGNED NOT NULL,
  `vehicle_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED DEFAULT NULL,
  `driver_id` int UNSIGNED DEFAULT NULL,
  `canal` enum('email','sms','whatsapp') COLLATE utf8mb4_unicode_ci NOT NULL,
  `prag_zile` int NOT NULL,
  `document_data_expirare` date NOT NULL,
  `destinatar` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('trimis','esuat','sarit') COLLATE utf8mb4_unicode_ci NOT NULL,
  `furnizor` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subiect` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mesaj` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `raspuns_furnizor` longtext COLLATE utf8mb4_unicode_ci,
  `mesaj_eroare` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notificari_log`
--

INSERT INTO `notificari_log` (`id`, `document_id`, `vehicle_id`, `user_id`, `driver_id`, `canal`, `prag_zile`, `document_data_expirare`, `destinatar`, `status`, `furnizor`, `subiect`, `mesaj`, `raspuns_furnizor`, `mesaj_eroare`, `created_at`) VALUES
(1, 3, 2, 1, NULL, 'email', 5, '2026-04-11', 'admin@example.com', 'esuat', 'migadu', '[Fleet Management MVP] Expira in 5 zile: Rovinieta pentru B-202-FLT', 'Salut, Administrator Sistem!\n\nAvem o alerta pentru un document din flota.\n\nVehicul: B-202-FLT\nTip document: Rovinieta\nSerie / numar: ROV-7788\nData expirare: 11.04.2026\nStatus: Expira in 5 zile\n\nVezi documentul in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=3\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'Conexiunea SMTP a esuat:  (0)', '2026-04-06 12:57:03'),
(2, 3, 2, 2, NULL, 'email', 5, '2026-04-11', 'user@example.com', 'esuat', 'migadu', '[Fleet Management MVP] Expira in 5 zile: Rovinieta pentru B-202-FLT', 'Salut, Operator Flota!\n\nAvem o alerta pentru un document din flota.\n\nVehicul: B-202-FLT\nTip document: Rovinieta\nSerie / numar: ROV-7788\nData expirare: 11.04.2026\nStatus: Expira in 5 zile\n\nVezi documentul in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=3\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'Conexiunea SMTP a esuat:  (0)', '2026-04-06 12:57:18'),
(3, 3, 2, 1, NULL, 'sms', 5, '2026-04-11', '+40722000001', 'esuat', 'smsalert', NULL, 'Expira in 5 zile: Rovinieta pentru B-202-FLT expira la 11.04.2026 . Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid authorization headers\"}', 'SMSAlert a raspuns cu codul HTTP 401.', '2026-04-06 12:57:19'),
(4, 7, 1, 1, NULL, 'email', 9, '2026-04-15', 'admin@example.com', 'esuat', 'migadu', '[Fleet Management MVP] Expira in 9 zile: ROV pentru B-101-FLT', 'Salut, Administrator Sistem!\n\nAvem o alerta pentru un document din flota.\n\nVehicul: B-101-FLT\nTip document: ROV\nSerie / numar: -\nData expirare: 15.04.2026\nStatus: Expira in 9 zile\n\nVezi documentul in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=7\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'Conexiunea SMTP a esuat:  (0)', '2026-04-06 12:57:34'),
(5, 7, 1, 2, NULL, 'email', 9, '2026-04-15', 'user@example.com', 'esuat', 'migadu', '[Fleet Management MVP] Expira in 9 zile: ROV pentru B-101-FLT', 'Salut, Operator Flota!\n\nAvem o alerta pentru un document din flota.\n\nVehicul: B-101-FLT\nTip document: ROV\nSerie / numar: -\nData expirare: 15.04.2026\nStatus: Expira in 9 zile\n\nVezi documentul in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=7\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'Conexiunea SMTP a esuat:  (0)', '2026-04-06 12:57:49'),
(6, 7, 1, 1, NULL, 'sms', 9, '2026-04-15', '+40722000001', 'esuat', 'smsalert', NULL, 'Expira in 9 zile: ROV pentru B-101-FLT expira la 15.04.2026 . Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid authorization headers\"}', 'SMSAlert a raspuns cu codul HTTP 401.', '2026-04-06 12:57:50'),
(10, 1, 1, 1, NULL, 'email', 12, '2026-04-18', 'admin@example.com', 'esuat', 'migadu', '[Fleet Management MVP] Expira in 12 zile: RCA pentru B-101-FLT', 'Salut, Administrator Sistem!\n\nAvem o alerta pentru un document din flota.\n\nVehicul: B-101-FLT\nTip document: RCA\nSerie / numar: RCA-001-2026\nData expirare: 18.04.2026\nStatus: Expira in 12 zile\n\nVezi documentul in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=1\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'Conexiunea SMTP a esuat:  (0)', '2026-04-06 12:58:37'),
(11, 1, 1, 2, NULL, 'email', 12, '2026-04-18', 'user@example.com', 'esuat', 'migadu', '[Fleet Management MVP] Expira in 12 zile: RCA pentru B-101-FLT', 'Salut, Operator Flota!\n\nAvem o alerta pentru un document din flota.\n\nVehicul: B-101-FLT\nTip document: RCA\nSerie / numar: RCA-001-2026\nData expirare: 18.04.2026\nStatus: Expira in 12 zile\n\nVezi documentul in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=1\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'Conexiunea SMTP a esuat:  (0)', '2026-04-06 12:58:52'),
(12, 1, 1, 1, NULL, 'sms', 12, '2026-04-18', '+40722000001', 'esuat', 'smsalert', NULL, 'Expira in 12 zile: RCA pentru B-101-FLT expira la 18.04.2026 . Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid authorization headers\"}', 'SMSAlert a raspuns cu codul HTTP 401.', '2026-04-06 12:58:53'),
(13, 3, 2, 1, NULL, 'email', 5, '2026-04-11', 'admin@example.com', 'esuat', 'migadu', '[Fleet Management MVP] Expira in 5 zile: Rovinieta pentru B-202-FLT', 'Salut, Administrator Sistem!\n\nAvem o alerta pentru un document din flota.\n\nVehicul: B-202-FLT\nTip document: Rovinieta\nSerie / numar: ROV-7788\nData expirare: 11.04.2026\nStatus: Expira in 5 zile\n\nVezi documentul in aplicatie:\nhttp://127.0.0.1:8000/scripts/index.php?page=documente&action=show&id=3\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'Conexiunea SMTP a esuat: An attempt was made to access a socket in a way forbidden by its access permissions (10013)', '2026-04-06 14:47:07'),
(14, 3, 2, 2, NULL, 'email', 5, '2026-04-11', 'user@example.com', 'esuat', 'migadu', '[Fleet Management MVP] Expira in 5 zile: Rovinieta pentru B-202-FLT', 'Salut, Operator Flota!\n\nAvem o alerta pentru un document din flota.\n\nVehicul: B-202-FLT\nTip document: Rovinieta\nSerie / numar: ROV-7788\nData expirare: 11.04.2026\nStatus: Expira in 5 zile\n\nVezi documentul in aplicatie:\nhttp://127.0.0.1:8000/scripts/index.php?page=documente&action=show&id=3\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'Conexiunea SMTP a esuat: An attempt was made to access a socket in a way forbidden by its access permissions (10013)', '2026-04-06 14:47:07'),
(15, 3, 2, 1, NULL, 'sms', 5, '2026-04-11', '+40722000001', 'esuat', 'smsalert', NULL, 'Expira in 5 zile: Rovinieta pentru B-202-FLT expira la 11.04.2026 . Verifica aplicatia.', NULL, 'Failed to connect to smsalert.mobi port 443 after 27 ms: Could not connect to server', '2026-04-06 14:47:07'),
(16, 7, 1, 1, NULL, 'email', 9, '2026-04-15', 'admin@example.com', 'esuat', 'migadu', '[Fleet Management MVP] Expira in 9 zile: ROV pentru B-101-FLT', 'Salut, Administrator Sistem!\n\nAvem o alerta pentru un document din flota.\n\nVehicul: B-101-FLT\nTip document: ROV\nSerie / numar: -\nData expirare: 15.04.2026\nStatus: Expira in 9 zile\n\nVezi documentul in aplicatie:\nhttp://127.0.0.1:8000/scripts/index.php?page=documente&action=show&id=7\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'Conexiunea SMTP a esuat: An attempt was made to access a socket in a way forbidden by its access permissions (10013)', '2026-04-06 14:47:07'),
(17, 7, 1, 2, NULL, 'email', 9, '2026-04-15', 'user@example.com', 'esuat', 'migadu', '[Fleet Management MVP] Expira in 9 zile: ROV pentru B-101-FLT', 'Salut, Operator Flota!\n\nAvem o alerta pentru un document din flota.\n\nVehicul: B-101-FLT\nTip document: ROV\nSerie / numar: -\nData expirare: 15.04.2026\nStatus: Expira in 9 zile\n\nVezi documentul in aplicatie:\nhttp://127.0.0.1:8000/scripts/index.php?page=documente&action=show&id=7\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'Conexiunea SMTP a esuat: An attempt was made to access a socket in a way forbidden by its access permissions (10013)', '2026-04-06 14:47:07'),
(18, 7, 1, 1, NULL, 'sms', 9, '2026-04-15', '+40722000001', 'esuat', 'smsalert', NULL, 'Expira in 9 zile: ROV pentru B-101-FLT expira la 15.04.2026 . Verifica aplicatia.', NULL, 'Failed to connect to smsalert.mobi port 443 after 2 ms: Could not connect to server', '2026-04-06 14:47:07'),
(22, 1, 1, 1, NULL, 'email', 12, '2026-04-18', 'admin@example.com', 'esuat', 'migadu', '[Fleet Management MVP] Expira in 12 zile: RCA pentru B-101-FLT', 'Salut, Administrator Sistem!\n\nAvem o alerta pentru un document din flota.\n\nVehicul: B-101-FLT\nTip document: RCA\nSerie / numar: RCA-001-2026\nData expirare: 18.04.2026\nStatus: Expira in 12 zile\n\nVezi documentul in aplicatie:\nhttp://127.0.0.1:8000/scripts/index.php?page=documente&action=show&id=1\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'Conexiunea SMTP a esuat: An attempt was made to access a socket in a way forbidden by its access permissions (10013)', '2026-04-06 14:47:08'),
(23, 1, 1, 2, NULL, 'email', 12, '2026-04-18', 'user@example.com', 'esuat', 'migadu', '[Fleet Management MVP] Expira in 12 zile: RCA pentru B-101-FLT', 'Salut, Operator Flota!\n\nAvem o alerta pentru un document din flota.\n\nVehicul: B-101-FLT\nTip document: RCA\nSerie / numar: RCA-001-2026\nData expirare: 18.04.2026\nStatus: Expira in 12 zile\n\nVezi documentul in aplicatie:\nhttp://127.0.0.1:8000/scripts/index.php?page=documente&action=show&id=1\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'Conexiunea SMTP a esuat: An attempt was made to access a socket in a way forbidden by its access permissions (10013)', '2026-04-06 14:47:08'),
(24, 1, 1, 1, NULL, 'sms', 12, '2026-04-18', '+40722000001', 'esuat', 'smsalert', NULL, 'Expira in 12 zile: RCA pentru B-101-FLT expira la 18.04.2026 . Verifica aplicatia.', NULL, 'Failed to connect to smsalert.mobi port 443 after 4 ms: Could not connect to server', '2026-04-06 14:47:08'),
(25, 3, 2, NULL, NULL, 'email', 5, '2026-04-11', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] Expira in 5 zile: Rovinieta pentru B-202-FLT', 'Salut, Inbox notificari!\n\nAvem o alerta pentru un document din flota.\n\nVehicul: B-202-FLT\nTip document: Rovinieta\nSerie / numar: ROV-7788\nData expirare: 11.04.2026\nStatus: Expira in 5 zile\n\nVezi documentul in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=3\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'Conexiunea SMTP a esuat:  (0)', '2026-04-06 14:56:15'),
(26, 3, 2, 1, NULL, 'sms', 5, '2026-04-11', '+40722000001', 'esuat', 'smsalert', NULL, 'Expira in 5 zile: Rovinieta pentru B-202-FLT expira la 11.04.2026 . Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid authorization headers\"}', 'SMSAlert a raspuns cu codul HTTP 401.', '2026-04-06 14:56:15'),
(27, 7, 1, NULL, NULL, 'email', 9, '2026-04-15', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] Expira in 9 zile: ROV pentru B-101-FLT', 'Salut, Inbox notificari!\n\nAvem o alerta pentru un document din flota.\n\nVehicul: B-101-FLT\nTip document: ROV\nSerie / numar: -\nData expirare: 15.04.2026\nStatus: Expira in 9 zile\n\nVezi documentul in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=7\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'Conexiunea SMTP a esuat:  (0)', '2026-04-06 14:56:30'),
(28, 7, 1, 1, NULL, 'sms', 9, '2026-04-15', '+40722000001', 'esuat', 'smsalert', NULL, 'Expira in 9 zile: ROV pentru B-101-FLT expira la 15.04.2026 . Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid authorization headers\"}', 'SMSAlert a raspuns cu codul HTTP 401.', '2026-04-06 14:56:31'),
(31, 1, 1, NULL, NULL, 'email', 12, '2026-04-18', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] Expira in 12 zile: RCA pentru B-101-FLT', 'Salut, Inbox notificari!\n\nAvem o alerta pentru un document din flota.\n\nVehicul: B-101-FLT\nTip document: RCA\nSerie / numar: RCA-001-2026\nData expirare: 18.04.2026\nStatus: Expira in 12 zile\n\nVezi documentul in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=1\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'Conexiunea SMTP a esuat:  (0)', '2026-04-06 14:57:02'),
(32, 1, 1, 1, NULL, 'sms', 12, '2026-04-18', '+40722000001', 'esuat', 'smsalert', NULL, 'Expira in 12 zile: RCA pentru B-101-FLT expira la 18.04.2026 . Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid authorization headers\"}', 'SMSAlert a raspuns cu codul HTTP 401.', '2026-04-06 14:57:03'),
(33, 3, 2, NULL, NULL, 'email', 5, '2026-04-11', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] Expira in 5 zile: Rovinieta pentru B-202-FLT', 'Salut, Inbox notificari!\n\nAvem o alerta pentru un document din flota.\n\nVehicul: B-202-FLT\nTip document: Rovinieta\nSerie / numar: ROV-7788\nData expirare: 11.04.2026\nStatus: Expira in 5 zile\n\nVezi documentul in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=3\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'Conexiunea SMTP a esuat:  (0)', '2026-04-06 14:59:31'),
(34, 3, 2, 1, NULL, 'sms', 5, '2026-04-11', '+40774420199', 'esuat', 'smsalert', NULL, 'Expira in 5 zile: Rovinieta pentru B-202-FLT expira la 11.04.2026 . Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid authorization headers\"}', 'SMSAlert a raspuns cu codul HTTP 401.', '2026-04-06 14:59:31'),
(35, 7, 1, NULL, NULL, 'email', 9, '2026-04-15', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] Expira in 9 zile: ROV pentru B-101-FLT', 'Salut, Inbox notificari!\n\nAvem o alerta pentru un document din flota.\n\nVehicul: B-101-FLT\nTip document: ROV\nSerie / numar: -\nData expirare: 15.04.2026\nStatus: Expira in 9 zile\n\nVezi documentul in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=7\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'Conexiunea SMTP a esuat:  (0)', '2026-04-06 14:59:46'),
(36, 7, 1, 1, NULL, 'sms', 9, '2026-04-15', '+40774420199', 'esuat', 'smsalert', NULL, 'Expira in 9 zile: ROV pentru B-101-FLT expira la 15.04.2026 . Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid authorization headers\"}', 'SMSAlert a raspuns cu codul HTTP 401.', '2026-04-06 14:59:47'),
(39, 1, 1, NULL, NULL, 'email', 12, '2026-04-18', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] Expira in 12 zile: RCA pentru B-101-FLT', 'Salut, Inbox notificari!\n\nAvem o alerta pentru un document din flota.\n\nVehicul: B-101-FLT\nTip document: RCA\nSerie / numar: RCA-001-2026\nData expirare: 18.04.2026\nStatus: Expira in 12 zile\n\nVezi documentul in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=1\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'Conexiunea SMTP a esuat:  (0)', '2026-04-06 15:00:18'),
(40, 1, 1, 1, NULL, 'sms', 12, '2026-04-18', '+40774420199', 'esuat', 'smsalert', NULL, 'Expira in 12 zile: RCA pentru B-101-FLT expira la 18.04.2026 . Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid authorization headers\"}', 'SMSAlert a raspuns cu codul HTTP 401.', '2026-04-06 15:00:19'),
(41, 3, 2, NULL, NULL, 'email', 5, '2026-04-11', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] Expira in 5 zile: Rovinieta pentru B-202-FLT', 'Salut, Inbox notificari!\n\nAvem o alerta pentru un document din flota.\n\nVehicul: B-202-FLT\nTip document: Rovinieta\nSerie / numar: ROV-7788\nData expirare: 11.04.2026\nStatus: Expira in 5 zile\n\nVezi documentul in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=3\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'Conexiunea SMTP a esuat: stream_socket_client(): Unable to connect to ssl://smtp.migadu.com:465 (Unknown error) (0)', '2026-04-06 15:08:51'),
(42, 3, 2, 1, NULL, 'sms', 5, '2026-04-11', '+40774420199', 'esuat', 'smsalert', NULL, 'Expira in 5 zile: Rovinieta pentru B-202-FLT expira la 11.04.2026 . Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid authorization headers\"}', 'SMSAlert a respins autentificarea: Invalid authorization headers. Verifica API key-ul configurat.', '2026-04-06 15:08:52'),
(43, 7, 1, NULL, NULL, 'email', 9, '2026-04-15', 'alarma@lpg-auto.ro', 'trimis', 'migadu', '[Fleet Management MVP] Expira in 9 zile: ROV pentru B-101-FLT', 'Salut, Inbox notificari!\n\nAvem o alerta pentru un document din flota.\n\nVehicul: B-101-FLT\nTip document: ROV\nSerie / numar: -\nData expirare: 15.04.2026\nStatus: Expira in 9 zile\n\nVezi documentul in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=7\n\nMesaj generat automat de Fleet Management MVP.', '220 mta0.migadu.com ESMTP\n250-mta0.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-AUTH PLAIN LOGIN\r\n250-AUTH=PLAIN LOGIN\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n334 VXNlcm5hbWU6\n334 UGFzc3dvcmQ6\n235 2.7.0 Authentication successful\n250 2.1.0 Ok\n250 2.1.5 Ok\n354 End data with <CR><LF>.<CR><LF>\n250 2.0.0 Ok: queued as 02794101375', NULL, '2026-04-06 15:08:54'),
(44, 7, 1, 1, NULL, 'sms', 9, '2026-04-15', '+40774420199', 'esuat', 'smsalert', NULL, 'Expira in 9 zile: ROV pentru B-101-FLT expira la 15.04.2026 . Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid authorization headers\"}', 'SMSAlert a respins autentificarea: Invalid authorization headers. Verifica API key-ul configurat.', '2026-04-06 15:08:55'),
(47, 1, 1, NULL, NULL, 'email', 12, '2026-04-18', 'alarma@lpg-auto.ro', 'trimis', 'migadu', '[Fleet Management MVP] Expira in 12 zile: RCA pentru B-101-FLT', 'Salut, Inbox notificari!\n\nAvem o alerta pentru un document din flota.\n\nVehicul: B-101-FLT\nTip document: RCA\nSerie / numar: RCA-001-2026\nData expirare: 18.04.2026\nStatus: Expira in 12 zile\n\nVezi documentul in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=1\n\nMesaj generat automat de Fleet Management MVP.', '220 mta1.migadu.com ESMTP\n250-mta1.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-AUTH PLAIN LOGIN\r\n250-AUTH=PLAIN LOGIN\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n334 VXNlcm5hbWU6\n334 UGFzc3dvcmQ6\n235 2.7.0 Authentication successful\n250 2.1.0 Ok\n250 2.1.5 Ok\n354 End data with <CR><LF>.<CR><LF>\n250 2.0.0 Ok: queued as 8B3D010D8CE', NULL, '2026-04-06 15:09:15'),
(48, 1, 1, 1, NULL, 'sms', 12, '2026-04-18', '+40774420199', 'esuat', 'smsalert', NULL, 'Expira in 12 zile: RCA pentru B-101-FLT expira la 18.04.2026 . Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid authorization headers\"}', 'SMSAlert a respins autentificarea: Invalid authorization headers. Verifica API key-ul configurat.', '2026-04-06 15:09:16'),
(49, 3, 2, NULL, NULL, 'email', 5, '2026-04-11', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] Expira in 5 zile: Rovinieta pentru B-202-FLT', 'Salut, Inbox notificari!\n\nAvem o alerta pentru un document din flota.\n\nVehicul: B-202-FLT\nTip document: Rovinieta\nSerie / numar: ROV-7788\nData expirare: 11.04.2026\nStatus: Expira in 5 zile\n\nVezi documentul in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=3\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-06 15:22:39'),
(50, 3, 2, 1, NULL, 'sms', 5, '2026-04-11', '+40774420199', 'esuat', 'smsalert', NULL, 'Expira in 5 zile: Rovinieta pentru B-202-FLT expira la 11.04.2026 . Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid authorization headers\"}', 'SMSAlert a respins autentificarea: Invalid authorization headers. Verifica API key-ul configurat.', '2026-04-06 15:22:40'),
(51, 7, 1, 1, NULL, 'sms', 9, '2026-04-15', '+40774420199', 'esuat', 'smsalert', NULL, 'Expira in 9 zile: ROV pentru B-101-FLT expira la 15.04.2026 . Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid authorization headers\"}', 'SMSAlert a respins autentificarea: Invalid authorization headers. Verifica API key-ul configurat.', '2026-04-06 15:22:41'),
(54, 1, 1, 1, NULL, 'sms', 12, '2026-04-18', '+40774420199', 'esuat', 'smsalert', NULL, 'Expira in 12 zile: RCA pentru B-101-FLT expira la 18.04.2026 . Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid authorization headers\"}', 'SMSAlert a respins autentificarea: Invalid authorization headers. Verifica API key-ul configurat.', '2026-04-06 15:22:57'),
(55, 3, 2, NULL, NULL, 'email', 5, '2026-04-11', 'alarma@lpg-auto.ro', 'trimis', 'migadu', '[Fleet Management MVP] Expira in 5 zile: Rovinieta pentru B-202-FLT', 'Salut, Inbox notificari!\n\nAvem o alerta pentru un document din flota.\n\nVehicul: B-202-FLT\nTip document: Rovinieta\nSerie / numar: ROV-7788\nData expirare: 11.04.2026\nStatus: Expira in 5 zile\n\nVezi documentul in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=3\n\nMesaj generat automat de Fleet Management MVP.', '220 mta0.migadu.com ESMTP\n250-mta0.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-AUTH PLAIN LOGIN\r\n250-AUTH=PLAIN LOGIN\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n334 VXNlcm5hbWU6\n334 UGFzc3dvcmQ6\n235 2.7.0 Authentication successful\n250 2.1.0 Ok\n250 2.1.5 Ok\n354 End data with <CR><LF>.<CR><LF>\n250 2.0.0 Ok: queued as 7F9CBE1944', NULL, '2026-04-06 15:34:26'),
(58, 3, 2, NULL, NULL, 'email', 5, '2026-04-11', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] Expira in 5 zile: Rovinieta pentru B-202-FLT', 'Salut, Inbox notificari!\n\nAvem o alerta pentru un document din flota.\n\nVehicul: B-202-FLT\nTip document: Rovinieta\nSerie / numar: ROV-7788\nData expirare: 11.04.2026\nStatus: Expira in 5 zile\n\nVezi documentul in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=3\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/3 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/3 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 3/3 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-06 16:34:34'),
(59, 3, 2, 1, NULL, 'sms', 5, '2026-04-11', '+40774420199', 'esuat', 'smsalert', NULL, 'Expira in 5 zile: Rovinieta pentru B-202-FLT expira la 11.04.2026 . Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid user or apiKey\"}', 'SMSAlert a respins autentificarea: Invalid user or apiKey. Verifica API key-ul configurat.', '2026-04-06 16:34:35'),
(60, 7, 1, NULL, NULL, 'email', 9, '2026-04-15', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] Expira in 9 zile: ROV pentru B-101-FLT', 'Salut, Inbox notificari!\n\nAvem o alerta pentru un document din flota.\n\nVehicul: B-101-FLT\nTip document: ROV\nSerie / numar: -\nData expirare: 15.04.2026\nStatus: Expira in 9 zile\n\nVezi documentul in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=7\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/3 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/3 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 3/3 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-06 16:35:21'),
(61, 7, 1, 1, NULL, 'sms', 9, '2026-04-15', '+40774420199', 'esuat', 'smsalert', NULL, 'Expira in 9 zile: ROV pentru B-101-FLT expira la 15.04.2026 . Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid user or apiKey\"}', 'SMSAlert a respins autentificarea: Invalid user or apiKey. Verifica API key-ul configurat.', '2026-04-06 16:35:22'),
(64, 3, 2, NULL, NULL, 'email', 5, '2026-04-11', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] Expira in 5 zile: Rovinieta pentru B-202-FLT', 'Salut, Inbox notificari!\n\nAvem o alerta pentru un document din flota.\n\nVehicul: B-202-FLT\nTip document: Rovinieta\nSerie / numar: ROV-7788\nData expirare: 11.04.2026\nStatus: Expira in 5 zile\n\nVezi documentul in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=3\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/3 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/3 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 3/3 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-06 16:38:33'),
(65, 3, 2, 1, NULL, 'sms', 5, '2026-04-11', '+40774420199', 'esuat', 'smsalert', NULL, 'Expira in 5 zile: Rovinieta pentru B-202-FLT expira la 11.04.2026 . Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid user or apiKey\"}', 'SMSAlert a respins autentificarea: Invalid user or apiKey. Verifica API key-ul configurat.', '2026-04-06 16:38:34'),
(66, 7, 1, NULL, NULL, 'email', 9, '2026-04-15', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] Expira in 9 zile: ROV pentru B-101-FLT', 'Salut, Inbox notificari!\n\nAvem o alerta pentru un document din flota.\n\nVehicul: B-101-FLT\nTip document: ROV\nSerie / numar: -\nData expirare: 15.04.2026\nStatus: Expira in 9 zile\n\nVezi documentul in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=7\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/3 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/3 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 3/3 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-06 16:39:20'),
(67, 7, 1, 1, NULL, 'sms', 9, '2026-04-15', '+40774420199', 'esuat', 'smsalert', NULL, 'Expira in 9 zile: ROV pentru B-101-FLT expira la 15.04.2026 . Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid user or apiKey\"}', 'SMSAlert a respins autentificarea: Invalid user or apiKey. Verifica API key-ul configurat.', '2026-04-06 16:39:21'),
(70, 1, 1, NULL, NULL, 'email', 12, '2026-04-18', 'alarma@lpg-auto.ro', 'trimis', 'migadu', '[Fleet Management MVP] Expira in 12 zile: RCA pentru B-101-FLT', 'Salut, Inbox notificari!\n\nAvem o alerta pentru un document din flota.\n\nVehicul: B-101-FLT\nTip document: RCA\nSerie / numar: RCA-001-2026\nData expirare: 18.04.2026\nStatus: Expira in 12 zile\n\nVezi documentul in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente&action=show&id=1\n\nMesaj generat automat de Fleet Management MVP.', '220 mta1.migadu.com ESMTP\n250-mta1.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-STARTTLS\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n220 2.0.0 Ready to start TLS\n250-mta1.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-AUTH PLAIN LOGIN\r\n250-AUTH=PLAIN LOGIN\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n334 VXNlcm5hbWU6\n334 UGFzc3dvcmQ6\n235 2.7.0 Authentication successful\n250 2.1.0 Ok\n250 2.1.5 Ok\n354 End data with <CR><LF>.<CR><LF>\n250 2.0.0 Ok: queued as 9AEB611C2D7', NULL, '2026-04-06 16:39:43'),
(71, 1, 1, 1, NULL, 'sms', 12, '2026-04-18', '+40774420199', 'esuat', 'smsalert', NULL, 'Expira in 12 zile: RCA pentru B-101-FLT expira la 18.04.2026 . Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid user or apiKey\"}', 'SMSAlert a respins autentificarea: Invalid user or apiKey. Verifica API key-ul configurat.', '2026-04-06 16:39:44'),
(72, 3, 2, NULL, NULL, 'email', 5, '2026-04-11', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 4 documente eligibile pentru notificare', 'Salut, Inbox notificari!\n\nAvem 4 documente eligibile pentru notificare in aceasta rulare.\n\n- B-202-FLT | Rovinieta | Expira in 5 zile | expira la 11.04.2026\n- B-101-FLT | ROV | Expira in 9 zile | expira la 15.04.2026\n- B-101-FLT | ROV | Expira in 10 zile | expira la 16.04.2026\n- B-101-FLT | RCA | Expira in 12 zile | expira la 18.04.2026\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-06 16:45:41'),
(73, 7, 1, NULL, NULL, 'email', 9, '2026-04-15', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 4 documente eligibile pentru notificare', 'Salut, Inbox notificari!\n\nAvem 4 documente eligibile pentru notificare in aceasta rulare.\n\n- B-202-FLT | Rovinieta | Expira in 5 zile | expira la 11.04.2026\n- B-101-FLT | ROV | Expira in 9 zile | expira la 15.04.2026\n- B-101-FLT | ROV | Expira in 10 zile | expira la 16.04.2026\n- B-101-FLT | RCA | Expira in 12 zile | expira la 18.04.2026\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-06 16:45:41'),
(75, 1, 1, NULL, NULL, 'email', 12, '2026-04-18', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 4 documente eligibile pentru notificare', 'Salut, Inbox notificari!\n\nAvem 4 documente eligibile pentru notificare in aceasta rulare.\n\n- B-202-FLT | Rovinieta | Expira in 5 zile | expira la 11.04.2026\n- B-101-FLT | ROV | Expira in 9 zile | expira la 15.04.2026\n- B-101-FLT | ROV | Expira in 10 zile | expira la 16.04.2026\n- B-101-FLT | RCA | Expira in 12 zile | expira la 18.04.2026\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-06 16:45:41'),
(76, 3, 2, 1, NULL, 'sms', 5, '2026-04-11', '+40774420199', 'esuat', 'smsalert', NULL, 'Alerta flota: 4 documente eligibile. B-202-FLT Rovinieta (5z), B-101-FLT ROV (9z). +2 alte documente. Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid user or apiKey\"}', 'SMSAlert a respins autentificarea: Invalid user or apiKey. Verifica API key-ul configurat.', '2026-04-06 16:45:42'),
(77, 7, 1, 1, NULL, 'sms', 9, '2026-04-15', '+40774420199', 'esuat', 'smsalert', NULL, 'Alerta flota: 4 documente eligibile. B-202-FLT Rovinieta (5z), B-101-FLT ROV (9z). +2 alte documente. Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid user or apiKey\"}', 'SMSAlert a respins autentificarea: Invalid user or apiKey. Verifica API key-ul configurat.', '2026-04-06 16:45:42'),
(79, 1, 1, 1, NULL, 'sms', 12, '2026-04-18', '+40774420199', 'esuat', 'smsalert', NULL, 'Alerta flota: 4 documente eligibile. B-202-FLT Rovinieta (5z), B-101-FLT ROV (9z). +2 alte documente. Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid user or apiKey\"}', 'SMSAlert a respins autentificarea: Invalid user or apiKey. Verifica API key-ul configurat.', '2026-04-06 16:45:42'),
(80, 3, 2, NULL, NULL, 'email', 5, '2026-04-11', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 4 documente eligibile pentru notificare', 'Salut, Inbox notificari!\n\nAvem 4 documente eligibile pentru notificare in aceasta rulare.\n\n- B-202-FLT | Rovinieta | Expira in 5 zile | expira la 11.04.2026\n- B-101-FLT | ROV | Expira in 9 zile | expira la 15.04.2026\n- B-101-FLT | ROV | Expira in 10 zile | expira la 16.04.2026\n- B-101-FLT | RCA | Expira in 12 zile | expira la 18.04.2026\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-06 16:51:09'),
(81, 7, 1, NULL, NULL, 'email', 9, '2026-04-15', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 4 documente eligibile pentru notificare', 'Salut, Inbox notificari!\n\nAvem 4 documente eligibile pentru notificare in aceasta rulare.\n\n- B-202-FLT | Rovinieta | Expira in 5 zile | expira la 11.04.2026\n- B-101-FLT | ROV | Expira in 9 zile | expira la 15.04.2026\n- B-101-FLT | ROV | Expira in 10 zile | expira la 16.04.2026\n- B-101-FLT | RCA | Expira in 12 zile | expira la 18.04.2026\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-06 16:51:09'),
(83, 1, 1, NULL, NULL, 'email', 12, '2026-04-18', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 4 documente eligibile pentru notificare', 'Salut, Inbox notificari!\n\nAvem 4 documente eligibile pentru notificare in aceasta rulare.\n\n- B-202-FLT | Rovinieta | Expira in 5 zile | expira la 11.04.2026\n- B-101-FLT | ROV | Expira in 9 zile | expira la 15.04.2026\n- B-101-FLT | ROV | Expira in 10 zile | expira la 16.04.2026\n- B-101-FLT | RCA | Expira in 12 zile | expira la 18.04.2026\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-06 16:51:09'),
(84, 3, 2, 1, NULL, 'sms', 5, '2026-04-11', '+40774420199', 'esuat', 'smsalert', NULL, 'Alerta flota: 4 documente eligibile. B-202-FLT Rovinieta (5z), B-101-FLT ROV (9z). +2 alte documente. Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid authorization headers\"}', 'SMSAlert a respins autentificarea (bearer password fallback): Invalid authorization headers.', '2026-04-06 16:51:11'),
(85, 7, 1, 1, NULL, 'sms', 9, '2026-04-15', '+40774420199', 'esuat', 'smsalert', NULL, 'Alerta flota: 4 documente eligibile. B-202-FLT Rovinieta (5z), B-101-FLT ROV (9z). +2 alte documente. Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid authorization headers\"}', 'SMSAlert a respins autentificarea (bearer password fallback): Invalid authorization headers.', '2026-04-06 16:51:11'),
(87, 1, 1, 1, NULL, 'sms', 12, '2026-04-18', '+40774420199', 'esuat', 'smsalert', NULL, 'Alerta flota: 4 documente eligibile. B-202-FLT Rovinieta (5z), B-101-FLT ROV (9z). +2 alte documente. Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid authorization headers\"}', 'SMSAlert a respins autentificarea (bearer password fallback): Invalid authorization headers.', '2026-04-06 16:51:11'),
(88, 3, 2, NULL, NULL, 'email', 5, '2026-04-11', 'alarma@lpg-auto.ro', 'trimis', 'migadu', '[Fleet Management MVP] 4 documente eligibile pentru notificare', 'Salut, Inbox notificari!\n\nAvem 4 documente eligibile pentru notificare in aceasta rulare.\n\n- B-202-FLT | Rovinieta | Expira in 5 zile | expira la 11.04.2026\n- B-101-FLT | ROV | Expira in 9 zile | expira la 15.04.2026\n- B-101-FLT | ROV | Expira in 10 zile | expira la 16.04.2026\n- B-101-FLT | RCA | Expira in 12 zile | expira la 18.04.2026\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nMesaj generat automat de Fleet Management MVP.', '220 mta1.migadu.com ESMTP\n250-mta1.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-AUTH PLAIN LOGIN\r\n250-AUTH=PLAIN LOGIN\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n334 VXNlcm5hbWU6\n334 UGFzc3dvcmQ6\n235 2.7.0 Authentication successful\n250 2.1.0 Ok\n250 2.1.5 Ok\n354 End data with <CR><LF>.<CR><LF>\n250 2.0.0 Ok: queued as 0124711CAD7', NULL, '2026-04-06 16:59:47'),
(89, 7, 1, NULL, NULL, 'email', 9, '2026-04-15', 'alarma@lpg-auto.ro', 'trimis', 'migadu', '[Fleet Management MVP] 4 documente eligibile pentru notificare', 'Salut, Inbox notificari!\n\nAvem 4 documente eligibile pentru notificare in aceasta rulare.\n\n- B-202-FLT | Rovinieta | Expira in 5 zile | expira la 11.04.2026\n- B-101-FLT | ROV | Expira in 9 zile | expira la 15.04.2026\n- B-101-FLT | ROV | Expira in 10 zile | expira la 16.04.2026\n- B-101-FLT | RCA | Expira in 12 zile | expira la 18.04.2026\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nMesaj generat automat de Fleet Management MVP.', '220 mta1.migadu.com ESMTP\n250-mta1.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-AUTH PLAIN LOGIN\r\n250-AUTH=PLAIN LOGIN\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n334 VXNlcm5hbWU6\n334 UGFzc3dvcmQ6\n235 2.7.0 Authentication successful\n250 2.1.0 Ok\n250 2.1.5 Ok\n354 End data with <CR><LF>.<CR><LF>\n250 2.0.0 Ok: queued as 0124711CAD7', NULL, '2026-04-06 16:59:47'),
(91, 1, 1, NULL, NULL, 'email', 12, '2026-04-18', 'alarma@lpg-auto.ro', 'trimis', 'migadu', '[Fleet Management MVP] 4 documente eligibile pentru notificare', 'Salut, Inbox notificari!\n\nAvem 4 documente eligibile pentru notificare in aceasta rulare.\n\n- B-202-FLT | Rovinieta | Expira in 5 zile | expira la 11.04.2026\n- B-101-FLT | ROV | Expira in 9 zile | expira la 15.04.2026\n- B-101-FLT | ROV | Expira in 10 zile | expira la 16.04.2026\n- B-101-FLT | RCA | Expira in 12 zile | expira la 18.04.2026\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nMesaj generat automat de Fleet Management MVP.', '220 mta1.migadu.com ESMTP\n250-mta1.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-AUTH PLAIN LOGIN\r\n250-AUTH=PLAIN LOGIN\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n334 VXNlcm5hbWU6\n334 UGFzc3dvcmQ6\n235 2.7.0 Authentication successful\n250 2.1.0 Ok\n250 2.1.5 Ok\n354 End data with <CR><LF>.<CR><LF>\n250 2.0.0 Ok: queued as 0124711CAD7', NULL, '2026-04-06 16:59:47'),
(92, 3, 2, NULL, NULL, 'email', 5, '2026-04-11', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 4 documente eligibile pentru notificare', 'Salut, Inbox notificari!\n\nAvem 4 documente eligibile pentru notificare in aceasta rulare.\n\n- B-202-FLT | Rovinieta | Expira in 5 zile | expira la 11.04.2026\n- B-101-FLT | ROV | Expira in 9 zile | expira la 15.04.2026\n- B-101-FLT | ROV | Expira in 10 zile | expira la 16.04.2026\n- B-101-FLT | RCA | Expira in 12 zile | expira la 18.04.2026\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-06 17:00:59'),
(93, 7, 1, NULL, NULL, 'email', 9, '2026-04-15', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 4 documente eligibile pentru notificare', 'Salut, Inbox notificari!\n\nAvem 4 documente eligibile pentru notificare in aceasta rulare.\n\n- B-202-FLT | Rovinieta | Expira in 5 zile | expira la 11.04.2026\n- B-101-FLT | ROV | Expira in 9 zile | expira la 15.04.2026\n- B-101-FLT | ROV | Expira in 10 zile | expira la 16.04.2026\n- B-101-FLT | RCA | Expira in 12 zile | expira la 18.04.2026\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-06 17:00:59'),
(95, 1, 1, NULL, NULL, 'email', 12, '2026-04-18', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 4 documente eligibile pentru notificare', 'Salut, Inbox notificari!\n\nAvem 4 documente eligibile pentru notificare in aceasta rulare.\n\n- B-202-FLT | Rovinieta | Expira in 5 zile | expira la 11.04.2026\n- B-101-FLT | ROV | Expira in 9 zile | expira la 15.04.2026\n- B-101-FLT | ROV | Expira in 10 zile | expira la 16.04.2026\n- B-101-FLT | RCA | Expira in 12 zile | expira la 18.04.2026\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-06 17:00:59'),
(96, 3, 2, NULL, NULL, 'email', 5, '2026-04-11', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 4 documente eligibile pentru notificare', 'Salut, Inbox notificari!\n\nAvem 4 documente eligibile pentru notificare in aceasta rulare.\n\n- B-202-FLT | Rovinieta | Expira in 5 zile | expira la 11.04.2026\n- B-101-FLT | ROV | Expira in 9 zile | expira la 15.04.2026\n- B-101-FLT | ROV | Expira in 10 zile | expira la 16.04.2026\n- B-101-FLT | RCA | Expira in 12 zile | expira la 18.04.2026\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-06 17:09:31'),
(97, 7, 1, NULL, NULL, 'email', 9, '2026-04-15', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 4 documente eligibile pentru notificare', 'Salut, Inbox notificari!\n\nAvem 4 documente eligibile pentru notificare in aceasta rulare.\n\n- B-202-FLT | Rovinieta | Expira in 5 zile | expira la 11.04.2026\n- B-101-FLT | ROV | Expira in 9 zile | expira la 15.04.2026\n- B-101-FLT | ROV | Expira in 10 zile | expira la 16.04.2026\n- B-101-FLT | RCA | Expira in 12 zile | expira la 18.04.2026\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-06 17:09:31'),
(99, 1, 1, NULL, NULL, 'email', 12, '2026-04-18', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 4 documente eligibile pentru notificare', 'Salut, Inbox notificari!\n\nAvem 4 documente eligibile pentru notificare in aceasta rulare.\n\n- B-202-FLT | Rovinieta | Expira in 5 zile | expira la 11.04.2026\n- B-101-FLT | ROV | Expira in 9 zile | expira la 15.04.2026\n- B-101-FLT | ROV | Expira in 10 zile | expira la 16.04.2026\n- B-101-FLT | RCA | Expira in 12 zile | expira la 18.04.2026\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-06 17:09:31'),
(100, 3, 2, 1, NULL, 'sms', 5, '2026-04-11', '+40774420199', 'esuat', 'smsalert', NULL, 'Alerta flota: 4 documente eligibile. B-202-FLT Rovinieta (5z), B-101-FLT ROV (9z). +2 alte documente. Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid authorization headers\"}', 'SMSAlert a respins autentificarea: Invalid authorization headers. Pentru API v2 verifica API key-ul din platforma SMSAlert.', '2026-04-06 17:09:32'),
(101, 7, 1, 1, NULL, 'sms', 9, '2026-04-15', '+40774420199', 'esuat', 'smsalert', NULL, 'Alerta flota: 4 documente eligibile. B-202-FLT Rovinieta (5z), B-101-FLT ROV (9z). +2 alte documente. Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid authorization headers\"}', 'SMSAlert a respins autentificarea: Invalid authorization headers. Pentru API v2 verifica API key-ul din platforma SMSAlert.', '2026-04-06 17:09:32'),
(103, 1, 1, 1, NULL, 'sms', 12, '2026-04-18', '+40774420199', 'esuat', 'smsalert', NULL, 'Alerta flota: 4 documente eligibile. B-202-FLT Rovinieta (5z), B-101-FLT ROV (9z). +2 alte documente. Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid authorization headers\"}', 'SMSAlert a respins autentificarea: Invalid authorization headers. Pentru API v2 verifica API key-ul din platforma SMSAlert.', '2026-04-06 17:09:32'),
(104, 3, 2, NULL, NULL, 'email', 5, '2026-04-11', 'alarma@lpg-auto.ro', 'trimis', 'migadu', '[Fleet Management MVP] 4 documente eligibile pentru notificare', 'Salut, Inbox notificari!\n\nAvem 4 documente eligibile pentru notificare in aceasta rulare.\n\n- B-202-FLT | Rovinieta | Expira in 5 zile | expira la 11.04.2026\n- B-101-FLT | ROV | Expira in 9 zile | expira la 15.04.2026\n- B-101-FLT | ROV | Expira in 10 zile | expira la 16.04.2026\n- B-101-FLT | RCA | Expira in 12 zile | expira la 18.04.2026\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nMesaj generat automat de Fleet Management MVP.', '220 mta1.migadu.com ESMTP\n250-mta1.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-AUTH PLAIN LOGIN\r\n250-AUTH=PLAIN LOGIN\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n334 VXNlcm5hbWU6\n334 UGFzc3dvcmQ6\n235 2.7.0 Authentication successful\n250 2.1.0 Ok\n250 2.1.5 Ok\n354 End data with <CR><LF>.<CR><LF>\n250 2.0.0 Ok: queued as 9F74C11FA24', NULL, '2026-04-06 17:13:58'),
(105, 7, 1, NULL, NULL, 'email', 9, '2026-04-15', 'alarma@lpg-auto.ro', 'trimis', 'migadu', '[Fleet Management MVP] 4 documente eligibile pentru notificare', 'Salut, Inbox notificari!\n\nAvem 4 documente eligibile pentru notificare in aceasta rulare.\n\n- B-202-FLT | Rovinieta | Expira in 5 zile | expira la 11.04.2026\n- B-101-FLT | ROV | Expira in 9 zile | expira la 15.04.2026\n- B-101-FLT | ROV | Expira in 10 zile | expira la 16.04.2026\n- B-101-FLT | RCA | Expira in 12 zile | expira la 18.04.2026\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nMesaj generat automat de Fleet Management MVP.', '220 mta1.migadu.com ESMTP\n250-mta1.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-AUTH PLAIN LOGIN\r\n250-AUTH=PLAIN LOGIN\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n334 VXNlcm5hbWU6\n334 UGFzc3dvcmQ6\n235 2.7.0 Authentication successful\n250 2.1.0 Ok\n250 2.1.5 Ok\n354 End data with <CR><LF>.<CR><LF>\n250 2.0.0 Ok: queued as 9F74C11FA24', NULL, '2026-04-06 17:13:58'),
(107, 1, 1, NULL, NULL, 'email', 12, '2026-04-18', 'alarma@lpg-auto.ro', 'trimis', 'migadu', '[Fleet Management MVP] 4 documente eligibile pentru notificare', 'Salut, Inbox notificari!\n\nAvem 4 documente eligibile pentru notificare in aceasta rulare.\n\n- B-202-FLT | Rovinieta | Expira in 5 zile | expira la 11.04.2026\n- B-101-FLT | ROV | Expira in 9 zile | expira la 15.04.2026\n- B-101-FLT | ROV | Expira in 10 zile | expira la 16.04.2026\n- B-101-FLT | RCA | Expira in 12 zile | expira la 18.04.2026\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nMesaj generat automat de Fleet Management MVP.', '220 mta1.migadu.com ESMTP\n250-mta1.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-AUTH PLAIN LOGIN\r\n250-AUTH=PLAIN LOGIN\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n334 VXNlcm5hbWU6\n334 UGFzc3dvcmQ6\n235 2.7.0 Authentication successful\n250 2.1.0 Ok\n250 2.1.5 Ok\n354 End data with <CR><LF>.<CR><LF>\n250 2.0.0 Ok: queued as 9F74C11FA24', NULL, '2026-04-06 17:13:58'),
(108, 3, 2, 1, NULL, 'sms', 5, '2026-04-11', '+40774420199', 'esuat', 'smsalert', NULL, 'Alerta flota: 4 documente eligibile. B-202-FLT Rovinieta (5z), B-101-FLT ROV (9z). +2 alte documente. Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid authorization headers\"}', 'SMSAlert a respins autentificarea: Invalid authorization headers. Pentru API v2 verifica API key-ul din platforma SMSAlert.', '2026-04-06 17:13:59'),
(109, 7, 1, 1, NULL, 'sms', 9, '2026-04-15', '+40774420199', 'esuat', 'smsalert', NULL, 'Alerta flota: 4 documente eligibile. B-202-FLT Rovinieta (5z), B-101-FLT ROV (9z). +2 alte documente. Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid authorization headers\"}', 'SMSAlert a respins autentificarea: Invalid authorization headers. Pentru API v2 verifica API key-ul din platforma SMSAlert.', '2026-04-06 17:13:59'),
(111, 1, 1, 1, NULL, 'sms', 12, '2026-04-18', '+40774420199', 'esuat', 'smsalert', NULL, 'Alerta flota: 4 documente eligibile. B-202-FLT Rovinieta (5z), B-101-FLT ROV (9z). +2 alte documente. Verifica aplicatia.', '{\"status\":false,\"message\":\"Invalid authorization headers\"}', 'SMSAlert a respins autentificarea: Invalid authorization headers. Pentru API v2 verifica API key-ul din platforma SMSAlert.', '2026-04-06 17:13:59'),
(116, 3, 2, NULL, NULL, 'email', 4, '2026-04-11', 'alarma@lpg-auto.ro', 'trimis', 'migadu', '[Fleet Management MVP] 3 documente in ciclu activ de notificare', 'Salut, Inbox notificari!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: 4 | praguri: 12, 10, 9, 5, 1, 0\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: 8 | praguri: 12, 10, 9, 5, 1, 0\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 11 | praguri: 12, 10, 9, 5, 1, 0\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', '220 mta1.migadu.com ESMTP\n250-mta1.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-STARTTLS\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n220 2.0.0 Ready to start TLS\n250-mta1.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-AUTH PLAIN LOGIN\r\n250-AUTH=PLAIN LOGIN\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n334 VXNlcm5hbWU6\n334 UGFzc3dvcmQ6\n235 2.7.0 Authentication successful\n250 2.1.0 Ok\n250 2.1.5 Ok\n354 End data with <CR><LF>.<CR><LF>\n250 2.0.0 Ok: queued as 8299111B84B', NULL, '2026-04-07 14:19:43');
INSERT INTO `notificari_log` (`id`, `document_id`, `vehicle_id`, `user_id`, `driver_id`, `canal`, `prag_zile`, `document_data_expirare`, `destinatar`, `status`, `furnizor`, `subiect`, `mesaj`, `raspuns_furnizor`, `mesaj_eroare`, `created_at`) VALUES
(117, 7, 1, NULL, NULL, 'email', 8, '2026-04-15', 'alarma@lpg-auto.ro', 'trimis', 'migadu', '[Fleet Management MVP] 3 documente in ciclu activ de notificare', 'Salut, Inbox notificari!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: 4 | praguri: 12, 10, 9, 5, 1, 0\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: 8 | praguri: 12, 10, 9, 5, 1, 0\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 11 | praguri: 12, 10, 9, 5, 1, 0\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', '220 mta1.migadu.com ESMTP\n250-mta1.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-STARTTLS\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n220 2.0.0 Ready to start TLS\n250-mta1.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-AUTH PLAIN LOGIN\r\n250-AUTH=PLAIN LOGIN\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n334 VXNlcm5hbWU6\n334 UGFzc3dvcmQ6\n235 2.7.0 Authentication successful\n250 2.1.0 Ok\n250 2.1.5 Ok\n354 End data with <CR><LF>.<CR><LF>\n250 2.0.0 Ok: queued as 8299111B84B', NULL, '2026-04-07 14:19:43'),
(118, 1, 1, NULL, NULL, 'email', 11, '2026-04-18', 'alarma@lpg-auto.ro', 'trimis', 'migadu', '[Fleet Management MVP] 3 documente in ciclu activ de notificare', 'Salut, Inbox notificari!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: 4 | praguri: 12, 10, 9, 5, 1, 0\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: 8 | praguri: 12, 10, 9, 5, 1, 0\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 11 | praguri: 12, 10, 9, 5, 1, 0\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', '220 mta1.migadu.com ESMTP\n250-mta1.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-STARTTLS\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n220 2.0.0 Ready to start TLS\n250-mta1.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-AUTH PLAIN LOGIN\r\n250-AUTH=PLAIN LOGIN\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n334 VXNlcm5hbWU6\n334 UGFzc3dvcmQ6\n235 2.7.0 Authentication successful\n250 2.1.0 Ok\n250 2.1.5 Ok\n354 End data with <CR><LF>.<CR><LF>\n250 2.0.0 Ok: queued as 8299111B84B', NULL, '2026-04-07 14:19:43'),
(119, 3, 2, 1, NULL, 'email', 4, '2026-04-11', 'gigel.trandafir@lpg-auto.ro', 'trimis', 'migadu', '[Fleet Management MVP] 3 documente in ciclu activ de notificare', 'Salut, Administrator Sistem!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: 4 | praguri: 12, 10, 9, 5, 1, 0\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: 8 | praguri: 12, 10, 9, 5, 1, 0\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 11 | praguri: 12, 10, 9, 5, 1, 0\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', '220 mta1.migadu.com ESMTP\n250-mta1.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-STARTTLS\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n220 2.0.0 Ready to start TLS\n250-mta1.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-AUTH PLAIN LOGIN\r\n250-AUTH=PLAIN LOGIN\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n334 VXNlcm5hbWU6\n334 UGFzc3dvcmQ6\n235 2.7.0 Authentication successful\n250 2.1.0 Ok\n250 2.1.5 Ok\n354 End data with <CR><LF>.<CR><LF>\n250 2.0.0 Ok: queued as 56DF011CE04', NULL, '2026-04-07 14:19:48'),
(120, 7, 1, 1, NULL, 'email', 8, '2026-04-15', 'gigel.trandafir@lpg-auto.ro', 'trimis', 'migadu', '[Fleet Management MVP] 3 documente in ciclu activ de notificare', 'Salut, Administrator Sistem!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: 4 | praguri: 12, 10, 9, 5, 1, 0\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: 8 | praguri: 12, 10, 9, 5, 1, 0\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 11 | praguri: 12, 10, 9, 5, 1, 0\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', '220 mta1.migadu.com ESMTP\n250-mta1.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-STARTTLS\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n220 2.0.0 Ready to start TLS\n250-mta1.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-AUTH PLAIN LOGIN\r\n250-AUTH=PLAIN LOGIN\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n334 VXNlcm5hbWU6\n334 UGFzc3dvcmQ6\n235 2.7.0 Authentication successful\n250 2.1.0 Ok\n250 2.1.5 Ok\n354 End data with <CR><LF>.<CR><LF>\n250 2.0.0 Ok: queued as 56DF011CE04', NULL, '2026-04-07 14:19:48'),
(121, 1, 1, 1, NULL, 'email', 11, '2026-04-18', 'gigel.trandafir@lpg-auto.ro', 'trimis', 'migadu', '[Fleet Management MVP] 3 documente in ciclu activ de notificare', 'Salut, Administrator Sistem!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: 4 | praguri: 12, 10, 9, 5, 1, 0\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: 8 | praguri: 12, 10, 9, 5, 1, 0\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 11 | praguri: 12, 10, 9, 5, 1, 0\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', '220 mta1.migadu.com ESMTP\n250-mta1.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-STARTTLS\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n220 2.0.0 Ready to start TLS\n250-mta1.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-AUTH PLAIN LOGIN\r\n250-AUTH=PLAIN LOGIN\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n334 VXNlcm5hbWU6\n334 UGFzc3dvcmQ6\n235 2.7.0 Authentication successful\n250 2.1.0 Ok\n250 2.1.5 Ok\n354 End data with <CR><LF>.<CR><LF>\n250 2.0.0 Ok: queued as 56DF011CE04', NULL, '2026-04-07 14:19:48'),
(122, 3, 2, 2, NULL, 'email', 4, '2026-04-11', 'alexandra.iordache@lpg-auto.ro', 'trimis', 'migadu', '[Fleet Management MVP] 3 documente in ciclu activ de notificare', 'Salut, Operator Flota!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: 4 | praguri: 12, 10, 9, 5, 1, 0\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: 8 | praguri: 12, 10, 9, 5, 1, 0\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 11 | praguri: 12, 10, 9, 5, 1, 0\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', '220 mta1.migadu.com ESMTP\n250-mta1.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-STARTTLS\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n220 2.0.0 Ready to start TLS\n250-mta1.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-AUTH PLAIN LOGIN\r\n250-AUTH=PLAIN LOGIN\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n334 VXNlcm5hbWU6\n334 UGFzc3dvcmQ6\n235 2.7.0 Authentication successful\n250 2.1.0 Ok\n250 2.1.5 Ok\n354 End data with <CR><LF>.<CR><LF>\n250 2.0.0 Ok: queued as A493611CE80', NULL, '2026-04-07 14:19:55'),
(123, 7, 1, 2, NULL, 'email', 8, '2026-04-15', 'alexandra.iordache@lpg-auto.ro', 'trimis', 'migadu', '[Fleet Management MVP] 3 documente in ciclu activ de notificare', 'Salut, Operator Flota!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: 4 | praguri: 12, 10, 9, 5, 1, 0\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: 8 | praguri: 12, 10, 9, 5, 1, 0\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 11 | praguri: 12, 10, 9, 5, 1, 0\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', '220 mta1.migadu.com ESMTP\n250-mta1.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-STARTTLS\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n220 2.0.0 Ready to start TLS\n250-mta1.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-AUTH PLAIN LOGIN\r\n250-AUTH=PLAIN LOGIN\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n334 VXNlcm5hbWU6\n334 UGFzc3dvcmQ6\n235 2.7.0 Authentication successful\n250 2.1.0 Ok\n250 2.1.5 Ok\n354 End data with <CR><LF>.<CR><LF>\n250 2.0.0 Ok: queued as A493611CE80', NULL, '2026-04-07 14:19:55'),
(124, 1, 1, 2, NULL, 'email', 11, '2026-04-18', 'alexandra.iordache@lpg-auto.ro', 'trimis', 'migadu', '[Fleet Management MVP] 3 documente in ciclu activ de notificare', 'Salut, Operator Flota!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: 4 | praguri: 12, 10, 9, 5, 1, 0\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: 8 | praguri: 12, 10, 9, 5, 1, 0\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 11 | praguri: 12, 10, 9, 5, 1, 0\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', '220 mta1.migadu.com ESMTP\n250-mta1.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-STARTTLS\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n220 2.0.0 Ready to start TLS\n250-mta1.migadu.com\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-ETRN\r\n250-AUTH PLAIN LOGIN\r\n250-AUTH=PLAIN LOGIN\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250-DSN\r\n250 CHUNKING\n334 VXNlcm5hbWU6\n334 UGFzc3dvcmQ6\n235 2.7.0 Authentication successful\n250 2.1.0 Ok\n250 2.1.5 Ok\n354 End data with <CR><LF>.<CR><LF>\n250 2.0.0 Ok: queued as A493611CE80', NULL, '2026-04-07 14:19:55'),
(125, 3, 2, NULL, 2, 'sms', 4, '2026-04-11', '+40722000002', 'trimis', 'smsalert', NULL, 'Popescu Andrei, Rovinieta pentru B-202-FLT expira la 11.04.2026. Zile ramase: 4. Actualizeaza documentul.', '{\"id\":\"bc048058ef72bf437288ad4ca61dacea\",\"status\":true,\"smsCount\":1}', NULL, '2026-04-07 14:19:56'),
(126, 7, 1, NULL, 1, 'sms', 8, '2026-04-15', '+40774420199', 'trimis', 'smsalert', NULL, 'Ionescu Mihai: Ai 2 documente de vehicul in ciclu activ. B-101-FLT ROV (8z), B-101-FLT RCA (11z). Verifica documentele vehiculului.', '{\"id\":\"f675c31dbf4fbc86a275c0bbdec367ff\",\"status\":true,\"smsCount\":1}', NULL, '2026-04-07 14:19:57'),
(127, 1, 1, NULL, 1, 'sms', 11, '2026-04-18', '+40774420199', 'trimis', 'smsalert', NULL, 'Ionescu Mihai: Ai 2 documente de vehicul in ciclu activ. B-101-FLT ROV (8z), B-101-FLT RCA (11z). Verifica documentele vehiculului.', '{\"id\":\"f675c31dbf4fbc86a275c0bbdec367ff\",\"status\":true,\"smsCount\":1}', NULL, '2026-04-07 14:19:57'),
(128, 4, 3, NULL, NULL, 'email', -14, '2026-04-01', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Inbox notificari!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -14 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -4 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: 0 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 3 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-15 17:02:32'),
(129, 3, 2, NULL, NULL, 'email', -4, '2026-04-11', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Inbox notificari!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -14 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -4 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: 0 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 3 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-15 17:02:32'),
(130, 8, 1, NULL, NULL, 'email', -1, '2026-04-14', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Inbox notificari!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -14 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -4 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: 0 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 3 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-15 17:02:32'),
(131, 7, 1, NULL, NULL, 'email', 0, '2026-04-15', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Inbox notificari!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -14 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -4 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: 0 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 3 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-15 17:02:32'),
(132, 1, 1, NULL, NULL, 'email', 3, '2026-04-18', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Inbox notificari!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -14 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -4 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: 0 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 3 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-15 17:02:32'),
(133, 4, 3, 1, NULL, 'email', -14, '2026-04-01', 'gigel.trandafir@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Administrator Sistem!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -14 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -4 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: 0 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 3 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-15 17:02:48'),
(134, 3, 2, 1, NULL, 'email', -4, '2026-04-11', 'gigel.trandafir@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Administrator Sistem!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -14 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -4 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: 0 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 3 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-15 17:02:48'),
(135, 8, 1, 1, NULL, 'email', -1, '2026-04-14', 'gigel.trandafir@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Administrator Sistem!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -14 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -4 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: 0 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 3 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-15 17:02:48'),
(136, 7, 1, 1, NULL, 'email', 0, '2026-04-15', 'gigel.trandafir@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Administrator Sistem!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -14 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -4 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: 0 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 3 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-15 17:02:48'),
(137, 1, 1, 1, NULL, 'email', 3, '2026-04-18', 'gigel.trandafir@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Administrator Sistem!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -14 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -4 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: 0 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 3 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-15 17:02:48'),
(138, 4, 3, 2, NULL, 'email', -14, '2026-04-01', 'alexandra.iordache@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Operator Flota!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -14 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -4 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: 0 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 3 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-15 17:03:08'),
(139, 3, 2, 2, NULL, 'email', -4, '2026-04-11', 'alexandra.iordache@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Operator Flota!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -14 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -4 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: 0 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 3 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-15 17:03:08'),
(140, 8, 1, 2, NULL, 'email', -1, '2026-04-14', 'alexandra.iordache@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Operator Flota!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -14 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -4 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: 0 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 3 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-15 17:03:08'),
(141, 7, 1, 2, NULL, 'email', 0, '2026-04-15', 'alexandra.iordache@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Operator Flota!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -14 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -4 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: 0 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 3 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-15 17:03:08'),
(142, 1, 1, 2, NULL, 'email', 3, '2026-04-18', 'alexandra.iordache@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Operator Flota!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -14 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -4 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: 0 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 3 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-15 17:03:08'),
(143, 8, 1, NULL, 1, 'sms', -1, '2026-04-14', '+40774420199', 'trimis', 'smsalert', NULL, 'Ionescu Mihai: Ai 3 documente de vehicul in ciclu activ. B-101-FLT Rovinieta (-1z), B-101-FLT ROV (0z). +1 alte documente. Verifica documentele vehiculului.', '{\"id\":\"f7b480d865ed8452cef3d42f0496ca45\",\"status\":true,\"smsCount\":1}', NULL, '2026-04-15 17:03:09'),
(144, 7, 1, NULL, 1, 'sms', 0, '2026-04-15', '+40774420199', 'trimis', 'smsalert', NULL, 'Ionescu Mihai: Ai 3 documente de vehicul in ciclu activ. B-101-FLT Rovinieta (-1z), B-101-FLT ROV (0z). +1 alte documente. Verifica documentele vehiculului.', '{\"id\":\"f7b480d865ed8452cef3d42f0496ca45\",\"status\":true,\"smsCount\":1}', NULL, '2026-04-15 17:03:09'),
(145, 1, 1, NULL, 1, 'sms', 3, '2026-04-18', '+40774420199', 'trimis', 'smsalert', NULL, 'Ionescu Mihai: Ai 3 documente de vehicul in ciclu activ. B-101-FLT Rovinieta (-1z), B-101-FLT ROV (0z). +1 alte documente. Verifica documentele vehiculului.', '{\"id\":\"f7b480d865ed8452cef3d42f0496ca45\",\"status\":true,\"smsCount\":1}', NULL, '2026-04-15 17:03:09'),
(146, 4, 3, NULL, NULL, 'email', -15, '2026-04-01', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Inbox notificari!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -15 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -5 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -2 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 2 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-16 12:33:14'),
(147, 3, 2, NULL, NULL, 'email', -5, '2026-04-11', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Inbox notificari!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -15 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -5 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -2 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 2 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-16 12:33:14'),
(148, 8, 1, NULL, NULL, 'email', -2, '2026-04-14', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Inbox notificari!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -15 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -5 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -2 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 2 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-16 12:33:14'),
(149, 7, 1, NULL, NULL, 'email', -1, '2026-04-15', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Inbox notificari!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -15 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -5 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -2 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 2 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-16 12:33:14'),
(150, 1, 1, NULL, NULL, 'email', 2, '2026-04-18', 'alarma@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Inbox notificari!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -15 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -5 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -2 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 2 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-16 12:33:14'),
(151, 4, 3, 1, NULL, 'email', -15, '2026-04-01', 'gigel.trandafir@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Administrator Sistem!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -15 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -5 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -2 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 2 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-16 12:33:30'),
(152, 3, 2, 1, NULL, 'email', -5, '2026-04-11', 'gigel.trandafir@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Administrator Sistem!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -15 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -5 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -2 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 2 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-16 12:33:30'),
(153, 8, 1, 1, NULL, 'email', -2, '2026-04-14', 'gigel.trandafir@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Administrator Sistem!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -15 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -5 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -2 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 2 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-16 12:33:30'),
(154, 7, 1, 1, NULL, 'email', -1, '2026-04-15', 'gigel.trandafir@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Administrator Sistem!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -15 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -5 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -2 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 2 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-16 12:33:30'),
(155, 1, 1, 1, NULL, 'email', 2, '2026-04-18', 'gigel.trandafir@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Administrator Sistem!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -15 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -5 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -2 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 2 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-16 12:33:31'),
(156, 4, 3, 2, NULL, 'email', -15, '2026-04-01', 'alexandra.iordache@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Operator Flota!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -15 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -5 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -2 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 2 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-16 12:33:47'),
(157, 3, 2, 2, NULL, 'email', -5, '2026-04-11', 'alexandra.iordache@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Operator Flota!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -15 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -5 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -2 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 2 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-16 12:33:47'),
(158, 8, 1, 2, NULL, 'email', -2, '2026-04-14', 'alexandra.iordache@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Operator Flota!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -15 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -5 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -2 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 2 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-16 12:33:47'),
(159, 7, 1, 2, NULL, 'email', -1, '2026-04-15', 'alexandra.iordache@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Operator Flota!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -15 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -5 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -2 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 2 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-16 12:33:47');
INSERT INTO `notificari_log` (`id`, `document_id`, `vehicle_id`, `user_id`, `driver_id`, `canal`, `prag_zile`, `document_data_expirare`, `destinatar`, `status`, `furnizor`, `subiect`, `mesaj`, `raspuns_furnizor`, `mesaj_eroare`, `created_at`) VALUES
(160, 1, 1, 2, NULL, 'email', 2, '2026-04-18', 'alexandra.iordache@lpg-auto.ro', 'esuat', 'migadu', '[Fleet Management MVP] 5 documente in ciclu activ de notificare', 'Salut, Operator Flota!\n\nDocumentele de mai jos sunt in ciclul activ de notificare si vor continua sa fie raportate pana la actualizarea datei de expirare de catre un operator.\n\n- B-303-FLT | RCA | expira la 01.04.2026 | zile ramase: -15 | notificarea incepe cu: 12 zile\n- B-202-FLT | Rovinieta | expira la 11.04.2026 | zile ramase: -5 | notificarea incepe cu: 12 zile\n- B-101-FLT | Rovinieta | expira la 14.04.2026 | zile ramase: -2 | notificarea incepe cu: 12 zile\n- B-101-FLT | ROV | expira la 15.04.2026 | zile ramase: -1 | notificarea incepe cu: 12 zile\n- B-101-FLT | RCA | expira la 18.04.2026 | zile ramase: 2 | notificarea incepe cu: 12 zile\n\nVezi documentele in aplicatie:\nhttp://127.0.0.1:8000/index.php?page=documente\n\nCiclul se reseteaza cand un operator actualizeaza data de expirare.\nMesaj generat automat de Fleet Management MVP.', NULL, 'incercarea 1/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat. | incercarea 2/2 - Conexiunea SMTP s-a inchis inainte de raspunsul asteptat.', '2026-04-16 12:33:47');

-- --------------------------------------------------------

--
-- Table structure for table `notificari_reguli_documente`
--

CREATE TABLE `notificari_reguli_documente` (
  `id` int UNSIGNED NOT NULL,
  `tip_document` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `praguri_zile` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `activ` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notificari_reguli_documente`
--

INSERT INTO `notificari_reguli_documente` (`id`, `tip_document`, `praguri_zile`, `activ`, `created_at`, `updated_at`) VALUES
(1, 'RCA', '12', 1, '2026-04-07 14:06:04', '2026-04-08 08:52:31'),
(2, 'ITP', '12', 1, '2026-04-07 14:06:04', '2026-04-08 08:52:31'),
(3, 'Rovinieta', '12', 1, '2026-04-07 14:06:04', '2026-04-08 08:52:31'),
(4, 'ROV', '12', 1, '2026-04-07 14:06:04', '2026-04-08 08:52:31');

-- --------------------------------------------------------

--
-- Table structure for table `soferi`
--

CREATE TABLE `soferi` (
  `id` int UNSIGNED NOT NULL,
  `nume` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefon` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
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

INSERT INTO `soferi` (`id`, `nume`, `telefon`, `vehicle_id`, `permis_expira_la`, `status`, `observatii`, `created_at`, `updated_at`) VALUES
(1, 'Ionescu Mihai', '0774420199', 11, '2027-01-28', 'inactiv', 'Disponibil full-time', '2026-04-03 15:08:04', '2026-04-27 17:05:01'),
(2, 'Popescu Andrei', '0722000002', 11, '2026-08-01', 'inactiv', 'Route urban', '2026-04-03 15:08:04', '2026-04-21 13:02:59'),
(3, 'Marin Elena', '0722000003', 26, '2026-05-18', 'inactiv', 'CO', '2026-04-03 15:08:04', '2026-05-06 10:35:14'),
(4, 'Pulea Spataru', '0771342736', 19, '2026-06-25', 'activ', NULL, '2026-05-06 11:58:52', '2026-05-11 11:59:52'),
(5, 'Dan Spataru', '077383993', 20, '2026-06-30', 'activ', NULL, '2026-05-06 12:01:20', '2026-05-06 12:02:14'),
(6, 'Dan Dobrin', '07883929', 22, '2026-06-23', 'activ', NULL, '2026-05-06 12:02:46', '2026-05-06 12:03:27'),
(7, 'Petre Trandafir', '077883282', 21, '2026-08-19', 'activ', NULL, '2026-05-06 12:03:54', '2026-05-06 12:04:33'),
(8, 'Marius Manole', '073232678', 15, '2026-07-22', 'activ', NULL, '2026-05-06 12:05:16', '2026-05-06 12:05:51'),
(9, 'Trandafir Marius', '07838259952', 16, '2026-09-15', 'activ', NULL, '2026-05-06 12:06:16', '2026-05-06 12:07:07'),
(10, 'Magnolia Dorel', '078992392', 19, '2026-09-30', 'activ', NULL, '2026-05-06 12:07:40', '2026-05-11 11:59:34'),
(11, 'Condrea Daniel', '079892942', 21, '2026-09-29', 'activ', NULL, '2026-05-06 12:09:02', '2026-05-06 12:09:38'),
(12, 'Petre Trandafir', '07443432435', 28, '2026-05-27', 'activ', NULL, '2026-05-11 12:42:25', '2026-05-11 12:43:08');

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
  `rol` enum('admin','utilizator') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'utilizator',
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
(2, 'Sofer', 'test_utilizator@gmail.com', '0771342736', '$2y$10$8bj3jRbgGwEvKBd62Pf3aeEWKAnv9gEMMQf5i3BFCv5xo6By3VyXy', 'utilizator', 'activ', 1, 1, '2026-04-03 15:08:04', '2026-04-21 13:05:59');

-- --------------------------------------------------------

--
-- Table structure for table `vehicule`
--

CREATE TABLE `vehicule` (
  `id` int UNSIGNED NOT NULL,
  `nr_inmatriculare` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `marca` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tip_vehicul` enum('autovehicul','camion','cap_tractor','semiremorca') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'autovehicul',
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
  `garaj` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `vehicule`
--

INSERT INTO `vehicule` (`id`, `nr_inmatriculare`, `marca`, `model`, `tip_vehicul`, `an_fabricatie`, `km_bord`, `km_revizie`, `serie_sasiu`, `nr_fabricatie`, `capacitate_transport`, `formula_axelor`, `capacitate_rezervor`, `mma`, `organism_notificat`, `poza_original`, `poza_stocata`, `consum_mediu`, `status`, `observatii`, `created_at`, `updated_at`, `garaj`) VALUES
(1, 'B-101-FLT', 'Dacia', 'Duster', 'autovehicul', 2021, 0, 0, 'UU1HSDACIA0001001', NULL, NULL, '2x2', NULL, NULL, NULL, NULL, NULL, 7.30, 'inactiv', 'Vehicul pentru livrari locale', '2026-04-03 15:08:04', '2026-05-12 11:25:27', NULL),
(2, 'B-202-FLT', 'Ford', 'Transit', 'autovehicul', 2020, 0, 0, 'WF0XXXTTGXLA02021', NULL, NULL, '2x2', NULL, NULL, NULL, NULL, NULL, 9.10, 'inactiv', 'Autoutilitara transport marfa', '2026-04-03 15:08:04', '2026-05-12 11:25:27', NULL),
(3, 'B-303-FLT', 'Renault', 'Clio', 'autovehicul', 2019, 0, 0, 'VF1RCLIOFLEET3030', NULL, NULL, '2x2', NULL, NULL, NULL, NULL, NULL, 6.20, 'inactiv', 'In service prelungit', '2026-04-03 15:08:04', '2026-05-12 11:25:27', NULL),
(5, 'B 102 FLT', 'Duster', 'Dacia', 'autovehicul', 2011, 0, 0, 'TEMPVIN0000000005', NULL, NULL, '2x2', NULL, NULL, NULL, 'download.jpg', 'vehicul_20260416_104502_182ab2f0a80df1de.jpg', 3.50, 'inactiv', NULL, '2026-04-15 09:39:09', '2026-05-12 11:25:27', NULL),
(6, 'B 235 NET', 'MERCEDES', 'BENZ', 'cap_tractor', 2012, 0, 0, 'WDB9505311L665472', 'FAB-TEST-001', 24.50, '6x4', 1199.98, 40000.02, 'RAR', 'download_1.jpg', 'vehicul_20260416_105133_9a44137160bd3dc5.jpg', NULL, 'activ', NULL, '2026-04-16 10:51:33', '2026-05-06 10:03:55', NULL),
(9, 'B 218 NET', 'VPS', 'BENZ', 'semiremorca', 2018, 250, 10000, 'WDB9505311L665475', NULL, NULL, '3 axe', NULL, NULL, NULL, 'tank-transport-gas-tank-semi-trailer-VPS-CN50---1776080545104524884_big--26033115022303912300.jpg', 'vehicul_20260420_092951_11225154f0966084.jpg', NULL, 'activ', NULL, '2026-04-20 09:29:51', '2026-05-13 09:27:56', NULL),
(10, 'B 238 NET', 'VPS', 'BENZ', 'semiremorca', 2012, 0, 0, 'WDB9505311L665478', NULL, NULL, '3 axe', NULL, NULL, NULL, 'tank-transport-gas-tank-semi-trailer-VPS-CN50---1776080545104524884_big--26033115022303912300.jpg', 'vehicul_20260420_095109_3212d38400bf0aad.jpg', NULL, 'inactiv', NULL, '2026-04-20 09:51:09', '2026-05-12 11:25:27', NULL),
(11, 'B 665 NET', 'DAF', 'H4EN3', 'cap_tractor', 2019, 710860, 0, 'XLRTEH4100G274734', NULL, NULL, '4x2', 544.00, 20050.00, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-04-21 09:15:42', '2026-05-12 11:25:27', 'Bihor'),
(12, 'B 405 NET', 'VPS', 'VPSCN3/BBEA/', 'semiremorca', 2016, 198, 0, 'TN9VPSCN3GRVP5512', '61331', 18.00, '3 axe', NULL, 38000.00, 'TUV', NULL, NULL, NULL, 'inactiv', NULL, '2026-04-21 09:20:12', '2026-05-13 14:13:56', 'Bihor'),
(13, 'B 655 NET', 'DAF', 'H4EN3', 'cap_tractor', 2019, 569509, 569509, 'XLRTEH4100G250526', NULL, NULL, '4x2', 544.00, 20050.00, NULL, NULL, NULL, NULL, 'inactiv', NULL, '2026-04-21 09:33:26', '2026-05-12 11:25:27', NULL),
(14, 'B 305 NET', 'VPS', 'VPSCN3/BBEA/', 'semiremorca', 2014, 0, 0, 'TN9VPSCN3ERVP5377', '53695', 18.00, '3 axe', NULL, 38000.00, 'EUROCERT', NULL, NULL, NULL, 'inactiv', NULL, '2026-04-21 12:06:27', '2026-05-12 11:25:27', NULL),
(15, 'B 285 NET', 'Mercedes', 'Benz', 'camion', 2018, 248, 10000, 'XLRTEH4100G274736', '3213125', 10.00, '4x2', 600.00, 10000.00, '-', NULL, NULL, NULL, 'activ', NULL, '2026-04-24 14:16:58', '2026-05-12 11:25:27', 'Oradea'),
(16, 'B 375 NET', 'MERCEDES', 'BENZ', 'camion', 2022, 346678, 10000, 'XLRTEH4100G274737', '327992', 10.00, '4x2', 600.00, NULL, '-', NULL, NULL, NULL, 'activ', NULL, '2026-04-24 14:17:42', '2026-05-12 11:25:27', 'Oradea'),
(17, 'B 385 NET', 'MERCEDES', 'BENZ', 'autovehicul', 2021, 250, 200, 'TN9VPSCN3ERVP5377', NULL, NULL, '2x2', NULL, NULL, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-04-24 14:18:30', '2026-05-12 11:25:27', 'Bihor'),
(18, 'B 437 NET', 'MERCEDES', 'BENZ', 'camion', 2021, 250, 200, 'XLRTEH4100G274739', NULL, NULL, '4x2', NULL, NULL, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-04-24 14:19:11', '2026-05-12 11:25:27', 'Bihor'),
(19, 'B 219 NET', 'MERCEDES', 'BENZ', 'camion', 2021, 1150, 9100, 'XLRTEH4100G274738', '2191991', 10.00, '4x2', 300.00, 10000.00, '-', NULL, NULL, NULL, 'activ', NULL, '2026-04-24 14:20:00', '2026-05-15 10:13:45', 'Lugoj'),
(20, 'B 345 NET', 'MERCEDES', 'BENZ', 'camion', 2014, 250, 9999, 'WDB9505311L665479', NULL, NULL, '4x2', NULL, 10000.00, '-', NULL, NULL, NULL, 'activ', NULL, '2026-04-24 14:20:45', '2026-05-12 11:25:27', 'Lugoj'),
(21, 'B 311 NET', 'MERCEDES', 'BENZ', 'camion', 2015, 250, 250, 'WDB9505311L665475', NULL, NULL, '4x2', NULL, NULL, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-04-24 14:21:36', '2026-05-12 11:25:27', 'Lugoj'),
(22, 'B 430 NET', 'MERCEDES', 'BENZ', 'camion', 2021, 254546, 10000, 'TN9VPSCN3ERVP5379', NULL, 10.00, '4x2', 450.00, 10000.00, '-', NULL, NULL, NULL, 'activ', NULL, '2026-04-24 14:22:08', '2026-05-12 11:25:27', 'Lugoj'),
(23, 'B 275 NET', 'MERCEDES', 'BENZ', 'camion', 2015, 250, 250, 'TN9VPSCN3GRVP5512', NULL, NULL, '4x2', NULL, NULL, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-04-24 14:23:11', '2026-05-12 11:25:27', 'Bihor'),
(24, 'B 325 NET', 'MERCEDES', 'BENZ', 'camion', 2016, 250, 250, 'TN9VPSCN3ERVP5375', NULL, NULL, '4x2', NULL, NULL, NULL, NULL, NULL, NULL, 'activ', NULL, '2026-04-24 14:23:48', '2026-05-12 11:25:27', 'Bihor'),
(25, 'B 775 NET', 'MERCEDES', 'BENZ', 'camion', 2017, 250, 250, 'WDB9505311L665354', NULL, NULL, '4x2', NULL, NULL, NULL, NULL, NULL, NULL, 'inactiv', NULL, '2026-04-24 14:25:20', '2026-05-12 11:25:27', 'Dambovita'),
(26, 'B 677 NET', 'MAN', 'TGA', 'cap_tractor', 2014, 20000, 10000, 'WDB9505311L665475', '34231', 10.00, '4x2', 600.00, 20000.00, '-', NULL, NULL, NULL, 'activ', NULL, '2026-05-05 12:40:12', '2026-05-13 09:27:56', 'Salonta'),
(27, 'B 435 NET', 'MAN', 'TGX', 'camion', 2004, 200000, 200000, 'WDB9505311L665476', '677782', 10.00, '4x2', 200.00, 20000.00, '-', NULL, NULL, NULL, 'activ', NULL, '2026-05-05 12:52:38', '2026-05-12 11:25:27', 'Salonta'),
(28, 'B 605 NET', 'DAF', 'XF', 'camion', 2011, 323232, 23232, 'WDB9505311L663567', '32131', 20.00, '4x2', 500.00, 205000.00, '-', NULL, NULL, NULL, 'activ', NULL, '2026-05-07 12:28:17', '2026-05-13 09:27:56', 'Contesti');

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
(1, 1, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(2, 1, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(3, 1, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(4, 1, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(5, 2, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(6, 2, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(7, 2, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(8, 2, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(9, 3, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(10, 3, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(11, 3, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(12, 3, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(13, 5, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(14, 5, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(15, 5, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(16, 5, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(17, 6, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(18, 6, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(19, 6, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(20, 6, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(21, 6, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(22, 6, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(23, 6, 'A3-LO', 'Axa 3 - Stanga exterior', 3, 'LO', 'dual', 7, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(24, 6, 'A3-LI', 'Axa 3 - Stanga interior', 3, 'LI', 'dual', 8, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(25, 6, 'A3-RI', 'Axa 3 - Dreapta interior', 3, 'RI', 'dual', 9, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(26, 6, 'A3-RO', 'Axa 3 - Dreapta exterior', 3, 'RO', 'dual', 10, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(27, 9, 'A1-LO', 'Axa 1 - Stanga exterior', 1, 'LO', 'dual', 1, 1, '2026-05-12 11:25:27', '2026-05-12 13:20:04'),
(28, 9, 'A1-LI', 'Axa 1 - Stanga interior', 1, 'LI', 'dual', 2, 1, '2026-05-12 11:25:27', '2026-05-12 13:20:04'),
(29, 9, 'A1-RI', 'Axa 1 - Dreapta interior', 1, 'RI', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-05-12 13:20:04'),
(30, 9, 'A1-RO', 'Axa 1 - Dreapta exterior', 1, 'RO', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-05-12 13:20:04'),
(31, 9, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-05-12 13:20:04'),
(32, 9, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-05-12 13:20:04'),
(33, 9, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 7, 1, '2026-05-12 11:25:27', '2026-05-12 13:20:04'),
(34, 9, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 8, 1, '2026-05-12 11:25:27', '2026-05-12 13:20:04'),
(35, 9, 'A3-LO', 'Axa 3 - Stanga exterior', 3, 'LO', 'dual', 9, 1, '2026-05-12 11:25:27', '2026-05-12 13:20:04'),
(36, 9, 'A3-LI', 'Axa 3 - Stanga interior', 3, 'LI', 'dual', 10, 1, '2026-05-12 11:25:27', '2026-05-12 13:20:04'),
(37, 9, 'A3-RI', 'Axa 3 - Dreapta interior', 3, 'RI', 'dual', 11, 1, '2026-05-12 11:25:27', '2026-05-12 13:20:04'),
(38, 9, 'A3-RO', 'Axa 3 - Dreapta exterior', 3, 'RO', 'dual', 12, 1, '2026-05-12 11:25:27', '2026-05-12 13:20:04'),
(39, 10, 'A1-LO', 'Axa 1 - Stanga exterior', 1, 'LO', 'dual', 1, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(40, 10, 'A1-LI', 'Axa 1 - Stanga interior', 1, 'LI', 'dual', 2, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(41, 10, 'A1-RI', 'Axa 1 - Dreapta interior', 1, 'RI', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(42, 10, 'A1-RO', 'Axa 1 - Dreapta exterior', 1, 'RO', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(43, 10, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(44, 10, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(45, 10, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 7, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(46, 10, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 8, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(47, 10, 'A3-LO', 'Axa 3 - Stanga exterior', 3, 'LO', 'dual', 9, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(48, 10, 'A3-LI', 'Axa 3 - Stanga interior', 3, 'LI', 'dual', 10, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(49, 10, 'A3-RI', 'Axa 3 - Dreapta interior', 3, 'RI', 'dual', 11, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(50, 10, 'A3-RO', 'Axa 3 - Dreapta exterior', 3, 'RO', 'dual', 12, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(51, 11, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(52, 11, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(53, 11, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(54, 11, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(55, 11, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(56, 11, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(57, 12, 'A1-LO', 'Axa 1 - Stanga exterior', 1, 'LO', 'dual', 1, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(58, 12, 'A1-LI', 'Axa 1 - Stanga interior', 1, 'LI', 'dual', 2, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(59, 12, 'A1-RI', 'Axa 1 - Dreapta interior', 1, 'RI', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(60, 12, 'A1-RO', 'Axa 1 - Dreapta exterior', 1, 'RO', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(61, 12, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(62, 12, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(63, 12, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 7, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(64, 12, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 8, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(65, 12, 'A3-LO', 'Axa 3 - Stanga exterior', 3, 'LO', 'dual', 9, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(66, 12, 'A3-LI', 'Axa 3 - Stanga interior', 3, 'LI', 'dual', 10, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(67, 12, 'A3-RI', 'Axa 3 - Dreapta interior', 3, 'RI', 'dual', 11, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(68, 12, 'A3-RO', 'Axa 3 - Dreapta exterior', 3, 'RO', 'dual', 12, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(69, 13, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(70, 13, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(71, 13, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(72, 13, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(73, 13, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(74, 13, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(75, 14, 'A1-LO', 'Axa 1 - Stanga exterior', 1, 'LO', 'dual', 1, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(76, 14, 'A1-LI', 'Axa 1 - Stanga interior', 1, 'LI', 'dual', 2, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(77, 14, 'A1-RI', 'Axa 1 - Dreapta interior', 1, 'RI', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(78, 14, 'A1-RO', 'Axa 1 - Dreapta exterior', 1, 'RO', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(79, 14, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(80, 14, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(81, 14, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 7, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(82, 14, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 8, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(83, 14, 'A3-LO', 'Axa 3 - Stanga exterior', 3, 'LO', 'dual', 9, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(84, 14, 'A3-LI', 'Axa 3 - Stanga interior', 3, 'LI', 'dual', 10, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(85, 14, 'A3-RI', 'Axa 3 - Dreapta interior', 3, 'RI', 'dual', 11, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(86, 14, 'A3-RO', 'Axa 3 - Dreapta exterior', 3, 'RO', 'dual', 12, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(87, 15, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(88, 15, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(89, 15, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(90, 15, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(91, 15, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(92, 15, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(93, 16, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(94, 16, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(95, 16, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(96, 16, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(97, 16, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(98, 16, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:29'),
(99, 17, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(100, 17, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(101, 17, 'A2-L', 'Axa 2 - Stanga', 2, 'L', 'single', 3, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(102, 17, 'A2-R', 'Axa 2 - Dreapta', 2, 'R', 'single', 4, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(103, 18, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(104, 18, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(105, 18, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(106, 18, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(107, 18, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(108, 18, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(109, 19, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-05-14 09:28:23'),
(110, 19, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-05-14 09:28:23'),
(111, 19, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-05-14 09:28:23'),
(112, 19, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-05-14 09:28:23'),
(113, 19, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-05-14 09:28:23'),
(114, 19, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-05-14 09:28:23'),
(115, 20, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(116, 20, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(117, 20, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(118, 20, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(119, 20, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(120, 20, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(121, 21, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(122, 21, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(123, 21, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(124, 21, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(125, 21, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(126, 21, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(127, 22, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(128, 22, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(129, 22, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(130, 22, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(131, 22, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(132, 22, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(133, 23, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(134, 23, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(135, 23, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(136, 23, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(137, 23, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(138, 23, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(139, 24, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(140, 24, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(141, 24, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(142, 24, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(143, 24, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(144, 24, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(145, 25, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(146, 25, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(147, 25, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(148, 25, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(149, 25, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(150, 25, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-05-12 11:25:27'),
(151, 26, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(152, 26, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(153, 26, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(154, 26, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(155, 26, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(156, 26, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(157, 27, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(158, 27, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(159, 27, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(160, 27, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(161, 27, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(162, 27, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-05-12 12:43:28'),
(163, 28, 'A1-L', 'Axa 1 - Stanga', 1, 'L', 'single', 1, 1, '2026-05-12 11:25:27', '2026-05-14 13:33:14'),
(164, 28, 'A1-R', 'Axa 1 - Dreapta', 1, 'R', 'single', 2, 1, '2026-05-12 11:25:27', '2026-05-14 13:33:14'),
(165, 28, 'A2-LO', 'Axa 2 - Stanga exterior', 2, 'LO', 'dual', 3, 1, '2026-05-12 11:25:27', '2026-05-14 13:33:14'),
(166, 28, 'A2-LI', 'Axa 2 - Stanga interior', 2, 'LI', 'dual', 4, 1, '2026-05-12 11:25:27', '2026-05-14 13:33:14'),
(167, 28, 'A2-RI', 'Axa 2 - Dreapta interior', 2, 'RI', 'dual', 5, 1, '2026-05-12 11:25:27', '2026-05-14 13:33:14'),
(168, 28, 'A2-RO', 'Axa 2 - Dreapta exterior', 2, 'RO', 'dual', 6, 1, '2026-05-12 11:25:27', '2026-05-14 13:33:14');

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
(19, 26, 9, 1, '2026-05-05 15:41:12', NULL, 1, '2026-05-05 15:41:12', '2026-05-05 15:41:12');

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
  ADD KEY `idx_curse_driver` (`driver_id`);

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
  ADD KEY `idx_documente_soferi_driver` (`driver_id`),
  ADD KEY `idx_documente_soferi_expirare` (`data_expirare`);

--
-- Indexes for table `mentenanta`
--
ALTER TABLE `mentenanta`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_mentenanta_vehicle` (`vehicle_id`),
  ADD KEY `idx_mentenanta_data` (`data_interventie`);

--
-- Indexes for table `notificari_log`
--
ALTER TABLE `notificari_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notificari_document_canal` (`document_id`,`canal`,`prag_zile`),
  ADD KEY `idx_notificari_created_at` (`created_at`),
  ADD KEY `idx_notificari_status` (`status`),
  ADD KEY `fk_notificari_vehicle` (`vehicle_id`),
  ADD KEY `fk_notificari_user` (`user_id`),
  ADD KEY `idx_notificari_ciclu` (`document_id`,`canal`,`document_data_expirare`),
  ADD KEY `fk_notificari_driver` (`driver_id`);

--
-- Indexes for table `notificari_reguli_documente`
--
ALTER TABLE `notificari_reguli_documente`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tip_document` (`tip_document`),
  ADD KEY `idx_notificari_reguli_activ` (`activ`);

--
-- Indexes for table `soferi`
--
ALTER TABLE `soferi`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_soferi_status` (`status`),
  ADD KEY `idx_soferi_vehicle` (`vehicle_id`);

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
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `anvelope`
--
ALTER TABLE `anvelope`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=137;

--
-- AUTO_INCREMENT for table `anvelope_alocari`
--
ALTER TABLE `anvelope_alocari`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=117;

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=93;

--
-- AUTO_INCREMENT for table `concedii`
--
ALTER TABLE `concedii`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `configurare_beneficiari_transport`
--
ALTER TABLE `configurare_beneficiari_transport`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `configurare_compresor_vehicule`
--
ALTER TABLE `configurare_compresor_vehicule`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `configurare_locuri_incarcare`
--
ALTER TABLE `configurare_locuri_incarcare`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=75;

--
-- AUTO_INCREMENT for table `configurare_locuri_incarcare_vehicule`
--
ALTER TABLE `configurare_locuri_incarcare_vehicule`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=66;

--
-- AUTO_INCREMENT for table `configurare_rute_distributie`
--
ALTER TABLE `configurare_rute_distributie`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `configurare_rute_primar`
--
ALTER TABLE `configurare_rute_primar`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `configurare_zone_distributie`
--
ALTER TABLE `configurare_zone_distributie`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=72;

--
-- AUTO_INCREMENT for table `configurare_zone_distributie_vehicule`
--
ALTER TABLE `configurare_zone_distributie_vehicule`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=65;

--
-- AUTO_INCREMENT for table `curse_cheltuieli`
--
ALTER TABLE `curse_cheltuieli`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `curse_cheltuieli_documente`
--
ALTER TABLE `curse_cheltuieli_documente`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `curse_dispecer`
--
ALTER TABLE `curse_dispecer`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=91;

--
-- AUTO_INCREMENT for table `documente`
--
ALTER TABLE `documente`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=64;

--
-- AUTO_INCREMENT for table `documente_soferi`
--
ALTER TABLE `documente_soferi`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `mentenanta`
--
ALTER TABLE `mentenanta`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=161;

--
-- AUTO_INCREMENT for table `notificari_log`
--
ALTER TABLE `notificari_log`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=161;

--
-- AUTO_INCREMENT for table `notificari_reguli_documente`
--
ALTER TABLE `notificari_reguli_documente`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `soferi`
--
ALTER TABLE `soferi`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `utilizatori`
--
ALTER TABLE `utilizatori`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `vehicule`
--
ALTER TABLE `vehicule`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `vehicule_anvelope_pozitii`
--
ALTER TABLE `vehicule_anvelope_pozitii`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=173;

--
-- AUTO_INCREMENT for table `vehicule_cuplaje`
--
ALTER TABLE `vehicule_cuplaje`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

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
-- Constraints for table `mentenanta`
--
ALTER TABLE `mentenanta`
  ADD CONSTRAINT `fk_mentenanta_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicule` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notificari_log`
--
ALTER TABLE `notificari_log`
  ADD CONSTRAINT `fk_notificari_document` FOREIGN KEY (`document_id`) REFERENCES `documente` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_notificari_driver` FOREIGN KEY (`driver_id`) REFERENCES `soferi` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_notificari_user` FOREIGN KEY (`user_id`) REFERENCES `utilizatori` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_notificari_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicule` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `soferi`
--
ALTER TABLE `soferi`
  ADD CONSTRAINT `fk_soferi_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicule` (`id`) ON DELETE SET NULL;

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
