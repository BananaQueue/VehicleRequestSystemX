-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 21, 2025 at 04:14 AM
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
-- Database: `vrsx_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `drivers`
--

CREATE TABLE `drivers` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `position` varchar(255) DEFAULT 'Driver',
  `phone` varchar(20) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'Available'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `drivers`
--

INSERT INTO `drivers` (`id`, `name`, `email`, `position`, `phone`, `status`) VALUES
(1, 'Alice Smith', 'alice.smith@company.com', 'Driver', '09955665324', 'available'),
(2, 'Bob Johnson', 'bob.johnson@company.com', 'Driver', '09866886688', 'Available'),
(3, 'Charlie Brown', 'charlie.brown@company.com', 'Driver', '09572315664', 'Available'),
(4, 'Diana Prince', 'diana.prince@company.com', 'Driver', '09213216548', 'Available'),
(5, 'Bailando', 'bailando@example.com', 'Driver', '09455224514', 'Available'),
(6, 'Rogelio', 'rogelio@example.com', 'Driver', '09090900909', 'Available'),
(7, 'El mumbo', 'elmumbo@example.com', 'Driver', '09359878524', 'Available');

-- --------------------------------------------------------

--
-- Table structure for table `requests`
--

CREATE TABLE `requests` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `requestor_name` varchar(255) NOT NULL,
  `requestor_email` varchar(255) NOT NULL,
  `destination` varchar(255) NOT NULL,
  `purpose` text NOT NULL,
  `request_date` datetime DEFAULT current_timestamp(),
  `status` enum('pending_dispatch_assignment','pending_admin_approval','approved','rejected_new_request','rejected_reassign_dispatch','rejected') DEFAULT 'pending_dispatch_assignment',
  `assigned_vehicle_id` int(11) DEFAULT NULL,
  `assigned_driver_id` int(11) DEFAULT NULL,
  `rejection_reason` varchar(255) DEFAULT NULL,
  `travel_date` date DEFAULT NULL,
  `passenger_count` int(11) DEFAULT 0,
  `passenger_names` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `requests`
--

INSERT INTO `requests` (`id`, `user_id`, `requestor_name`, `requestor_email`, `destination`, `purpose`, `request_date`, `status`, `assigned_vehicle_id`, `assigned_driver_id`, `rejection_reason`, `travel_date`, `passenger_count`, `passenger_names`) VALUES
(1, 2, 'Rocco', 'rocco@example.com', 'Baguio City', 'Strategic Plan Formulation and Meeting', '2025-08-20 12:01:12', 'approved', NULL, 1, NULL, NULL, 0, NULL),
(2, 2, 'Rocco', 'rocco@example.com', 'Baguio City', 'Meeting', '2025-08-20 13:30:02', 'approved', NULL, 1, NULL, NULL, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','employee','dispatch','driver') NOT NULL,
  `position` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`, `position`, `created_at`) VALUES
(1, 'Admin User', 'admin@example.com', '$2y$10$NJhbgxocrpjtuSQJGFwRheR2/oD3C6OR.BWNxsdWlkz0qgB5xwDy6', 'admin', 'System Administrator', '2025-08-03 07:25:18'),
(2, 'Rocco', 'rocco@example.com', '$2y$10$fq4TI/Cg5mdnnS4MrEOYQOOfB8/y4Id3ter4u50JDxfAKd/A.BvTK', 'employee', 'ChildOfOwner', '2025-08-03 07:53:32'),
(3, 'Koda', 'koda@example.com', '$2y$10$M27J2uwr5cQq7CF1SvRLsuncwpCJ2c904QFCL6crN9widpx3dP69C', 'employee', 'StreetCat', '2025-08-03 07:53:45'),
(4, 'Dispatch', 'dispatch@example.com', '$2y$10$WLlKszOnH9INjKobrTpD1.tyDWqbY3c4eYwsLsxTHqWetf5SVNKOW', 'dispatch', 'dispatcher', '2025-08-19 04:04:15'),
(12, 'Krestel', 'krestel@example.com', '$2y$10$g0SITvZyf4QjuLH5BnbX1.HRdLSHM8fUdfaVQvH3Lx0V63y.jhdL6', 'employee', 'EMS I', '2025-08-20 07:33:42'),
(13, 'Fuljuencio', 'fuljuencio@example.com', '$2y$10$qxu5P/Bt/D9RMG119ZRJX.40ww0zz0XwTWbH0PYRkFl3QaMwF3GV.', 'employee', 'EMS II', '2025-08-26 03:09:18'),
(14, 'Stanley', 'stanley@example.com', '$2y$10$8iQEnimQuMXzHwrMdKMYkuo/xPmRqVV3S1uIYb1m7Se6iiqu5Jhfq', 'employee', 'InsulatedTumbler (IT)', '2025-08-26 07:19:13');

-- --------------------------------------------------------

--
-- Table structure for table `vehicles`
--

CREATE TABLE `vehicles` (
  `id` int(11) NOT NULL,
  `plate_number` varchar(20) NOT NULL,
  `driver_name` varchar(100) DEFAULT NULL,
  `make` varchar(100) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `type` varchar(100) DEFAULT NULL,
  `assigned_to` varchar(100) DEFAULT NULL,
  `status` enum('available','assigned','returning','maintenance','unavailable') DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `return_date` datetime DEFAULT NULL,
  `returned_by` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vehicles`
--

INSERT INTO `vehicles` (`id`, `plate_number`, `driver_name`, `make`, `model`, `type`, `assigned_to`, `status`, `created_at`, `return_date`, `returned_by`) VALUES
(7, 'EMBR2500', NULL, 'Toyota', 'Landcruiser', 'SUV', NULL, 'available', '2025-08-19 04:24:25', NULL, NULL),
(8, 'EMBR9999', NULL, 'Toyota', 'Grandia', 'Van', NULL, 'available', '2025-08-19 04:25:09', NULL, NULL),
(9, 'EMBR9824', NULL, 'Toyota', 'Hi-ace', 'Van', NULL, 'available', '2025-08-19 04:25:37', NULL, NULL),
(10, 'EMBR2143', '', 'Toyota', 'Hilux GRS', 'Pick-up', NULL, 'available', '2025-08-19 04:26:14', NULL, NULL),
(11, 'EMBR4321', NULL, 'Porsche', 'GT3RS', 'Sedan', NULL, 'available', '2025-08-19 04:27:16', NULL, NULL),
(12, 'EMBR7864', NULL, 'Nissan', 'Patrol', 'SUV', NULL, 'available', '2025-08-19 04:27:42', NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `drivers`
--
ALTER TABLE `drivers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `requests`
--
ALTER TABLE `requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `fk_assigned_vehicle` (`assigned_vehicle_id`),
  ADD KEY `fk_assigned_driver` (`assigned_driver_id`),
  ADD KEY `idx_request_status` (`status`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `plate_number` (`plate_number`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `drivers`
--
ALTER TABLE `drivers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `requests`
--
ALTER TABLE `requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `requests`
--
ALTER TABLE `requests`
  ADD CONSTRAINT `fk_assigned_driver` FOREIGN KEY (`assigned_driver_id`) REFERENCES `drivers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_assigned_vehicle` FOREIGN KEY (`assigned_vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
