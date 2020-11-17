-- phpMyAdmin SQL Dump
-- version 5.0.1
-- https://www.phpmyadmin.net/
--
-- Host: aqualounge.c3s5mryiqcoy.us-west-2.rds.amazonaws.com
-- Generation Time: Nov 17, 2020 at 11:09 AM
-- Server version: 5.7.26-log
-- PHP Version: 7.3.13

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `live_feeds_api`
--

-- --------------------------------------------------------

--
-- Table structure for table `feeds_movement_groups_consumption`
--

DROP TABLE IF EXISTS `feeds_movement_groups_consumption`;
CREATE TABLE `feeds_movement_groups_consumption` (
  `id` int(11) NOT NULL,
  `update_date` date DEFAULT '0000-00-00',
  `group_id` int(11) DEFAULT NULL,
  `feed_type` int(11) DEFAULT NULL,
  `consumption` double DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `feeds_movement_groups_consumption`
--
ALTER TABLE `feeds_movement_groups_consumption`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `feeds_movement_groups_consumption`
--
ALTER TABLE `feeds_movement_groups_consumption`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
