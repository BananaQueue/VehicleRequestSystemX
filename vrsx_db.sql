-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 19, 2026 at 04:03 AM
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
  `status` enum('pending_dispatch_assignment','pending_admin_approval','approved','rejected_new_request','rejected_reassign_dispatch','rejected','cancelled') DEFAULT 'pending_dispatch_assignment',
  `assigned_vehicle_id` int(11) DEFAULT NULL,
  `assigned_driver_id` int(11) DEFAULT NULL,
  `rejection_reason` varchar(255) DEFAULT NULL,
  `travel_date` date DEFAULT NULL,
  `passenger_count` int(11) DEFAULT 0,
  `passenger_names` text DEFAULT NULL,
  `departure_date` date DEFAULT NULL,
  `return_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `requests`
--

INSERT INTO `requests` (`id`, `user_id`, `requestor_name`, `requestor_email`, `destination`, `purpose`, `request_date`, `status`, `assigned_vehicle_id`, `assigned_driver_id`, `rejection_reason`, `travel_date`, `passenger_count`, `passenger_names`, `departure_date`, `return_date`) VALUES
(3, 2, 'Rocco', 'rocco@example.com', 'Ilocos Norte', 'Meeting', '2026-01-15 14:56:56', 'pending_dispatch_assignment', NULL, NULL, NULL, NULL, 2, '[\"Rocco\",\"Krestel\"]', '2026-01-19', '2026-01-21');

-- --------------------------------------------------------

--
-- Table structure for table `request_audit_logs`
--

CREATE TABLE `request_audit_logs` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `actor_id` int(11) DEFAULT NULL,
  `actor_role` varchar(50) DEFAULT NULL,
  `actor_name` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` enum('admin','employee','dispatch','driver') NOT NULL,
  `position` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`, `position`, `phone`, `created_at`) VALUES
(1, 'Admin User', 'admin@example.com', '$2y$10$NJhbgxocrpjtuSQJGFwRheR2/oD3C6OR.BWNxsdWlkz0qgB5xwDy6', 'admin', 'System Administrator', NULL, '2025-08-03 07:25:18'),
(2, 'Rocco', 'rocco@example.com', '$2y$10$fq4TI/Cg5mdnnS4MrEOYQOOfB8/y4Id3ter4u50JDxfAKd/A.BvTK', 'employee', 'ChildOfOwner', NULL, '2025-08-03 07:53:32'),
(3, 'Koda', 'koda@example.com', '$2y$10$M27J2uwr5cQq7CF1SvRLsuncwpCJ2c904QFCL6crN9widpx3dP69C', 'employee', 'StreetCat', NULL, '2025-08-03 07:53:45'),
(4, 'Dispatch', 'dispatch@example.com', '$2y$10$WLlKszOnH9INjKobrTpD1.tyDWqbY3c4eYwsLsxTHqWetf5SVNKOW', 'dispatch', 'dispatcher', NULL, '2025-08-19 04:04:15'),
(12, 'Krestel', 'krestel@example.com', '$2y$10$g0SITvZyf4QjuLH5BnbX1.HRdLSHM8fUdfaVQvH3Lx0V63y.jhdL6', 'employee', 'EMS I', NULL, '2025-08-20 07:33:42'),
(13, 'Fuljuencio', 'fuljuencio@example.com', '$2y$10$qxu5P/Bt/D9RMG119ZRJX.40ww0zz0XwTWbH0PYRkFl3QaMwF3GV.', 'employee', 'EMS II', NULL, '2025-08-26 03:09:18'),
(14, 'Stanley', 'stanley@example.com', '$2y$10$8iQEnimQuMXzHwrMdKMYkuo/xPmRqVV3S1uIYb1m7Se6iiqu5Jhfq', 'employee', 'InsulatedTumbler (IT)', NULL, '2025-08-26 07:19:13'),
(15, 'Alice Smith', 'alice.smith@company.com', NULL, 'driver', 'Driver', '09955665324', '2026-01-08 00:32:59'),
(16, 'Bob Johnson', 'bob.johnson@company.com', NULL, 'driver', 'Driver', '09866886688', '2026-01-08 00:32:59'),
(17, 'Charlie Brown', 'charlie.brown@company.com', NULL, 'driver', 'Driver', '09572315664', '2026-01-08 00:32:59'),
(18, 'Diana Prince', 'diana.prince@company.com', NULL, 'driver', 'Driver', '09213216548', '2026-01-08 00:32:59'),
(19, 'Bailando', 'bailando@example.com', NULL, 'driver', 'Driver', '09455224514', '2026-01-08 00:32:59'),
(20, 'Rogelio', 'rogelio@example.com', NULL, 'driver', 'Driver', '09090900909', '2026-01-08 00:32:59'),
(21, 'El mumbo', 'elmumbo@example.com', NULL, 'driver', 'Driver', '09359878524', '2026-01-08 00:32:59');

-- --------------------------------------------------------

--
-- Table structure for table `vehicles`
--

CREATE TABLE `vehicles` (
  `id` int(11) NOT NULL,
  `plate_number` varchar(20) NOT NULL,
  `driver_id` int(11) DEFAULT NULL,
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

INSERT INTO `vehicles` (`id`, `plate_number`, `driver_id`, `make`, `model`, `type`, `assigned_to`, `status`, `created_at`, `return_date`, `returned_by`) VALUES
(7, 'EMBR2500', NULL, 'Toyota', 'Landcruiser', 'SUV', NULL, 'available', '2025-08-19 04:24:25', NULL, NULL),
(8, 'EMBR9999', NULL, 'Toyota', 'Grandia', 'Van', NULL, 'available', '2025-08-19 04:25:09', NULL, NULL),
(9, 'EMBR9824', NULL, 'Toyota', 'Hi-ace', 'Van', NULL, 'available', '2025-08-19 04:25:37', NULL, NULL),
(10, 'EMBR2143', NULL, 'Toyota', 'Hilux GRS', 'Pick-up', NULL, 'available', '2025-08-19 04:26:14', NULL, NULL),
(11, 'EMBR4321', NULL, 'Porsche', 'GT3RS', 'Sedan', NULL, 'available', '2025-08-19 04:27:16', NULL, NULL),
(12, 'EMBR7864', NULL, 'Nissan', 'Patrol', 'SUV', NULL, 'available', '2025-08-19 04:27:42', NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `requests`
--
ALTER TABLE `requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `fk_assigned_vehicle` (`assigned_vehicle_id`),
  ADD KEY `fk_assigned_driver` (`assigned_driver_id`),
  ADD KEY `idx_request_status` (`status`),
  ADD KEY `idx_assigned_vehicle_status` (`assigned_vehicle_id`, `status`),
  ADD KEY `idx_assigned_driver_status` (`assigned_driver_id`, `status`),
  ADD KEY `idx_request_user_status` (`user_id`, `status`),
  ADD KEY `idx_status_dates` (`status`, `departure_date`, `return_date`),
  ADD FULLTEXT KEY `idx_passenger_names` (`passenger_names`);

--
-- Indexes for table `request_audit_logs`
--
ALTER TABLE `request_audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_request_audit_request_id` (`request_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_user_role` (`role`);

--
-- Indexes for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `plate_number` (`plate_number`),
  ADD KEY `fk_vehicle_driver` (`driver_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `requests`
--
ALTER TABLE `requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `request_audit_logs`
--
ALTER TABLE `request_audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

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
  ADD CONSTRAINT `fk_assigned_driver_user` FOREIGN KEY (`assigned_driver_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_assigned_vehicle` FOREIGN KEY (`assigned_vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `request_audit_logs`
--
ALTER TABLE `request_audit_logs`
  ADD CONSTRAINT `fk_request_audit_request` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD CONSTRAINT `fk_vehicle_driver` FOREIGN KEY (`driver_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
