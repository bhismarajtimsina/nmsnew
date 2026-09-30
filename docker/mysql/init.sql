-- MySQL dump 10.13  Distrib 8.0.25, for Linux (x86_64)
--
-- Host: localhost    Database: support
-- ------------------------------------------------------
-- Server version	8.0.25-0ubuntu0.20.04.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `device_accesses`
--

DROP TABLE IF EXISTS `device_accesses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8 */;
CREATE TABLE `device_accesses` (
                                   `id` int NOT NULL AUTO_INCREMENT,
                                   `name` varchar(50) NOT NULL,
                                   `community` varchar(50) NOT NULL,
                                   `login` varchar(50) DEFAULT NULL,
                                   `password` varchar(50) NOT NULL,
                                   PRIMARY KEY (`id`),
                                   UNIQUE KEY `device_accesses_name_uindex` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `device_accesses`
--

LOCK TABLES `device_accesses` WRITE;
/*!40000 ALTER TABLE `device_accesses` DISABLE KEYS */;
INSERT INTO `device_accesses` VALUES (1,'Access L2','public','billing','billing');
/*!40000 ALTER TABLE `device_accesses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `device_interfaces`
--

DROP TABLE IF EXISTS `device_interfaces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8 */;
CREATE TABLE `device_interfaces` (
                                     `id` int NOT NULL AUTO_INCREMENT,
                                     `created_at` datetime DEFAULT NULL,
                                     `device_id` int NOT NULL,
                                     `name` varchar(255) DEFAULT NULL,
                                     `type` enum('ETH','PON','SFP','ONU','UNI') DEFAULT NULL,
                                     `params` json DEFAULT NULL,
                                     `status` enum('ONLINE','OFFLINE','DISABLED','ERROR','UNKNOWN') NOT NULL,
                                     `billing_link` varchar(255) DEFAULT NULL,
                                     `ip` varchar(15) DEFAULT NULL,
                                     `agreement` varchar(50) DEFAULT NULL,
                                     `description` varchar(255) DEFAULT NULL,
                                     `bind_key` varchar(50) NOT NULL,
                                     `updated_at` datetime NOT NULL,
                                     PRIMARY KEY (`id`),
                                     UNIQUE KEY `device_interfaces_device_id_bind_key_uindex` (`device_id`,`bind_key`),
                                     CONSTRAINT `device_interfaces_devices_id_fk` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `device_models`
--

DROP TABLE IF EXISTS `device_models`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8 */;
CREATE TABLE `device_models` (
                                 `id` int NOT NULL AUTO_INCREMENT,
                                 `key` varchar(150) NOT NULL,
                                 `name` varchar(100) DEFAULT NULL,
                                 `params` json DEFAULT NULL,
                                 `vendor` varchar(50) DEFAULT NULL,
                                 `model` varchar(50) DEFAULT NULL,
                                 `type` enum('SWITCH','OLT','ONU','ROUTER') NOT NULL DEFAULT 'SWITCH',
                                 `icon` varchar(255) DEFAULT NULL,
                                 PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `devices`
--

DROP TABLE IF EXISTS `devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8 */;
CREATE TABLE `devices` (
                           `id` int NOT NULL AUTO_INCREMENT,
                           `ip` varchar(50) NOT NULL,
                           `name` varchar(150) DEFAULT NULL,
                           `description` varchar(255) NOT NULL DEFAULT '',
                           `access_id` int DEFAULT NULL,
                           `model_id` int DEFAULT NULL,
                           `params` json DEFAULT NULL,
                           `created_at` datetime DEFAULT NULL,
                           `updated_at` datetime DEFAULT NULL,
                           `mac` varchar(50) DEFAULT '',
                           `serial` varchar(50) DEFAULT '',
                           `enabled` tinyint NOT NULL DEFAULT '1',
                           PRIMARY KEY (`id`),
                           UNIQUE KEY `devices_ip_uindex` (`ip`),
                           KEY `devices_device_models_id_fk` (`model_id`),
                           KEY `devices_device_accesses_id_fk` (`access_id`),
                           CONSTRAINT `devices_device_accesses_id_fk` FOREIGN KEY (`access_id`) REFERENCES `device_accesses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                           CONSTRAINT `devices_device_models_id_fk` FOREIGN KEY (`model_id`) REFERENCES `device_models` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `switcher_core_actions`
--

DROP TABLE IF EXISTS `switcher_core_actions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8 */;
CREATE TABLE `switcher_core_actions` (
                                         `id` int NOT NULL AUTO_INCREMENT,
                                         `time` datetime NOT NULL,
                                         `user_id` int NOT NULL,
                                         `device_id` int NOT NULL,
                                         `status` enum('SUCCESS','FAILED') NOT NULL DEFAULT 'SUCCESS',
                                         `hash` varchar(50) NOT NULL,
                                         `module` varchar(100) NOT NULL,
                                         `arguments` json DEFAULT NULL,
                                         `data` json NOT NULL,
                                         `meta` json DEFAULT NULL,
                                         PRIMARY KEY (`id`),
                                         KEY `switcher_core_actions_devices_id_fk` (`device_id`),
                                         KEY `switcher_core_actions_users_id_fk` (`user_id`),
                                         KEY `switcher_core_actions_time_index` (`time` DESC),
                                         CONSTRAINT `switcher_core_actions_devices_id_fk` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                                         CONSTRAINT `switcher_core_actions_users_id_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `system_actions`
--

DROP TABLE IF EXISTS `system_actions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8 */;
CREATE TABLE `system_actions` (
                                  `id` int NOT NULL AUTO_INCREMENT,
                                  `created_at` datetime NOT NULL ON UPDATE CURRENT_TIMESTAMP,
                                  `action` varchar(255) NOT NULL,
                                  `user_id` int NOT NULL,
                                  `message` varchar(255) DEFAULT NULL,
                                  `meta` json DEFAULT NULL,
                                  `status` enum('SUCCESS','FAILED') DEFAULT 'SUCCESS',
                                  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `system_components`
--

DROP TABLE IF EXISTS `system_components`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8 */;
CREATE TABLE `system_components` (
                                     `id` int NOT NULL AUTO_INCREMENT,
                                     `name` varchar(100) DEFAULT NULL,
                                     `key` varchar(50) NOT NULL,
                                     `namespace` varchar(50) DEFAULT NULL,
                                     `enabled` tinyint NOT NULL DEFAULT '0',
                                     `built_in` tinyint NOT NULL DEFAULT '0',
                                     `configuration` json DEFAULT NULL,
                                     PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_auth_keys`
--

DROP TABLE IF EXISTS `user_auth_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8 */;
CREATE TABLE `user_auth_keys` (
                                  `id` int NOT NULL AUTO_INCREMENT,
                                  `user_id` int NOT NULL,
                                  `key` varchar(50) NOT NULL,
                                  `created_at` datetime NOT NULL,
                                  `expired_at` datetime NOT NULL,
                                  `remote_addr` varchar(50) DEFAULT NULL,
                                  `user_agent` varchar(255) DEFAULT NULL,
                                  `status` enum('ACTIVE','EXPIRED','CLOSED') NOT NULL DEFAULT 'ACTIVE',
                                  `last_activity` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                                  `device_info` json DEFAULT NULL,
                                  PRIMARY KEY (`id`),
                                  UNIQUE KEY `user_auth_keys_key_uindex` (`key`),
                                  KEY `user_auth_keys_users_id_fk` (`user_id`),
                                  CONSTRAINT `user_auth_keys_users_id_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_groups`
--

DROP TABLE IF EXISTS `user_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8 */;
CREATE TABLE `user_groups` (
                               `id` int NOT NULL AUTO_INCREMENT,
                               `name` varchar(255) DEFAULT NULL,
                               `display` varchar(255) DEFAULT NULL,
                               `permissions` json DEFAULT NULL,
                               `description` varchar(255) NOT NULL DEFAULT '',
                               PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_groups`
--

LOCK TABLES `user_groups` WRITE;
/*!40000 ALTER TABLE `user_groups` DISABLE KEYS */;
INSERT INTO `user_groups`
VALUES (-2,'Owner','1','[]','System owner'),
       (-1,'System','0','[]',''),
       (7,'Installer','1','["system_info", "dashboard_edit", "global_search", "user_self", "device_show", "switches_info", "prom_chart_info", "live_traffic_info", "fdb_history_by_interface", "unregistered_onts", "events_show", "pon_boxes_view", "router_os_info", "user_self_control", "olts_info", "poller_info_by_device", "links_view"]',''),
       (8,'Operator','1','["system_info", "dashboard_edit", "global_search", "user_self", "device_show", "switches_info", "prom_chart_info", "live_traffic_info", "fdb_history_by_interface", "unregistered_onts", "events_show", "pon_boxes_view", "router_os_info", "user_self_control", "olts_info", "poller_info_by_device", "links_view"]','');
/*!40000 ALTER TABLE `user_groups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8 */;
CREATE TABLE `users` (
                         `id` int NOT NULL AUTO_INCREMENT,
                         `login` varchar(50) NOT NULL,
                         `name` varchar(255) NOT NULL,
                         `created_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                         `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                         `group_id` int NOT NULL,
                         `password` varchar(255) DEFAULT NULL,
                         `status` enum('DISABLED','ENABLED') NOT NULL DEFAULT 'ENABLED',
                         `settings` json DEFAULT NULL,
                         PRIMARY KEY (`id`),
                         UNIQUE KEY `users_login_uindex` (`login`),
                         KEY `users_user_groups_id_fk` (`group_id`),
                         CONSTRAINT `users_user_groups_id_fk` FOREIGN KEY (`group_id`) REFERENCES `user_groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (-3,'cron','System events','2021-03-15 23:34:05','2021-03-15 23:34:13',-1,'event','ENABLED',NULL),(-2,'console','console','2021-02-15 22:02:26','2021-02-15 22:02:29',-1,'console','ENABLED',NULL),(-1,'sys','system','2021-02-13 20:38:20','2021-02-13 20:38:20',-1,'system','ENABLED',NULL),(1,'admin','Admin','2021-03-10 13:15:24','2021-03-10 13:15:24',-2,'d033e22ae348aeb5660fc2140aec35850c4da997','ENABLED','{\"lang\": \"ru\"}');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `wca_migrations`
--

DROP TABLE IF EXISTS `wca_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8 */;
CREATE TABLE `wca_migrations` (
                                  `id` int NOT NULL AUTO_INCREMENT,
                                  `created_at` datetime DEFAULT NULL,
                                  `name` varchar(50) NOT NULL,
                                  PRIMARY KEY (`id`),
                                  UNIQUE KEY `wca_migrations_name_uindex` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


-- Dump completed on 2021-05-21 11:54:51
