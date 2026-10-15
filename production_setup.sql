-- Mawared production schema only.
-- No customer records, employee accounts, passwords, sessions, or activity data are included.
-- Existing tables are left unchanged; this file only creates tables that are missing.
-- It does not update or repair the schema of tables that already exist.
SET NAMES utf8mb4;

/*M!999999\- enable the sandbox mode */ 

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `user_name` varchar(120) DEFAULT NULL,
  `role` varchar(30) DEFAULT NULL,
  `event_type` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `detail` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_type` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `target_type` varchar(100) DEFAULT NULL,
  `target_id` bigint(20) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `calendar_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `assigned_to` int(11) NOT NULL,
  `created_by` int(11) NOT NULL,
  `event_type` varchar(20) NOT NULL DEFAULT 'other',
  `title` varchar(200) NOT NULL,
  `note` varchar(1000) DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  `client_name` varchar(200) DEFAULT NULL,
  `start_at` datetime NOT NULL,
  `all_day` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(12) NOT NULL DEFAULT 'open',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_assigned` (`assigned_to`,`start_at`),
  KEY `idx_created` (`created_by`,`start_at`),
  KEY `idx_start` (`start_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `calls` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `client_id` int(11) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `sheet_name` varchar(100) DEFAULT NULL,
  `status` varchar(100) DEFAULT NULL,
  `ptp` varchar(100) DEFAULT NULL,
  `ptp_date` varchar(50) DEFAULT NULL,
  `dcr` text DEFAULT NULL,
  `called_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_date` (`user_id`,`called_at`),
  KEY `idx_client` (`client_id`),
  KEY `idx_phone` (`phone`),
  KEY `idx_calls_user_date` (`user_id`,`called_at`),
  KEY `idx_calls_client_user` (`client_id`,`user_id`,`called_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `client_reminders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `remind_at` datetime NOT NULL,
  `note` varchar(500) DEFAULT NULL,
  `is_done` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_due` (`user_id`,`is_done`,`remind_at`),
  KEY `idx_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `clients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sheet_name` varchar(100) NOT NULL,
  `excel_row` int(11) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `nid` varchar(20) DEFAULT NULL,
  `loan_number` varchar(50) DEFAULT NULL,
  `client_number` varchar(50) DEFAULT NULL,
  `installment` decimal(12,2) DEFAULT 0.00,
  `delay_days` int(11) DEFAULT 0,
  `interest_fine_balance` decimal(12,2) DEFAULT 0.00,
  `loan_balance` decimal(12,2) DEFAULT 0.00,
  `legal_num` varchar(100) DEFAULT NULL,
  `legal_status` varchar(255) DEFAULT NULL,
  `guarantor_name` varchar(255) DEFAULT NULL,
  `guarantor_phone` varchar(30) DEFAULT NULL,
  `guarantor_relation` varchar(100) DEFAULT NULL,
  `status` varchar(100) DEFAULT NULL,
  `ptp` varchar(100) DEFAULT NULL,
  `ptp_date` varchar(50) DEFAULT NULL,
  `dcr` text DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `owner_user_id` int(11) DEFAULT NULL,
  `cycle_id` int(11) DEFAULT NULL,
  `needs_sync` tinyint(4) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `address` text DEFAULT NULL,
  `landmark` varchar(255) DEFAULT NULL,
  `id_front_image` text DEFAULT NULL,
  `id_back_image` text DEFAULT NULL,
  `coll` varchar(100) DEFAULT NULL,
  `s_v` varchar(100) DEFAULT NULL,
  `keep_field` varchar(100) DEFAULT NULL,
  `execution_date` varchar(50) DEFAULT NULL,
  `branch` varchar(100) DEFAULT NULL,
  `governorate` varchar(100) DEFAULT NULL,
  `visit_flag` varchar(50) DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `client_contact` varchar(50) DEFAULT NULL,
  `paid` varchar(50) DEFAULT NULL,
  `paid_date` varchar(50) DEFAULT NULL,
  `broken` varchar(50) DEFAULT NULL,
  `comment` text DEFAULT NULL,
  `data_field` text DEFAULT NULL,
  `grant_amount` decimal(14,2) DEFAULT 0.00,
  `interest` decimal(14,2) DEFAULT 0.00,
  `fine` decimal(14,2) DEFAULT 0.00,
  `guarantor_card` varchar(50) DEFAULT NULL,
  `guarantor_contact` varchar(50) DEFAULT NULL,
  `guarantor_address` text DEFAULT NULL,
  `guarantor_landmark` varchar(255) DEFAULT NULL,
  `activity_address` text DEFAULT NULL,
  `activity_landmark` varchar(255) DEFAULT NULL,
  `grant_date` varchar(50) DEFAULT NULL,
  `late_installment_date` varchar(50) DEFAULT NULL,
  `portfolio` varchar(50) DEFAULT NULL,
  `finance_officer` varchar(100) DEFAULT NULL,
  `sep_owner` varchar(100) DEFAULT NULL,
  `collection_stage` varchar(40) NOT NULL DEFAULT 'new',
  `priority_level` varchar(20) NOT NULL DEFAULT 'normal',
  `risk_level` varchar(20) NOT NULL DEFAULT 'medium',
  `next_action_at` datetime DEFAULT NULL,
  `last_contact_at` datetime DEFAULT NULL,
  `last_visit_at` datetime DEFAULT NULL,
  `last_activity_at` datetime DEFAULT NULL,
  `ptp_amount` decimal(14,2) DEFAULT NULL,
  `ptp_status` varchar(30) DEFAULT NULL,
  `dcr_code` varchar(50) DEFAULT NULL,
  `collection_state` varchar(40) DEFAULT 'new',
  `submitted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_phone_owner` (`phone`,`owner_user_id`),
  KEY `idx_phone` (`phone`),
  KEY `idx_name` (`name`),
  KEY `idx_assigned` (`assigned_to`),
  KEY `idx_owner` (`owner_user_id`),
  KEY `idx_clients_owner` (`owner_user_id`),
  KEY `idx_clients_assigned` (`assigned_to`),
  KEY `idx_clients_stage` (`collection_stage`,`priority_level`),
  KEY `idx_clients_next_action` (`next_action_at`),
  KEY `idx_clients_last_contact` (`last_contact_at`),
  KEY `idx_clients_guarantor_phone` (`guarantor_phone`),
  KEY `idx_clients_nid` (`nid`),
  KEY `idx_clients_coll` (`coll`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `collection_archive_clients` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `cycle_id` int(11) NOT NULL,
  `original_client_id` int(11) NOT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `snapshot_json` longtext NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_archive_cycle` (`cycle_id`),
  KEY `idx_archive_client` (`original_client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `collection_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cycle_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `assigned_count` int(11) NOT NULL DEFAULT 0,
  `uploaded_count` int(11) NOT NULL DEFAULT 0,
  `status` enum('pending','working','submitted','approved') NOT NULL DEFAULT 'pending',
  `submitted_at` datetime DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cycle_user` (`cycle_id`,`user_id`),
  KEY `idx_assignment_cycle` (`cycle_id`),
  KEY `idx_assignment_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `collection_audit_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `entity_type` varchar(40) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_client` (`client_id`,`created_at`),
  KEY `idx_audit_user` (`user_id`,`created_at`),
  KEY `idx_audit_action` (`action`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `collection_complaints` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `category` varchar(60) NOT NULL,
  `priority` varchar(20) NOT NULL DEFAULT 'normal',
  `status` varchar(30) NOT NULL DEFAULT 'open',
  `subject` varchar(255) NOT NULL,
  `details` text DEFAULT NULL,
  `resolution` text DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_complaint_status` (`status`,`priority`),
  KEY `idx_complaint_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `collection_cycles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(180) NOT NULL,
  `portfolio_name` varchar(180) DEFAULT NULL,
  `month_key` char(7) NOT NULL,
  `status` enum('draft','active','review','closed','archived') NOT NULL DEFAULT 'draft',
  `created_by` int(11) NOT NULL,
  `started_at` datetime DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cycle_month` (`month_key`),
  KEY `idx_cycle_status` (`status`),
  KEY `idx_cycle_creator` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `collection_employee_collections` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `cycle_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_employee_collection_cycle_user` (`cycle_id`,`employee_id`),
  KEY `idx_employee_collection_creator` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `collection_entries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entry_date` date NOT NULL,
  `product_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `note` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_entry_scope` (`admin_id`,`entry_date`),
  KEY `idx_entry_product` (`product_id`,`entry_date`),
  KEY `idx_entry_employee` (`employee_id`,`entry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `collection_events` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `cycle_id` int(11) DEFAULT NULL,
  `client_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `event_type` varchar(40) NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_event_client` (`client_id`),
  KEY `idx_event_cycle` (`cycle_id`),
  KEY `idx_event_user` (`user_id`),
  KEY `idx_event_type` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `collection_legal_cases` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `case_number` varchar(100) DEFAULT NULL,
  `case_type` varchar(80) DEFAULT NULL,
  `court` varchar(160) DEFAULT NULL,
  `status` varchar(60) NOT NULL DEFAULT 'open',
  `filing_date` date DEFAULT NULL,
  `next_hearing_date` date DEFAULT NULL,
  `lawyer_name` varchar(160) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_legal_client` (`client_id`),
  KEY `idx_legal_status` (`status`,`next_hearing_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `collection_notes` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `cycle_id` int(11) DEFAULT NULL,
  `client_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `note_type` varchar(40) NOT NULL DEFAULT 'general',
  `body` text NOT NULL,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_note_client` (`client_id`),
  KEY `idx_note_cycle` (`cycle_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `collection_notifications` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` varchar(40) NOT NULL DEFAULT 'task',
  `title` varchar(255) NOT NULL,
  `body` text DEFAULT NULL,
  `entity_type` varchar(40) DEFAULT NULL,
  `entity_id` bigint(20) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notification_user` (`user_id`,`is_read`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `collection_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `visit_id` int(11) DEFAULT NULL,
  `recorded_by` int(11) NOT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `payment_date` date NOT NULL,
  `method` varchar(40) NOT NULL DEFAULT 'cash',
  `reference_no` varchar(100) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'pending_review',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `rejection_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_payment_client` (`client_id`),
  KEY `idx_payment_date` (`payment_date`),
  KEY `idx_payment_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `collection_portfolios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(160) NOT NULL,
  `client_name` varchar(160) DEFAULT NULL,
  `branch` varchar(120) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'active',
  `received_at` date DEFAULT NULL,
  `target_amount` decimal(16,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_portfolio_status` (`status`),
  KEY `idx_portfolio_client` (`client_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `collection_promises` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `created_by` int(11) NOT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `due_date` date NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'open',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_promise_client` (`client_id`),
  KEY `idx_promise_due` (`status`,`due_date`),
  KEY `idx_promise_creator` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `collection_qa_reviews` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `reviewer_id` int(11) NOT NULL,
  `client_id` int(11) DEFAULT NULL,
  `call_id` int(11) DEFAULT NULL,
  `visit_id` int(11) DEFAULT NULL,
  `score` decimal(5,2) NOT NULL DEFAULT 0.00,
  `result` varchar(30) NOT NULL DEFAULT 'reviewed',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_qa_employee` (`employee_id`,`created_at`),
  KEY `idx_qa_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `collection_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(80) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `collection_status_history` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `old_status` varchar(100) DEFAULT NULL,
  `new_status` varchar(100) DEFAULT NULL,
  `collection_stage` varchar(40) DEFAULT NULL,
  `changed_by` int(11) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_status_client` (`client_id`,`created_at`),
  KEY `idx_status_stage` (`collection_stage`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `collection_targets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `target_cases` int(11) NOT NULL DEFAULT 0,
  `target_contacts` int(11) NOT NULL DEFAULT 0,
  `target_collected` decimal(16,2) NOT NULL DEFAULT 0.00,
  `target_ptp` decimal(16,2) NOT NULL DEFAULT 0.00,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `target_date` date DEFAULT NULL,
  `product_id` int(11) NOT NULL DEFAULT 0,
  `admin_id` int(11) NOT NULL DEFAULT 0,
  `target_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_target` (`user_id`,`period_start`,`period_end`),
  KEY `idx_target_period` (`period_start`,`period_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `collection_tasks` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `task_type` varchar(40) NOT NULL DEFAULT 'follow_up',
  `priority` varchar(20) NOT NULL DEFAULT 'normal',
  `due_at` datetime NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'open',
  `title` varchar(255) NOT NULL,
  `notes` text DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_task_agent` (`assigned_to`,`status`,`due_at`),
  KEY `idx_task_client` (`client_id`,`status`),
  KEY `idx_task_due` (`status`,`due_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `company_product_targets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `target_date` date NOT NULL,
  `product_id` int(11) NOT NULL,
  `target_kind` varchar(10) NOT NULL,
  `target_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_product_target` (`target_date`,`product_id`,`target_kind`),
  KEY `idx_company_target_date` (`target_date`,`target_kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `daily_targets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `target_date` date NOT NULL,
  `calls_target` int(11) NOT NULL DEFAULT 0,
  `visits_target` int(11) NOT NULL DEFAULT 0,
  `set_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_day` (`user_id`,`target_date`),
  KEY `idx_date` (`target_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lookup` (`username`,`ip`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `from_user_id` int(11) NOT NULL,
  `from_name` varchar(120) DEFAULT NULL,
  `to_user_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `body_text` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_to_unread` (`to_user_id`,`is_read`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `product_teams` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `team_name` varchar(120) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prod_admin` (`product_id`,`admin_id`),
  KEY `idx_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `code` varchar(40) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_sess_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `sheet_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `cycle_id` int(11) DEFAULT NULL,
  `action` enum('upload','download') NOT NULL,
  `filename` varchar(255) DEFAULT NULL,
  `client_count` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_date` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `sync_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `direction` enum('import','export') NOT NULL,
  `rows_affected` int(11) DEFAULT 0,
  `status` varchar(50) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `ran_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `from_user_id` int(11) NOT NULL,
  `to_user_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `body` text DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'open',
  `created_at` datetime NOT NULL,
  `done_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_to` (`to_user_id`,`status`),
  KEY `idx_from` (`from_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `user_sheets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(11) DEFAULT 0,
  `client_count` int(11) DEFAULT 0,
  `uploaded_at` timestamp NULL DEFAULT current_timestamp(),
  `last_download_at` datetime DEFAULT NULL,
  `is_active` tinyint(4) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_user_active` (`user_id`,`is_active`),
  CONSTRAINT `fk_sheet_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `user_webhooks` (
  `user_id` int(11) NOT NULL,
  `call_url` varchar(500) DEFAULT NULL,
  `hangup_url` varchar(500) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `method` varchar(10) NOT NULL DEFAULT 'android',
  `shortcut_name` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `user_webhooks_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `role` enum('super_admin','admin','collector','data_entry') NOT NULL DEFAULT 'collector',
  `phone` varchar(20) DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `visits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `collector_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `visit_date` date DEFAULT NULL,
  `visited_at` datetime DEFAULT NULL,
  `status` varchar(50) DEFAULT 'pending',
  `collected_amount` decimal(12,2) DEFAULT 0.00,
  `collector_notes` text DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `checkin_at` datetime DEFAULT NULL,
  `checkout_at` datetime DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `outcome` varchar(60) DEFAULT NULL,
  `next_action_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_col_date` (`collector_id`,`visit_date`),
  KEY `idx_visits_collector_date` (`collector_id`,`visit_date`),
  KEY `idx_visits_client` (`client_id`),
  KEY `idx_visits_date` (`visit_date`),
  KEY `idx_visits_created` (`created_by`,`visit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;
