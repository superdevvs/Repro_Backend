-- MySQL baseline for the SQLite-to-MySQL migration rehearsal.
-- Contains schema and applied migration names only; no application/customer rows.
-- Existing SQLite databases continue to use the normal PHP migration history.

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `account_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_links` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `main_account_id` bigint NOT NULL,
  `linked_account_id` bigint NOT NULL,
  `shared_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'{}'),
  `notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'active',
  `linked_at` datetime(6) DEFAULT NULL,
  `unlinked_at` datetime(6) DEFAULT NULL,
  `created_by` bigint NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_account_link` (`main_account_id`,`linked_account_id`),
  KEY `account_links_status_index` (`status`),
  KEY `account_links_linked_account_id_index` (`linked_account_id`),
  KEY `account_links_main_account_id_index` (`main_account_id`),
  KEY `fk_account_links_0_496467e1f63c` (`created_by`),
  CONSTRAINT `fk_account_links_0_496467e1f63c` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_account_links_1_6febd0864d67` FOREIGN KEY (`linked_account_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_account_links_2_a108704013c9` FOREIGN KEY (`main_account_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_account_links_0_496467e1f63c` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'inactive',_utf8mb4'suspended')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `accounting_expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounting_expenses` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `category` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'General',
  `description` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `amount` decimal(65,30) NOT NULL DEFAULT '0.000000000000000000000000000000',
  `expense_date` date NOT NULL,
  `vendor` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'unreviewed'),
  `reimbursable` bigint NOT NULL DEFAULT '0',
  `notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `related_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `related_id` bigint DEFAULT NULL,
  `created_by` bigint DEFAULT NULL,
  `receipt_disk` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `receipt_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `receipt_original_name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `receipt_mime_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `receipt_size` bigint DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `accounting_expenses_related_type_related_id_index` (`related_type`,`related_id`),
  KEY `accounting_expenses_category_expense_date_index` (`category`,`expense_date`),
  KEY `fk_accounting_expenses_0_62afb95e7564` (`created_by`),
  CONSTRAINT `fk_accounting_expenses_0_62afb95e7564` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_chat_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_chat_sessions` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `title` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `topic` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'general',
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `engine` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'rules'),
  `intent` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `state_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `step` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `channel` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'WEB',
  `phone_e164` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `contact_id` bigint DEFAULT NULL,
  `last_inbound_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ai_chat_sessions_contact_id_index` (`contact_id`),
  KEY `ai_chat_sessions_phone_e164_index` (`phone_e164`),
  KEY `ai_chat_sessions_channel_index` (`channel`),
  KEY `ai_chat_sessions_created_at_index` (`created_at`),
  KEY `ai_chat_sessions_topic_index` (`topic`),
  KEY `ai_chat_sessions_user_id_index` (`user_id`),
  CONSTRAINT `fk_ai_chat_sessions_0_bd5e68ffcff6` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_ai_chat_sessions_0_bd5e68ffcff6` CHECK ((`topic` in (_utf8mb4'booking',_utf8mb4'listing',_utf8mb4'insight',_utf8mb4'general')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_editing_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_editing_jobs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint DEFAULT NULL,
  `shoot_file_id` bigint DEFAULT NULL,
  `user_id` bigint NOT NULL,
  `fotello_job_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'pending',
  `editing_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `editing_params` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `original_image_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `edited_image_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `error_message` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `retry_count` bigint NOT NULL DEFAULT '0',
  `started_at` datetime(6) DEFAULT NULL,
  `completed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `provider` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'autoenhance'),
  `provider_job_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `provider_order_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `autoenhance_image_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `provider_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `provider_result` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `project_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `request_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ai_editing_jobs_fotello_job_id_unique` (`fotello_job_id`),
  KEY `ai_editing_jobs_request_id_index` (`request_id`),
  KEY `ai_editing_jobs_user_id_index` (`user_id`),
  KEY `ai_editing_jobs_status_index` (`status`),
  KEY `ai_editing_jobs_shoot_id_status_index` (`shoot_id`,`status`),
  KEY `ai_editing_jobs_provider_job_id_index` (`provider_job_id`),
  KEY `ai_editing_jobs_fotello_job_id_index` (`fotello_job_id`),
  KEY `ai_editing_jobs_autoenhance_image_id_index` (`autoenhance_image_id`),
  KEY `fk_ai_editing_jobs_0_8aadde8c8922` (`project_id`),
  KEY `fk_ai_editing_jobs_2_3d8740003902` (`shoot_file_id`),
  CONSTRAINT `fk_ai_editing_jobs_0_8aadde8c8922` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ai_editing_jobs_1_09e1bb6d8c14` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_editing_jobs_2_3d8740003902` FOREIGN KEY (`shoot_file_id`) REFERENCES `shoot_files` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ai_editing_jobs_3_44f021e72fd4` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_bin */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_cascade_delete_ai_editing_jobs` BEFORE DELETE ON `ai_editing_jobs` FOR EACH ROW BEGIN
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_files.UPDATE' FROM `shoot_files` c0 JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c0.`ai_editing_job_id` = OLD.`id`;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `ai_listing_video_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_listing_video_jobs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint DEFAULT NULL,
  `user_id` bigint NOT NULL,
  `provider` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'fal'),
  `selected_file_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `target_seconds` bigint NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'queued',
  `total_clips` bigint NOT NULL DEFAULT '0',
  `completed_clips` bigint NOT NULL DEFAULT '0',
  `outputs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `provider_request_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `estimated_cost` decimal(65,30) NOT NULL DEFAULT '0.000000000000000000000000000000',
  `error_message` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `started_at` datetime(6) DEFAULT NULL,
  `completed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `project_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `request_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `source_media_refs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `workflow_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `brand_state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  PRIMARY KEY (`id`),
  KEY `ai_listing_video_jobs_user_id_index` (`user_id`),
  KEY `ai_listing_video_jobs_status_index` (`status`),
  KEY `ai_listing_video_jobs_shoot_id_status_index` (`shoot_id`,`status`),
  KEY `ai_listing_video_jobs_request_id_index` (`request_id`),
  KEY `fk_ai_listing_video_jobs_2_4bad6dc8df08` (`project_id`),
  CONSTRAINT `fk_ai_listing_video_jobs_0_c5ca9b3f3876` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_listing_video_jobs_1_2156abcd45d0` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_listing_video_jobs_2_4bad6dc8df08` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_messages` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `chat_session_id` bigint NOT NULL,
  `sender` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'user',
  `content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ai_messages_created_at_index` (`created_at`),
  KEY `ai_messages_sender_index` (`sender`),
  KEY `ai_messages_chat_session_id_index` (`chat_session_id`),
  CONSTRAINT `fk_ai_messages_0_fdccb3d0b36e` FOREIGN KEY (`chat_session_id`) REFERENCES `ai_chat_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_ai_messages_0_fdccb3d0b36e` CHECK ((`sender` in (_utf8mb4'user',_utf8mb4'assistant',_utf8mb4'system')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_provider_usage`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_provider_usage` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `occurred_at` datetime(6) NOT NULL,
  `provider` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'openai'),
  `feature` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `model` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `endpoint` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'metered',
  `calls` bigint NOT NULL DEFAULT '1',
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'pending'),
  `request_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `http_status` bigint DEFAULT NULL,
  `input_tokens` bigint DEFAULT NULL,
  `cached_tokens` bigint DEFAULT NULL,
  `output_tokens` bigint DEFAULT NULL,
  `estimated_cost_usd` decimal(65,30) DEFAULT NULL,
  `pricing_version` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `duration_ms` bigint DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ai_provider_usage_occurred_at_index` (`occurred_at`),
  KEY `ai_provider_usage_source_occurred_at_index` (`source`,`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_reel_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_reel_jobs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint DEFAULT NULL,
  `user_id` bigint NOT NULL,
  `provider` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'fal'),
  `selected_file_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'queued',
  `outputs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `error_message` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `started_at` datetime(6) DEFAULT NULL,
  `completed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `project_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `request_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `source_media_refs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `workflow_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `brand_state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  PRIMARY KEY (`id`),
  KEY `ai_reel_jobs_request_id_index` (`request_id`),
  KEY `ai_reel_jobs_user_id_index` (`user_id`),
  KEY `ai_reel_jobs_status_index` (`status`),
  KEY `ai_reel_jobs_shoot_id_status_index` (`shoot_id`,`status`),
  KEY `fk_ai_reel_jobs_0_5eb8f97bedd2` (`project_id`),
  CONSTRAINT `fk_ai_reel_jobs_0_5eb8f97bedd2` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ai_reel_jobs_1_dbbc2030e249` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_reel_jobs_2_f48a40b18af8` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_video_generation_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_video_generation_jobs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `preset_id` bigint NOT NULL,
  `start_frame_file_id` bigint DEFAULT NULL,
  `end_frame_file_id` bigint DEFAULT NULL,
  `preset_prompt` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `aspect_ratio` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'horizontal'),
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'pending',
  `higgsfield_video_request_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `original_start_frame_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `original_end_frame_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `selected_start_frame_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `selected_end_frame_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `video_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `video_thumbnail_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `error_message` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `retry_count` bigint NOT NULL DEFAULT '0',
  `started_at` datetime(6) DEFAULT NULL,
  `completed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ai_video_generation_jobs_status_index` (`status`),
  KEY `ai_video_generation_jobs_user_id_index` (`user_id`),
  KEY `ai_video_generation_jobs_shoot_id_status_index` (`shoot_id`,`status`),
  KEY `fk_ai_video_generation_jobs_0_e41ebe3285a0` (`end_frame_file_id`),
  KEY `fk_ai_video_generation_jobs_1_7677d1b3d567` (`start_frame_file_id`),
  KEY `fk_ai_video_generation_jobs_2_ec0c563f9b27` (`preset_id`),
  CONSTRAINT `fk_ai_video_generation_jobs_0_e41ebe3285a0` FOREIGN KEY (`end_frame_file_id`) REFERENCES `shoot_files` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ai_video_generation_jobs_1_7677d1b3d567` FOREIGN KEY (`start_frame_file_id`) REFERENCES `shoot_files` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ai_video_generation_jobs_2_ec0c563f9b27` FOREIGN KEY (`preset_id`) REFERENCES `ai_video_presets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_video_generation_jobs_3_1b5a76839d5b` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_video_generation_jobs_4_586804c1e6a2` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_video_generation_jobs_backup`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_video_generation_jobs_backup` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `preset_id` bigint NOT NULL,
  `start_frame_file_id` bigint DEFAULT NULL,
  `end_frame_file_id` bigint DEFAULT NULL,
  `preset_prompt` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `aspect_ratio` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'pending'),
  `higgsfield_video_request_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `original_start_frame_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `original_end_frame_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `selected_start_frame_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `selected_end_frame_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `video_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `video_thumbnail_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `error_message` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `retry_count` bigint NOT NULL DEFAULT '0',
  `started_at` datetime(6) DEFAULT NULL,
  `completed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_ai_video_generation_jobs_backup_0_a003173e80a1` (`end_frame_file_id`),
  KEY `fk_ai_video_generation_jobs_backup_1_875b9c1d5587` (`start_frame_file_id`),
  KEY `fk_ai_video_generation_jobs_backup_2_1c3356ef0cca` (`preset_id`),
  KEY `fk_ai_video_generation_jobs_backup_3_e634b796b82b` (`user_id`),
  KEY `fk_ai_video_generation_jobs_backup_4_98943d8539c8` (`shoot_id`),
  CONSTRAINT `fk_ai_video_generation_jobs_backup_0_a003173e80a1` FOREIGN KEY (`end_frame_file_id`) REFERENCES `shoot_files` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ai_video_generation_jobs_backup_1_875b9c1d5587` FOREIGN KEY (`start_frame_file_id`) REFERENCES `shoot_files` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ai_video_generation_jobs_backup_2_1c3356ef0cca` FOREIGN KEY (`preset_id`) REFERENCES `ai_video_presets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_video_generation_jobs_backup_3_e634b796b82b` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_video_generation_jobs_backup_4_98943d8539c8` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_ai_video_generation_jobs_backup_0_a003173e80a1` CHECK ((`aspect_ratio` in (_utf8mb4'horizontal',_utf8mb4'vertical'))),
  CONSTRAINT `ck_ai_video_generation_jobs_backup_1_875b9c1d5587` CHECK ((`status` in (_utf8mb4'pending',_utf8mb4'converting_aspect',_utf8mb4'awaiting_approval',_utf8mb4'generating',_utf8mb4'completed',_utf8mb4'failed',_utf8mb4'cancelled')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_video_presets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_video_presets` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `description` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `icon` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'Film'),
  `category` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'Lighting',
  `prompt_template` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `max_frames` bigint NOT NULL DEFAULT '2',
  `is_active` bigint NOT NULL DEFAULT '1',
  `sort_order` bigint NOT NULL DEFAULT '0',
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ai_video_presets_slug_unique` (`slug`),
  KEY `ai_video_presets_category_index` (`category`),
  KEY `ai_video_presets_is_active_index` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_video_vertical_variants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_video_vertical_variants` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `video_generation_job_id` bigint NOT NULL,
  `frame_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'start',
  `variant_index` bigint NOT NULL DEFAULT '0',
  `higgsfield_request_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'pending'),
  `image_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `is_selected` bigint NOT NULL DEFAULT '0',
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ai_video_vertical_variants_video_generation_j_d320863a3a30` (`video_generation_job_id`,`frame_type`),
  CONSTRAINT `fk_ai_video_vertical_variants_0_2bc56ced80fa` FOREIGN KEY (`video_generation_job_id`) REFERENCES `ai_video_generation_jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `aryeo_changes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `aryeo_changes` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `client_id` bigint DEFAULT NULL,
  `kind` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `aryeo_changes_client_id_index` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `aryeo_connections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `aryeo_connections` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `company_key` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `client_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `token_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `enabled` bigint NOT NULL DEFAULT '1',
  `processing_enabled` bigint NOT NULL DEFAULT '0',
  `delivery_shoot_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `last_seen_at` datetime(6) DEFAULT NULL,
  `capabilities` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `discover_all_clients` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `aryeo_connections_token_hash_unique` (`token_hash`),
  UNIQUE KEY `aryeo_connections_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `aryeo_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `aryeo_jobs` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `request_record_id` bigint NOT NULL,
  `connection_id` bigint NOT NULL,
  `media_version` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `operation_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'queued'),
  `snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `steps` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `receipt` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `error` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `lease_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `claim_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `lease_expires_at` datetime(6) DEFAULT NULL,
  `attempts` bigint NOT NULL DEFAULT '0',
  `created_by` bigint NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `progress` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  PRIMARY KEY (`id`),
  UNIQUE KEY `aryeo_jobs_operation_key_unique` (`operation_key`),
  KEY `fk_aryeo_jobs_0_fbc02ed66dc7` (`connection_id`),
  KEY `fk_aryeo_jobs_1_cad48db2626e` (`request_record_id`),
  CONSTRAINT `fk_aryeo_jobs_0_fbc02ed66dc7` FOREIGN KEY (`connection_id`) REFERENCES `aryeo_connections` (`id`),
  CONSTRAINT `fk_aryeo_jobs_1_cad48db2626e` FOREIGN KEY (`request_record_id`) REFERENCES `aryeo_requests` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `aryeo_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `aryeo_requests` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `connection_id` bigint NOT NULL,
  `source_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `request_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `listing_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `shoot_id` bigint DEFAULT NULL,
  `unit_id` bigint DEFAULT NULL,
  `match_status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'unmatched'),
  `discovery` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `inventory` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `inventory_checked_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `match_verification` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  PRIMARY KEY (`id`),
  UNIQUE KEY `aryeo_requests_connection_id_source_id_unique` (`connection_id`,`source_id`),
  UNIQUE KEY `aryeo_requests_connection_id_request_id_unique` (`connection_id`,`request_id`),
  KEY `aryeo_requests_shoot_id_index` (`shoot_id`),
  CONSTRAINT `fk_aryeo_requests_0_7a0e457db313` FOREIGN KEY (`connection_id`) REFERENCES `aryeo_connections` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `auth_security_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `auth_security_limits` (
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `attempts` bigint NOT NULL DEFAULT '0',
  `expires_at` bigint NOT NULL,
  `reported` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`key`),
  KEY `auth_security_limits_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `auth_security_rollouts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `auth_security_rollouts` (
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `started_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `automation_dispatches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_dispatches` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `automation_rule_id` bigint NOT NULL,
  `message_id` bigint DEFAULT NULL,
  `trigger_event` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `trigger_context_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'PENDING'),
  `scheduled_at` datetime(6) DEFAULT NULL,
  `sent_at` datetime(6) DEFAULT NULL,
  `error_message` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `trigger_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `period_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `scheduled_for` datetime(6) DEFAULT NULL,
  `command` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `output` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `started_at` datetime(6) DEFAULT NULL,
  `completed_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `automation_dispatches_trigger_schedule_idx` (`trigger_type`,`scheduled_for`),
  KEY `automation_dispatches_rule_period_idx` (`automation_rule_id`,`period_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `automation_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_rules` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `description` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `trigger_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `is_active` bigint NOT NULL DEFAULT '1',
  `scope` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'GLOBAL'),
  `owner_id` bigint DEFAULT NULL,
  `template_id` bigint DEFAULT NULL,
  `channel_id` bigint DEFAULT NULL,
  `condition_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `schedule_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `recipients_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_by` bigint DEFAULT NULL,
  `updated_by` bigint DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `editor_mode` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'visual'),
  `engine_version` bigint NOT NULL DEFAULT '2',
  `workflow_definition_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `entry_trigger_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `is_system_locked` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `automation_run_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_run_steps` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `automation_run_id` bigint NOT NULL,
  `automation_rule_id` bigint DEFAULT NULL,
  `node_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `node_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'pending',
  `attempt_count` bigint NOT NULL DEFAULT '0',
  `scheduled_for` datetime(6) DEFAULT NULL,
  `started_at` datetime(6) DEFAULT NULL,
  `completed_at` datetime(6) DEFAULT NULL,
  `input_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `output_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `error_message` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `automation_run_steps_node_id_node_type_index` (`node_id`,`node_type`),
  KEY `automation_run_steps_scheduled_for_status_index` (`scheduled_for`,`status`),
  KEY `automation_run_steps_automation_run_id_status_index` (`automation_run_id`,`status`),
  KEY `fk_automation_run_steps_0_7730a81225d1` (`automation_rule_id`),
  CONSTRAINT `fk_automation_run_steps_0_7730a81225d1` FOREIGN KEY (`automation_rule_id`) REFERENCES `automation_rules` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_automation_run_steps_1_1da36fafad80` FOREIGN KEY (`automation_run_id`) REFERENCES `automation_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `automation_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_runs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `automation_rule_id` bigint NOT NULL,
  `trigger_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'pending',
  `context_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `related_shoot_id` bigint DEFAULT NULL,
  `related_account_id` bigint DEFAULT NULL,
  `related_invoice_id` bigint DEFAULT NULL,
  `scheduled_for` datetime(6) DEFAULT NULL,
  `started_at` datetime(6) DEFAULT NULL,
  `completed_at` datetime(6) DEFAULT NULL,
  `error_message` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `automation_runs_scheduled_for_status_index` (`scheduled_for`,`status`),
  KEY `automation_runs_trigger_type_created_at_index` (`trigger_type`,`created_at`),
  KEY `automation_runs_automation_rule_id_status_index` (`automation_rule_id`,`status`),
  CONSTRAINT `fk_automation_runs_0_b0064def4a03` FOREIGN KEY (`automation_rule_id`) REFERENCES `automation_rules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `brand_state`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `brand_state` (
  `team_id` bigint NOT NULL AUTO_INCREMENT,
  `created_by` bigint NOT NULL,
  `updated_by` bigint NOT NULL,
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `version` bigint NOT NULL DEFAULT '1',
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`team_id`),
  KEY `fk_brand_state_0_40a2f5858370` (`updated_by`),
  KEY `fk_brand_state_1_5eda05e24650` (`created_by`),
  CONSTRAINT `fk_brand_state_0_40a2f5858370` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_brand_state_1_5eda05e24650` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `owner` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `icon` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `is_default` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `categories_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_bin */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_cascade_delete_categories` BEFORE DELETE ON `categories` FOR EACH ROW BEGIN
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT c0.id,c0.client_id,'shoots.DELETE' FROM `shoots` c0 JOIN `services` c1 ON c0.`service_id` = c1.`id` WHERE c1.`category_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_service.DELETE' FROM `shoot_service` c0 JOIN `shoots` c1 ON c0.`shoot_id` = c1.`id` JOIN `services` c2 ON c1.`service_id` = c2.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c2.`category_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_service.DELETE' FROM `shoot_service` c0 JOIN `services` c1 ON c0.`service_id` = c1.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c1.`category_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_units.DELETE' FROM `shoot_units` c0 JOIN `shoots` c1 ON c0.`shoot_id` = c1.`id` JOIN `services` c2 ON c1.`service_id` = c2.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c2.`category_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_files.UPDATE' FROM `shoot_files` c0 JOIN `shoot_raw_upload_batches` c1 ON c0.`raw_upload_batch_id` = c1.`id` JOIN `shoots` c2 ON c1.`shoot_id` = c2.`id` JOIN `services` c3 ON c2.`service_id` = c3.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c3.`category_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_files.UPDATE' FROM `shoot_files` c0 JOIN `ai_editing_jobs` c1 ON c0.`ai_editing_job_id` = c1.`id` JOIN `shoots` c2 ON c1.`shoot_id` = c2.`id` JOIN `services` c3 ON c2.`service_id` = c3.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c3.`category_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_files.UPDATE' FROM `shoot_files` c0 JOIN `shoot_media_albums` c1 ON c0.`album_id` = c1.`id` JOIN `shoots` c2 ON c1.`shoot_id` = c2.`id` JOIN `services` c3 ON c2.`service_id` = c3.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c3.`category_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_files.DELETE' FROM `shoot_files` c0 JOIN `shoots` c1 ON c0.`shoot_id` = c1.`id` JOIN `services` c2 ON c1.`service_id` = c2.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c2.`category_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_files.UPDATE' FROM `shoot_files` c0 JOIN `shoot_service` c1 ON c0.`shoot_service_id` = c1.`id` JOIN `shoots` c2 ON c1.`shoot_id` = c2.`id` JOIN `services` c3 ON c2.`service_id` = c3.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c3.`category_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_files.UPDATE' FROM `shoot_files` c0 JOIN `shoot_service` c1 ON c0.`shoot_service_id` = c1.`id` JOIN `services` c2 ON c1.`service_id` = c2.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c2.`category_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'payments.DELETE' FROM `payments` c0 JOIN `shoots` c1 ON c0.`shoot_id` = c1.`id` JOIN `services` c2 ON c1.`service_id` = c2.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c2.`category_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'payment.status' FROM `payments` c0 JOIN `shoots` c1 ON c0.`shoot_id` = c1.`id` JOIN `services` c2 ON c1.`service_id` = c2.`id` JOIN payment_service_allocations feed_a ON feed_a.payment_id=c0.id JOIN shoot_service feed_ss ON feed_ss.id=feed_a.shoot_service_id JOIN shoots feed_s ON feed_s.id=feed_ss.shoot_id WHERE c2.`category_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'allocations.DELETE' FROM `payment_service_allocations` c0 JOIN `shoot_service` c1 ON c0.`shoot_service_id` = c1.`id` JOIN `shoots` c2 ON c1.`shoot_id` = c2.`id` JOIN `services` c3 ON c2.`service_id` = c3.`id` JOIN shoot_service feed_ss ON feed_ss.id=c0.shoot_service_id JOIN shoots feed_s ON feed_s.id=feed_ss.shoot_id WHERE c3.`category_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'allocations.DELETE' FROM `payment_service_allocations` c0 JOIN `shoot_service` c1 ON c0.`shoot_service_id` = c1.`id` JOIN `services` c2 ON c1.`service_id` = c2.`id` JOIN shoot_service feed_ss ON feed_ss.id=c0.shoot_service_id JOIN shoots feed_s ON feed_s.id=feed_ss.shoot_id WHERE c2.`category_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'allocations.DELETE' FROM `payment_service_allocations` c0 JOIN `payments` c1 ON c0.`payment_id` = c1.`id` JOIN `shoots` c2 ON c1.`shoot_id` = c2.`id` JOIN `services` c3 ON c2.`service_id` = c3.`id` JOIN shoot_service feed_ss ON feed_ss.id=c0.shoot_service_id JOIN shoots feed_s ON feed_s.id=feed_ss.shoot_id WHERE c3.`category_id` = OLD.`id`;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `client_delivery_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_delivery_notifications` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `shoot_id` bigint NOT NULL,
  `delivery_event_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `delivered_at` datetime(6) NOT NULL,
  `seen_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_delivery_event_unique` (`user_id`,`delivery_event_key`),
  KEY `client_delivery_unseen_index` (`user_id`,`seen_at`,`delivered_at`),
  KEY `fk_client_delivery_notifications_0_82837e387a39` (`shoot_id`),
  CONSTRAINT `fk_client_delivery_notifications_0_82837e387a39` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_client_delivery_notifications_1_d64d22f74ae3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_email_verification_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_email_verification_tokens` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `email_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `email_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `token_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `expires_at` datetime(6) DEFAULT NULL,
  `used_at` datetime(6) DEFAULT NULL,
  `superseded_at` datetime(6) DEFAULT NULL,
  `issued_by` bigint DEFAULT NULL,
  `issued_context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'system'),
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_email_verification_tokens_expires_at_index` (`expires_at`),
  KEY `client_email_verification_tokens_user_id_token_hash_index` (`user_id`,`token_hash`),
  KEY `client_email_verification_tokens_user_id_email_hash_index` (`user_id`,`email_hash`),
  KEY `fk_client_email_verification_tokens_0_70dbbe0de36b` (`issued_by`),
  CONSTRAINT `fk_client_email_verification_tokens_0_70dbbe0de36b` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_client_email_verification_tokens_1_869014852e0a` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `comp_reshoot_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `comp_reshoot_items` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `shoot_service_id` bigint DEFAULT NULL,
  `source_shoot_service_id` bigint DEFAULT NULL,
  `service_id_snapshot` bigint NOT NULL,
  `service_name_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `source_service_id_snapshot` bigint DEFAULT NULL,
  `source_service_name_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `nominal_unit_price_snapshot` decimal(65,30) NOT NULL DEFAULT '0.000000000000000000000000000000',
  `quantity_snapshot` bigint NOT NULL DEFAULT '1',
  `nominal_total_snapshot` decimal(65,30) NOT NULL DEFAULT '0.000000000000000000000000000000',
  `reason_code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `reason_note` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `responsibility` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `responsible_staff_id` bigint DEFAULT NULL,
  `created_by` bigint DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `comp_reshoot_items_child_service_unique` (`shoot_service_id`),
  KEY `comp_reshoot_items_source_index` (`source_shoot_service_id`),
  KEY `comp_reshoot_items_shoot_reason_index` (`shoot_id`,`reason_code`),
  KEY `fk_comp_reshoot_items_0_57822bce16ed` (`created_by`),
  KEY `fk_comp_reshoot_items_1_28627b1ee27a` (`responsible_staff_id`),
  CONSTRAINT `fk_comp_reshoot_items_0_57822bce16ed` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_comp_reshoot_items_1_28627b1ee27a` FOREIGN KEY (`responsible_staff_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_comp_reshoot_items_2_a0473b197cfa` FOREIGN KEY (`source_shoot_service_id`) REFERENCES `shoot_service` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_comp_reshoot_items_3_ce05243f579b` FOREIGN KEY (`shoot_service_id`) REFERENCES `shoot_service` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_comp_reshoot_items_4_fe64edaadba3` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_submissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_submissions` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `client_id` bigint DEFAULT NULL,
  `sender_name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `sender_email` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `sender_phone` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `message` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `source` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'client_portfolio'),
  `is_read` bigint NOT NULL DEFAULT '0',
  `read_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_contact_submissions_0_c3da6bb201ff` (`client_id`),
  CONSTRAINT `fk_contact_submissions_0_c3da6bb201ff` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contacts` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'other'),
  `user_id` bigint DEFAULT NULL,
  `account_id` bigint DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `sms_opt_out` bigint NOT NULL DEFAULT '0',
  `sms_opt_out_at` datetime(6) DEFAULT NULL,
  `sms_ai_enabled` bigint NOT NULL DEFAULT '0',
  `phones_json` json DEFAULT NULL,
  `tags_json` json DEFAULT NULL,
  `comment` text COLLATE utf8mb4_0900_bin,
  PRIMARY KEY (`id`),
  KEY `contacts_phone_index` (`phone`),
  KEY `contacts_email_index` (`email`),
  KEY `fk_contacts_0_943bf24ff224` (`user_id`),
  CONSTRAINT `fk_contacts_0_943bf24ff224` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `copilot_auth_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `copilot_auth_requests` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `client_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `user_id` bigint DEFAULT NULL,
  `redirect_uri` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `resource` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `scopes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `challenge` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `code_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `expires_at` datetime(6) NOT NULL,
  `consented_at` datetime(6) DEFAULT NULL,
  `used_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `copilot_auth_requests_code_hash_unique` (`code_hash`),
  KEY `copilot_auth_requests_client_id_index` (`client_id`),
  KEY `fk_copilot_auth_requests_0_5776bdf78047` (`user_id`),
  CONSTRAINT `fk_copilot_auth_requests_0_5776bdf78047` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `copilot_clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `copilot_clients` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `redirect_uris` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `copilot_drafts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `copilot_drafts` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `user_id` bigint NOT NULL,
  `grant_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `kind` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `review` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `review_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `record_revision` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'prepared'),
  `result` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `expires_at` datetime(6) NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `copilot_drafts_grant_id_index` (`grant_id`),
  KEY `fk_copilot_drafts_0_f2064cef9f35` (`user_id`),
  CONSTRAINT `fk_copilot_drafts_0_f2064cef9f35` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `copilot_grants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `copilot_grants` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `user_id` bigint NOT NULL,
  `client_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `scopes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `resource` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `revoked_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `copilot_grants_client_id_index` (`client_id`),
  KEY `fk_copilot_grants_0_3e9494e4b94e` (`user_id`),
  CONSTRAINT `fk_copilot_grants_0_3e9494e4b94e` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `copilot_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `copilot_tokens` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `grant_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `access_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `refresh_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `expires_at` datetime(6) NOT NULL,
  `refresh_expires_at` datetime(6) NOT NULL,
  `refresh_used_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `copilot_tokens_refresh_hash_unique` (`refresh_hash`),
  UNIQUE KEY `copilot_tokens_access_hash_unique` (`access_hash`),
  KEY `copilot_tokens_grant_id_index` (`grant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `copilot_watch_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `copilot_watch_events` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `user_id` bigint NOT NULL,
  `grant_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `shoot_id` bigint NOT NULL,
  `snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `created_at` datetime(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `copilot_watch_events_grant_id_index` (`grant_id`),
  KEY `copilot_watch_events_user_id_created_at_index` (`user_id`,`created_at`),
  KEY `fk_copilot_watch_events_0_5c3e8d66df6f` (`shoot_id`),
  CONSTRAINT `fk_copilot_watch_events_0_5c3e8d66df6f` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_copilot_watch_events_1_0b98c62c8e4c` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `copilot_watches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `copilot_watches` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `user_id` bigint NOT NULL,
  `grant_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `shoot_id` bigint NOT NULL,
  `enabled` bigint NOT NULL DEFAULT '1',
  `last_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `last_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `last_checked_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `copilot_watches_grant_id_shoot_id_unique` (`grant_id`,`shoot_id`),
  KEY `copilot_watches_grant_id_index` (`grant_id`),
  KEY `fk_copilot_watches_0_7c3684575e81` (`shoot_id`),
  KEY `fk_copilot_watches_1_3cb692f9eaea` (`user_id`),
  CONSTRAINT `fk_copilot_watches_0_7c3684575e81` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_copilot_watches_1_3cb692f9eaea` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `coupons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `coupons` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `amount` decimal(65,30) NOT NULL,
  `max_uses` bigint DEFAULT NULL,
  `current_uses` bigint NOT NULL DEFAULT '0',
  `is_active` bigint NOT NULL DEFAULT '1',
  `valid_until` date DEFAULT NULL,
  `created_by` bigint DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `coupons_code_unique` (`code`),
  KEY `fk_coupons_0_c740e9477dfd` (`created_by`),
  CONSTRAINT `fk_coupons_0_c740e9477dfd` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ck_coupons_0_c740e9477dfd` CHECK ((`type` in (_utf8mb4'percentage',_utf8mb4'fixed')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `digital_assignment_correction_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `digital_assignment_correction_snapshots` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `source_table` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `source_id` bigint NOT NULL,
  `before_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `applied_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `identity_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `applied_at` datetime(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_digital_assignment_correction_snapshots_sourc_cd9f5a8a1eb7` (`source_table`,`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dropbox_folders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dropbox_folders` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `folder_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `dropbox_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `dropbox_folder_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `service_category` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  PRIMARY KEY (`id`),
  KEY `fk_dropbox_folders_0_64bc5dee9e77` (`shoot_id`),
  CONSTRAINT `fk_dropbox_folders_0_64bc5dee9e77` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `edit_helper_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `edit_helper_devices` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `user_id` bigint NOT NULL,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `platform` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `credential_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `photoshop_detected` bigint NOT NULL DEFAULT '0',
  `auto_upload` bigint NOT NULL DEFAULT '0',
  `expires_at` datetime(6) NOT NULL,
  `revoked_at` datetime(6) DEFAULT NULL,
  `last_seen_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_edit_helper_devices_0_130578b70796` (`user_id`),
  CONSTRAINT `fk_edit_helper_devices_0_130578b70796` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `edit_helper_pairings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `edit_helper_pairings` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `user_id` bigint NOT NULL,
  `claim_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `platform` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `comparison_code` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `device_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `credential_encrypted` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `approved_at` datetime(6) DEFAULT NULL,
  `expires_at` datetime(6) NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_edit_helper_pairings_0_4c25a8b606c6` (`user_id`),
  CONSTRAINT `fk_edit_helper_pairings_0_4c25a8b606c6` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `edit_helper_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `edit_helper_sessions` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `user_id` bigint NOT NULL,
  `device_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `file_id` bigint NOT NULL,
  `expected_version` bigint NOT NULL,
  `launch_expires_at` datetime(6) NOT NULL,
  `claimed_at` datetime(6) DEFAULT NULL,
  `expires_at` datetime(6) NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edit_helper_sessions_file_id_index` (`file_id`),
  KEY `fk_edit_helper_sessions_0_cac68caa045f` (`device_id`),
  KEY `fk_edit_helper_sessions_1_51a907669d69` (`user_id`),
  CONSTRAINT `fk_edit_helper_sessions_0_cac68caa045f` FOREIGN KEY (`device_id`) REFERENCES `edit_helper_devices` (`id`),
  CONSTRAINT `fk_edit_helper_sessions_1_51a907669d69` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `editing_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `editing_requests` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint DEFAULT NULL,
  `requester_id` bigint NOT NULL,
  `tracking_code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `summary` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `priority` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'normal'),
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'open'),
  `target_team` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'editor'),
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `editing_requests_tracking_code_unique` (`tracking_code`),
  KEY `fk_editing_requests_0_b2b359e7a845` (`requester_id`),
  KEY `fk_editing_requests_1_004a46704bda` (`shoot_id`),
  CONSTRAINT `fk_editing_requests_0_b2b359e7a845` FOREIGN KEY (`requester_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_editing_requests_1_004a46704bda` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ck_editing_requests_0_b2b359e7a845` CHECK ((`priority` in (_utf8mb4'low',_utf8mb4'normal',_utf8mb4'high'))),
  CONSTRAINT `ck_editing_requests_1_004a46704bda` CHECK ((`status` in (_utf8mb4'open',_utf8mb4'in_progress',_utf8mb4'completed')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `editor_payouts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `editor_payouts` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `editor_id` bigint NOT NULL,
  `shoot_id` bigint NOT NULL,
  `service_id` bigint DEFAULT NULL,
  `service_name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `quantity_snapshot` bigint NOT NULL DEFAULT '1',
  `rate_snapshot` decimal(65,30) NOT NULL DEFAULT '0.000000000000000000000000000000',
  `payout_amount` decimal(65,30) NOT NULL DEFAULT '0.000000000000000000000000000000',
  `completed_at` datetime(6) DEFAULT NULL,
  `is_paid` bigint NOT NULL DEFAULT '0',
  `paid_at` datetime(6) DEFAULT NULL,
  `paid_by` bigint DEFAULT NULL,
  `payout_batch_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `shoot_service_id` bigint DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `editor_payouts_execution_unique` (`editor_id`,`shoot_id`,`service_id`,(coalesce(`shoot_service_id`,0))),
  KEY `editor_payouts_editor_paid_completed_idx` (`editor_id`,`is_paid`,`completed_at`),
  KEY `editor_payouts_batch_idx` (`payout_batch_id`),
  KEY `fk_editor_payouts_0_846c116f1a89` (`shoot_service_id`),
  KEY `fk_editor_payouts_2_f9001f786278` (`shoot_id`),
  KEY `fk_editor_payouts_3_ae6032862b35` (`service_id`),
  KEY `fk_editor_payouts_4_7603e09befd9` (`paid_by`),
  CONSTRAINT `fk_editor_payouts_0_846c116f1a89` FOREIGN KEY (`shoot_service_id`) REFERENCES `shoot_service` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_editor_payouts_1_90e76b95ce01` FOREIGN KEY (`editor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_editor_payouts_2_f9001f786278` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_editor_payouts_3_ae6032862b35` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_editor_payouts_4_7603e09befd9` FOREIGN KEY (`paid_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_template_artworks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_template_artworks` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `user_id` bigint NOT NULL,
  `kind` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `prompt` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'queued'),
  `path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `error` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_email_template_artworks_0_a796975d6edb` (`user_id`),
  CONSTRAINT `fk_email_template_artworks_0_a796975d6edb` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_template_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_template_assets` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `user_id` bigint NOT NULL,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `source` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'uploaded'),
  `artwork_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'processing'),
  `original_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `mime` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `original_bytes` bigint NOT NULL DEFAULT '0',
  `bytes` bigint NOT NULL DEFAULT '0',
  `width` bigint NOT NULL DEFAULT '0',
  `height` bigint NOT NULL DEFAULT '0',
  `options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `error` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `archived` bigint NOT NULL DEFAULT '0',
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_template_assets_artwork_id_unique` (`artwork_id`),
  KEY `email_template_assets_user_id_archived_created_at_index` (`user_id`,`archived`,`created_at`),
  CONSTRAINT `fk_email_template_assets_0_3e0bb33a814b` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_template_drafts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_template_drafts` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `user_id` bigint NOT NULL,
  `template_id` bigint DEFAULT NULL,
  `workspace_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `revision` bigint NOT NULL DEFAULT '1',
  `draft` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_template_drafts_user_id_workspace_key_unique` (`user_id`,`workspace_key`),
  KEY `email_template_drafts_template_id_index` (`template_id`),
  CONSTRAINT `fk_email_template_drafts_0_21935a89dd6d` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_template_revisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_template_revisions` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `template_id` bigint NOT NULL,
  `user_id` bigint DEFAULT NULL,
  `snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `email_template_revisions_template_id_index` (`template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_template_sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_template_sections` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `user_id` bigint NOT NULL,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `section` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_email_template_sections_0_bbd60241ff59` (`user_id`),
  CONSTRAINT `fk_email_template_sections_0_bbd60241ff59` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `external_booking_submissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `external_booking_submissions` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `external_reference` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `request_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `shoot_id` bigint DEFAULT NULL,
  `response_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `external_booking_source_reference_unique` (`source`,`external_reference`),
  KEY `fk_external_booking_submissions_0_e65c2a079577` (`shoot_id`),
  CONSTRAINT `fk_external_booking_submissions_0_e65c2a079577` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `connection` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `queue` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `exception` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `failed_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `featured_shoot_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `featured_shoot_images` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `shoot_file_id` bigint NOT NULL,
  `sort_order` bigint NOT NULL DEFAULT '1',
  `alt_text` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `focal_point` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'50% 50%'),
  `variant_640_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `variant_1280_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `variant_1920_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `width` bigint DEFAULT NULL,
  `height` bigint DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `featured_shoot_images_unique_file` (`shoot_id`,`shoot_file_id`),
  KEY `featured_shoot_images_sort_index` (`shoot_id`,`sort_order`),
  KEY `fk_featured_shoot_images_0_b0e4aa1ff423` (`shoot_file_id`),
  CONSTRAINT `fk_featured_shoot_images_0_b0e4aa1ff423` FOREIGN KEY (`shoot_file_id`) REFERENCES `shoot_files` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_featured_shoot_images_1_57abb9a655d8` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `floor_plan_duration_correction_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `floor_plan_duration_correction_snapshots` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `source_table` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `source_id` bigint NOT NULL,
  `previous_duration` bigint DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_floor_plan_duration_correction_snapshots_sour_4e4e0596fdd2` (`source_table`,`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `generated_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `generated_assets` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `team_id` bigint NOT NULL,
  `created_by` bigint NOT NULL,
  `instruction_index` bigint NOT NULL,
  `instruction_text` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `asset_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `placement` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `alt_text` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `version` bigint NOT NULL DEFAULT '1',
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `generated_assets_team_id_instruction_index_unique` (`team_id`,`instruction_index`),
  KEY `generated_assets_team_id_updated_at_index` (`team_id`,`updated_at`),
  KEY `generated_assets_team_id_placement_index` (`team_id`,`placement`),
  KEY `fk_generated_assets_0_69ba955d8bbb` (`created_by`),
  CONSTRAINT `fk_generated_assets_0_69ba955d8bbb` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `ck_generated_assets_0_69ba955d8bbb` CHECK ((`status` in (_utf8mb4'produced',_utf8mb4'failed')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `google_calendar_connections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `google_calendar_connections` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `provider_email` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `calendar_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'primary'),
  `access_token` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `refresh_token` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `token_expires_at` datetime(6) DEFAULT NULL,
  `sync_enabled` bigint NOT NULL DEFAULT '1',
  `last_synced_at` datetime(6) DEFAULT NULL,
  `last_error` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_google_calendar_connections_0_e3ad1a687b18` (`user_id`),
  CONSTRAINT `fk_google_calendar_connections_0_e3ad1a687b18` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `google_calendar_event_mappings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `google_calendar_event_mappings` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `calendar_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'primary'),
  `google_event_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `sync_fingerprint` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `last_synced_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `shoot_service_id` bigint DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `calendar_mapping_shoot_service_user_unique` (`shoot_id`,`shoot_service_id`,`user_id`),
  KEY `google_calendar_event_mappings_shoot_id_index` (`shoot_id`),
  KEY `fk_google_calendar_event_mappings_0_f49c8b23a023` (`shoot_service_id`),
  KEY `fk_google_calendar_event_mappings_1_41b18bf52e35` (`user_id`),
  CONSTRAINT `fk_google_calendar_event_mappings_0_f49c8b23a023` FOREIGN KEY (`shoot_service_id`) REFERENCES `shoot_service` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_google_calendar_event_mappings_1_41b18bf52e35` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `groundwire_sms_pushes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `groundwire_sms_pushes` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `device_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `message_id` bigint NOT NULL,
  `binding_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'queued',
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `groundwire_sms_pushes_device_id_message_id_unique` (`device_id`,`message_id`),
  KEY `groundwire_sms_pushes_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `groundwire_sms_sends`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `groundwire_sms_sends` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `device_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `request_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `fingerprint` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `message_id` bigint DEFAULT NULL,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'attempting'),
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `groundwire_sms_sends_device_id_request_key_unique` (`device_id`,`request_key`),
  KEY `groundwire_sms_sends_fingerprint_index` (`fingerprint`),
  KEY `groundwire_sms_sends_device_id_index` (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `historical_import_releases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `historical_import_releases` (
  `external_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `shoot_id` bigint NOT NULL,
  `source_sha256` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `invoice_id` bigint NOT NULL,
  `payment_id` bigint NOT NULL,
  `shoot_service_id` bigint NOT NULL,
  `released_at` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  PRIMARY KEY (`external_key`),
  UNIQUE KEY `unique_historical_import_releases_shoot_service_i_1bac16d84fd5` (`shoot_service_id`),
  UNIQUE KEY `unique_historical_import_releases_payment_id_eebc5cb893c6` (`payment_id`),
  UNIQUE KEY `unique_historical_import_releases_invoice_id_33db5867e1f4` (`invoice_id`),
  UNIQUE KEY `unique_historical_import_releases_shoot_id_b1210d5ea3dc` (`shoot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `historical_nocharge_releases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `historical_nocharge_releases` (
  `external_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `shoot_id` bigint NOT NULL,
  `source_sha256` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `invoice_id` bigint NOT NULL,
  `shoot_service_id` bigint NOT NULL,
  `released_at` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  PRIMARY KEY (`external_key`),
  UNIQUE KEY `unique_historical_nocharge_releases_shoot_service_624a7aa55fd2` (`shoot_service_id`),
  UNIQUE KEY `unique_historical_nocharge_releases_invoice_id_444d7e6f6104` (`invoice_id`),
  UNIQUE KEY `unique_historical_nocharge_releases_shoot_id_4e42f03d1296` (`shoot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `iguide_offline_upload_chunks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `iguide_offline_upload_chunks` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `upload_session_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `chunk_index` bigint NOT NULL,
  `offset_bytes` bigint NOT NULL,
  `size_bytes` bigint NOT NULL,
  `sha256` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `storage_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `iguide_chunk_session_index_unique` (`upload_session_id`,`chunk_index`),
  CONSTRAINT `fk_iguide_offline_upload_chunks_0_4e8505b97ad2` FOREIGN KEY (`upload_session_id`) REFERENCES `iguide_offline_upload_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `iguide_offline_upload_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `iguide_offline_upload_sessions` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `shoot_id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `idempotency_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `original_filename` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `size_bytes` bigint NOT NULL,
  `expected_sha256` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `chunk_size_bytes` bigint NOT NULL,
  `total_chunks` bigint NOT NULL,
  `received_bytes` bigint NOT NULL DEFAULT '0',
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `error` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `retryable` bigint NOT NULL DEFAULT '0',
  `shoot_file_id` bigint DEFAULT NULL,
  `last_activity_at` datetime(6) NOT NULL,
  `expires_at` datetime(6) NOT NULL,
  `processing_started_at` datetime(6) DEFAULT NULL,
  `assembly_token` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `assembly_lease_expires_at` datetime(6) DEFAULT NULL,
  `completed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `shoot_service_id` bigint DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `iguide_upload_shoot_idempotency_unique` (`shoot_id`,`idempotency_key`),
  KEY `iguide_upload_status_expiry_index` (`status`,`expires_at`),
  KEY `iguide_upload_shoot_status_index` (`shoot_id`,`status`),
  KEY `fk_iguide_offline_upload_sessions_0_29cad5698a7f` (`shoot_service_id`),
  KEY `fk_iguide_offline_upload_sessions_2_4e01b2562050` (`user_id`),
  KEY `fk_iguide_offline_upload_sessions_3_03ed226b2689` (`shoot_file_id`),
  CONSTRAINT `fk_iguide_offline_upload_sessions_0_29cad5698a7f` FOREIGN KEY (`shoot_service_id`) REFERENCES `shoot_service` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_iguide_offline_upload_sessions_1_33c9e48f3849` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_iguide_offline_upload_sessions_2_4e01b2562050` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_iguide_offline_upload_sessions_3_03ed226b2689` FOREIGN KEY (`shoot_file_id`) REFERENCES `shoot_files` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoice_accounts_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_accounts_notes` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint NOT NULL,
  `note` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `author_id` bigint DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_accounts_notes_invoice_id_unique` (`invoice_id`),
  KEY `fk_invoice_accounts_notes_0_753e2552c69c` (`author_id`),
  CONSTRAINT `fk_invoice_accounts_notes_0_753e2552c69c` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_invoice_accounts_notes_1_d8255f7e2ceb` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoice_audit_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_audit_events` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint NOT NULL,
  `actor_id` bigint DEFAULT NULL,
  `event` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `summary` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `invoice_audit_events_event_index` (`event`),
  KEY `invoice_audit_events_invoice_id_created_at_index` (`invoice_id`,`created_at`),
  KEY `fk_invoice_audit_events_0_3eb1a26d4d03` (`actor_id`),
  CONSTRAINT `fk_invoice_audit_events_0_3eb1a26d4d03` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_invoice_audit_events_1_240496e94974` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoice_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_items` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint NOT NULL,
  `shoot_id` bigint DEFAULT NULL,
  `type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `description` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `quantity` bigint NOT NULL DEFAULT '1',
  `unit_amount` decimal(65,30) NOT NULL DEFAULT '0.000000000000000000000000000000',
  `total_amount` decimal(65,30) NOT NULL DEFAULT '0.000000000000000000000000000000',
  `recorded_at` datetime(6) DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `shoot_compensation_id` bigint DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_items_shoot_compensation_unique` (`shoot_compensation_id`),
  KEY `invoice_items_invoice_lookup` (`invoice_id`),
  KEY `fk_invoice_items_2_a50b31bbaa67` (`shoot_id`),
  CONSTRAINT `fk_invoice_items_0_be7924b0ad65` FOREIGN KEY (`shoot_compensation_id`) REFERENCES `shoot_compensations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_invoice_items_1_dedad2262280` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_invoice_items_2_a50b31bbaa67` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoice_shoot`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_shoot` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint NOT NULL,
  `shoot_id` bigint NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_shoot_invoice_id_shoot_id_unique` (`invoice_id`,`shoot_id`),
  KEY `fk_invoice_shoot_0_cd5cc7f56d77` (`shoot_id`),
  CONSTRAINT `fk_invoice_shoot_0_cd5cc7f56d77` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_invoice_shoot_1_9684d1e8d826` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoices` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `role` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `charges_total` decimal(65,30) NOT NULL DEFAULT '0.000000000000000000000000000000',
  `payments_total` decimal(65,30) NOT NULL DEFAULT '0.000000000000000000000000000000',
  `balance_due` decimal(65,30) NOT NULL DEFAULT '0.000000000000000000000000000000',
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'draft'),
  `sent_at` datetime(6) DEFAULT NULL,
  `paid_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `approval_status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'pending'),
  `rejection_reason` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `rejected_by` bigint DEFAULT NULL,
  `rejected_at` datetime(6) DEFAULT NULL,
  `approved_by` bigint DEFAULT NULL,
  `approved_at` datetime(6) DEFAULT NULL,
  `modified_by` bigint DEFAULT NULL,
  `modified_at` datetime(6) DEFAULT NULL,
  `modification_notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `shoot_id` bigint DEFAULT NULL,
  `client_id` bigint DEFAULT NULL,
  `photographer_id` bigint DEFAULT NULL,
  `invoice_number` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `issue_date` datetime(6) DEFAULT NULL,
  `due_date` datetime(6) DEFAULT NULL,
  `subtotal` decimal(65,30) DEFAULT NULL,
  `tax` decimal(65,30) DEFAULT NULL,
  `total` decimal(65,30) DEFAULT NULL,
  `billing_period_start` date DEFAULT NULL,
  `billing_period_end` date DEFAULT NULL,
  `total_amount` decimal(65,30) DEFAULT NULL,
  `amount_paid` decimal(65,30) NOT NULL DEFAULT '0.000000000000000000000000000000',
  `is_paid` bigint NOT NULL DEFAULT '0',
  `is_sent` bigint NOT NULL DEFAULT '0',
  `payment_method` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `payment_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `sales_rep_id` bigint DEFAULT NULL,
  `approval_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `unresolved_warnings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `warning_override_reason` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `warning_override_by` bigint DEFAULT NULL,
  `warning_override_at` datetime(6) DEFAULT NULL,
  `document_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'invoice',
  `payment_required` bigint NOT NULL DEFAULT '1',
  `payout_revision` bigint NOT NULL DEFAULT '0',
  `payout_edited` bigint NOT NULL DEFAULT '0',
  `payout_submission` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `payout_submission_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `payout_edit_baseline` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  PRIMARY KEY (`id`),
  KEY `invoices_payment_required_index` (`payment_required`),
  KEY `invoices_document_type_index` (`document_type`),
  KEY `fk_invoices_0_a4ed94af4e61` (`warning_override_by`),
  KEY `fk_invoices_1_17ef98f2404c` (`photographer_id`),
  KEY `fk_invoices_2_bd76e246a72b` (`client_id`),
  KEY `fk_invoices_3_76314d642949` (`shoot_id`),
  KEY `fk_invoices_4_711574afcd4d` (`user_id`),
  KEY `fk_invoices_5_c647e32f9fa5` (`rejected_by`),
  KEY `fk_invoices_6_8b1163902fab` (`approved_by`),
  KEY `fk_invoices_7_805008f4a017` (`modified_by`),
  KEY `fk_invoices_8_8db20b480ebb` (`sales_rep_id`),
  CONSTRAINT `fk_invoices_0_a4ed94af4e61` FOREIGN KEY (`warning_override_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_invoices_1_17ef98f2404c` FOREIGN KEY (`photographer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_invoices_2_bd76e246a72b` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_invoices_3_76314d642949` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_invoices_4_711574afcd4d` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_invoices_5_c647e32f9fa5` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_invoices_6_8b1163902fab` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_invoices_7_805008f4a017` FOREIGN KEY (`modified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_invoices_8_8db20b480ebb` FOREIGN KEY (`sales_rep_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_bin */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_cascade_delete_invoices` BEFORE DELETE ON `invoices` FOR EACH ROW BEGIN
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT c0.id,c0.client_id,'shoots.UPDATE' FROM `shoots` c0  WHERE c0.`photographer_paid_invoice_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT c0.id,c0.client_id,'shoots.UPDATE' FROM `shoots` c0  WHERE c0.`sales_rep_paid_invoice_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'payments.UPDATE' FROM `payments` c0 JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c0.`invoice_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'payment.status' FROM `payments` c0 JOIN payment_service_allocations feed_a ON feed_a.payment_id=c0.id JOIN shoot_service feed_ss ON feed_ss.id=feed_a.shoot_service_id JOIN shoots feed_s ON feed_s.id=feed_ss.shoot_id WHERE c0.`invoice_id` = OLD.`id`;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `total_jobs` bigint NOT NULL,
  `pending_jobs` bigint NOT NULL,
  `failed_jobs` bigint NOT NULL,
  `failed_job_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `cancelled_at` bigint DEFAULT NULL,
  `created_at` bigint NOT NULL,
  `finished_at` bigint DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `attempts` bigint NOT NULL,
  `reserved_at` bigint DEFAULT NULL,
  `available_at` bigint NOT NULL,
  `created_at` bigint NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `legacy_activity_imports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `legacy_activity_imports` (
  `source_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `shoot_id` bigint NOT NULL,
  `activity_log_id` bigint NOT NULL,
  `imported_at` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  PRIMARY KEY (`source_key`),
  UNIQUE KEY `unique_legacy_activity_imports_activity_log_id_83318ba3b59f` (`activity_log_id`),
  KEY `fk_legacy_activity_imports_1_194817604b93` (`shoot_id`),
  CONSTRAINT `fk_legacy_activity_imports_0_ecf99433be7b` FOREIGN KEY (`activity_log_id`) REFERENCES `shoot_activity_logs` (`id`),
  CONSTRAINT `fk_legacy_activity_imports_1_194817604b93` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `legacy_shoot_imports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `legacy_shoot_imports` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `source_system` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `source_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `shoot_id` bigint NOT NULL,
  `batch_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `action` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `source_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `created_at` datetime(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `legacy_shoot_imports_source_system_source_id_unique` (`source_system`,`source_id`),
  KEY `fk_legacy_shoot_imports_0_b87a01a34891` (`shoot_id`),
  CONSTRAINT `fk_legacy_shoot_imports_0_b87a01a34891` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `legal_acceptances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `legal_acceptances` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `role_at_acceptance` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `document_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `document_version` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `content_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `effective_date` date DEFAULT NULL,
  `accepted_at` datetime(6) NOT NULL,
  `ip_address` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `user_agent` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `audit_metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `legal_acceptance_user_document_version_unique` (`user_id`,`document_key`,`document_version`),
  KEY `legal_acceptances_document_key_document_version_index` (`document_key`,`document_version`),
  CONSTRAINT `fk_legal_acceptances_0_30c509f938d1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_account_setups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_account_setups` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `client_id` bigint NOT NULL,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'pending'),
  `attempts` bigint NOT NULL DEFAULT '0',
  `notification_state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `last_error` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `sent_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `listing_studio_account_setups_client_id_unique` (`client_id`),
  CONSTRAINT `fk_listing_studio_account_setups_0_80e6ec4878d6` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_billing_emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_billing_emails` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `billing_request_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `client_id` bigint NOT NULL,
  `actor_id` bigint NOT NULL,
  `request_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `recipient` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'queued'),
  `submission_started_at` datetime(6) DEFAULT NULL,
  `sent_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `studio_billing_email_replay` (`billing_request_id`,`request_key`),
  KEY `listing_studio_billing_emails_client_id_created_at_index` (`client_id`,`created_at`),
  KEY `fk_listing_studio_billing_emails_0_bb00b8c38278` (`actor_id`),
  CONSTRAINT `fk_listing_studio_billing_emails_0_bb00b8c38278` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_listing_studio_billing_emails_1_abdb63efdc50` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_listing_studio_billing_emails_2_6f960ea344c1` FOREIGN KEY (`billing_request_id`) REFERENCES `listing_studio_billing_requests` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_billing_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_billing_requests` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `client_id` bigint NOT NULL,
  `actor_id` bigint NOT NULL,
  `order_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `subscription_id` bigint DEFAULT NULL,
  `kind` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `plan_code` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `request_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `request_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `checkout_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'pending',
  `amount_cents` bigint DEFAULT NULL,
  `currency` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'usd'),
  `preview` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `expires_at` datetime(6) NOT NULL,
  `confirmed_at` datetime(6) DEFAULT NULL,
  `completed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `listing_studio_billing_requests_checkout_key_unique` (`checkout_key`),
  UNIQUE KEY `studio_billing_request_replay` (`actor_id`,`client_id`,`request_key`),
  KEY `listing_studio_billing_requests_expires_at_index` (`expires_at`),
  KEY `listing_studio_billing_requests_client_id_status_index` (`client_id`,`status`),
  KEY `fk_listing_studio_billing_requests_0_45a9fa75d6b6` (`subscription_id`),
  KEY `fk_listing_studio_billing_requests_1_25c2a0a91e95` (`order_id`),
  CONSTRAINT `fk_listing_studio_billing_requests_0_45a9fa75d6b6` FOREIGN KEY (`subscription_id`) REFERENCES `listing_studio_subscriptions` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_listing_studio_billing_requests_1_25c2a0a91e95` FOREIGN KEY (`order_id`) REFERENCES `listing_studio_orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_listing_studio_billing_requests_2_f1e0ffba9a2b` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_listing_studio_billing_requests_3_74f1785f8c98` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_checkout_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_checkout_attempts` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `client_id` bigint NOT NULL,
  `order_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `plan_code` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `kind` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `idempotency_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `request_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `account_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `livemode` bigint NOT NULL,
  `customer_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `session_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `payment_intent_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `subscription_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `currency` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `amount_cents` bigint NOT NULL,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'pending'),
  `parameters` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `checkout_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `submission_started_at` datetime(6) DEFAULT NULL,
  `expires_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `actor_id` bigint DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `studio_checkout_client_key_unique` (`client_id`,`idempotency_key`),
  UNIQUE KEY `studio_checkout_session_unique` (`account_id`,`livemode`,`session_id`),
  UNIQUE KEY `studio_checkout_payment_unique` (`account_id`,`livemode`,`payment_intent_id`),
  KEY `listing_studio_checkout_attempts_order_id_index` (`order_id`),
  KEY `fk_listing_studio_checkout_attempts_0_d2a18a8e0214` (`actor_id`),
  CONSTRAINT `fk_listing_studio_checkout_attempts_0_d2a18a8e0214` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_listing_studio_checkout_attempts_1_ebd401ad855f` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_credit_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_credit_entries` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `subscription_id` bigint NOT NULL,
  `stripe_invoice_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `source_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `source_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `plan_code` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `amount_cents` bigint NOT NULL,
  `currency` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `period_start` datetime(6) NOT NULL,
  `expires_at` datetime(6) NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `listing_studio_credit_source` (`subscription_id`,`source_type`,`source_key`),
  KEY `listing_studio_credit_entries_stripe_invoice_id_index` (`stripe_invoice_id`),
  KEY `listing_studio_credit_entries_subscription_id_expires_at_index` (`subscription_id`,`expires_at`),
  CONSTRAINT `fk_listing_studio_credit_entries_0_3d89fcc9a1f0` FOREIGN KEY (`subscription_id`) REFERENCES `listing_studio_subscriptions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_credit_reservations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_credit_reservations` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `order_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `subscription_id` bigint NOT NULL,
  `amount_cents` bigint NOT NULL,
  `allocations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'reserved',
  `period_start` datetime(6) NOT NULL,
  `expires_at` datetime(6) NOT NULL,
  `captured_at` datetime(6) DEFAULT NULL,
  `released_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `listing_studio_credit_reservations_order_id_unique` (`order_id`),
  KEY `idx_listing_studio_credit_reservations_subscripti_6750be6d9832` (`subscription_id`,`status`,`expires_at`),
  CONSTRAINT `fk_listing_studio_credit_reservations_0_35e6fa7e18c6` FOREIGN KEY (`subscription_id`) REFERENCES `listing_studio_subscriptions` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_listing_studio_credit_reservations_1_4b9507079498` FOREIGN KEY (`order_id`) REFERENCES `listing_studio_orders` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_guest_claim_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_guest_claim_tokens` (
  `token_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `trial_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `session_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `expires_at` datetime(6) NOT NULL,
  `client_id` bigint DEFAULT NULL,
  `claimed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`token_hash`),
  KEY `listing_studio_guest_claim_tokens_session_hash_index` (`session_hash`),
  KEY `listing_studio_guest_claim_tokens_trial_id_index` (`trial_id`),
  KEY `fk_listing_studio_guest_claim_tokens_0_02ca23e0a263` (`client_id`),
  CONSTRAINT `fk_listing_studio_guest_claim_tokens_0_02ca23e0a263` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_guest_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_guest_sessions` (
  `session_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `client_id` bigint DEFAULT NULL,
  `claimed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`session_hash`),
  KEY `fk_listing_studio_guest_sessions_0_697df243016c` (`client_id`),
  CONSTRAINT `fk_listing_studio_guest_sessions_0_697df243016c` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_guest_trials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_guest_trials` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `session_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `ip_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `client_id` bigint DEFAULT NULL,
  `service` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'queued',
  `idempotency_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `request_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `source_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `source_mime` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `source_name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `workspace_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `output_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `preview_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `error_code` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `started_at` datetime(6) NOT NULL,
  `completed_at` datetime(6) DEFAULT NULL,
  `claimed_at` datetime(6) DEFAULT NULL,
  `expires_at` datetime(6) NOT NULL,
  `media_purged_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `worker_started_at` datetime(6) DEFAULT NULL,
  `first_download_requested_at` datetime(6) DEFAULT NULL,
  `download_request_count` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `studio_guest_idempotency` (`session_hash`,`idempotency_key`),
  KEY `listing_studio_guest_trials_media_purged_at_index` (`media_purged_at`),
  KEY `listing_studio_guest_trials_expires_at_index` (`expires_at`),
  KEY `listing_studio_guest_trials_started_at_index` (`started_at`),
  KEY `listing_studio_guest_trials_workspace_id_index` (`workspace_id`),
  KEY `listing_studio_guest_trials_ip_hash_index` (`ip_hash`),
  KEY `listing_studio_guest_trials_session_hash_index` (`session_hash`),
  KEY `studio_guest_client_service` (`client_id`,`service`,`status`),
  KEY `studio_guest_service_status` (`session_hash`,`service`,`status`),
  CONSTRAINT `fk_listing_studio_guest_trials_0_4b8bd621962d` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_order_funding_effects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_order_funding_effects` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `order_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `kind` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `external_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `amount_cents` bigint NOT NULL,
  `evidence` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `occurred_at` datetime(6) NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `listing_studio_order_funding_effects_kind_external_key_unique` (`kind`,`external_key`),
  KEY `listing_studio_order_funding_effects_order_id_kind_status_index` (`order_id`,`kind`,`status`),
  CONSTRAINT `fk_listing_studio_order_funding_effects_0_ecd18292087a` FOREIGN KEY (`order_id`) REFERENCES `listing_studio_orders` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_order_items` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `order_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `client_id` bigint NOT NULL,
  `kind` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `service` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'awaiting_funding',
  `source_kind` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `source_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `source_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `instructions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `workspace_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `output_descriptor` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `progress` bigint DEFAULT NULL,
  `processing_attempt_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `processing_lease_token` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `processing_lease_expires_at` datetime(6) DEFAULT NULL,
  `started_at` datetime(6) DEFAULT NULL,
  `completed_at` datetime(6) DEFAULT NULL,
  `error_code` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `listing_studio_order_items_order_id_unique` (`order_id`),
  UNIQUE KEY `listing_studio_order_items_workspace_id_unique` (`workspace_id`),
  KEY `listing_studio_order_items_source_kind_source_id_index` (`source_kind`,`source_id`),
  KEY `listing_studio_order_items_client_id_status_index` (`client_id`,`status`),
  CONSTRAINT `fk_listing_studio_order_items_0_67ebfa8e5e06` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_listing_studio_order_items_1_07158972e014` FOREIGN KEY (`order_id`) REFERENCES `listing_studio_orders` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_orders` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `client_id` bigint NOT NULL,
  `quote_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `kind` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `service` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `funding_method` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `funding_status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'pending'),
  `currency` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'usd'),
  `total_cents` bigint NOT NULL,
  `paid_amount_cents` bigint NOT NULL DEFAULT '0',
  `refunded_amount_cents` bigint NOT NULL DEFAULT '0',
  `payment_reference` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `payment_evidence` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `pricing_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `idempotency_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `request_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `actor_id` bigint DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `listing_studio_orders_quote_id_unique` (`quote_id`),
  UNIQUE KEY `listing_studio_orders_client_id_idempotency_key_unique` (`client_id`,`idempotency_key`),
  UNIQUE KEY `listing_studio_orders_payment_reference_unique` (`payment_reference`),
  KEY `listing_studio_orders_client_id_created_at_index` (`client_id`,`created_at`),
  KEY `fk_listing_studio_orders_0_66c31d5fc9d0` (`actor_id`),
  CONSTRAINT `fk_listing_studio_orders_0_66c31d5fc9d0` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_listing_studio_orders_1_b460ff9fccfe` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_listing_studio_orders_2_4d942ce98978` FOREIGN KEY (`quote_id`) REFERENCES `listing_studio_quotes` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_outbox`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_outbox` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `aggregate_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `idempotency_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'pending',
  `attempts` bigint NOT NULL DEFAULT '0',
  `available_at` datetime(6) NOT NULL,
  `reserved_at` datetime(6) DEFAULT NULL,
  `lease_token` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `dispatched_at` datetime(6) DEFAULT NULL,
  `error_code` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `listing_studio_outbox_idempotency_key_unique` (`idempotency_key`),
  KEY `listing_studio_outbox_status_available_at_index` (`status`,`available_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_payment_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_payment_events` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `account_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `livemode` bigint NOT NULL,
  `event_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `event_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `processed_at` datetime(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `studio_payment_event_unique` (`account_id`,`livemode`,`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_quotes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_quotes` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `client_id` bigint NOT NULL,
  `kind` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `service` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `source_kind` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `source_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `source_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `instructions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `pricing_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `subscription_id` bigint DEFAULT NULL,
  `catalog_version` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `request_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `expires_at` datetime(6) NOT NULL,
  `consumed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `actor_id` bigint DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `listing_studio_quotes_client_id_expires_at_index` (`client_id`,`expires_at`),
  KEY `fk_listing_studio_quotes_0_14b097c5b1a3` (`actor_id`),
  KEY `fk_listing_studio_quotes_2_c8de8bf52a9a` (`subscription_id`),
  CONSTRAINT `fk_listing_studio_quotes_0_14b097c5b1a3` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_listing_studio_quotes_1_2ba2d4899946` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_listing_studio_quotes_2_c8de8bf52a9a` FOREIGN KEY (`subscription_id`) REFERENCES `listing_studio_subscriptions` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_refund_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_refund_attempts` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `order_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `operation_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `checkout_attempt_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `account_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `livemode` bigint NOT NULL,
  `payment_intent_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `refund_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `currency` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `amount_cents` bigint NOT NULL,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'pending'),
  `parameters` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `submission_started_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `listing_studio_refund_attempts_operation_id_unique` (`operation_id`),
  UNIQUE KEY `studio_refund_external_unique` (`account_id`,`livemode`,`refund_id`),
  KEY `listing_studio_refund_attempts_order_id_index` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_requests` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `submitted_by_id` bigint NOT NULL,
  `client_id` bigint DEFAULT NULL,
  `type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'pending',
  `contact` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `plan_code` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `services` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `phone` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `preferred_time` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `idempotency_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `request_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `review_note` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `reviewed_by_id` bigint DEFAULT NULL,
  `reviewed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `listing_studio_request_idempotency` (`submitted_by_id`,`idempotency_key`),
  KEY `listing_studio_requests_status_created_at_index` (`status`,`created_at`),
  KEY `listing_studio_requests_client_id_type_status_index` (`client_id`,`type`,`status`),
  KEY `fk_listing_studio_requests_0_884533ac0759` (`reviewed_by_id`),
  CONSTRAINT `fk_listing_studio_requests_0_884533ac0759` FOREIGN KEY (`reviewed_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_listing_studio_requests_1_ae2c8d788bc3` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_listing_studio_requests_2_cee63ed38ce4` FOREIGN KEY (`submitted_by_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_revision_actions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_revision_actions` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `task_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `actor_id` bigint DEFAULT NULL,
  `request_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `request_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `action` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `reason_code` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `note` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `listing_studio_revision_actions_task_id_request_key_unique` (`task_id`,`request_key`),
  KEY `fk_listing_studio_revision_actions_0_72dc4242c7f6` (`actor_id`),
  CONSTRAINT `fk_listing_studio_revision_actions_0_72dc4242c7f6` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_listing_studio_revision_actions_1_332df81ca9c6` FOREIGN KEY (`task_id`) REFERENCES `listing_studio_revision_tasks` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_revision_deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_revision_deliveries` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `task_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `actor_id` bigint DEFAULT NULL,
  `request_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `request_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `expected_version` bigint NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'pending',
  `descriptor` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `error_code` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `started_at` datetime(6) DEFAULT NULL,
  `completed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `listing_studio_revision_deliveries_task_id_request_key_unique` (`task_id`,`request_key`),
  KEY `listing_studio_revision_deliveries_status_index` (`status`),
  KEY `fk_listing_studio_revision_deliveries_0_8e0574e614e3` (`actor_id`),
  CONSTRAINT `fk_listing_studio_revision_deliveries_0_8e0574e614e3` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_listing_studio_revision_deliveries_1_ea65c558d57f` FOREIGN KEY (`task_id`) REFERENCES `listing_studio_revision_tasks` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_revision_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_revision_tasks` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `order_item_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `team_id` bigint DEFAULT NULL,
  `assignee_id` bigint DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'queued',
  `version` bigint NOT NULL DEFAULT '1',
  `delivery_attempt_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `assigned_at` datetime(6) DEFAULT NULL,
  `started_at` datetime(6) DEFAULT NULL,
  `delivered_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `listing_studio_revision_tasks_order_item_id_unique` (`order_item_id`),
  KEY `listing_studio_revision_tasks_delivery_attempt_id_index` (`delivery_attempt_id`),
  KEY `listing_studio_revision_tasks_status_index` (`status`),
  KEY `listing_studio_revision_tasks_team_id_index` (`team_id`),
  KEY `fk_listing_studio_revision_tasks_0_11237a356f55` (`assignee_id`),
  CONSTRAINT `fk_listing_studio_revision_tasks_0_11237a356f55` FOREIGN KEY (`assignee_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_listing_studio_revision_tasks_1_190ad53885e8` FOREIGN KEY (`order_item_id`) REFERENCES `listing_studio_order_items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_stripe_customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_stripe_customers` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `client_id` bigint NOT NULL,
  `account_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `livemode` bigint NOT NULL,
  `customer_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `create_parameters` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `submission_started_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `studio_customer_client_unique` (`account_id`,`livemode`,`client_id`),
  UNIQUE KEY `studio_customer_external_unique` (`account_id`,`livemode`,`customer_id`),
  KEY `fk_listing_studio_stripe_customers_0_39b0e2f18f81` (`client_id`),
  CONSTRAINT `fk_listing_studio_stripe_customers_0_39b0e2f18f81` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_stripe_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_stripe_events` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `stripe_account_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `livemode` bigint NOT NULL,
  `stripe_event_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `event_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `subscription_id` bigint NOT NULL,
  `processed_at` datetime(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `listing_studio_event_identity` (`stripe_account_id`,`livemode`,`stripe_event_id`),
  KEY `fk_listing_studio_stripe_events_0_d43d145d6e9a` (`subscription_id`),
  CONSTRAINT `fk_listing_studio_stripe_events_0_d43d145d6e9a` FOREIGN KEY (`subscription_id`) REFERENCES `listing_studio_subscriptions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_subscription_actions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_subscription_actions` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `client_id` bigint NOT NULL,
  `actor_id` bigint NOT NULL,
  `subscription_id` bigint NOT NULL,
  `billing_request_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `request_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `request_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `kind` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'reviewing',
  `account_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `livemode` bigint NOT NULL,
  `customer_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `stripe_subscription_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `item_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `current_plan_code` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `plan_code` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `source_price_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `target_price_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `preview` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `steps` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `confirmation_token_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `review_expires_at` datetime(6) DEFAULT NULL,
  `confirmed_at` datetime(6) DEFAULT NULL,
  `submitted_at` datetime(6) DEFAULT NULL,
  `effective_at` datetime(6) DEFAULT NULL,
  `completed_at` datetime(6) DEFAULT NULL,
  `invoice_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `schedule_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `error_code` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `listing_studio_subscription_actions_client_id_request_key_unique` (`client_id`,`request_key`),
  UNIQUE KEY `listing_studio_subscription_actions_billing_request_id_unique` (`billing_request_id`),
  KEY `listing_studio_subscription_actions_subscription_id_status_index` (`subscription_id`,`status`),
  KEY `fk_listing_studio_subscription_actions_1_ddf627d6b45a` (`actor_id`),
  CONSTRAINT `fk_listing_studio_subscription_actions_0_7177e6533417` FOREIGN KEY (`subscription_id`) REFERENCES `listing_studio_subscriptions` (`id`),
  CONSTRAINT `fk_listing_studio_subscription_actions_1_ddf627d6b45a` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_listing_studio_subscription_actions_2_1129c506b8b9` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_subscription_refunds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_subscription_refunds` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `stripe_account_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `livemode` bigint NOT NULL,
  `stripe_refund_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `stripe_subscription_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `stripe_customer_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `stripe_invoice_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `stripe_payment_intent_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `stripe_charge_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `amount_cents` bigint NOT NULL,
  `currency` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `refund_created_at` datetime(6) NOT NULL,
  `last_event_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `listing_studio_refund_identity` (`stripe_account_id`,`livemode`,`stripe_refund_id`),
  KEY `listing_studio_refund_subscription` (`stripe_account_id`,`livemode`,`stripe_subscription_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_subscriptions` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `stripe_account_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `livemode` bigint NOT NULL,
  `stripe_subscription_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `stripe_customer_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `stripe_price_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `client_id` bigint DEFAULT NULL,
  `customer_name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `customer_email` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `plan_code` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `plan_name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `sync_status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `attention_reason` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `amount_cents` bigint DEFAULT NULL,
  `currency` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `billing_interval` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `billing_interval_count` bigint DEFAULT NULL,
  `current_period_start` datetime(6) DEFAULT NULL,
  `current_period_end` datetime(6) DEFAULT NULL,
  `cancel_at_period_end` bigint NOT NULL DEFAULT '0',
  `canceled_at` datetime(6) DEFAULT NULL,
  `latest_invoice_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `latest_invoice_status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `last_paid_at` datetime(6) DEFAULT NULL,
  `account_created` bigint NOT NULL DEFAULT '0',
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `listing_studio_subscription_identity` (`stripe_account_id`,`livemode`,`stripe_subscription_id`),
  KEY `listing_studio_subscriptions_sync_status_updated_at_index` (`sync_status`,`updated_at`),
  KEY `listing_studio_subscriptions_client_id_status_index` (`client_id`,`status`),
  CONSTRAINT `fk_listing_studio_subscriptions_0_1bfad4687331` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_trial_admissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_trial_admissions` (
  `token_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `session_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `idempotency_key` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `ip_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `challenge_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `verification_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `verified_at` datetime(6) DEFAULT NULL,
  `expires_at` datetime(6) NOT NULL,
  `consumed_at` datetime(6) DEFAULT NULL,
  `trial_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`token_hash`),
  KEY `listing_studio_trial_admissions_expires_at_index` (`expires_at`),
  KEY `listing_studio_trial_admissions_session_hash_index` (`session_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_trial_claims`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_trial_claims` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `client_id` bigint DEFAULT NULL,
  `email_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `support_ticket_id` bigint NOT NULL,
  `request_key` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `request_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `edit_types` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `listing_studio_trial_claims_support_ticket_id_unique` (`support_ticket_id`),
  UNIQUE KEY `listing_studio_trial_claims_email_hash_unique` (`email_hash`),
  UNIQUE KEY `listing_studio_trial_claims_client_id_unique` (`client_id`),
  CONSTRAINT `fk_listing_studio_trial_claims_0_f596161d1b26` FOREIGN KEY (`support_ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_listing_studio_trial_claims_1_f436e6bbca5e` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_trial_counters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_trial_counters` (
  `day` date NOT NULL,
  `event` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `service` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `count` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`day`,`event`,`service`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_trial_guard`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_trial_guard` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `version` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_studio_uploads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_studio_uploads` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `client_id` bigint DEFAULT NULL,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'preparing'),
  `request_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `request_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `preview_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `sha256` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `mime` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `width` bigint NOT NULL,
  `height` bigint NOT NULL,
  `byte_size` bigint NOT NULL,
  `expires_at` datetime(6) NOT NULL,
  `pinned_at` datetime(6) DEFAULT NULL,
  `media_purged_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `actor_id` bigint DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `listing_studio_uploads_client_id_request_key_unique` (`client_id`,`request_key`),
  KEY `listing_studio_uploads_expires_at_index` (`expires_at`),
  KEY `fk_listing_studio_uploads_0_d1d3c3bd1f20` (`actor_id`),
  CONSTRAINT `fk_listing_studio_uploads_0_d1d3c3bd1f20` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_listing_studio_uploads_1_6852d350e685` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `message_channels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `message_channels` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `provider` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `display_name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `from_email` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `from_number` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `is_default` bigint NOT NULL DEFAULT '0',
  `owner_scope` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'GLOBAL',
  `owner_id` bigint DEFAULT NULL,
  `config_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `label` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `reply_to_email` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  PRIMARY KEY (`id`),
  KEY `message_channels_type_owner_scope_owner_id_index` (`type`,`owner_scope`,`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `message_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `message_templates` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `channel` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'EMAIL'),
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `slug` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `description` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `category` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `subject` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `body_html` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `body_text` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `variables_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_by` bigint DEFAULT NULL,
  `updated_by` bigint DEFAULT NULL,
  `scope` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'GLOBAL'),
  `owner_id` bigint DEFAULT NULL,
  `is_system` bigint NOT NULL DEFAULT '0',
  `is_active` bigint NOT NULL DEFAULT '1',
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `email_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `override_enabled` bigint NOT NULL DEFAULT '0',
  `override_health_status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `override_health_message` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `override_health_checked_at` datetime(6) DEFAULT NULL,
  `content_blocks_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `editor_document_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `workspace_revision` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `message_templates_email_type_index` (`email_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `message_threads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `message_threads` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `channel` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `contact_id` bigint NOT NULL,
  `last_message_at` datetime(6) DEFAULT NULL,
  `last_direction` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `last_snippet` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `unread_for_user_ids_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `related_shoot_id` bigint DEFAULT NULL,
  `ai_paused_until` datetime(6) DEFAULT NULL,
  `ai_session_id` bigint DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `sms_group_id` bigint DEFAULT NULL,
  `sms_inbox_active` bigint NOT NULL DEFAULT '0',
  `sms_needs_reply` bigint NOT NULL DEFAULT '0',
  `assigned_to_user_id` bigint DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `tags_json` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `message_threads_sms_group_sender_lookup` (`sms_group_id`,`last_message_at`),
  KEY `message_threads_sms_inbox_index` (`channel`,`sms_inbox_active`,`last_message_at`),
  KEY `message_threads_channel_last_message_at_index` (`channel`,`last_message_at`),
  KEY `message_threads_channel_contact_shoot_index` (`channel`,`contact_id`,`related_shoot_id`),
  KEY `message_threads_ai_session_id_index` (`ai_session_id`),
  KEY `fk_message_threads_1_f7da5dcd9d50` (`contact_id`),
  KEY `message_threads_assigned_to_user_id_foreign` (`assigned_to_user_id`),
  CONSTRAINT `fk_message_threads_0_1f698c684b70` FOREIGN KEY (`sms_group_id`) REFERENCES `sms_groups` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_message_threads_1_f7da5dcd9d50` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `message_threads_assigned_to_user_id_foreign` FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `channel` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'EMAIL',
  `direction` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'OUTBOUND'),
  `provider` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `from_address` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `to_address` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `reply_to_email` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `subject` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `body_text` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `body_html` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `attachments_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'QUEUED'),
  `send_source` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `tags_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `scheduled_at` datetime(6) DEFAULT NULL,
  `created_by` bigint DEFAULT NULL,
  `sender_user_id` bigint DEFAULT NULL,
  `sender_account_id` bigint DEFAULT NULL,
  `sender_role` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `sender_display_name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `template_id` bigint DEFAULT NULL,
  `related_shoot_id` bigint DEFAULT NULL,
  `related_account_id` bigint DEFAULT NULL,
  `related_invoice_id` bigint DEFAULT NULL,
  `thread_id` bigint DEFAULT NULL,
  `message_channel_id` bigint DEFAULT NULL,
  `provider_message_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `sent_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `cc_addresses_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `bcc_addresses_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `related_shoot_context_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `failed_at` datetime(6) DEFAULT NULL,
  `delivered_at` datetime(6) DEFAULT NULL,
  `error_message` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `hidden_from_inbox` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `messages_thread_visibility_lookup` (`thread_id`,`channel`,`hidden_from_inbox`),
  KEY `messages_hidden_from_inbox_index` (`hidden_from_inbox`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `messaging_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messaging_settings` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `provider` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `is_enabled` bigint NOT NULL DEFAULT '0',
  `credentials` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `messaging_settings_provider_unique` (`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `migration` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `batch` bigint NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mmm_punchout_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mmm_punchout_sessions` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `user_id` bigint DEFAULT NULL,
  `buyer_cookie` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `cost_center_number` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `employee_email` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `username` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `first_name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `last_name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `template_external_number` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `order_number` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `redirect_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `redirected_at` datetime(6) DEFAULT NULL,
  `returned_at` datetime(6) DEFAULT NULL,
  `last_error` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `request_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `response_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `return_token` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  PRIMARY KEY (`id`),
  KEY `mmm_punchout_sessions_buyer_cookie_index` (`buyer_cookie`),
  KEY `mmm_punchout_sessions_shoot_id_status_index` (`shoot_id`,`status`),
  KEY `fk_mmm_punchout_sessions_0_7e219f05ce90` (`user_id`),
  CONSTRAINT `fk_mmm_punchout_sessions_0_7e219f05ce90` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_mmm_punchout_sessions_1_515e522d13b4` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mobile_notification_deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mobile_notification_deliveries` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `device_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `user_id` bigint NOT NULL,
  `event_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `kind` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `source_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'queued',
  `provider_status` bigint DEFAULT NULL,
  `expires_at` datetime(6) NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `not_before` datetime(6) DEFAULT NULL,
  `attempts` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `mobile_notification_deliveries_device_id_event_key_unique` (`device_id`,`event_key`),
  KEY `mobile_notification_deliveries_not_before_index` (`not_before`),
  KEY `mobile_notification_deliveries_status_created_at_index` (`status`,`created_at`),
  KEY `fk_mobile_notification_deliveries_0_7c25c954b9fa` (`user_id`),
  CONSTRAINT `fk_mobile_notification_deliveries_0_7c25c954b9fa` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mobile_notification_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mobile_notification_devices` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `user_id` bigint NOT NULL,
  `personal_access_token_id` bigint NOT NULL,
  `token` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `token_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `sequence` bigint NOT NULL,
  `environment` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `permission` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `expires_at` datetime(6) NOT NULL,
  `revoked_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mobile_notification_devices_token_hash_unique` (`token_hash`),
  KEY `mobile_notification_devices_personal_access_token_id_index` (`personal_access_token_id`),
  KEY `fk_mobile_notification_devices_0_25b9b6b1bf25` (`user_id`),
  CONSTRAINT `fk_mobile_notification_devices_0_25b9b6b1bf25` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notification_read_states`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_read_states` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `context` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `last_seen_at` bigint DEFAULT NULL,
  `read_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `notification_read_states_user_id_context_unique` (`user_id`,`context`),
  CONSTRAINT `fk_notification_read_states_0_984ff33962af` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_tokens` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `provider` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `access_token` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `refresh_token` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `expires_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `user_id` bigint DEFAULT NULL,
  `account_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'shared'),
  `scopes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `provider_account_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `provider_account_email` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `provider_account_name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  PRIMARY KEY (`id`),
  KEY `fk_oauth_tokens_0_aabd5a41e78e` (`user_id`),
  CONSTRAINT `fk_oauth_tokens_0_aabd5a41e78e` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `onboarding_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `onboarding_events` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `role` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `onboarding_key` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `version` bigint DEFAULT NULL,
  `event_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `step_index` bigint DEFAULT NULL,
  `step_target` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `session_uuid` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `source` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `onboarding_events_session_uuid_index` (`session_uuid`),
  KEY `onboarding_events_role_event_type_created_at_index` (`role`,`event_type`,`created_at`),
  KEY `onboarding_events_user_id_index` (`user_id`),
  CONSTRAINT `fk_onboarding_events_0_6beb4628212d` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `token` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payment_refunds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_refunds` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `payment_id` bigint NOT NULL,
  `shoot_id` bigint DEFAULT NULL,
  `amount` decimal(65,30) NOT NULL,
  `provider` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `provider_refund_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `reason` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_by` bigint DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'succeeded'),
  `operation_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_refunds_payment_operation_unique` (`payment_id`,`operation_key`),
  UNIQUE KEY `payment_refunds_payment_provider_id_unique` (`payment_id`,`provider`,`provider_refund_id`),
  KEY `payment_refunds_shoot_id_index` (`shoot_id`),
  KEY `payment_refunds_payment_id_index` (`payment_id`),
  KEY `fk_payment_refunds_0_83afa23d2bd1` (`created_by`),
  CONSTRAINT `fk_payment_refunds_0_83afa23d2bd1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_payment_refunds_1_d4138843e00d` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_payment_refunds_2_295c18e1d920` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payment_reminders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_reminders` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `scheduled_date` date NOT NULL,
  `scheduled_at` datetime(6) DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'pending',
  `message_id` bigint DEFAULT NULL,
  `sent_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_reminders_shoot_date_unique` (`shoot_id`,`scheduled_date`),
  KEY `payment_reminders_status_scheduled_at_index` (`status`,`scheduled_at`),
  KEY `fk_payment_reminders_0_0d65c8013f24` (`message_id`),
  CONSTRAINT `fk_payment_reminders_0_0d65c8013f24` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_payment_reminders_1_70adcb2f35be` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payment_service_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_service_allocations` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `payment_id` bigint NOT NULL,
  `shoot_service_id` bigint NOT NULL,
  `amount` decimal(65,30) NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_service_allocations_payment_id_shoot_service_id_unique` (`payment_id`,`shoot_service_id`),
  KEY `payment_service_allocations_shoot_service_id_index` (`shoot_service_id`),
  CONSTRAINT `fk_payment_service_allocations_0_d2a1f00a86a5` FOREIGN KEY (`shoot_service_id`) REFERENCES `shoot_service` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payment_service_allocations_1_86eeaec31e5a` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_allocations_INSERT` AFTER INSERT ON `payment_service_allocations` FOR EACH ROW BEGIN INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT s.id,s.client_id,'allocations.INSERT' FROM shoots s JOIN shoot_service ss ON ss.shoot_id=s.id WHERE ss.id=NEW.shoot_service_id; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_allocations_UPDATE` AFTER UPDATE ON `payment_service_allocations` FOR EACH ROW BEGIN INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT s.id,s.client_id,'allocations.UPDATE' FROM shoots s JOIN shoot_service ss ON ss.shoot_id=s.id WHERE ss.id=NEW.shoot_service_id; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_allocations_moved` AFTER UPDATE ON `payment_service_allocations` FOR EACH ROW BEGIN IF NOT (OLD.shoot_service_id <=> NEW.shoot_service_id) THEN INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT s.id,s.client_id,'allocations.moved' FROM shoots s JOIN shoot_service ss ON ss.shoot_id=s.id WHERE ss.id=OLD.shoot_service_id; END IF; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_allocations_DELETE` AFTER DELETE ON `payment_service_allocations` FOR EACH ROW BEGIN INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT s.id,s.client_id,'allocations.DELETE' FROM shoots s JOIN shoot_service ss ON ss.shoot_id=s.id WHERE ss.id=OLD.shoot_service_id; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint DEFAULT NULL,
  `invoice_id` bigint DEFAULT NULL,
  `amount` decimal(65,30) NOT NULL,
  `currency` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'USD'),
  `square_payment_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `square_order_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `processed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `payment_method` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `payment_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `stripe_payment_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `stripe_session_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payments_stripe_session_id_unique` (`stripe_session_id`),
  UNIQUE KEY `payments_square_payment_id_unique` (`square_payment_id`),
  KEY `payments_shoot_id_index` (`shoot_id`),
  KEY `fk_payments_1_a8c68233cbd0` (`invoice_id`),
  CONSTRAINT `fk_payments_0_cf1637d23a1a` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payments_1_a8c68233cbd0` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_payments_INSERT` AFTER INSERT ON `payments` FOR EACH ROW BEGIN IF NEW.shoot_id IS NOT NULL THEN INSERT INTO aryeo_changes (shoot_id,client_id,kind) VALUES (NEW.shoot_id,(SELECT client_id FROM shoots WHERE id=NEW.shoot_id),'payments.INSERT'); END IF; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_payments_UPDATE` AFTER UPDATE ON `payments` FOR EACH ROW BEGIN IF NEW.shoot_id IS NOT NULL THEN INSERT INTO aryeo_changes (shoot_id,client_id,kind) VALUES (NEW.shoot_id,(SELECT client_id FROM shoots WHERE id=NEW.shoot_id),'payments.UPDATE'); END IF; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_payments_moved` AFTER UPDATE ON `payments` FOR EACH ROW BEGIN IF NOT (OLD.shoot_id <=> NEW.shoot_id) AND OLD.shoot_id IS NOT NULL THEN INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT id,client_id,'payments.moved' FROM shoots WHERE id=OLD.shoot_id; END IF; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_payment_status` AFTER UPDATE ON `payments` FOR EACH ROW BEGIN INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT DISTINCT s.id,s.client_id,'payment.status' FROM shoots s JOIN shoot_service ss ON ss.shoot_id=s.id JOIN payment_service_allocations a ON a.shoot_service_id=ss.id WHERE a.payment_id=NEW.id; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_bin */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_cascade_delete_payments` BEFORE DELETE ON `payments` FOR EACH ROW BEGIN
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'allocations.DELETE' FROM `payment_service_allocations` c0 JOIN shoot_service feed_ss ON feed_ss.id=c0.shoot_service_id JOIN shoots feed_s ON feed_s.id=feed_ss.shoot_id WHERE c0.`payment_id` = OLD.`id`;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_payments_DELETE` AFTER DELETE ON `payments` FOR EACH ROW BEGIN IF OLD.shoot_id IS NOT NULL THEN INSERT INTO aryeo_changes (shoot_id,client_id,kind) VALUES (OLD.shoot_id,(SELECT client_id FROM shoots WHERE id=OLD.shoot_id),'payments.DELETE'); END IF; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `payout_generation_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payout_generation_locks` (
  `lock_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `recipient_role` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`lock_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payout_work_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payout_work_allocations` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `role` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `recipient_id` bigint NOT NULL,
  `work_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `invoice_id` bigint NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payout_work_once` (`role`,`recipient_id`,`work_key`),
  KEY `fk_payout_work_allocations_0_5680f08cb959` (`invoice_id`),
  CONSTRAINT `fk_payout_work_allocations_0_5680f08cb959` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `tokenable_id` bigint NOT NULL,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `abilities` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `last_used_at` datetime(6) DEFAULT NULL,
  `expires_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `photographer_address_change_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `photographer_address_change_requests` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `street_address` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `city` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `zip` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'pending',
  `submitted_at` datetime(6) DEFAULT NULL,
  `reviewed_by` bigint DEFAULT NULL,
  `reviewed_at` datetime(6) DEFAULT NULL,
  `review_note` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `photographer_address_change_requests_user_id_status_index` (`user_id`,`status`),
  KEY `fk_photographer_address_change_requests_0_e3bb65122d66` (`reviewed_by`),
  CONSTRAINT `fk_photographer_address_change_requests_0_e3bb65122d66` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_photographer_address_change_requests_1_52fd7ec9b4d0` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `photographer_availabilities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `photographer_availabilities` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `photographer_id` bigint NOT NULL,
  `day_of_week` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `start_time` time(6) NOT NULL,
  `end_time` time(6) NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `date` date DEFAULT NULL,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'available'),
  `note` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  PRIMARY KEY (`id`),
  KEY `fk_photographer_availabilities_0_3a56c1eb5eee` (`photographer_id`),
  CONSTRAINT `fk_photographer_availabilities_0_3a56c1eb5eee` FOREIGN KEY (`photographer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `photographer_equipment_photos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `photographer_equipment_photos` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `equipment_id` bigint NOT NULL,
  `uploaded_by` bigint DEFAULT NULL,
  `type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `disk` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'local'),
  `path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `original_name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `mime_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `size` bigint DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `photographer_equipment_photos_equipment_id_type_index` (`equipment_id`,`type`),
  KEY `fk_photographer_equipment_photos_0_3563165b43ba` (`uploaded_by`),
  CONSTRAINT `fk_photographer_equipment_photos_0_3563165b43ba` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_photographer_equipment_photos_1_a50216994204` FOREIGN KEY (`equipment_id`) REFERENCES `photographer_equipments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `photographer_equipments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `photographer_equipments` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `photographer_id` bigint NOT NULL,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `serial_number` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `issue_date` date DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'pending_verification',
  `verification_requested_at` datetime(6) DEFAULT NULL,
  `submitted_at` datetime(6) DEFAULT NULL,
  `verified_at` datetime(6) DEFAULT NULL,
  `rejected_at` datetime(6) DEFAULT NULL,
  `verified_by` bigint DEFAULT NULL,
  `rejection_reason` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `purchase_cost` decimal(65,30) DEFAULT NULL,
  `vendor` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `expense_id` bigint DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `photographer_equipments_photographer_id_status_index` (`photographer_id`,`status`),
  KEY `fk_photographer_equipments_0_d914fe21babd` (`verified_by`),
  CONSTRAINT `fk_photographer_equipments_0_d914fe21babd` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_photographer_equipments_1_334721eeb975` FOREIGN KEY (`photographer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `photographer_service_areas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `photographer_service_areas` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `service_area_id` bigint NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `photographer_service_areas_user_id_service_area_id_unique` (`user_id`,`service_area_id`),
  KEY `fk_photographer_service_areas_0_7dbb85af2775` (`service_area_id`),
  CONSTRAINT `fk_photographer_service_areas_0_7dbb85af2775` FOREIGN KEY (`service_area_id`) REFERENCES `service_areas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_photographer_service_areas_1_21a2399d438b` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_media` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `project_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `team_id` bigint NOT NULL,
  `created_by` bigint NOT NULL,
  `media_ref` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `kind` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `version` bigint NOT NULL DEFAULT '1',
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_media_project_id_kind_index` (`project_id`,`kind`),
  KEY `project_media_team_id_created_at_index` (`team_id`,`created_at`),
  KEY `fk_project_media_0_b25180261e88` (`created_by`),
  CONSTRAINT `fk_project_media_0_b25180261e88` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_project_media_1_e617eacfbec2` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_project_media_0_b25180261e88` CHECK ((`kind` in (_utf8mb4'source',_utf8mb4'output')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `projects` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `team_id` bigint NOT NULL,
  `created_by` bigint NOT NULL,
  `shoot_id` bigint DEFAULT NULL,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `address` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `source_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `workflow_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'draft'),
  `version` bigint NOT NULL DEFAULT '1',
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `request_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `template_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `workflow_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `brand_state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  PRIMARY KEY (`id`),
  UNIQUE KEY `projects_creator_request_unique` (`created_by`,`request_id`),
  KEY `projects_team_id_updated_at_index` (`team_id`,`updated_at`),
  KEY `projects_team_id_created_by_index` (`team_id`,`created_by`),
  KEY `fk_projects_0_45816e0ee02c` (`template_id`),
  KEY `fk_projects_2_1a188d29afc0` (`shoot_id`),
  CONSTRAINT `fk_projects_0_45816e0ee02c` FOREIGN KEY (`template_id`) REFERENCES `templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_projects_1_8132eba48c49` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_projects_2_1a188d29afc0` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `public_payment_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `public_payment_access_tokens` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `created_by` bigint DEFAULT NULL,
  `expires_at` datetime(6) DEFAULT NULL,
  `revoked_at` datetime(6) DEFAULT NULL,
  `first_accessed_at` datetime(6) DEFAULT NULL,
  `last_accessed_at` datetime(6) DEFAULT NULL,
  `access_count` bigint NOT NULL DEFAULT '0',
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `public_payment_access_tokens_token_unique` (`token`),
  KEY `public_payment_access_tokens_expires_at_index` (`expires_at`),
  KEY `public_payment_access_tokens_shoot_id_revoked_at_index` (`shoot_id`,`revoked_at`),
  KEY `fk_public_payment_access_tokens_0_26420b7bdd03` (`created_by`),
  CONSTRAINT `fk_public_payment_access_tokens_0_26420b7bdd03` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_public_payment_access_tokens_1_3288b6185900` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scheduled_voice_calls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scheduled_voice_calls` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'scheduled',
  `automation_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `target_phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `from_phone` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `caller_user_id` bigint DEFAULT NULL,
  `caller_contact_id` bigint DEFAULT NULL,
  `related_shoot_id` bigint DEFAULT NULL,
  `related_invoice_id` bigint DEFAULT NULL,
  `original_voice_call_id` bigint DEFAULT NULL,
  `result_voice_call_id` bigint DEFAULT NULL,
  `created_by_user_id` bigint DEFAULT NULL,
  `scheduled_at` datetime(6) DEFAULT NULL,
  `next_attempt_at` datetime(6) DEFAULT NULL,
  `last_attempt_at` datetime(6) DEFAULT NULL,
  `completed_at` datetime(6) DEFAULT NULL,
  `attempts` bigint NOT NULL DEFAULT '0',
  `max_attempts` bigint NOT NULL DEFAULT '3',
  `quiet_hours` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `summary` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `last_error` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `scheduled_voice_calls_next_attempt_at_index` (`next_attempt_at`),
  KEY `scheduled_voice_calls_scheduled_at_index` (`scheduled_at`),
  KEY `scheduled_voice_calls_target_phone_index` (`target_phone`),
  KEY `scheduled_voice_calls_reason_index` (`reason`),
  KEY `scheduled_voice_calls_automation_type_index` (`automation_type`),
  KEY `scheduled_voice_calls_status_index` (`status`),
  KEY `scheduled_voice_calls_status_next_attempt_at_index` (`status`,`next_attempt_at`),
  KEY `fk_scheduled_voice_calls_0_d3b5b2e1f171` (`created_by_user_id`),
  KEY `fk_scheduled_voice_calls_1_04002cbfd30a` (`result_voice_call_id`),
  KEY `fk_scheduled_voice_calls_2_1acd8b0cfa2e` (`original_voice_call_id`),
  KEY `fk_scheduled_voice_calls_3_39292014db15` (`related_invoice_id`),
  KEY `fk_scheduled_voice_calls_4_db5fc2060053` (`related_shoot_id`),
  KEY `fk_scheduled_voice_calls_5_1f6ee3c41ee3` (`caller_contact_id`),
  KEY `fk_scheduled_voice_calls_6_c2c99daf77df` (`caller_user_id`),
  CONSTRAINT `fk_scheduled_voice_calls_0_d3b5b2e1f171` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_scheduled_voice_calls_1_04002cbfd30a` FOREIGN KEY (`result_voice_call_id`) REFERENCES `voice_calls` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_scheduled_voice_calls_2_1acd8b0cfa2e` FOREIGN KEY (`original_voice_call_id`) REFERENCES `voice_calls` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_scheduled_voice_calls_3_39292014db15` FOREIGN KEY (`related_invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_scheduled_voice_calls_4_db5fc2060053` FOREIGN KEY (`related_shoot_id`) REFERENCES `shoots` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_scheduled_voice_calls_5_1f6ee3c41ee3` FOREIGN KEY (`caller_contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_scheduled_voice_calls_6_c2c99daf77df` FOREIGN KEY (`caller_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scheduling_route_usage`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scheduling_route_usage` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `elements` bigint NOT NULL,
  `reserved_at` datetime(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `scheduling_route_usage_reserved_at_index` (`reserved_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `september_import_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `september_import_assets` (
  `external_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `snapshot_at` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `source_asset_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `shoot_id` bigint NOT NULL,
  `shoot_file_id` bigint DEFAULT NULL,
  `kind` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `sha256` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `bytes` bigint NOT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  PRIMARY KEY (`external_key`,`snapshot_at`,`source_asset_key`),
  KEY `fk_september_import_assets_0_4c520ecf1715` (`shoot_file_id`),
  KEY `fk_september_import_assets_1_bdc3afc3cae3` (`shoot_id`),
  CONSTRAINT `fk_september_import_assets_0_4c520ecf1715` FOREIGN KEY (`shoot_file_id`) REFERENCES `shoot_files` (`id`),
  CONSTRAINT `fk_september_import_assets_1_bdc3afc3cae3` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `september_import_ledger`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `september_import_ledger` (
  `external_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `snapshot_at` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `source_sha256` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `shoot_id` bigint DEFAULT NULL,
  `outcome` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `source_record` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `stored_media` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `created_at` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `error` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  PRIMARY KEY (`external_key`,`snapshot_at`),
  KEY `fk_september_import_ledger_0_d7f01f8be830` (`shoot_id`),
  CONSTRAINT `fk_september_import_ledger_0_d7f01f8be830` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_areas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_areas` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `kind` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `value` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `label` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `service_areas_kind_value_index` (`kind`,`value`),
  CONSTRAINT `ck_service_areas_0_c8d1da1cf6ac` CHECK ((`kind` in (_utf8mb4'region',_utf8mb4'state',_utf8mb4'area')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_duration_policy_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_duration_policy_snapshots` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `source_table` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `source_id` bigint NOT NULL,
  `before_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `applied_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `applied_at` datetime(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_duration_policy_snapshots_source_table_source_id_unique` (`source_table`,`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_group_service`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_group_service` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `service_group_id` bigint NOT NULL,
  `service_id` bigint NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_group_service_service_group_id_service_id_unique` (`service_group_id`,`service_id`),
  KEY `fk_service_group_service_0_9033eeace954` (`service_id`),
  CONSTRAINT `fk_service_group_service_0_9033eeace954` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_service_group_service_1_07c153a395ed` FOREIGN KEY (`service_group_id`) REFERENCES `service_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_group_user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_group_user` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `service_group_id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_group_user_service_group_id_user_id_unique` (`service_group_id`,`user_id`),
  KEY `fk_service_group_user_0_b66971ceb9e9` (`user_id`),
  CONSTRAINT `fk_service_group_user_0_b66971ceb9e9` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_service_group_user_1_51ae960cdd50` FOREIGN KEY (`service_group_id`) REFERENCES `service_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_groups` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `description` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `is_active` bigint NOT NULL DEFAULT '1',
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `is_default` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_groups_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_sqft_ranges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_sqft_ranges` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `service_id` bigint NOT NULL,
  `sqft_from` bigint NOT NULL,
  `sqft_to` bigint NOT NULL,
  `duration` bigint DEFAULT NULL,
  `price` decimal(65,30) NOT NULL,
  `photographer_pay` decimal(65,30) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `photo_count` bigint DEFAULT NULL,
  `photographer_pay_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'fixed'),
  `photographer_pay_percent` decimal(65,30) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `service_sqft_ranges_service_id_sqft_from_sqft_to_index` (`service_id`,`sqft_from`,`sqft_to`),
  CONSTRAINT `fk_service_sqft_ranges_0_b41faba5a3a6` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `services` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `description` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `price` decimal(65,30) NOT NULL,
  `delivery_time` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `category_id` bigint NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `icon` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `photographer_required` bigint NOT NULL DEFAULT '0',
  `photographer_pay` decimal(65,30) DEFAULT NULL,
  `photo_count` bigint DEFAULT NULL,
  `pricing_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'fixed'),
  `allow_multiple` bigint NOT NULL DEFAULT '0',
  `quantity` bigint DEFAULT NULL,
  `exclude_from_sales_commission` bigint NOT NULL DEFAULT '0',
  `requires_editing` bigint NOT NULL DEFAULT '1',
  `photographer_pay_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'fixed'),
  `photographer_pay_percent` decimal(65,30) DEFAULT NULL,
  `uses_hdr_brackets` bigint NOT NULL DEFAULT '0',
  `upload_intake_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'none',
  `is_migration_only` bigint NOT NULL DEFAULT '0',
  `shoot_duration_minutes` bigint DEFAULT NULL,
  `included_components` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  PRIMARY KEY (`id`),
  KEY `services_is_migration_only_index` (`is_migration_only`),
  KEY `services_upload_intake_type_index` (`upload_intake_type`),
  KEY `fk_services_0_3cae7382d586` (`category_id`),
  CONSTRAINT `fk_services_0_3cae7382d586` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_services_0_3cae7382d586` CHECK ((`pricing_type` in (_utf8mb4'fixed',_utf8mb4'variable')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_bin */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_cascade_delete_services` BEFORE DELETE ON `services` FOR EACH ROW BEGIN
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT c0.id,c0.client_id,'shoots.DELETE' FROM `shoots` c0  WHERE c0.`service_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_service.DELETE' FROM `shoot_service` c0 JOIN `shoots` c1 ON c0.`shoot_id` = c1.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c1.`service_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_service.DELETE' FROM `shoot_service` c0 JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c0.`service_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_units.DELETE' FROM `shoot_units` c0 JOIN `shoots` c1 ON c0.`shoot_id` = c1.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c1.`service_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_files.UPDATE' FROM `shoot_files` c0 JOIN `shoot_raw_upload_batches` c1 ON c0.`raw_upload_batch_id` = c1.`id` JOIN `shoots` c2 ON c1.`shoot_id` = c2.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c2.`service_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_files.UPDATE' FROM `shoot_files` c0 JOIN `ai_editing_jobs` c1 ON c0.`ai_editing_job_id` = c1.`id` JOIN `shoots` c2 ON c1.`shoot_id` = c2.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c2.`service_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_files.UPDATE' FROM `shoot_files` c0 JOIN `shoot_media_albums` c1 ON c0.`album_id` = c1.`id` JOIN `shoots` c2 ON c1.`shoot_id` = c2.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c2.`service_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_files.DELETE' FROM `shoot_files` c0 JOIN `shoots` c1 ON c0.`shoot_id` = c1.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c1.`service_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_files.UPDATE' FROM `shoot_files` c0 JOIN `shoot_service` c1 ON c0.`shoot_service_id` = c1.`id` JOIN `shoots` c2 ON c1.`shoot_id` = c2.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c2.`service_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_files.UPDATE' FROM `shoot_files` c0 JOIN `shoot_service` c1 ON c0.`shoot_service_id` = c1.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c1.`service_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'payments.DELETE' FROM `payments` c0 JOIN `shoots` c1 ON c0.`shoot_id` = c1.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c1.`service_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'payment.status' FROM `payments` c0 JOIN `shoots` c1 ON c0.`shoot_id` = c1.`id` JOIN payment_service_allocations feed_a ON feed_a.payment_id=c0.id JOIN shoot_service feed_ss ON feed_ss.id=feed_a.shoot_service_id JOIN shoots feed_s ON feed_s.id=feed_ss.shoot_id WHERE c1.`service_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'allocations.DELETE' FROM `payment_service_allocations` c0 JOIN `shoot_service` c1 ON c0.`shoot_service_id` = c1.`id` JOIN `shoots` c2 ON c1.`shoot_id` = c2.`id` JOIN shoot_service feed_ss ON feed_ss.id=c0.shoot_service_id JOIN shoots feed_s ON feed_s.id=feed_ss.shoot_id WHERE c2.`service_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'allocations.DELETE' FROM `payment_service_allocations` c0 JOIN `shoot_service` c1 ON c0.`shoot_service_id` = c1.`id` JOIN shoot_service feed_ss ON feed_ss.id=c0.shoot_service_id JOIN shoots feed_s ON feed_s.id=feed_ss.shoot_id WHERE c1.`service_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'allocations.DELETE' FROM `payment_service_allocations` c0 JOIN `payments` c1 ON c0.`payment_id` = c1.`id` JOIN `shoots` c2 ON c1.`shoot_id` = c2.`id` JOIN shoot_service feed_ss ON feed_ss.id=c0.shoot_service_id JOIN shoots feed_s ON feed_s.id=feed_ss.shoot_id WHERE c2.`service_id` = OLD.`id`;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `user_id` bigint DEFAULT NULL,
  `ip_address` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `user_agent` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `last_activity` bigint NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_last_activity_index` (`last_activity`),
  KEY `sessions_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'string'),
  `description` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settings_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shoot_activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shoot_activity_logs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `user_id` bigint DEFAULT NULL,
  `action` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `description` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shoot_activity_logs_shoot_id_created_at_index` (`shoot_id`,`created_at`),
  KEY `shoot_activity_logs_shoot_id_action_index` (`shoot_id`,`action`),
  KEY `fk_shoot_activity_logs_0_6db77fa65e19` (`user_id`),
  CONSTRAINT `fk_shoot_activity_logs_0_6db77fa65e19` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoot_activity_logs_1_b2d08b935348` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shoot_additional_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shoot_additional_files` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `shoot_service_id` bigint DEFAULT NULL,
  `uploaded_by` bigint NOT NULL,
  `entry_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `filename` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `mime_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `file_size` bigint NOT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shoot_additional_entry_unique` (`shoot_id`,`uploaded_by`,`entry_key`),
  KEY `fk_shoot_additional_files_0_9ac25d438ace` (`uploaded_by`),
  KEY `fk_shoot_additional_files_1_17009445cbde` (`shoot_service_id`),
  CONSTRAINT `fk_shoot_additional_files_0_9ac25d438ace` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_shoot_additional_files_1_17009445cbde` FOREIGN KEY (`shoot_service_id`) REFERENCES `shoot_service` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoot_additional_files_2_5a4516fa46e6` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shoot_compensations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shoot_compensations` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `shoot_service_id` bigint DEFAULT NULL,
  `scope_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `recipient_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `recipient_user_id` bigint DEFAULT NULL,
  `mode` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `suggested_mode` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `calculation_method` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `standard_calculation_method` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `quantity_snapshot` bigint NOT NULL DEFAULT '1',
  `basis_amount_snapshot` decimal(65,30) DEFAULT NULL,
  `rate_snapshot` decimal(65,30) DEFAULT NULL,
  `standard_rate_snapshot` decimal(65,30) DEFAULT NULL,
  `amount` decimal(65,30) DEFAULT NULL,
  `suggested_amount` decimal(65,30) DEFAULT NULL,
  `standard_amount_snapshot` decimal(65,30) NOT NULL DEFAULT '0.000000000000000000000000000000',
  `currency` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'USD'),
  `reason_code` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `policy_version` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `earned_at` datetime(6) DEFAULT NULL,
  `locked_at` datetime(6) DEFAULT NULL,
  `voided_at` datetime(6) DEFAULT NULL,
  `voided_by` bigint DEFAULT NULL,
  `void_reason` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_by` bigint DEFAULT NULL,
  `updated_by` bigint DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `line_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'base',
  `adjusts_compensation_id` bigint DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shoot_compensations_scope_unique` (`shoot_id`,`recipient_type`,`scope_key`),
  KEY `shoot_compensations_adjustment_index` (`adjusts_compensation_id`,`line_type`),
  KEY `shoot_compensations_shoot_voided_index` (`shoot_id`,`voided_at`),
  KEY `shoot_compensations_recipient_earned_index` (`recipient_user_id`,`recipient_type`,`earned_at`),
  KEY `fk_shoot_compensations_2_b614e4211b3f` (`shoot_service_id`),
  KEY `fk_shoot_compensations_4_1cdb8631bdb3` (`voided_by`),
  KEY `fk_shoot_compensations_5_8248647651de` (`created_by`),
  KEY `fk_shoot_compensations_6_510d96b9f071` (`updated_by`),
  CONSTRAINT `fk_shoot_compensations_0_44586731a40f` FOREIGN KEY (`adjusts_compensation_id`) REFERENCES `shoot_compensations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_shoot_compensations_1_b26b2f7a1038` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_shoot_compensations_2_b614e4211b3f` FOREIGN KEY (`shoot_service_id`) REFERENCES `shoot_service` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoot_compensations_3_10098dfc1275` FOREIGN KEY (`recipient_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoot_compensations_4_1cdb8631bdb3` FOREIGN KEY (`voided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoot_compensations_5_8248647651de` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoot_compensations_6_510d96b9f071` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shoot_editing_dispatch_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shoot_editing_dispatch_items` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `dispatch_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `input_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `workflow` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `sources` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `shoot_service_id` bigint DEFAULT NULL,
  `lane` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `destination` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `editor_id` bigint DEFAULT NULL,
  `workspace_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'queued',
  `primary_version_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `return_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `error` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `overview_return_files` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shoot_editing_dispatch_items_dispatch_id_input_key_unique` (`dispatch_id`,`input_key`),
  KEY `shoot_editing_dispatch_items_workspace_id_index` (`workspace_id`),
  KEY `shoot_editing_dispatch_items_editor_id_status_index` (`editor_id`,`status`),
  CONSTRAINT `fk_shoot_editing_dispatch_items_0_8d98b9d1f3d1` FOREIGN KEY (`editor_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_shoot_editing_dispatch_items_1_a4c0a74d51eb` FOREIGN KEY (`dispatch_id`) REFERENCES `shoot_editing_dispatches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shoot_editing_dispatches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shoot_editing_dispatches` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `request_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `shoot_id` bigint NOT NULL,
  `created_by` bigint NOT NULL,
  `scope` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `destination` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `workflow` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `instructions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `input_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `plan` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'queued',
  `error` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shoot_editing_dispatches_request_id_unique` (`request_id`),
  KEY `shoot_editing_dispatches_status_index` (`status`),
  KEY `fk_shoot_editing_dispatches_0_011372b9bb8d` (`created_by`),
  KEY `fk_shoot_editing_dispatches_1_8d0d8ec8ba53` (`shoot_id`),
  CONSTRAINT `fk_shoot_editing_dispatches_0_011372b9bb8d` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_shoot_editing_dispatches_1_8d0d8ec8ba53` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shoot_email_deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shoot_email_deliveries` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `recipient_user_id` bigint DEFAULT NULL,
  `event_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `recipient_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `source` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `reason_code` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `attempt_count` bigint NOT NULL DEFAULT '0',
  `last_attempted_at` datetime(6) DEFAULT NULL,
  `sent_at` datetime(6) DEFAULT NULL,
  `recovered_at` datetime(6) DEFAULT NULL,
  `last_message_id` bigint DEFAULT NULL,
  `last_error_message` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shoot_email_deliveries_unique_event` (`shoot_id`,`event_type`,`recipient_type`),
  KEY `shoot_email_deliveries_recipient_idx` (`recipient_user_id`,`status`),
  KEY `shoot_email_deliveries_status_idx` (`event_type`,`recipient_type`,`status`),
  KEY `fk_shoot_email_deliveries_0_7004c4625c41` (`last_message_id`),
  CONSTRAINT `fk_shoot_email_deliveries_0_7004c4625c41` FOREIGN KEY (`last_message_id`) REFERENCES `messages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoot_email_deliveries_1_77c9b9aa012a` FOREIGN KEY (`recipient_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoot_email_deliveries_2_5053ec3a50e6` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shoot_file_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shoot_file_versions` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `shoot_id` bigint NOT NULL,
  `source_file_id` bigint DEFAULT NULL,
  `target_file_id` bigint DEFAULT NULL,
  `published_file_id` bigint DEFAULT NULL,
  `expected_version` bigint NOT NULL,
  `version` bigint DEFAULT NULL,
  `request_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `sha256` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'queued',
  `snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_by` bigint NOT NULL,
  `dispatch_item_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `error_code` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `error` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shoot_file_versions_request_key_unique` (`request_key`),
  UNIQUE KEY `shoot_file_versions_published_file_id_version_unique` (`published_file_id`,`version`),
  KEY `shoot_file_versions_dispatch_item_id_index` (`dispatch_item_id`),
  KEY `shoot_file_versions_status_index` (`status`),
  KEY `shoot_file_versions_published_file_id_index` (`published_file_id`),
  KEY `shoot_file_versions_target_file_id_index` (`target_file_id`),
  KEY `shoot_file_versions_source_file_id_index` (`source_file_id`),
  KEY `fk_shoot_file_versions_0_e68373b19845` (`created_by`),
  KEY `fk_shoot_file_versions_1_491a579f54df` (`shoot_id`),
  CONSTRAINT `fk_shoot_file_versions_0_e68373b19845` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_shoot_file_versions_1_491a579f54df` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shoot_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shoot_files` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `filename` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `stored_filename` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `file_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `file_size` bigint NOT NULL,
  `uploaded_by` bigint NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `album_id` bigint DEFAULT NULL,
  `media_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'raw'),
  `storage_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `watermarked_storage_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `mime_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `uploaded_at` datetime(6) DEFAULT NULL,
  `is_cover` bigint NOT NULL DEFAULT '0',
  `is_favorite` bigint NOT NULL DEFAULT '0',
  `bracket_group` bigint DEFAULT NULL,
  `sequence` bigint DEFAULT NULL,
  `flag_reason` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `workflow_stage` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'todo',
  `dropbox_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `dropbox_file_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `moved_to_completed_at` datetime(6) DEFAULT NULL,
  `verified_at` datetime(6) DEFAULT NULL,
  `verified_by` bigint DEFAULT NULL,
  `verification_notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `ai_editing_job_id` bigint DEFAULT NULL,
  `is_ai_edited` bigint NOT NULL DEFAULT '0',
  `ai_editing_metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `sort_order` bigint NOT NULL DEFAULT '0',
  `thumbnail_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `web_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `placeholder_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `processed_at` datetime(6) DEFAULT NULL,
  `processing_failed_at` datetime(6) DEFAULT NULL,
  `processing_error` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `watermarked_thumbnail_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `watermarked_web_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `watermarked_placeholder_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `is_hidden` bigint NOT NULL DEFAULT '0',
  `shoot_service_id` bigint DEFAULT NULL,
  `is_extra` bigint NOT NULL DEFAULT '0',
  `required_for_editing` bigint NOT NULL DEFAULT '0',
  `scan_status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'quarantined',
  `scan_result` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `scanned_at` datetime(6) DEFAULT NULL,
  `grid_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `treatment` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `raw_upload_batch_id` bigint DEFAULT NULL,
  `raw_upload_position` bigint DEFAULT NULL,
  `content_version` bigint NOT NULL DEFAULT '1',
  `source_file_id` bigint DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shoot_files_legacy_required_index` (`shoot_id`,`shoot_service_id`,`required_for_editing`),
  KEY `shoot_files_service_required_index` (`shoot_service_id`,`required_for_editing`),
  KEY `shoot_files_shoot_stage_index` (`shoot_id`,`workflow_stage`),
  KEY `shoot_files_source_file_id_index` (`source_file_id`),
  KEY `shoot_files_scan_status_index` (`scan_status`),
  KEY `fk_shoot_files_0_d2f44c2560fb` (`raw_upload_batch_id`),
  KEY `fk_shoot_files_1_cba8c7e433a5` (`ai_editing_job_id`),
  KEY `fk_shoot_files_2_1c4b7165af49` (`album_id`),
  KEY `fk_shoot_files_4_3ce44fcbad75` (`uploaded_by`),
  KEY `fk_shoot_files_5_7f2f4e6cd3e4` (`verified_by`),
  CONSTRAINT `fk_shoot_files_0_d2f44c2560fb` FOREIGN KEY (`raw_upload_batch_id`) REFERENCES `shoot_raw_upload_batches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoot_files_1_cba8c7e433a5` FOREIGN KEY (`ai_editing_job_id`) REFERENCES `ai_editing_jobs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoot_files_2_1c4b7165af49` FOREIGN KEY (`album_id`) REFERENCES `shoot_media_albums` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoot_files_3_1f036a282cb2` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shoot_files_4_3ce44fcbad75` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shoot_files_5_7f2f4e6cd3e4` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_shoot_files_6_6ac7941e6a8a` FOREIGN KEY (`shoot_service_id`) REFERENCES `shoot_service` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_shoot_files_INSERT` AFTER INSERT ON `shoot_files` FOR EACH ROW BEGIN INSERT INTO aryeo_changes (shoot_id,client_id,kind) VALUES (NEW.shoot_id,(SELECT client_id FROM shoots WHERE id=NEW.shoot_id),'shoot_files.INSERT'); END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_shoot_files_UPDATE` AFTER UPDATE ON `shoot_files` FOR EACH ROW BEGIN INSERT INTO aryeo_changes (shoot_id,client_id,kind) VALUES (NEW.shoot_id,(SELECT client_id FROM shoots WHERE id=NEW.shoot_id),'shoot_files.UPDATE'); END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_shoot_files_moved` AFTER UPDATE ON `shoot_files` FOR EACH ROW BEGIN IF NOT (OLD.shoot_id <=> NEW.shoot_id) AND OLD.shoot_id IS NOT NULL THEN INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT id,client_id,'shoot_files.moved' FROM shoots WHERE id=OLD.shoot_id; END IF; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_shoot_files_DELETE` AFTER DELETE ON `shoot_files` FOR EACH ROW BEGIN INSERT INTO aryeo_changes (shoot_id,client_id,kind) VALUES (OLD.shoot_id,(SELECT client_id FROM shoots WHERE id=OLD.shoot_id),'shoot_files.DELETE'); END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `shoot_ghost_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shoot_ghost_users` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shoot_ghost_users_shoot_id_user_id_unique` (`shoot_id`,`user_id`),
  KEY `fk_shoot_ghost_users_0_dda311e285a2` (`user_id`),
  CONSTRAINT `fk_shoot_ghost_users_0_dda311e285a2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shoot_ghost_users_1_ffd82820d093` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shoot_media_albums`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shoot_media_albums` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `photographer_id` bigint DEFAULT NULL,
  `source` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'dropbox'),
  `folder_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `cover_image_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `is_watermarked` bigint NOT NULL DEFAULT '0',
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `shoot_service_id` bigint DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shoot_media_albums_shoot_id_photographer_id_index` (`shoot_id`,`photographer_id`),
  KEY `fk_shoot_media_albums_0_09483f9b2677` (`shoot_service_id`),
  KEY `fk_shoot_media_albums_2_d653358e7efa` (`photographer_id`),
  CONSTRAINT `fk_shoot_media_albums_0_09483f9b2677` FOREIGN KEY (`shoot_service_id`) REFERENCES `shoot_service` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoot_media_albums_1_306c2da9c88c` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shoot_media_albums_2_d653358e7efa` FOREIGN KEY (`photographer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_bin */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_cascade_delete_shoot_media_albums` BEFORE DELETE ON `shoot_media_albums` FOR EACH ROW BEGIN
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_files.UPDATE' FROM `shoot_files` c0 JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c0.`album_id` = OLD.`id`;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `shoot_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shoot_messages` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `sender_id` bigint DEFAULT NULL,
  `recipient_id` bigint DEFAULT NULL,
  `message` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `read_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_shoot_messages_0_3105f420bf35` (`recipient_id`),
  KEY `fk_shoot_messages_1_8caac3dba476` (`sender_id`),
  KEY `fk_shoot_messages_2_7c1ce80b7cae` (`shoot_id`),
  CONSTRAINT `fk_shoot_messages_0_3105f420bf35` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoot_messages_1_8caac3dba476` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoot_messages_2_7c1ce80b7cae` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shoot_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shoot_notes` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `author_id` bigint DEFAULT NULL,
  `type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'shoot',
  `visibility` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'internal',
  `content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `source` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `source_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shoot_notes_source_hash_unique` (`source_hash`),
  KEY `shoot_notes_shoot_id_visibility_index` (`shoot_id`,`visibility`),
  KEY `shoot_notes_shoot_id_type_index` (`shoot_id`,`type`),
  KEY `fk_shoot_notes_0_98fe07e7be2e` (`author_id`),
  CONSTRAINT `fk_shoot_notes_0_98fe07e7be2e` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shoot_notes_1_243f36df002c` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_shoot_notes_0_98fe07e7be2e` CHECK ((`type` in (_utf8mb4'shoot',_utf8mb4'company',_utf8mb4'photographer',_utf8mb4'editing',_utf8mb4'approval')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shoot_raw_upload_batch_slots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shoot_raw_upload_batch_slots` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `batch_id` bigint NOT NULL,
  `file_index` bigint NOT NULL,
  `idempotency_key` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shoot_raw_upload_batch_slots_batch_id_file_index_unique` (`batch_id`,`file_index`),
  CONSTRAINT `fk_shoot_raw_upload_batch_slots_0_4fea95c4f00d` FOREIGN KEY (`batch_id`) REFERENCES `shoot_raw_upload_batches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shoot_raw_upload_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shoot_raw_upload_batches` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `actor_id` bigint NOT NULL,
  `service_scope` bigint NOT NULL DEFAULT '0',
  `batch_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `upload_lane` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'photo',
  `bracket_mode` bigint NOT NULL DEFAULT '0',
  `total_files` bigint NOT NULL,
  `start_position` bigint NOT NULL,
  `prepared` bigint NOT NULL DEFAULT '0',
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shoot_raw_upload_batches_shoot_id_batch_id_unique` (`shoot_id`,`batch_id`),
  KEY `idx_shoot_raw_upload_batches_shoot_id_service_sco_f866cfdb32fe` (`shoot_id`,`service_scope`,`upload_lane`),
  CONSTRAINT `fk_shoot_raw_upload_batches_0_2fcec4a385b4` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_bin */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_cascade_delete_shoot_raw_upload_batches` BEFORE DELETE ON `shoot_raw_upload_batches` FOR EACH ROW BEGIN
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_files.UPDATE' FROM `shoot_files` c0 JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c0.`raw_upload_batch_id` = OLD.`id`;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `shoot_reschedule_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shoot_reschedule_requests` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `requested_by` bigint DEFAULT NULL,
  `approved_by` bigint DEFAULT NULL,
  `original_date` date DEFAULT NULL,
  `requested_date` date NOT NULL,
  `requested_time` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `reason` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'pending'),
  `reviewed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `original_time` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `applied_at` datetime(6) DEFAULT NULL,
  `review_notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `units_revision` bigint DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_shoot_reschedule_requests_0_f5892b0d85db` (`approved_by`),
  KEY `fk_shoot_reschedule_requests_1_bd62da4514f1` (`requested_by`),
  KEY `fk_shoot_reschedule_requests_2_84ae95f9e9af` (`shoot_id`),
  CONSTRAINT `fk_shoot_reschedule_requests_0_f5892b0d85db` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoot_reschedule_requests_1_bd62da4514f1` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoot_reschedule_requests_2_84ae95f9e9af` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_shoot_reschedule_requests_0_f5892b0d85db` CHECK ((`status` in (_utf8mb4'pending',_utf8mb4'approved',_utf8mb4'rejected')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shoot_service`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shoot_service` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `service_id` bigint NOT NULL,
  `price` decimal(65,30) DEFAULT NULL,
  `quantity` bigint NOT NULL DEFAULT '1',
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `photographer_pay` decimal(65,30) DEFAULT NULL,
  `photographer_id` bigint DEFAULT NULL,
  `editor_id` bigint DEFAULT NULL,
  `editing_completed_at` datetime(6) DEFAULT NULL,
  `scheduled_at` datetime(6) DEFAULT NULL,
  `workflow_status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'pending',
  `delivery_status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'not_started',
  `ready_at` datetime(6) DEFAULT NULL,
  `delivered_at` datetime(6) DEFAULT NULL,
  `cancelled_at` datetime(6) DEFAULT NULL,
  `is_deliverable` bigint NOT NULL DEFAULT '1',
  `force_unlock_delivery` bigint NOT NULL DEFAULT '0',
  `unlock_reason` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `unlocked_by` bigint DEFAULT NULL,
  `bracket_mode` bigint DEFAULT NULL,
  `nominal_value_snapshot` decimal(65,30) DEFAULT NULL,
  `video_editor_id` bigint DEFAULT NULL,
  `video_editing_completed_at` datetime(6) DEFAULT NULL,
  `shoot_unit_id` bigint DEFAULT NULL,
  `client_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `duration_minutes` bigint DEFAULT NULL,
  `contracted_photo_count` bigint DEFAULT NULL,
  `payout_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `active_work_link_output` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shoot_service_unit_catalog_unique` (`shoot_id`,`service_id`,(coalesce(`shoot_unit_id`,0))),
  UNIQUE KEY `shoot_service_client_key_unique` (`shoot_id`,`client_key`),
  KEY `shoot_service_workflow_status_index` (`workflow_status`),
  KEY `shoot_service_scheduled_at_index` (`scheduled_at`),
  KEY `shoot_service_photographer_id_index` (`photographer_id`),
  KEY `shoot_service_is_deliverable_index` (`is_deliverable`),
  KEY `shoot_service_force_unlock_delivery_index` (`force_unlock_delivery`),
  KEY `shoot_service_editor_id_index` (`editor_id`),
  KEY `shoot_service_delivery_status_index` (`delivery_status`),
  KEY `fk_shoot_service_0_3eae1ffa0f82` (`shoot_unit_id`),
  KEY `fk_shoot_service_1_96f49574e0e9` (`unlocked_by`),
  KEY `fk_shoot_service_4_6ea8fa977af8` (`service_id`),
  KEY `fk_shoot_service_6_19b3c19eff97` (`video_editor_id`),
  CONSTRAINT `fk_shoot_service_0_3eae1ffa0f82` FOREIGN KEY (`shoot_unit_id`) REFERENCES `shoot_units` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_shoot_service_1_96f49574e0e9` FOREIGN KEY (`unlocked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoot_service_2_0003f1464834` FOREIGN KEY (`photographer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoot_service_3_5c2e9cb22934` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shoot_service_4_6ea8fa977af8` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shoot_service_5_58de69391218` FOREIGN KEY (`editor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoot_service_6_19b3c19eff97` FOREIGN KEY (`video_editor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_shoot_service_INSERT` AFTER INSERT ON `shoot_service` FOR EACH ROW BEGIN INSERT INTO aryeo_changes (shoot_id,client_id,kind) VALUES (NEW.shoot_id,(SELECT client_id FROM shoots WHERE id = NEW.shoot_id),'shoot_service.INSERT'); END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_shoot_service_UPDATE` AFTER UPDATE ON `shoot_service` FOR EACH ROW BEGIN INSERT INTO aryeo_changes (shoot_id,client_id,kind) VALUES (NEW.shoot_id,(SELECT client_id FROM shoots WHERE id = NEW.shoot_id),'shoot_service.UPDATE'); END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_shoot_service_moved` AFTER UPDATE ON `shoot_service` FOR EACH ROW BEGIN IF NOT (OLD.shoot_id <=> NEW.shoot_id) THEN INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT id,client_id,'shoot_service.moved' FROM shoots WHERE id=OLD.shoot_id; END IF; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_bin */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_cascade_delete_shoot_service` BEFORE DELETE ON `shoot_service` FOR EACH ROW BEGIN
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_files.UPDATE' FROM `shoot_files` c0 JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c0.`shoot_service_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'allocations.DELETE' FROM `payment_service_allocations` c0 JOIN shoot_service feed_ss ON feed_ss.id=c0.shoot_service_id JOIN shoots feed_s ON feed_s.id=feed_ss.shoot_id WHERE c0.`shoot_service_id` = OLD.`id`;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_shoot_service_DELETE` AFTER DELETE ON `shoot_service` FOR EACH ROW BEGIN INSERT INTO aryeo_changes (shoot_id,client_id,kind) VALUES (OLD.shoot_id,(SELECT client_id FROM shoots WHERE id = OLD.shoot_id),'shoot_service.DELETE'); END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `shoot_share_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shoot_share_links` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `created_by` bigint NOT NULL,
  `share_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `dropbox_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `download_count` bigint NOT NULL DEFAULT '0',
  `expires_at` datetime(6) DEFAULT NULL,
  `is_revoked` bigint NOT NULL DEFAULT '0',
  `revoked_at` datetime(6) DEFAULT NULL,
  `revoked_by` bigint DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `media_stage` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'raw',
  `public_token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `last_accessed_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shoot_share_links_public_token_unique` (`public_token`),
  KEY `ssl_shoot_stage_revoked_idx` (`shoot_id`,`media_stage`,`is_revoked`),
  KEY `shoot_share_links_created_by_index` (`created_by`),
  KEY `shoot_share_links_shoot_id_is_revoked_index` (`shoot_id`,`is_revoked`),
  KEY `fk_shoot_share_links_0_bff93b2c1a3b` (`revoked_by`),
  CONSTRAINT `fk_shoot_share_links_0_bff93b2c1a3b` FOREIGN KEY (`revoked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoot_share_links_1_6fca6149d29b` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shoot_share_links_2_04581e2edc0b` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shoot_slideshows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shoot_slideshows` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `title` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `orientation` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'landscape'),
  `transition` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'fade'),
  `speed` bigint NOT NULL DEFAULT '3',
  `photo_urls` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `photo_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `ayrshare_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `ayrshare_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `download_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `visible` bigint NOT NULL DEFAULT '1',
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shoot_slideshows_ayrshare_id_index` (`ayrshare_id`),
  KEY `shoot_slideshows_shoot_id_index` (`shoot_id`),
  CONSTRAINT `fk_shoot_slideshows_0_0fff0912c84f` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_shoot_slideshows_0_0fff0912c84f` CHECK ((`orientation` in (_utf8mb4'portrait',_utf8mb4'landscape')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shoot_units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shoot_units` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `client_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `label` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `kind` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'unit'),
  `sqft` bigint DEFAULT NULL,
  `beds` bigint DEFAULT NULL,
  `baths` decimal(65,30) DEFAULT NULL,
  `access_notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `sort_order` bigint NOT NULL DEFAULT '0',
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `tour_links` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `property_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `provider_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `include_common_area_media` bigint NOT NULL DEFAULT '0',
  `property_status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `listing_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shoot_units_shoot_id_client_key_unique` (`shoot_id`,`client_key`),
  CONSTRAINT `fk_shoot_units_0_61b87892f60e` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_shoot_units_INSERT` AFTER INSERT ON `shoot_units` FOR EACH ROW BEGIN INSERT INTO aryeo_changes (shoot_id,client_id,kind) VALUES (NEW.shoot_id,(SELECT client_id FROM shoots WHERE id = NEW.shoot_id),'shoot_units.INSERT'); END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_shoot_units_UPDATE` AFTER UPDATE ON `shoot_units` FOR EACH ROW BEGIN INSERT INTO aryeo_changes (shoot_id,client_id,kind) VALUES (NEW.shoot_id,(SELECT client_id FROM shoots WHERE id = NEW.shoot_id),'shoot_units.UPDATE'); END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_shoot_units_moved` AFTER UPDATE ON `shoot_units` FOR EACH ROW BEGIN IF NOT (OLD.shoot_id <=> NEW.shoot_id) THEN INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT id,client_id,'shoot_units.moved' FROM shoots WHERE id=OLD.shoot_id; END IF; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_shoot_units_DELETE` AFTER DELETE ON `shoot_units` FOR EACH ROW BEGIN INSERT INTO aryeo_changes (shoot_id,client_id,kind) VALUES (OLD.shoot_id,(SELECT client_id FROM shoots WHERE id = OLD.shoot_id),'shoot_units.DELETE'); END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `shoot_upload_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shoot_upload_attempts` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `actor_id` bigint NOT NULL,
  `idempotency_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `request_fingerprint` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `upload_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `upload_batch_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `upload_batch_index` bigint DEFAULT NULL,
  `upload_batch_total` bigint DEFAULT NULL,
  `shoot_service_id` bigint DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'pending',
  `http_status` bigint DEFAULT NULL,
  `result_file_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `result_errors` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `result_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `correlation_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `completed_at` datetime(6) DEFAULT NULL,
  `failed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shoot_upload_attempt_actor_key_unique` (`shoot_id`,`actor_id`,`idempotency_key`),
  KEY `shoot_upload_attempt_batch_index` (`shoot_id`,`upload_batch_id`,`upload_batch_index`),
  KEY `shoot_upload_attempts_status_created_at_index` (`status`,`created_at`),
  KEY `fk_shoot_upload_attempts_0_8a91189602a6` (`shoot_service_id`),
  KEY `fk_shoot_upload_attempts_1_074392e44ffe` (`actor_id`),
  CONSTRAINT `fk_shoot_upload_attempts_0_8a91189602a6` FOREIGN KEY (`shoot_service_id`) REFERENCES `shoot_service` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoot_upload_attempts_1_074392e44ffe` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shoot_upload_attempts_2_989d615cea8f` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shoot_upload_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shoot_upload_batches` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `actor_id` bigint NOT NULL,
  `upload_batch_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `upload_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `shoot_service_id` bigint DEFAULT NULL,
  `bracket_mode` bigint DEFAULT NULL,
  `upload_batch_total` bigint NOT NULL,
  `reserved_offset` bigint NOT NULL,
  `parallel_uploads` bigint NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shoot_upload_batch_actor_key_unique` (`shoot_id`,`actor_id`,`upload_batch_id`),
  KEY `shoot_upload_batch_lookup` (`shoot_id`,`upload_batch_id`),
  KEY `fk_shoot_upload_batches_0_b0dfb0510742` (`shoot_service_id`),
  KEY `fk_shoot_upload_batches_1_6f9738102173` (`actor_id`),
  CONSTRAINT `fk_shoot_upload_batches_0_b0dfb0510742` FOREIGN KEY (`shoot_service_id`) REFERENCES `shoot_service` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoot_upload_batches_1_6f9738102173` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shoot_upload_batches_2_9a0226a59bf0` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shoots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shoots` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `client_id` bigint NOT NULL,
  `photographer_id` bigint DEFAULT NULL,
  `service_id` bigint DEFAULT NULL,
  `address` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `city` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `zip` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `scheduled_date` date DEFAULT NULL,
  `time` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `base_quote` decimal(65,30) NOT NULL,
  `tax_amount` decimal(65,30) NOT NULL,
  `total_quote` decimal(65,30) NOT NULL,
  `payment_status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'unpaid'),
  `payment_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'booked',
  `created_by` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `rep_id` bigint DEFAULT NULL,
  `bypass_paywall` bigint NOT NULL DEFAULT '0',
  `tax_region` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'none'),
  `tax_percent` decimal(65,30) NOT NULL DEFAULT '0.000000000000000000000000000000',
  `scheduled_at` datetime(6) DEFAULT NULL,
  `completed_at` datetime(6) DEFAULT NULL,
  `updated_by` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `mls_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `listing_source` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `property_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `integration_flags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `bright_mls_publish_status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `bright_mls_last_published_at` datetime(6) DEFAULT NULL,
  `bright_mls_response` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `bright_mls_manifest_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `iguide_tour_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `iguide_floorplans` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `iguide_last_synced_at` datetime(6) DEFAULT NULL,
  `iguide_property_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `is_private_listing` bigint NOT NULL DEFAULT '0',
  `package_name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `package_services_included` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `expected_final_count` bigint DEFAULT NULL,
  `bracket_mode` bigint DEFAULT NULL,
  `expected_raw_count` bigint DEFAULT NULL,
  `raw_photo_count` bigint NOT NULL DEFAULT '0',
  `edited_photo_count` bigint NOT NULL DEFAULT '0',
  `raw_missing_count` bigint NOT NULL DEFAULT '0',
  `edited_missing_count` bigint NOT NULL DEFAULT '0',
  `missing_raw` bigint NOT NULL DEFAULT '0',
  `missing_final` bigint NOT NULL DEFAULT '0',
  `hero_image` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `weather_summary` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `weather_temperature` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `workflow_status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'booked',
  `photos_uploaded_at` datetime(6) DEFAULT NULL,
  `editing_completed_at` datetime(6) DEFAULT NULL,
  `admin_verified_at` datetime(6) DEFAULT NULL,
  `verified_by` bigint DEFAULT NULL,
  `service_category` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `shoot_notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `company_notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `photographer_notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `editor_notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `admin_issue_notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `is_flagged` bigint NOT NULL DEFAULT '0',
  `issues_resolved_at` datetime(6) DEFAULT NULL,
  `issues_resolved_by` bigint DEFAULT NULL,
  `submitted_for_review_at` datetime(6) DEFAULT NULL,
  `property_slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `dropbox_raw_folder` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `dropbox_extra_folder` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `dropbox_edited_folder` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `dropbox_archive_folder` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `extra_photo_count` bigint NOT NULL DEFAULT '0',
  `editor_id` bigint DEFAULT NULL,
  `approval_notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `approved_at` datetime(6) DEFAULT NULL,
  `approved_by` bigint DEFAULT NULL,
  `declined_at` datetime(6) DEFAULT NULL,
  `declined_by` bigint DEFAULT NULL,
  `declined_reason` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `tour_links` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `cancellation_requested_at` datetime(6) DEFAULT NULL,
  `cancellation_requested_by` bigint DEFAULT NULL,
  `cancellation_reason` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `mmm_status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `mmm_order_number` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `mmm_buyer_cookie` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `mmm_redirect_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `mmm_last_punchout_at` datetime(6) DEFAULT NULL,
  `mmm_last_order_at` datetime(6) DEFAULT NULL,
  `mmm_last_error` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `hold_requested_at` datetime(6) DEFAULT NULL,
  `hold_requested_by` bigint DEFAULT NULL,
  `hold_reason` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `photographer_paid_at` datetime(6) DEFAULT NULL,
  `photographer_paid_invoice_id` bigint DEFAULT NULL,
  `sales_rep_paid_at` datetime(6) DEFAULT NULL,
  `sales_rep_paid_invoice_id` bigint DEFAULT NULL,
  `listing_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'for_sale'),
  `property_status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'available'),
  `discount_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `discount_value` decimal(65,30) DEFAULT NULL,
  `discount_amount` decimal(65,30) NOT NULL DEFAULT '0.000000000000000000000000000000',
  `is_featured` bigint NOT NULL DEFAULT '0',
  `delivery_status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'not_started',
  `timezone` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `mls_image_width` bigint DEFAULT NULL,
  `iguide_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `iguide_work_order_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `cubicasa_order_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `cubicasa_external_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `cubicasa_status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `cubicasa_product_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `cubicasa_tour_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `cubicasa_floorplans` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `cubicasa_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `cubicasa_last_synced_at` datetime(6) DEFAULT NULL,
  `cubicasa_last_status_at` datetime(6) DEFAULT NULL,
  `is_listing_hidden` bigint NOT NULL DEFAULT '0',
  `shoot_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'standard',
  `product_status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'has_product',
  `cubicasa_sync_status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `cubicasa_sync_job_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `cubicasa_sync_started_at` datetime(6) DEFAULT NULL,
  `cubicasa_last_sync_error` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `service_area_kind` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `service_area_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `shoot_ready_notified_at` datetime(6) DEFAULT NULL,
  `cubicasa_idempotency_key` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `featured_homepage_title` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `featured_homepage_location` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `featured_homepage_subtitle` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `featured_homepage_cta_label` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `featured_homepage_cta_href` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `latitude` decimal(65,30) DEFAULT NULL,
  `longitude` decimal(65,30) DEFAULT NULL,
  `alternate_scheduled_date` date DEFAULT NULL,
  `alternate_time` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `alternate_scheduled_at` datetime(6) DEFAULT NULL,
  `requested_photographers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `external_booking_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `external_booking_warnings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `external_booking_mapping_status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `featured_requested_at` datetime(6) DEFAULT NULL,
  `featured_requested_by` bigint DEFAULT NULL,
  `featured_approved_at` datetime(6) DEFAULT NULL,
  `featured_approved_by` bigint DEFAULT NULL,
  `media_order_version` bigint NOT NULL DEFAULT '0',
  `delivery_media_order` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `delivery_media_order_version` bigint DEFAULT NULL,
  `delivery_media_order_at` datetime(6) DEFAULT NULL,
  `reshoot_of_shoot_id` bigint DEFAULT NULL,
  `root_shoot_id` bigint DEFAULT NULL,
  `complimentary_reshoot_idempotency_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `complimentary_reshoot_request_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `sales_rep_pay_enabled` bigint NOT NULL DEFAULT '1',
  `units_revision` bigint NOT NULL DEFAULT '0',
  `approval_annotation` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `copilot_operation_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `active_work_started_at` datetime(6) DEFAULT NULL,
  `active_work_completed_at` datetime(6) DEFAULT NULL,
  `active_work_at` datetime(6) DEFAULT NULL,
  `active_work_date` date DEFAULT NULL,
  `active_work_time` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shoots_copilot_operation_id_unique` (`copilot_operation_id`),
  UNIQUE KEY `shoots_comp_reshoot_idempotency_unique` (`complimentary_reshoot_idempotency_key`),
  KEY `shoots_active_work_date_index` (`active_work_date`),
  KEY `shoots_active_work_at_index` (`active_work_at`),
  KEY `shoots_comp_reshoot_lineage_index` (`root_shoot_id`,`reshoot_of_shoot_id`),
  KEY `shoots_workflow_status_index` (`workflow_status`),
  KEY `shoots_status_workflow_index` (`status`,`workflow_status`),
  KEY `shoots_status_index` (`status`),
  KEY `shoots_shoot_type_index` (`shoot_type`),
  KEY `shoots_scheduled_date_index` (`scheduled_date`),
  KEY `shoots_property_slug_index` (`property_slug`),
  KEY `shoots_product_status_index` (`product_status`),
  KEY `shoots_photographer_id_index` (`photographer_id`),
  KEY `shoots_iguide_work_order_id_idx` (`iguide_work_order_id`),
  KEY `shoots_editor_id_index` (`editor_id`),
  KEY `shoots_delivery_status_index` (`delivery_status`),
  KEY `shoots_cubicasa_order_id_idx` (`cubicasa_order_id`),
  KEY `shoots_cubicasa_external_id_idx` (`cubicasa_external_id`),
  KEY `shoots_client_id_index` (`client_id`),
  KEY `idx_shoots_workflow_status_scheduled_date` (`workflow_status`,`scheduled_date`),
  KEY `idx_shoots_status_date` (`status`,`scheduled_date`),
  KEY `idx_shoots_status` (`status`),
  KEY `idx_shoots_scheduled_date` (`scheduled_date`),
  KEY `idx_shoots_photographer_status` (`photographer_id`,`status`),
  KEY `idx_shoots_photographer_id` (`photographer_id`),
  KEY `idx_shoots_editor_id` (`editor_id`),
  KEY `idx_shoots_editing_completed_at` (`editing_completed_at`),
  KEY `idx_shoots_created_at` (`created_at`),
  KEY `idx_shoots_client_status` (`client_id`,`status`),
  KEY `idx_shoots_client_id` (`client_id`),
  KEY `idx_shoots_admin_verified_at` (`admin_verified_at`),
  KEY `fk_shoots_1_8ae8930c0534` (`reshoot_of_shoot_id`),
  KEY `fk_shoots_2_b25d67ce1876` (`hold_requested_by`),
  KEY `fk_shoots_3_c2cc5f421913` (`declined_by`),
  KEY `fk_shoots_4_226f4c197203` (`approved_by`),
  KEY `fk_shoots_5_3cdbd579229a` (`verified_by`),
  KEY `fk_shoots_6_962fd0500bd0` (`service_id`),
  KEY `fk_shoots_9_5cb2dbb8693a` (`rep_id`),
  KEY `fk_shoots_10_827ae896be9f` (`issues_resolved_by`),
  KEY `fk_shoots_11_2483b6de464d` (`cancellation_requested_by`),
  KEY `fk_shoots_12_0cafbde4d36d` (`photographer_paid_invoice_id`),
  KEY `fk_shoots_13_0ae7bf04d9d9` (`sales_rep_paid_invoice_id`),
  KEY `fk_shoots_14_22608ad79af4` (`featured_requested_by`),
  KEY `fk_shoots_15_2c32bbd8cb2b` (`featured_approved_by`),
  CONSTRAINT `fk_shoots_0_0e98876a6339` FOREIGN KEY (`root_shoot_id`) REFERENCES `shoots` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_shoots_10_827ae896be9f` FOREIGN KEY (`issues_resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoots_11_2483b6de464d` FOREIGN KEY (`cancellation_requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoots_12_0cafbde4d36d` FOREIGN KEY (`photographer_paid_invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoots_13_0ae7bf04d9d9` FOREIGN KEY (`sales_rep_paid_invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoots_14_22608ad79af4` FOREIGN KEY (`featured_requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoots_15_2c32bbd8cb2b` FOREIGN KEY (`featured_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoots_1_8ae8930c0534` FOREIGN KEY (`reshoot_of_shoot_id`) REFERENCES `shoots` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_shoots_2_b25d67ce1876` FOREIGN KEY (`hold_requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoots_3_c2cc5f421913` FOREIGN KEY (`declined_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoots_4_226f4c197203` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoots_5_3cdbd579229a` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_shoots_6_962fd0500bd0` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shoots_7_79af96aac6d7` FOREIGN KEY (`photographer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shoots_8_7b0a90f56a1c` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shoots_9_5cb2dbb8693a` FOREIGN KEY (`rep_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_shoots_INSERT` AFTER INSERT ON `shoots` FOR EACH ROW BEGIN INSERT INTO aryeo_changes (shoot_id,client_id,kind) VALUES (NEW.id,NEW.client_id,'shoots.INSERT'); END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_client_scope` BEFORE UPDATE ON `shoots` FOR EACH ROW BEGIN IF NOT (OLD.client_id <=> NEW.client_id) THEN INSERT INTO aryeo_changes (shoot_id,client_id,kind) VALUES (OLD.id,OLD.client_id,'scope_revoked'); END IF; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_shoots_UPDATE` AFTER UPDATE ON `shoots` FOR EACH ROW BEGIN INSERT INTO aryeo_changes (shoot_id,client_id,kind) VALUES (NEW.id,NEW.client_id,'shoots.UPDATE'); END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_bin */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_cascade_delete_shoots` BEFORE DELETE ON `shoots` FOR EACH ROW BEGIN
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_service.DELETE' FROM `shoot_service` c0 JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c0.`shoot_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_units.DELETE' FROM `shoot_units` c0 JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c0.`shoot_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_files.UPDATE' FROM `shoot_files` c0 JOIN `shoot_raw_upload_batches` c1 ON c0.`raw_upload_batch_id` = c1.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c1.`shoot_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_files.UPDATE' FROM `shoot_files` c0 JOIN `ai_editing_jobs` c1 ON c0.`ai_editing_job_id` = c1.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c1.`shoot_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_files.UPDATE' FROM `shoot_files` c0 JOIN `shoot_media_albums` c1 ON c0.`album_id` = c1.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c1.`shoot_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_files.DELETE' FROM `shoot_files` c0 JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c0.`shoot_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_files.UPDATE' FROM `shoot_files` c0 JOIN `shoot_service` c1 ON c0.`shoot_service_id` = c1.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c1.`shoot_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'payments.DELETE' FROM `payments` c0 JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c0.`shoot_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'payment.status' FROM `payments` c0 JOIN payment_service_allocations feed_a ON feed_a.payment_id=c0.id JOIN shoot_service feed_ss ON feed_ss.id=feed_a.shoot_service_id JOIN shoots feed_s ON feed_s.id=feed_ss.shoot_id WHERE c0.`shoot_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'allocations.DELETE' FROM `payment_service_allocations` c0 JOIN `shoot_service` c1 ON c0.`shoot_service_id` = c1.`id` JOIN shoot_service feed_ss ON feed_ss.id=c0.shoot_service_id JOIN shoots feed_s ON feed_s.id=feed_ss.shoot_id WHERE c1.`shoot_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'allocations.DELETE' FROM `payment_service_allocations` c0 JOIN `payments` c1 ON c0.`payment_id` = c1.`id` JOIN shoot_service feed_ss ON feed_ss.id=c0.shoot_service_id JOIN shoots feed_s ON feed_s.id=feed_ss.shoot_id WHERE c1.`shoot_id` = OLD.`id`;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_shoots_DELETE` AFTER DELETE ON `shoots` FOR EACH ROW BEGIN INSERT INTO aryeo_changes (shoot_id,client_id,kind) VALUES (OLD.id,OLD.client_id,'shoots.DELETE'); END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `short_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `short_links` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `target_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `target_id` bigint NOT NULL,
  `target_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT '',
  `destination_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `expires_at` datetime(6) DEFAULT NULL,
  `revoked_at` datetime(6) DEFAULT NULL,
  `created_by` bigint DEFAULT NULL,
  `hit_count` bigint NOT NULL DEFAULT '0',
  `last_accessed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `short_links_code_unique` (`code`),
  UNIQUE KEY `short_links_target_unique` (`type`,`target_type`,`target_id`,`target_key`),
  KEY `short_links_type_revoked_at_index` (`type`,`revoked_at`),
  KEY `fk_short_links_0_cb2f95c282d6` (`created_by`),
  CONSTRAINT `fk_short_links_0_cb2f95c282d6` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sms_group_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sms_group_members` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `sms_group_id` bigint NOT NULL,
  `user_id` bigint DEFAULT NULL,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sms_group_members_sms_group_id_phone_unique` (`sms_group_id`,`phone`),
  KEY `fk_sms_group_members_0_e0509c1b8986` (`user_id`),
  CONSTRAINT `fk_sms_group_members_0_e0509c1b8986` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sms_group_members_1_0de4c9e2074d` FOREIGN KEY (`sms_group_id`) REFERENCES `sms_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sms_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sms_groups` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `created_by` bigint DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_sms_groups_0_1581372d6377` (`created_by`),
  CONSTRAINT `fk_sms_groups_0_1581372d6377` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sms_numbers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sms_numbers` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `phone_number` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `label` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `owner_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'GLOBAL'),
  `owner_id` bigint DEFAULT NULL,
  `is_default` bigint NOT NULL DEFAULT '0',
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `provider` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'TWILIO'),
  `twilio_phone_number_sid` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `telnyx_phone_number_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `messaging_profile_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `sms_ai_enabled` bigint DEFAULT NULL,
  `voice_ai_enabled` bigint DEFAULT NULL,
  `voice_assistant_id_override` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sms_shared_group_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sms_shared_group_members` (
  `group_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `user_id` bigint NOT NULL,
  `last_read_sequence` bigint NOT NULL DEFAULT '0',
  `last_read_message_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  PRIMARY KEY (`group_id`,`user_id`),
  KEY `fk_sms_shared_group_members_0_e13abd864b03` (`user_id`),
  CONSTRAINT `fk_sms_shared_group_members_0_e13abd864b03` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sms_shared_group_members_1_176c83467507` FOREIGN KEY (`group_id`) REFERENCES `sms_shared_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sms_shared_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sms_shared_groups` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `sms_number_id` bigint NOT NULL,
  `business_number` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `participant_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `participants` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `title` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `created_by` bigint NOT NULL,
  `last_message_at` datetime(6) DEFAULT NULL,
  `last_snippet` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4''),
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sms_shared_groups_participant_key_unique` (`participant_key`),
  KEY `sms_shared_groups_last_message_at_index` (`last_message_at`),
  KEY `fk_sms_shared_groups_0_8f828ee78031` (`created_by`),
  KEY `fk_sms_shared_groups_1_b35027f58f72` (`sms_number_id`),
  CONSTRAINT `fk_sms_shared_groups_0_8f828ee78031` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_sms_shared_groups_1_b35027f58f72` FOREIGN KEY (`sms_number_id`) REFERENCES `sms_numbers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sms_shared_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sms_shared_messages` (
  `sequence` bigint NOT NULL AUTO_INCREMENT,
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `group_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `created_by` bigint DEFAULT NULL,
  `request_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `request_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `direction` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `from_number` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `body_text` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `provider_message_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `provider_group_message_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `recipient_statuses` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `media_count` bigint NOT NULL DEFAULT '0',
  `provider_attempted_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`sequence`),
  UNIQUE KEY `sms_shared_messages_id_unique` (`id`),
  UNIQUE KEY `sms_shared_messages_provider_message_id_unique` (`provider_message_id`),
  UNIQUE KEY `sms_shared_messages_created_by_request_key_unique` (`created_by`,`request_key`),
  KEY `sms_shared_messages_provider_group_message_id_index` (`provider_group_message_id`),
  KEY `sms_shared_messages_group_id_created_at_index` (`group_id`,`created_at`),
  CONSTRAINT `fk_sms_shared_messages_0_945dd9ac2377` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sms_shared_messages_1_be6a9f593537` FOREIGN KEY (`group_id`) REFERENCES `sms_shared_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sms_shared_opt_outs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sms_shared_opt_outs` (
  `phone_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `created_at` datetime(6) NOT NULL,
  PRIMARY KEY (`phone_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sms_shared_webhook_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sms_shared_webhook_events` (
  `event_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `message_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `created_at` datetime(6) NOT NULL,
  PRIMARY KEY (`event_id`),
  KEY `fk_sms_shared_webhook_events_0_d69b0bc12f97` (`message_id`),
  CONSTRAINT `fk_sms_shared_webhook_events_0_d69b0bc12f97` FOREIGN KEY (`message_id`) REFERENCES `sms_shared_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stripe_checkout_attempt_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stripe_checkout_attempt_items` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `stripe_checkout_attempt_id` bigint NOT NULL,
  `shoot_id` bigint NOT NULL,
  `position` bigint NOT NULL,
  `expected_amount_cents` bigint NOT NULL,
  `allocation_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stripe_attempt_items_attempt_shoot_unique` (`stripe_checkout_attempt_id`,`shoot_id`),
  KEY `idx_stripe_checkout_attempt_items_shoot_id_stripe_dd582218e36d` (`shoot_id`,`stripe_checkout_attempt_id`),
  CONSTRAINT `fk_stripe_checkout_attempt_items_0_58141dd43330` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`),
  CONSTRAINT `fk_stripe_checkout_attempt_items_1_350c4459c155` FOREIGN KEY (`stripe_checkout_attempt_id`) REFERENCES `stripe_checkout_attempts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stripe_checkout_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stripe_checkout_attempts` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `client_id` bigint DEFAULT NULL,
  `scope` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `ui_mode` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `expected_amount_cents` bigint NOT NULL,
  `currency` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'creating',
  `request_fingerprint` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `idempotency_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `stripe_session_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `stripe_payment_intent_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `expires_at` datetime(6) DEFAULT NULL,
  `completed_at` datetime(6) DEFAULT NULL,
  `failure_message` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stripe_checkout_attempts_idempotency_key_unique` (`idempotency_key`),
  UNIQUE KEY `stripe_checkout_attempts_stripe_session_id_unique` (`stripe_session_id`),
  KEY `stripe_checkout_attempts_stripe_payment_intent_id_index` (`stripe_payment_intent_id`),
  KEY `stripe_checkout_attempts_client_id_status_index` (`client_id`,`status`),
  KEY `stripe_checkout_attempts_status_expires_at_index` (`status`,`expires_at`),
  CONSTRAINT `fk_stripe_checkout_attempts_0_0bde52e56150` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `studio_provider_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `studio_provider_settings` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `studio_workspaces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `studio_workspaces` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `team_id` bigint DEFAULT NULL,
  `created_by` bigint DEFAULT NULL,
  `request_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `preset_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `media` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `outputs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `prepared_frames` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `operation` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `history` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'draft'),
  `progress` bigint DEFAULT NULL,
  `error` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `version` bigint NOT NULL DEFAULT '1',
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `shoot_id` bigint DEFAULT NULL,
  `parent_workspace_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `shoot_dispatch_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `shoot_dispatch_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `shoot_service_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `shoot_service_item_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `editing_dispatch_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `listing_studio_trial_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `listing_studio_order_item_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `access_media` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  PRIMARY KEY (`id`),
  UNIQUE KEY `studio_workspaces_listing_studio_order_item_id_unique` (`listing_studio_order_item_id`),
  UNIQUE KEY `studio_workspaces_created_by_request_id_unique` (`created_by`,`request_id`),
  UNIQUE KEY `studio_shoot_dispatch_unique` (`shoot_id`,`shoot_dispatch_key`,`preset_id`),
  KEY `studio_workspaces_team_id_index` (`team_id`),
  KEY `studio_workspaces_parent_workspace_id_index` (`parent_workspace_id`),
  KEY `studio_workspaces_listing_studio_trial_id_index` (`listing_studio_trial_id`),
  KEY `studio_workspaces_editing_dispatch_id_index` (`editing_dispatch_id`),
  CONSTRAINT `fk_studio_workspaces_0_b4a0188af818` FOREIGN KEY (`listing_studio_order_item_id`) REFERENCES `listing_studio_order_items` (`id`),
  CONSTRAINT `fk_studio_workspaces_1_3265aa9facb6` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_studio_workspaces_2_6b1e40f5ff66` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `support_ticket_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `support_ticket_messages` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `support_ticket_id` bigint NOT NULL,
  `author_id` bigint DEFAULT NULL,
  `request_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `body` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `kind` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'reply'),
  `internal` bigint NOT NULL DEFAULT '0',
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `source_message_id` bigint DEFAULT NULL,
  `attachments_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  PRIMARY KEY (`id`),
  UNIQUE KEY `support_ticket_messages_source_message_id_unique` (`source_message_id`),
  UNIQUE KEY `support_message_idempotency` (`support_ticket_id`,`author_id`,`request_key`),
  KEY `support_ticket_messages_support_ticket_id_id_index` (`support_ticket_id`,`id`),
  KEY `fk_support_ticket_messages_0_5a533b5539b4` (`author_id`),
  CONSTRAINT `fk_support_ticket_messages_0_5a533b5539b4` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_support_ticket_messages_1_3f838661f5a4` FOREIGN KEY (`support_ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `support_tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `support_tickets` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `requester_id` bigint NOT NULL,
  `assigned_to` bigint DEFAULT NULL,
  `request_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `subject` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `category` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'open',
  `priority` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'normal'),
  `page_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `version` bigint NOT NULL DEFAULT '1',
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `support_tickets_requester_id_request_key_unique` (`requester_id`,`request_key`),
  KEY `support_tickets_status_updated_at_index` (`status`,`updated_at`),
  KEY `fk_support_tickets_0_2c8480e5b201` (`assigned_to`),
  CONSTRAINT `fk_support_tickets_0_2c8480e5b201` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_support_tickets_1_a68f4c7873e3` FOREIGN KEY (`requester_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system_email_dispatches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_email_dispatches` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `email_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `email_alias` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `email_version` bigint NOT NULL DEFAULT '1',
  `category` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `idempotency_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `correlation_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `recipient_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `recipient_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `related_account_id` bigint DEFAULT NULL,
  `related_shoot_id` bigint DEFAULT NULL,
  `related_invoice_id` bigint DEFAULT NULL,
  `message_id` bigint DEFAULT NULL,
  `provider` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `provider_message_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `send_source` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'SYSTEM'),
  `delivery_mode` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'sync'),
  `template_view` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `template_version` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'v1'),
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'pending',
  `attempt_count` bigint NOT NULL DEFAULT '0',
  `payload_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `transport_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `error_code` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `error_message` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `sent_at` datetime(6) DEFAULT NULL,
  `failed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `system_email_dispatches_idempotency_key_unique` (`idempotency_key`),
  KEY `system_email_dispatches_status_created_at_index` (`status`,`created_at`),
  KEY `system_email_dispatches_related_invoice_id_email_alias_index` (`related_invoice_id`,`email_alias`),
  KEY `system_email_dispatches_related_shoot_id_email_alias_index` (`related_shoot_id`,`email_alias`),
  KEY `system_email_dispatches_related_account_id_email_alias_index` (`related_account_id`,`email_alias`),
  KEY `system_email_dispatches_email_alias_recipient_email_index` (`email_alias`,`recipient_email`),
  KEY `fk_system_email_dispatches_0_011b23353c15` (`message_id`),
  CONSTRAINT `fk_system_email_dispatches_0_011b23353c15` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_system_email_dispatches_1_ba4563a3936e` FOREIGN KEY (`related_invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_system_email_dispatches_2_477e0938fc88` FOREIGN KEY (`related_shoot_id`) REFERENCES `shoots` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_system_email_dispatches_3_93e6d029b6eb` FOREIGN KEY (`related_account_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system_overview_error_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_overview_error_events` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `system_overview_session_id` bigint DEFAULT NULL,
  `session_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `user_id` bigint DEFAULT NULL,
  `trace_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `severity` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `route_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `component_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `blocker_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `error_class` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `message` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `context_summary` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `occurred_at` datetime(6) NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `system_overview_error_events_occurred_at_index` (`occurred_at`),
  KEY `system_overview_error_events_error_class_index` (`error_class`),
  KEY `system_overview_error_events_blocker_type_index` (`blocker_type`),
  KEY `system_overview_error_events_component_name_index` (`component_name`),
  KEY `system_overview_error_events_route_path_index` (`route_path`),
  KEY `system_overview_error_events_severity_index` (`severity`),
  KEY `system_overview_error_events_source_index` (`source`),
  KEY `system_overview_error_events_trace_id_index` (`trace_id`),
  KEY `system_overview_error_events_session_key_index` (`session_key`),
  KEY `fk_system_overview_error_events_0_37c70469e7b0` (`user_id`),
  KEY `fk_system_overview_error_events_1_c48cf35dd8e8` (`system_overview_session_id`),
  CONSTRAINT `fk_system_overview_error_events_0_37c70469e7b0` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_system_overview_error_events_1_c48cf35dd8e8` FOREIGN KEY (`system_overview_session_id`) REFERENCES `system_overview_sessions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system_overview_request_traces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_overview_request_traces` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `system_overview_session_id` bigint DEFAULT NULL,
  `session_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `user_id` bigint DEFAULT NULL,
  `trace_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `domain` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `route_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `method` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `current_route` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `controller_action` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `status_code` bigint DEFAULT NULL,
  `duration_ms` bigint NOT NULL DEFAULT '0',
  `request_bytes` bigint NOT NULL DEFAULT '0',
  `response_bytes` bigint NOT NULL DEFAULT '0',
  `blocker_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `blocker_message` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `error_class` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `request_payload_summary` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `response_payload_summary` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `occurred_at` datetime(6) NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `system_overview_request_traces_trace_id_unique` (`trace_id`),
  KEY `system_overview_request_traces_occurred_at_index` (`occurred_at`),
  KEY `system_overview_request_traces_error_class_index` (`error_class`),
  KEY `system_overview_request_traces_blocker_type_index` (`blocker_type`),
  KEY `system_overview_request_traces_duration_ms_index` (`duration_ms`),
  KEY `system_overview_request_traces_status_code_index` (`status_code`),
  KEY `system_overview_request_traces_controller_action_index` (`controller_action`),
  KEY `system_overview_request_traces_current_route_index` (`current_route`),
  KEY `system_overview_request_traces_path_index` (`path`),
  KEY `system_overview_request_traces_method_index` (`method`),
  KEY `system_overview_request_traces_route_name_index` (`route_name`),
  KEY `system_overview_request_traces_domain_index` (`domain`),
  KEY `system_overview_request_traces_session_key_index` (`session_key`),
  KEY `fk_system_overview_request_traces_0_3432bc709412` (`user_id`),
  KEY `fk_system_overview_request_traces_1_5620be655e91` (`system_overview_session_id`),
  CONSTRAINT `fk_system_overview_request_traces_0_3432bc709412` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_system_overview_request_traces_1_5620be655e91` FOREIGN KEY (`system_overview_session_id`) REFERENCES `system_overview_sessions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system_overview_route_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_overview_route_events` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `system_overview_session_id` bigint DEFAULT NULL,
  `session_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `user_id` bigint DEFAULT NULL,
  `event_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `route_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `page_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `component_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `action_name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `severity` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `blocker_state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `payload_summary` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `occurred_at` datetime(6) NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `system_overview_route_events_occurred_at_index` (`occurred_at`),
  KEY `system_overview_route_events_component_name_index` (`component_name`),
  KEY `system_overview_route_events_page_key_index` (`page_key`),
  KEY `system_overview_route_events_route_path_index` (`route_path`),
  KEY `system_overview_route_events_event_type_index` (`event_type`),
  KEY `system_overview_route_events_session_key_index` (`session_key`),
  KEY `fk_system_overview_route_events_0_8aa54b30dab5` (`user_id`),
  KEY `fk_system_overview_route_events_1_a22f79465f84` (`system_overview_session_id`),
  CONSTRAINT `fk_system_overview_route_events_0_8aa54b30dab5` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_system_overview_route_events_1_a22f79465f84` FOREIGN KEY (`system_overview_session_id`) REFERENCES `system_overview_sessions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system_overview_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_overview_sessions` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `session_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `user_id` bigint DEFAULT NULL,
  `user_name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `user_role` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `is_authenticated` bigint NOT NULL DEFAULT '0',
  `is_active` bigint NOT NULL DEFAULT '1',
  `current_route` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `current_page` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `current_action` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `component_stack` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `blocker_state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `blocker_message` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `last_api_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `last_trace_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `started_at` datetime(6) DEFAULT NULL,
  `last_activity_at` datetime(6) DEFAULT NULL,
  `ended_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `system_overview_sessions_session_key_unique` (`session_key`),
  KEY `system_overview_sessions_ended_at_index` (`ended_at`),
  KEY `system_overview_sessions_last_activity_at_index` (`last_activity_at`),
  KEY `system_overview_sessions_started_at_index` (`started_at`),
  KEY `system_overview_sessions_last_trace_id_index` (`last_trace_id`),
  KEY `system_overview_sessions_current_route_index` (`current_route`),
  KEY `fk_system_overview_sessions_0_eda007825920` (`user_id`),
  CONSTRAINT `fk_system_overview_sessions_0_eda007825920` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `telnyx_webhook_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `telnyx_webhook_events` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `provider` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'TELNYX',
  `channel` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `telnyx_event_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `event_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `event_received_at` datetime(6) DEFAULT NULL,
  `raw_event_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `processed_at` datetime(6) DEFAULT NULL,
  `processing_error` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `related_message_id` bigint DEFAULT NULL,
  `related_voice_call_id` bigint DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `telnyx_webhook_events_provider_telnyx_event_id_unique` (`provider`,`telnyx_event_id`),
  KEY `telnyx_webhook_events_event_type_index` (`event_type`),
  KEY `telnyx_webhook_events_telnyx_event_id_index` (`telnyx_event_id`),
  KEY `telnyx_webhook_events_channel_index` (`channel`),
  KEY `telnyx_webhook_events_provider_index` (`provider`),
  KEY `fk_telnyx_webhook_events_0_27bd253902c9` (`related_voice_call_id`),
  KEY `fk_telnyx_webhook_events_1_065e534b6893` (`related_message_id`),
  CONSTRAINT `fk_telnyx_webhook_events_0_27bd253902c9` FOREIGN KEY (`related_voice_call_id`) REFERENCES `voice_calls` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_telnyx_webhook_events_1_065e534b6893` FOREIGN KEY (`related_message_id`) REFERENCES `messages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `templates` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `team_id` bigint NOT NULL,
  `created_by` bigint NOT NULL,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `workflow_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `version` bigint NOT NULL DEFAULT '1',
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `visibility` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `preset_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  PRIMARY KEY (`id`),
  KEY `templates_team_id_visibility_created_by_index` (`team_id`,`visibility`,`created_by`),
  KEY `templates_team_id_workflow_id_index` (`team_id`,`workflow_id`),
  KEY `templates_team_id_updated_at_index` (`team_id`,`updated_at`),
  KEY `fk_templates_0_d7a95af29765` (`created_by`),
  CONSTRAINT `fk_templates_0_d7a95af29765` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tool_bridge_invocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tool_bridge_invocations` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `tool` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `channel` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `phone_e164` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `contact_id` bigint DEFAULT NULL,
  `user_id` bigint DEFAULT NULL,
  `telnyx_event_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `telnyx_conversation_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `call_control_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `idempotency_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `error_code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `latency_ms` bigint NOT NULL DEFAULT '0',
  `request_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `response_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `raw_request_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `raw_response_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tool_bridge_invocations_idempotency_key_unique` (`idempotency_key`),
  KEY `tool_bridge_invocations_error_code_index` (`error_code`),
  KEY `tool_bridge_invocations_status_index` (`status`),
  KEY `tool_bridge_invocations_call_control_id_index` (`call_control_id`),
  KEY `tool_bridge_invocations_telnyx_conversation_id_index` (`telnyx_conversation_id`),
  KEY `tool_bridge_invocations_telnyx_event_id_index` (`telnyx_event_id`),
  KEY `tool_bridge_invocations_phone_e164_index` (`phone_e164`),
  KEY `tool_bridge_invocations_channel_index` (`channel`),
  KEY `tool_bridge_invocations_tool_index` (`tool`),
  KEY `tool_bridge_invocations_telnyx_event_id_tool_index` (`telnyx_event_id`,`tool`),
  KEY `fk_tool_bridge_invocations_0_929d196215e9` (`user_id`),
  KEY `fk_tool_bridge_invocations_1_9291ccfc9601` (`contact_id`),
  CONSTRAINT `fk_tool_bridge_invocations_0_929d196215e9` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tool_bridge_invocations_1_9291ccfc9601` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tour_branding`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tour_branding` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `company_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `logo` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `primary_color` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'#1a56db'),
  `secondary_color` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'#7e3af2'),
  `font_family` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'Inter'),
  `custom_domain` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `facebook_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `linkedin_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `instagram_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  PRIMARY KEY (`id`),
  KEY `tour_branding_company_name_index` (`company_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tour_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tour_events` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `event_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `tour_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `visitor_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `ip_address` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `user_agent` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `referrer` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `country` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `city` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tour_events_created_at_index` (`created_at`),
  KEY `tour_events_visitor_id_index` (`visitor_id`),
  KEY `tour_events_shoot_id_index` (`shoot_id`),
  KEY `tour_events_shoot_id_created_at_index` (`shoot_id`,`created_at`),
  KEY `tour_events_shoot_id_event_type_index` (`shoot_id`,`event_type`),
  CONSTRAINT `fk_tour_events_0_2ded96155ecf` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_activity_logs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint DEFAULT NULL,
  `actor_user_id` bigint DEFAULT NULL,
  `event_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `title` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `description` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `occurred_at` datetime(6) NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `target_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `target_id` bigint DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_activity_logs_user_id_occurred_at_index` (`user_id`,`occurred_at`),
  KEY `user_activity_logs_target_index` (`target_type`,`target_id`),
  KEY `user_activity_logs_occurred_at_index` (`occurred_at`),
  KEY `user_activity_logs_event_type_index` (`event_type`),
  KEY `fk_user_activity_logs_1_f2c3fc863611` (`actor_user_id`),
  CONSTRAINT `fk_user_activity_logs_0_0f9d8e73196a` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_user_activity_logs_1_f2c3fc863611` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_branding`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_branding` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `logo` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `primary_color` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'#1a56db'),
  `secondary_color` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'#7e3af2'),
  `font_family` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'Inter'),
  `custom_domain` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `facebook_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `linkedin_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `instagram_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `banner` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `about` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `hero_headline` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `hero_subtitle` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `hero_image` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `show_map` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_branding_user_id_unique` (`user_id`),
  CONSTRAINT `fk_user_branding_0_dec6a0a796e1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_branding_clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_branding_clients` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `client_id` bigint NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_branding_clients_user_id_client_id_unique` (`user_id`,`client_id`),
  KEY `user_branding_clients_client_id_index` (`client_id`),
  KEY `user_branding_clients_user_id_index` (`user_id`),
  CONSTRAINT `fk_user_branding_clients_0_5a9c93d79828` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_branding_clients_1_5fbec611619e` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_tax_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_tax_documents` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `user_id` bigint NOT NULL,
  `path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `original_name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `mime_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `size` bigint NOT NULL,
  `sha256` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `legacy_public_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `submitted_at` datetime(6) NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_tax_documents_user_id_unique` (`user_id`),
  CONSTRAINT `fk_user_tax_documents_0_fe5d001e7cad` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `email_verified_at` datetime(6) DEFAULT NULL,
  `password` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `remember_token` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `created_by_name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_by_id` bigint DEFAULT NULL,
  `username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `phonenumber` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `company_name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `role` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'client'),
  `avatar` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `bio` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `account_status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'active'),
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `timezone` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'America/New_York'),
  `address` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `city` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `zip` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `license_number` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `company_notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `secondary_roles` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `phone` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `facebook_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `twitter_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `linkedin_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `pinterest_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `shoot_cc_emails` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `client_discount_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `client_discount_value` decimal(65,30) DEFAULT NULL,
  `email_status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `verification_sent_at` datetime(6) DEFAULT NULL,
  `email_last_delivery_attempt_at` datetime(6) DEFAULT NULL,
  `email_last_bounced_at` datetime(6) DEFAULT NULL,
  `email_bounce_reason` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `email_warning_code` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `email_warning_message` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `email_suggested_correction` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `sms_opt_out` bigint NOT NULL DEFAULT '0',
  `sms_opt_out_at` datetime(6) DEFAULT NULL,
  `sms_ai_enabled` bigint NOT NULL DEFAULT '0',
  `deleted_at` datetime(6) DEFAULT NULL,
  `locked_at` datetime(6) DEFAULT NULL,
  `password_reset_required` bigint NOT NULL DEFAULT '0',
  `restore_until` datetime(6) DEFAULT NULL,
  `default_bracket_mode` bigint DEFAULT NULL,
  `two_factor_secret` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `two_factor_recovery_codes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `two_factor_confirmed_at` datetime(6) DEFAULT NULL,
  `two_factor_last_used_step` bigint DEFAULT NULL,
  `password_changed_at` datetime(6) DEFAULT NULL,
  `email_verification_required_at` datetime(6) DEFAULT NULL,
  `email_verified_email` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `permission_overrides` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `office_phone` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `show_office_phone_on_tour` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_username_unique` (`username`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `fk_users_0_cd8e250bccce` (`created_by_id`),
  CONSTRAINT `fk_users_0_cd8e250bccce` FOREIGN KEY (`created_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_bin */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `aryeo_cascade_delete_users` BEFORE DELETE ON `users` FOR EACH ROW BEGIN
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT c0.id,c0.client_id,'shoots.UPDATE' FROM `shoots` c0  WHERE c0.`hold_requested_by` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT c0.id,c0.client_id,'shoots.UPDATE' FROM `shoots` c0  WHERE c0.`declined_by` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT c0.id,c0.client_id,'shoots.UPDATE' FROM `shoots` c0  WHERE c0.`approved_by` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT c0.id,c0.client_id,'shoots.UPDATE' FROM `shoots` c0  WHERE c0.`photographer_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT c0.id,c0.client_id,'shoots.DELETE' FROM `shoots` c0  WHERE c0.`client_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT c0.id,c0.client_id,'shoots.UPDATE' FROM `shoots` c0  WHERE c0.`rep_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT c0.id,c0.client_id,'shoots.UPDATE' FROM `shoots` c0  WHERE c0.`issues_resolved_by` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT c0.id,c0.client_id,'shoots.UPDATE' FROM `shoots` c0  WHERE c0.`cancellation_requested_by` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT c0.id,c0.client_id,'shoots.UPDATE' FROM `shoots` c0 JOIN `invoices` c1 ON c0.`photographer_paid_invoice_id` = c1.`id` WHERE c1.`user_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT c0.id,c0.client_id,'shoots.UPDATE' FROM `shoots` c0 JOIN `invoices` c1 ON c0.`sales_rep_paid_invoice_id` = c1.`id` WHERE c1.`user_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT c0.id,c0.client_id,'shoots.UPDATE' FROM `shoots` c0  WHERE c0.`featured_requested_by` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT c0.id,c0.client_id,'shoots.UPDATE' FROM `shoots` c0  WHERE c0.`featured_approved_by` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_service.UPDATE' FROM `shoot_service` c0 JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c0.`unlocked_by` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_service.UPDATE' FROM `shoot_service` c0 JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c0.`photographer_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_service.DELETE' FROM `shoot_service` c0 JOIN `shoots` c1 ON c0.`shoot_id` = c1.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c1.`client_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_service.UPDATE' FROM `shoot_service` c0 JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c0.`editor_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_service.UPDATE' FROM `shoot_service` c0 JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c0.`video_editor_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_units.DELETE' FROM `shoot_units` c0 JOIN `shoots` c1 ON c0.`shoot_id` = c1.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c1.`client_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_files.UPDATE' FROM `shoot_files` c0 JOIN `shoot_raw_upload_batches` c1 ON c0.`raw_upload_batch_id` = c1.`id` JOIN `shoots` c2 ON c1.`shoot_id` = c2.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c2.`client_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_files.UPDATE' FROM `shoot_files` c0 JOIN `ai_editing_jobs` c1 ON c0.`ai_editing_job_id` = c1.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c1.`user_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_files.UPDATE' FROM `shoot_files` c0 JOIN `ai_editing_jobs` c1 ON c0.`ai_editing_job_id` = c1.`id` JOIN `shoots` c2 ON c1.`shoot_id` = c2.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c2.`client_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_files.UPDATE' FROM `shoot_files` c0 JOIN `shoot_media_albums` c1 ON c0.`album_id` = c1.`id` JOIN `shoots` c2 ON c1.`shoot_id` = c2.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c2.`client_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_files.DELETE' FROM `shoot_files` c0 JOIN `shoots` c1 ON c0.`shoot_id` = c1.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c1.`client_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_files.DELETE' FROM `shoot_files` c0 JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c0.`uploaded_by` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'shoot_files.UPDATE' FROM `shoot_files` c0 JOIN `shoot_service` c1 ON c0.`shoot_service_id` = c1.`id` JOIN `shoots` c2 ON c1.`shoot_id` = c2.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c2.`client_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'payments.DELETE' FROM `payments` c0 JOIN `shoots` c1 ON c0.`shoot_id` = c1.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c1.`client_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'payment.status' FROM `payments` c0 JOIN `shoots` c1 ON c0.`shoot_id` = c1.`id` JOIN payment_service_allocations feed_a ON feed_a.payment_id=c0.id JOIN shoot_service feed_ss ON feed_ss.id=feed_a.shoot_service_id JOIN shoots feed_s ON feed_s.id=feed_ss.shoot_id WHERE c1.`client_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'payments.UPDATE' FROM `payments` c0 JOIN `invoices` c1 ON c0.`invoice_id` = c1.`id` JOIN shoots feed_s ON feed_s.id=c0.shoot_id WHERE c1.`user_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'payment.status' FROM `payments` c0 JOIN `invoices` c1 ON c0.`invoice_id` = c1.`id` JOIN payment_service_allocations feed_a ON feed_a.payment_id=c0.id JOIN shoot_service feed_ss ON feed_ss.id=feed_a.shoot_service_id JOIN shoots feed_s ON feed_s.id=feed_ss.shoot_id WHERE c1.`user_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'allocations.DELETE' FROM `payment_service_allocations` c0 JOIN `shoot_service` c1 ON c0.`shoot_service_id` = c1.`id` JOIN `shoots` c2 ON c1.`shoot_id` = c2.`id` JOIN shoot_service feed_ss ON feed_ss.id=c0.shoot_service_id JOIN shoots feed_s ON feed_s.id=feed_ss.shoot_id WHERE c2.`client_id` = OLD.`id`;
    INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT feed_s.id,feed_s.client_id,'allocations.DELETE' FROM `payment_service_allocations` c0 JOIN `payments` c1 ON c0.`payment_id` = c1.`id` JOIN `shoots` c2 ON c1.`shoot_id` = c2.`id` JOIN shoot_service feed_ss ON feed_ss.id=c0.shoot_service_id JOIN shoots feed_s ON feed_s.id=feed_ss.shoot_id WHERE c2.`client_id` = OLD.`id`;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `voice_automation_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_automation_rules` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `trigger_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `enabled` bigint NOT NULL DEFAULT '0',
  `conditions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `delay_minutes` bigint NOT NULL DEFAULT '60',
  `quiet_hours` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `max_attempts` bigint NOT NULL DEFAULT '3',
  `retry_delay_minutes` bigint NOT NULL DEFAULT '60',
  `action_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `action_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `created_by_user_id` bigint DEFAULT NULL,
  `creation_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `creation_request_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `deleted_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `voice_automation_rule_creation` (`created_by_user_id`,`creation_key`),
  KEY `voice_automation_rules_trigger_type_index` (`trigger_type`),
  CONSTRAINT `fk_voice_automation_rules_0_84db6c637cec` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_automation_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_automation_runs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `voice_automation_rule_id` bigint NOT NULL,
  `source_event_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `trigger_type` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `reason` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `rule_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `scheduled_at` datetime(6) DEFAULT NULL,
  `scheduled_voice_call_id` bigint DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `voice_automation_run_event` (`voice_automation_rule_id`,`source_event_key`),
  KEY `fk_voice_automation_runs_0_a6147866ddfd` (`scheduled_voice_call_id`),
  CONSTRAINT `fk_voice_automation_runs_0_a6147866ddfd` FOREIGN KEY (`scheduled_voice_call_id`) REFERENCES `scheduled_voice_calls` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_voice_automation_runs_1_a47bc1bbde86` FOREIGN KEY (`voice_automation_rule_id`) REFERENCES `voice_automation_rules` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_browser_calls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_browser_calls` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `voice_call_id` bigint NOT NULL,
  `owner_id` bigint NOT NULL,
  `session_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `mode` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'awaiting_agent'),
  `conference_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `idempotency_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `request_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `voice_browser_calls_owner_id_idempotency_key_unique` (`owner_id`,`idempotency_key`),
  KEY `voice_browser_calls_conference_id_index` (`conference_id`),
  KEY `fk_voice_browser_calls_0_fb871096d696` (`voice_call_id`),
  KEY `fk_voice_browser_calls_2_e0fb6d825b52` (`session_id`),
  CONSTRAINT `fk_voice_browser_calls_0_fb871096d696` FOREIGN KEY (`voice_call_id`) REFERENCES `voice_calls` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_voice_browser_calls_1_9524bb662a47` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_voice_browser_calls_2_e0fb6d825b52` FOREIGN KEY (`session_id`) REFERENCES `voice_browser_sessions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_browser_commands`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_browser_commands` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `command_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'pending'),
  `response` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `voice_browser_commands_command_key_unique` (`command_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_browser_legs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_browser_legs` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `browser_call_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `session_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `user_id` bigint DEFAULT NULL,
  `role` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `mode` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'pending'),
  `call_control_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `call_session_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `browser_call_control_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `operation_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `client_state` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `destination` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `muted` bigint NOT NULL DEFAULT '0',
  `held` bigint NOT NULL DEFAULT '0',
  `answered_at` datetime(6) DEFAULT NULL,
  `joined_at` datetime(6) DEFAULT NULL,
  `ended_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `sip_device_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `mobile_session_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `voice_browser_legs_client_state_unique` (`client_state`),
  UNIQUE KEY `voice_browser_legs_browser_call_control_id_unique` (`browser_call_control_id`),
  KEY `voice_browser_legs_operation_key_index` (`operation_key`),
  KEY `voice_browser_legs_call_session_id_index` (`call_session_id`),
  KEY `voice_browser_legs_call_control_id_index` (`call_control_id`),
  KEY `fk_voice_browser_legs_0_cbcd04b81d30` (`mobile_session_id`),
  KEY `fk_voice_browser_legs_1_179ab8a1da7e` (`user_id`),
  KEY `fk_voice_browser_legs_2_fd777f6a7a22` (`session_id`),
  KEY `fk_voice_browser_legs_3_824fe43f00e9` (`browser_call_id`),
  KEY `fk_voice_browser_legs_4_14d4f5d962a8` (`sip_device_id`),
  CONSTRAINT `fk_voice_browser_legs_0_cbcd04b81d30` FOREIGN KEY (`mobile_session_id`) REFERENCES `voice_mobile_sessions` (`id`),
  CONSTRAINT `fk_voice_browser_legs_1_179ab8a1da7e` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_voice_browser_legs_2_fd777f6a7a22` FOREIGN KEY (`session_id`) REFERENCES `voice_browser_sessions` (`id`),
  CONSTRAINT `fk_voice_browser_legs_3_824fe43f00e9` FOREIGN KEY (`browser_call_id`) REFERENCES `voice_browser_calls` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_voice_browser_legs_4_14d4f5d962a8` FOREIGN KEY (`sip_device_id`) REFERENCES `voice_sip_devices` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_browser_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_browser_sessions` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `user_id` bigint NOT NULL,
  `device_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `credential_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `sip_username` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'creating'),
  `registered` bigint NOT NULL DEFAULT '0',
  `expires_at` datetime(6) NOT NULL,
  `heartbeat_at` datetime(6) DEFAULT NULL,
  `revoked_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `voice_browser_sessions_user_id_device_id_index` (`user_id`,`device_id`),
  CONSTRAINT `fk_voice_browser_sessions_0_35abb1d9c4c3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_call_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_call_events` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `voice_call_id` bigint DEFAULT NULL,
  `provider` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `event_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `normalized_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `raw_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `received_at` datetime(6) DEFAULT NULL,
  `processed_at` datetime(6) DEFAULT NULL,
  `idempotency_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `processing_error` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `voice_call_events_idempotency_key_unique` (`idempotency_key`),
  KEY `voice_call_events_received_at_index` (`received_at`),
  KEY `voice_call_events_normalized_type_index` (`normalized_type`),
  KEY `voice_call_events_event_type_index` (`event_type`),
  KEY `voice_call_events_provider_index` (`provider`),
  KEY `fk_voice_call_events_0_ec0c49a880c7` (`voice_call_id`),
  CONSTRAINT `fk_voice_call_events_0_ec0c49a880c7` FOREIGN KEY (`voice_call_id`) REFERENCES `voice_calls` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_call_tool_invocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_call_tool_invocations` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `voice_call_id` bigint DEFAULT NULL,
  `tool_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `provider_tool_call_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'pending',
  `input_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `output_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `requires_confirmation` bigint NOT NULL DEFAULT '0',
  `error_message` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `voice_call_tool_invocations_requires_confirmation_index` (`requires_confirmation`),
  KEY `voice_call_tool_invocations_status_index` (`status`),
  KEY `voice_call_tool_invocations_provider_tool_call_id_index` (`provider_tool_call_id`),
  KEY `voice_call_tool_invocations_tool_name_index` (`tool_name`),
  KEY `fk_voice_call_tool_invocations_0_8f397d8d9a72` (`voice_call_id`),
  CONSTRAINT `fk_voice_call_tool_invocations_0_8f397d8d9a72` FOREIGN KEY (`voice_call_id`) REFERENCES `voice_calls` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_call_transcripts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_call_transcripts` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `voice_call_id` bigint NOT NULL,
  `provider_message_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `speaker` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `transcript_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'final',
  `text` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `confidence` decimal(65,30) DEFAULT NULL,
  `occurred_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `voice_call_transcripts_occurred_at_index` (`occurred_at`),
  KEY `voice_call_transcripts_transcript_type_index` (`transcript_type`),
  KEY `voice_call_transcripts_speaker_index` (`speaker`),
  KEY `voice_call_transcripts_provider_message_id_index` (`provider_message_id`),
  KEY `fk_voice_call_transcripts_0_119781fa3e47` (`voice_call_id`),
  CONSTRAINT `fk_voice_call_transcripts_0_119781fa3e47` FOREIGN KEY (`voice_call_id`) REFERENCES `voice_calls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_call_verifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_call_verifications` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `phone_e164` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `voice_call_id` bigint DEFAULT NULL,
  `user_id` bigint DEFAULT NULL,
  `contact_id` bigint DEFAULT NULL,
  `method` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `success` bigint NOT NULL DEFAULT '0',
  `attempts` bigint NOT NULL DEFAULT '1',
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `voice_call_verifications_success_index` (`success`),
  KEY `voice_call_verifications_method_index` (`method`),
  KEY `voice_call_verifications_phone_e164_index` (`phone_e164`),
  KEY `fk_voice_call_verifications_0_4caa3dd97c69` (`contact_id`),
  KEY `fk_voice_call_verifications_1_eb27e8181fe9` (`user_id`),
  KEY `fk_voice_call_verifications_2_e8bd48fae80a` (`voice_call_id`),
  CONSTRAINT `fk_voice_call_verifications_0_4caa3dd97c69` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_voice_call_verifications_1_eb27e8181fe9` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_voice_call_verifications_2_e8bd48fae80a` FOREIGN KEY (`voice_call_id`) REFERENCES `voice_calls` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_calls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_calls` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `direction` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `disposition` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `from_phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `to_phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `caller_user_id` bigint DEFAULT NULL,
  `caller_contact_id` bigint DEFAULT NULL,
  `assistant_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `call_control_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `telnyx_conversation_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `ai_chat_session_id` bigint DEFAULT NULL,
  `related_shoot_id` bigint DEFAULT NULL,
  `duration_seconds` decimal(65,30) DEFAULT NULL,
  `recording_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `recording_consent_given` bigint NOT NULL DEFAULT '0',
  `transcript` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `summary` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `client_state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_by_user_id` bigint DEFAULT NULL,
  `started_at` datetime(6) DEFAULT NULL,
  `ended_at` datetime(6) DEFAULT NULL,
  `verified_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `intent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `menu_digit` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `escalation_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `callback_status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `callback_requested_at` datetime(6) DEFAULT NULL,
  `preferred_callback_at` datetime(6) DEFAULT NULL,
  `scheduled_voice_call_id` bigint DEFAULT NULL,
  `last_telnyx_command_status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `provider` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'telnyx',
  `vapi_call_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `vapi_phone_number_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `handled_by` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `external_provider_status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `provider_event_last_seen_at` datetime(6) DEFAULT NULL,
  `vapi_ended_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `telnyx_failure_code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `carrier_failure_reason` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `ai_current_state` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `ai_current_speaker` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `live_transcript_preview` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `sentiment` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `booking_probability` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `needs_follow_up` bigint NOT NULL DEFAULT '0',
  `summary_generated_at` datetime(6) DEFAULT NULL,
  `recording_provider` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `answered_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `voice_calls_recording_provider_index` (`recording_provider`),
  KEY `voice_calls_needs_follow_up_index` (`needs_follow_up`),
  KEY `voice_calls_booking_probability_index` (`booking_probability`),
  KEY `voice_calls_sentiment_index` (`sentiment`),
  KEY `voice_calls_ai_current_speaker_index` (`ai_current_speaker`),
  KEY `voice_calls_ai_current_state_index` (`ai_current_state`),
  KEY `voice_calls_telnyx_failure_code_index` (`telnyx_failure_code`),
  KEY `voice_calls_vapi_ended_reason_index` (`vapi_ended_reason`),
  KEY `voice_calls_external_provider_status_index` (`external_provider_status`),
  KEY `voice_calls_handled_by_index` (`handled_by`),
  KEY `voice_calls_vapi_phone_number_id_index` (`vapi_phone_number_id`),
  KEY `voice_calls_vapi_call_id_index` (`vapi_call_id`),
  KEY `voice_calls_provider_index` (`provider`),
  KEY `voice_calls_callback_status_index` (`callback_status`),
  KEY `voice_calls_escalation_reason_index` (`escalation_reason`),
  KEY `voice_calls_menu_digit_index` (`menu_digit`),
  KEY `voice_calls_intent_index` (`intent`),
  KEY `voice_calls_to_phone_index` (`to_phone`),
  KEY `voice_calls_telnyx_conversation_id_index` (`telnyx_conversation_id`),
  KEY `voice_calls_status_index` (`status`),
  KEY `voice_calls_from_phone_index` (`from_phone`),
  KEY `voice_calls_disposition_index` (`disposition`),
  KEY `voice_calls_direction_index` (`direction`),
  KEY `voice_calls_call_control_id_index` (`call_control_id`),
  KEY `voice_calls_assistant_id_index` (`assistant_id`),
  KEY `fk_voice_calls_0_62e44875d96c` (`scheduled_voice_call_id`),
  KEY `fk_voice_calls_1_cf22d09f7801` (`caller_user_id`),
  KEY `fk_voice_calls_2_4c4b8aea59f4` (`caller_contact_id`),
  KEY `fk_voice_calls_3_101bf801646a` (`ai_chat_session_id`),
  KEY `fk_voice_calls_4_3c9f0e31f351` (`related_shoot_id`),
  KEY `fk_voice_calls_5_852fed71c17e` (`created_by_user_id`),
  CONSTRAINT `fk_voice_calls_0_62e44875d96c` FOREIGN KEY (`scheduled_voice_call_id`) REFERENCES `scheduled_voice_calls` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_voice_calls_1_cf22d09f7801` FOREIGN KEY (`caller_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_voice_calls_2_4c4b8aea59f4` FOREIGN KEY (`caller_contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_voice_calls_3_101bf801646a` FOREIGN KEY (`ai_chat_session_id`) REFERENCES `ai_chat_sessions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_voice_calls_4_3c9f0e31f351` FOREIGN KEY (`related_shoot_id`) REFERENCES `shoots` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_voice_calls_5_852fed71c17e` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_cancelled_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_cancelled_attempts` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `idempotency_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `voice_cancelled_attempts_user_id_idempotency_key_unique` (`user_id`,`idempotency_key`),
  CONSTRAINT `fk_voice_cancelled_attempts_0_576b5b300aed` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_follow_up_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_follow_up_tasks` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `voice_call_id` bigint DEFAULT NULL,
  `assigned_to_user_id` bigint DEFAULT NULL,
  `related_shoot_id` bigint DEFAULT NULL,
  `title` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `due_at` datetime(6) NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'open',
  `completed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `automation_run_id` bigint DEFAULT NULL,
  `related_invoice_id` bigint DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `voice_follow_up_tasks_automation_run_id_unique` (`automation_run_id`),
  UNIQUE KEY `voice_follow_up_manual_call` (((case when (`automation_run_id` is null) then `voice_call_id` else NULL end))),
  KEY `voice_follow_up_tasks_assigned_to_user_id_status_due_at_index` (`assigned_to_user_id`,`status`,`due_at`),
  KEY `fk_voice_follow_up_tasks_0_e34c970725b4` (`related_invoice_id`),
  KEY `fk_voice_follow_up_tasks_2_d252f1eec365` (`voice_call_id`),
  KEY `fk_voice_follow_up_tasks_4_27103c4003fc` (`related_shoot_id`),
  CONSTRAINT `fk_voice_follow_up_tasks_0_e34c970725b4` FOREIGN KEY (`related_invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_voice_follow_up_tasks_1_57e9f30b03d1` FOREIGN KEY (`automation_run_id`) REFERENCES `voice_automation_runs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_voice_follow_up_tasks_2_d252f1eec365` FOREIGN KEY (`voice_call_id`) REFERENCES `voice_calls` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_voice_follow_up_tasks_3_dd0023b82e7f` FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_voice_follow_up_tasks_4_27103c4003fc` FOREIGN KEY (`related_shoot_id`) REFERENCES `shoots` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_incoming_offers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_incoming_offers` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `voice_call_id` bigint NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'waiting',
  `eligible_user_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `expires_at` datetime(6) NOT NULL,
  `claimed_by_id` bigint DEFAULT NULL,
  `claimed_session_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `claim_key` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `claimed_at` datetime(6) DEFAULT NULL,
  `browser_call_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `voice_incoming_offers_voice_call_id_unique` (`voice_call_id`),
  KEY `voice_incoming_offers_expires_at_index` (`expires_at`),
  KEY `voice_incoming_offers_status_index` (`status`),
  KEY `fk_voice_incoming_offers_0_7905505af7ed` (`claimed_by_id`),
  CONSTRAINT `fk_voice_incoming_offers_0_7905505af7ed` FOREIGN KEY (`claimed_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_voice_incoming_offers_1_f7fdc56713e6` FOREIGN KEY (`voice_call_id`) REFERENCES `voice_calls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_line_policies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_line_policies` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `phone_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `mode` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'staff_voicemail'),
  `recipient_role` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `recipient_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `timezone` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'UTC'),
  `weekly_hours` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `office_greeting` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `closed_greeting` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `recipient_roles` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  PRIMARY KEY (`id`),
  UNIQUE KEY `voice_line_policies_phone_number_unique` (`phone_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_llm_usage`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_llm_usage` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `voice_call_id` bigint DEFAULT NULL,
  `purpose` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `model` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `input_tokens` bigint NOT NULL DEFAULT '0',
  `output_tokens` bigint NOT NULL DEFAULT '0',
  `cost_usd` decimal(65,30) NOT NULL DEFAULT '0.000000000000000000000000000000',
  `created_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `voice_llm_usage_created_at_index` (`created_at`),
  KEY `voice_llm_usage_purpose_index` (`purpose`),
  KEY `fk_voice_llm_usage_0_2c548137c9e9` (`voice_call_id`),
  CONSTRAINT `fk_voice_llm_usage_0_2c548137c9e9` FOREIGN KEY (`voice_call_id`) REFERENCES `voice_calls` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_mobile_offers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_mobile_offers` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `incoming_offer_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `session_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `sdk_call_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'pending'),
  `call_control_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `client_state` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `expires_at` datetime(6) NOT NULL,
  `accepted_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `voice_mobile_offers_client_state_unique` (`client_state`),
  UNIQUE KEY `voice_mobile_offers_incoming_offer_id_session_id_unique` (`incoming_offer_id`,`session_id`),
  UNIQUE KEY `voice_mobile_offers_call_control_id_unique` (`call_control_id`),
  UNIQUE KEY `voice_mobile_offers_session_id_sdk_call_id_unique` (`session_id`,`sdk_call_id`),
  CONSTRAINT `fk_voice_mobile_offers_0_45ed8b6e0c07` FOREIGN KEY (`session_id`) REFERENCES `voice_mobile_sessions` (`id`),
  CONSTRAINT `fk_voice_mobile_offers_1_7cddec9aaa0c` FOREIGN KEY (`incoming_offer_id`) REFERENCES `voice_incoming_offers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_mobile_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_mobile_sessions` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `user_id` bigint NOT NULL,
  `device_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `apns_environment` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `bundle_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `push_token` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `push_token_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `connection_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `connection_username` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `credential_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `sip_username` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'creating'),
  `provision_attempted_at` datetime(6) DEFAULT NULL,
  `credential_attempted_at` datetime(6) DEFAULT NULL,
  `expires_at` datetime(6) NOT NULL,
  `registration_expires_at` datetime(6) NOT NULL,
  `revoked_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `personal_access_token_id` bigint DEFAULT NULL,
  `credential_rotation_from` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `credential_cleanup_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  PRIMARY KEY (`id`),
  UNIQUE KEY `voice_mobile_sessions_push_token_hash_unique` (`push_token_hash`),
  UNIQUE KEY `voice_mobile_sessions_connection_id_unique` (`connection_id`),
  KEY `voice_mobile_sessions_user_id_device_id_index` (`user_id`,`device_id`),
  KEY `fk_voice_mobile_sessions_0_752451b2a46a` (`personal_access_token_id`),
  CONSTRAINT `fk_voice_mobile_sessions_0_752451b2a46a` FOREIGN KEY (`personal_access_token_id`) REFERENCES `personal_access_tokens` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_voice_mobile_sessions_1_0f826aa2e3ca` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_mobile_tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_mobile_tickets` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `session_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `idempotency_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `destination` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `caller_number` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'pending'),
  `expires_at` datetime(6) NOT NULL,
  `call_control_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `voice_call_id` bigint DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `voice_mobile_tickets_session_id_idempotency_key_unique` (`session_id`,`idempotency_key`),
  UNIQUE KEY `voice_mobile_tickets_call_control_id_unique` (`call_control_id`),
  KEY `fk_voice_mobile_tickets_0_6d981cec82f9` (`voice_call_id`),
  CONSTRAINT `fk_voice_mobile_tickets_0_6d981cec82f9` FOREIGN KEY (`voice_call_id`) REFERENCES `voice_calls` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_voice_mobile_tickets_1_2c67577b31c5` FOREIGN KEY (`session_id`) REFERENCES `voice_mobile_sessions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_phone_offers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_phone_offers` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `incoming_offer_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `user_id` bigint NOT NULL,
  `destination` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'pending'),
  `call_control_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `client_state` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `expires_at` datetime(6) NOT NULL,
  `accepted_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `sip_device_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `call_session_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `peer_call_control_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `voice_phone_offers_incoming_offer_id_user_id_unique` (`incoming_offer_id`,`user_id`),
  UNIQUE KEY `voice_phone_offers_client_state_unique` (`client_state`),
  KEY `voice_phone_offers_peer_call_control_id_index` (`peer_call_control_id`),
  KEY `voice_phone_offers_call_session_id_index` (`call_session_id`),
  KEY `voice_phone_offers_call_control_id_index` (`call_control_id`),
  KEY `fk_voice_phone_offers_0_d2d2b69289ea` (`sip_device_id`),
  KEY `fk_voice_phone_offers_2_76c42adf248b` (`user_id`),
  CONSTRAINT `fk_voice_phone_offers_0_d2d2b69289ea` FOREIGN KEY (`sip_device_id`) REFERENCES `voice_sip_devices` (`id`),
  CONSTRAINT `fk_voice_phone_offers_1_74aa1eb43fd5` FOREIGN KEY (`incoming_offer_id`) REFERENCES `voice_incoming_offers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_voice_phone_offers_2_76c42adf248b` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_push_deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_push_deliveries` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `subscription_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `voice_call_id` bigint DEFAULT NULL,
  `offer_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `scope` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `event` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'queued'),
  `expires_at` datetime(6) NOT NULL,
  `error_code` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `http_status` bigint DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `voice_push_delivery_unique` (`subscription_id`,`offer_id`,`event`),
  KEY `voice_push_deliveries_offer_id_event_index` (`offer_id`,`event`),
  KEY `fk_voice_push_deliveries_0_6978859dd893` (`voice_call_id`),
  CONSTRAINT `fk_voice_push_deliveries_0_6978859dd893` FOREIGN KEY (`voice_call_id`) REFERENCES `voice_calls` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_voice_push_deliveries_1_9dcddcae95be` FOREIGN KEY (`subscription_id`) REFERENCES `voice_push_subscriptions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_push_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_push_preferences` (
  `user_id` bigint NOT NULL AUTO_INCREMENT,
  `incoming_calls` bigint NOT NULL DEFAULT '1',
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_voice_push_preferences_0_63f942d1d1f1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_push_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_push_subscriptions` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `user_id` bigint NOT NULL,
  `endpoint_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `endpoint` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `public_key` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `auth_token` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `label` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `scope` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `revoke_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `revoked_at` datetime(6) DEFAULT NULL,
  `last_success_at` datetime(6) DEFAULT NULL,
  `last_error` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `voice_push_subscriptions_endpoint_hash_unique` (`endpoint_hash`),
  KEY `fk_voice_push_subscriptions_0_bfff5e7c63d3` (`user_id`),
  CONSTRAINT `fk_voice_push_subscriptions_0_bfff5e7c63d3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_schedule_overrides`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_schedule_overrides` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `starts_at` datetime(6) NOT NULL,
  `ends_at` datetime(6) NOT NULL,
  `mode` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'closed'),
  `label` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_by` bigint DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `voice_schedule_overrides_ends_at_index` (`ends_at`),
  KEY `voice_schedule_overrides_starts_at_index` (`starts_at`),
  KEY `fk_voice_schedule_overrides_0_8c9492af9350` (`created_by`),
  CONSTRAINT `fk_voice_schedule_overrides_0_8c9492af9350` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_sip_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_sip_devices` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `user_id` bigint NOT NULL,
  `creation_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `label` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `caller_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `connection_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `sip_username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `sip_password` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'creating'),
  `enabled` bigint NOT NULL DEFAULT '1',
  `registered` bigint NOT NULL DEFAULT '0',
  `registration_checked_at` datetime(6) DEFAULT NULL,
  `provision_attempted_at` datetime(6) DEFAULT NULL,
  `revoked_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `sms_number` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `sms_install_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `sms_push` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `sms_bound_at` datetime(6) DEFAULT NULL,
  `sms_last_fetch_at` datetime(6) DEFAULT NULL,
  `sms_last_send_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `voice_sip_devices_sip_username_unique` (`sip_username`),
  UNIQUE KEY `voice_sip_devices_user_id_creation_key_unique` (`user_id`,`creation_key`),
  UNIQUE KEY `voice_sip_devices_connection_id_unique` (`connection_id`),
  CONSTRAINT `fk_voice_sip_devices_0_5f4f46a1aea3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_staff_phones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_staff_phones` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `available` bigint NOT NULL DEFAULT '1',
  `phone_enabled` bigint NOT NULL DEFAULT '0',
  `phone` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `verified_at` datetime(6) DEFAULT NULL,
  `pending_phone` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `verification_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `verification_expires_at` datetime(6) DEFAULT NULL,
  `verification_attempts` bigint NOT NULL DEFAULT '0',
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `voice_staff_phones_user_id_unique` (`user_id`),
  CONSTRAINT `fk_voice_staff_phones_0_9ed02a8a88eb` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_transcript_recoveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_transcript_recoveries` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `voice_call_id` bigint NOT NULL,
  `requested_by_id` bigint DEFAULT NULL,
  `recording_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `source_call_control_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `source_event_id` bigint NOT NULL,
  `idempotency_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'queued',
  `attempt` bigint NOT NULL,
  `model` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'openai/whisper-large-v3-turbo'),
  `error` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `started_at` datetime(6) DEFAULT NULL,
  `completed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `voice_transcript_recoveries_voice_call_id_idempotency_key_unique` (`voice_call_id`,`idempotency_key`),
  KEY `fk_voice_transcript_recoveries_0_fe133e5c984b` (`requested_by_id`),
  KEY `idx_voice_transcript_recoveries_voice_call_id_rec_d73775287b04` (`voice_call_id`,`recording_id`,`status`),
  CONSTRAINT `fk_voice_transcript_recoveries_0_fe133e5c984b` FOREIGN KEY (`requested_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_voice_transcript_recoveries_1_2cf7d03cfe7b` FOREIGN KEY (`voice_call_id`) REFERENCES `voice_calls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_voicemails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_voicemails` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `voice_call_id` bigint NOT NULL,
  `phone_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `recipient_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'answering'),
  `greeting_kind` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `recording_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  `recording_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `duration_seconds` bigint DEFAULT NULL,
  `failure` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `recording_started_at` datetime(6) DEFAULT NULL,
  `saved_at` datetime(6) DEFAULT NULL,
  `read_at` datetime(6) DEFAULT NULL,
  `resolved_at` datetime(6) DEFAULT NULL,
  `resolved_by` bigint DEFAULT NULL,
  `expires_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `voice_voicemails_voice_call_id_unique` (`voice_call_id`),
  UNIQUE KEY `voice_voicemails_recording_id_unique` (`recording_id`),
  KEY `voice_voicemails_expires_at_index` (`expires_at`),
  KEY `voice_voicemails_phone_number_index` (`phone_number`),
  KEY `fk_voice_voicemails_0_9ee86e756712` (`resolved_by`),
  CONSTRAINT `fk_voice_voicemails_0_9ee86e756712` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_voice_voicemails_1_f3e13271282b` FOREIGN KEY (`voice_call_id`) REFERENCES `voice_calls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_wrap_up_operations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_wrap_up_operations` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `voice_call_id` bigint NOT NULL,
  `idempotency_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `request_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `request_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `sms_status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'draft'),
  `message_id` bigint DEFAULT NULL,
  `response_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `voice_wrap_up_operation_key` (`voice_call_id`,`idempotency_key`),
  KEY `fk_voice_wrap_up_operations_0_f2477647d9bb` (`message_id`),
  CONSTRAINT `fk_voice_wrap_up_operations_0_f2477647d9bb` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_voice_wrap_up_operations_1_98e0cfa582e0` FOREIGN KEY (`voice_call_id`) REFERENCES `voice_calls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `watermark_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `watermark_settings` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'default'),
  `is_active` bigint NOT NULL DEFAULT '1',
  `logo_enabled` bigint NOT NULL DEFAULT '1',
  `logo_position` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'bottom-right'),
  `logo_opacity` bigint NOT NULL DEFAULT '60',
  `logo_size` decimal(65,30) NOT NULL DEFAULT '20.000000000000000000000000000000',
  `logo_offset_x` bigint NOT NULL DEFAULT '3',
  `logo_offset_y` bigint NOT NULL DEFAULT '8',
  `custom_logo_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `text_enabled` bigint NOT NULL DEFAULT '0',
  `text_content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `text_style` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'diagonal'),
  `text_opacity` bigint NOT NULL DEFAULT '40',
  `text_color` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'#FFFFFF'),
  `text_size` bigint NOT NULL DEFAULT '10',
  `text_spacing` bigint NOT NULL DEFAULT '200',
  `overlay_enabled` bigint NOT NULL DEFAULT '0',
  `overlay_color` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'rgba(0,0,0,0.1)'),
  `updated_by` bigint DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `text_angle` bigint NOT NULL DEFAULT '-30',
  PRIMARY KEY (`id`),
  KEY `fk_watermark_settings_0_00d1a27588fe` (`updated_by`),
  CONSTRAINT `fk_watermark_settings_0_00d1a27588fe` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `website_email_deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_email_deliveries` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `reference` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `payload_hash` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (_utf8mb4'pending'),
  `message_id` bigint DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `website_email_deliveries_type_reference_unique` (`type`,`reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_logs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `shoot_id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `action` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_workflow_logs_0_7d55fb0e61b0` (`user_id`),
  KEY `fk_workflow_logs_1_09757bfe6773` (`shoot_id`),
  CONSTRAINT `fk_workflow_logs_0_7d55fb0e61b0` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_workflow_logs_1_09757bfe6773` FOREIGN KEY (`shoot_id`) REFERENCES `shoots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


INSERT INTO `migrations` (`id`,`migration`,`batch`) VALUES
(1,'0001_01_01_000000_create_users_table',1),
(2,'0001_01_01_000001_create_cache_table',1),
(3,'0001_01_01_000002_create_jobs_table',1),
(4,'2024_07_09_000001_create_shoot_service_table',1),
(5,'2024_12_31_200533_create_shoots_table',1),
(6,'2025_01_15_000000_add_created_by_to_users_table',1),
(7,'2025_01_15_100000_make_username_nullable',1),
(8,'2025_01_18_192836_create_shoot_files_table',1),
(9,'2025_01_19_000000_create_invoices_tables',1),
(10,'2025_01_20_000000_enhance_shoots_table_for_workflow',1),
(11,'2025_01_20_000001_add_invoice_approval_workflow_fields',1),
(12,'2025_01_20_000001_create_shoot_notes_table',1),
(13,'2025_01_20_000002_create_shoot_media_albums_table',1),
(14,'2025_01_20_000002_seed_system_automations',1),
(15,'2025_01_20_000003_enhance_shoot_files_for_albums',1),
(16,'2025_01_20_000004_create_shoot_activity_logs_table',1),
(17,'2025_01_21_000000_create_shoot_slideshows_table',1),
(18,'2025_01_21_000001_create_tour_branding_table',1),
(19,'2025_01_21_000002_create_user_branding_tables',1),
(20,'2025_01_XX_update_client_data_from_csv',1),
(21,'2025_02_21_000001_add_integration_fields_to_shoots',1),
(22,'2025_05_23_075547_create_personal_access_tokens_table',1),
(23,'2025_05_23_081724_add_extended_fields_to_users_table',1),
(24,'2025_05_24_075428_create_categories_table',1),
(25,'2025_05_24_081530_create_services_table',1),
(26,'2025_06_16_203243_create_photographer_availabilities_table',1),
(27,'2025_07_02_192837_add_shared_shoot_fields',1),
(28,'2025_07_02_192837_enhance_workflow_system',1),
(29,'2025_07_02_192838_add_service_category_to_shoots',1),
(30,'2025_07_15_000000_create_oauth_tokens_table',1),
(31,'2025_07_16_000000_add_notes_fields_to_shoots',1),
(32,'2025_07_17_000000_add_shoot_approval_fields',1),
(33,'2025_11_01_000001_add_date_and_status_to_photographer_availabilities',1),
(34,'2025_11_01_100020_add_invoice_id_to_payments_table',1),
(35,'2025_11_26_232529_add_icon_to_services_table',1),
(36,'2025_11_27_000000_create_coupons_table',1),
(37,'2025_11_27_000100_create_shoot_reschedule_requests_table',1),
(38,'2025_11_27_000200_create_shoot_messages_table',1),
(39,'2025_11_27_082325_create_settings_table',1),
(40,'2025_11_27_105337_add_icon_to_categories_table',1),
(42,'2025_11_27_192227_align_email_schema_fields',1),
(43,'2025_11_27_193031_create_automation_dispatches_table',1),
(45,'2025_11_28_000500_update_message_channels_columns',1),
(46,'2025_11_28_090936_add_dropbox_integration_fields_to_shoots',1),
(48,'2025_12_01_000001_add_metadata_to_users_table',1),
(49,'2025_12_01_010000_create_editing_requests_table',1),
(50,'2025_12_02_163510_create_account_links_table',1),
(51,'2025_12_10_000000_update_sms_conversations',1),
(52,'2025_12_15_000000_create_ai_chat_sessions_table',1),
(53,'2025_12_15_000001_create_ai_messages_table',1),
(54,'2025_12_16_000000_add_state_fields_to_ai_chat_sessions_table',1),
(55,'2025_12_17_000000_add_chat_fields_to_ai_chat_sessions_table',1),
(56,'2025_12_06_063000_add_indexes_to_shoots_table',2),
(57,'2025_12_12_124244_add_photographer_pay_to_shoot_service_table',3),
(58,'2025_12_20_000001_add_timezone_to_users_table',3),
(59,'2025_12_12_125057_add_photographer_fields_to_services_table',4),
(60,'2025_12_21_000000_create_ai_editing_jobs_table',5),
(61,'2025_12_21_000001_add_ai_editing_fields_to_shoot_files_table',6),
(62,'2025_12_15_190000_add_photo_count_to_services_table',7),
(63,'2025_12_15_191000_add_is_default_to_categories_table',7),
(64,'2025_12_17_201000_migrate_legacy_uploaded_statuses',8),
(65,'2025_12_17_202500_remap_legacy_statuses',9),
(66,'2025_12_17_211500_normalize_shoot_statuses_to_unified',10),
(67,'2025_12_17_230000_convert_completed_status_to_uploaded',11),
(68,'2025_12_17_232900_migrate_shoots_with_images_to_uploaded',12),
(69,'2025_12_18_001000_add_editor_id_to_shoots',13),
(70,'2025_12_18_001010_enforce_single_editor_377',14),
(71,'2025_12_18_000500_enforce_single_editor_and_assign_editing_shoots',15),
(72,'2025_12_18_200000_add_pricing_fields_to_services_table',15),
(73,'2025_12_21_150000_add_quantity_to_services_table',16),
(74,'2025_12_21_151000_add_photo_count_to_service_sqft_ranges_table',16),
(75,'2025_12_21_131012_add_sort_order_to_shoot_files_table',17),
(76,'2025_12_22_190000_add_approval_workflow_fields_to_shoots',18),
(77,'2025_12_22_223933_add_indexes_to_shoots_table',19),
(78,'2025_12_22_225408_add_image_processing_fields_to_shoot_files_table',20),
(79,'2026_01_13_100552_make_square_payment_id_nullable_in_payments_table',21),
(80,'2026_01_13_102200_add_shoot_invoice_columns_to_invoices_table',22),
(81,'2026_01_14_042832_add_composite_index_workflow_status_scheduled_date_to_shoots_table',23),
(82,'2026_01_14_111516_remove_review_status_from_shoots',24),
(83,'2026_01_14_150000_add_billing_period_columns_to_invoices',24),
(84,'2026_01_16_145748_add_tour_links_to_shoots_table',25),
(85,'2026_01_15_120000_create_invoice_shoot_table',26),
(86,'2025_12_23_000001_add_watermarked_size_paths_to_shoot_files_table',27),
(87,'2026_01_18_200000_create_shoot_share_links_table',27),
(88,'2026_01_19_111618_create_watermark_settings_table',28),
(89,'2026_01_19_000000_add_cancellation_request_fields_to_shoots',29),
(90,'2025_12_20_000000_add_sender_metadata_to_messages',30),
(91,'2026_01_21_130000_add_text_angle_to_watermark_settings_table',30),
(92,'2025_12_18_001500_enforce_single_editor_and_assign_editing_shoots',31),
(93,'2026_01_22_000001_add_mmm_fields_to_shoots',31),
(94,'2026_01_22_000002_create_mmm_punchout_sessions_table',31),
(96,'2025_11_27_185709_update_messaging_tables_for_email_and_automation',32),
(97,'2025_11_27_200000_create_messaging_system_tables',33),
(98,'2025_11_28_100000_enhance_messaging_for_full_center',34),
(99,'2026_01_30_000001_add_contact_fields_to_users_table',34),
(100,'2025_01_30_000000_add_secondary_roles_to_users_table',35),
(101,'2026_01_31_160000_remove_media_upload_photos_ready_automation',36),
(102,'2026_02_05_000100_add_payment_method_details_to_payments_and_invoices',37),
(103,'2026_02_05_000200_add_hold_request_fields_to_shoots',37),
(104,'2026_02_09_090000_add_photographer_paid_fields_to_shoots_table',38),
(105,'2026_02_09_190000_add_sales_rep_id_to_invoices_table',39),
(106,'2026_02_22_000001_add_listing_type_to_shoots_table',40),
(107,'2026_02_22_000002_create_contact_submissions_table',40),
(108,'2026_02_22_000003_add_social_media_to_users_table',40),
(109,'2026_02_22_140000_add_photographer_id_to_shoot_service_table',40),
(110,'2026_03_01_180000_add_stripe_fields_to_payments_table',41),
(111,'2026_03_02_100000_add_social_links_to_user_branding',42),
(112,'2026_03_02_120000_add_banner_about_to_user_branding',43),
(113,'2026_03_14_200000_add_hero_fields_to_user_branding',44),
(114,'2026_03_16_070000_add_is_hidden_to_shoot_files_table',45),
(115,'2026_03_19_000001_update_email_branding_to_r_e_pro',46),
(116,'2026_03_20_170000_add_property_status_to_shoots_table',47),
(117,'2026_03_20_180000_create_tour_events_table',48),
(118,'2026_03_20_000001_create_ai_video_presets_table',49),
(119,'2026_03_20_000002_create_ai_video_generation_jobs_table',49),
(121,'2026_03_20_000003_create_ai_video_vertical_variants_table',50),
(122,'2026_03_21_000001_add_square_standard_aspect_ratios',50),
(123,'2026_03_21_000002_rebuild_ai_video_vertical_variants_table',51),
(124,'2026_03_22_000001_create_service_groups_tables',52),
(125,'2026_03_22_000002_enhance_automation_dispatches_table',53),
(126,'2026_03_22_000003_add_workflow_fields_to_automation_rules',54),
(127,'2026_03_22_000004_create_automation_runs_tables',54),
(128,'2026_03_22_000005_refresh_messaging_system_templates',54),
(129,'2026_03_23_000006_refresh_messaging_template_design_system',55),
(130,'2026_03_23_000007_refresh_messaging_template_polish',56),
(131,'2026_03_23_000008_add_weekly_invoice_messaging_template',57),
(132,'2026_03_23_000009_refresh_shoot_messaging_consistency',58),
(133,'2026_03_26_000001_replace_mightycall_sms_with_twilio',59),
(134,'2026_03_28_000001_create_system_overview_sessions_table',60),
(135,'2026_03_28_000002_create_system_overview_route_events_table',60),
(136,'2026_03_28_000003_create_system_overview_request_traces_table',60),
(137,'2026_03_28_000004_create_system_overview_error_events_table',60),
(138,'2026_03_29_000001_add_client_discount_and_shoot_cc_fields',61),
(139,'2026_04_01_000001_align_shoot_email_matrix',62),
(140,'2026_04_02_000002_add_shoot_request_modified_automation',63),
(141,'2026_04_03_000001_add_is_default_to_service_groups_table',64),
(142,'2026_04_06_000001_create_shoot_ghost_users_table',65),
(143,'2026_04_07_000000_add_related_shoot_context_type_to_messages_table',65),
(144,'2026_04_07_000001_add_show_map_to_user_branding',65),
(145,'2026_04_07_010000_add_related_shoot_id_to_message_threads_table',65),
(146,'2026_04_07_020000_add_is_featured_to_shoots_table',65),
(147,'2026_04_08_000001_add_media_stage_to_shoot_share_links_table',66),
(148,'2026_04_08_000002_add_editor_fields_to_shoot_service_table',66),
(149,'2026_04_09_000000_create_google_calendar_connections_table',67),
(150,'2026_04_09_000100_create_google_calendar_event_mappings_table',67),
(151,'2026_04_09_180000_create_editor_payouts_table',68),
(152,'2026_04_10_000001_create_public_payment_access_tokens_table',69),
(153,'2026_04_10_000002_add_public_token_to_shoot_share_links_table',69),
(154,'2026_04_11_000001_create_user_activity_logs_table',70),
(155,'2026_04_12_000001_add_email_health_fields_to_users_table',71),
(156,'2026_04_18_000001_refresh_declined_shoot_email_reason_layout',72),
(157,'2026_04_18_000002_refresh_shoot_booked_recipient_policy',72),
(158,'2026_04_18_000003_create_shoot_email_deliveries_table',72),
(159,'2026_04_18_000004_add_metadata_to_messages_table',72),
(160,'2026_04_23_000001_create_system_email_dispatches_table',73),
(161,'2026_04_23_000002_mark_protected_email_templates_legacy',73),
(162,'2026_04_23_000003_create_client_email_verification_tokens_table',74),
(163,'2026_04_27_000001_add_weekly_payout_automation_fields',75),
(164,'2026_04_29_000001_create_photographer_equipment_tables',76),
(165,'2026_04_29_000002_add_accounting_expenses_and_equipment_financials',77),
(166,'2026_05_01_000001_add_service_item_operations',78),
(167,'2026_05_04_000001_add_display_settings_to_shoots_table',79),
(168,'2026_05_04_183700_expand_property_status_values',79),
(169,'2026_05_04_190000_allow_new_property_status_values_on_sqlite',80),
(170,'2026_05_07_133000_rebrand_ai_editing_jobs_to_autoenhance',81),
(171,'2026_05_10_000000_add_iguide_data_to_shoots',82),
(172,'2026_05_11_000000_add_cubicasa_data_to_shoots',83),
(173,'2026_05_11_120000_add_is_listing_hidden_to_shoots_table',84),
(174,'2026_05_12_223227_make_ai_editing_jobs_shoot_id_nullable',84),
(175,'2026_05_15_120000_add_telnyx_to_sms_numbers',85),
(176,'2026_05_16_120000_add_sms_opt_in_flags',85),
(177,'2026_05_16_120100_add_ai_columns_to_message_threads',85),
(178,'2026_05_16_120200_add_channel_to_ai_chat_sessions',85),
(179,'2026_05_16_120300_add_ai_override_to_sms_numbers',85),
(180,'2026_05_17_120000_create_tool_bridge_invocations_table',85),
(181,'2026_05_18_120000_create_voice_calls_table',85),
(182,'2026_05_18_120100_create_telnyx_webhook_events_table',85),
(183,'2026_05_18_120200_create_voice_call_verifications_table',85),
(184,'2026_05_18_120300_add_voice_flags_to_sms_numbers',85),
(185,'2026_05_18_120400_create_scheduled_voice_calls_and_extend_voice_calls',85),
(186,'2026_05_20_120000_create_voice_llm_usage_table',86),
(187,'2026_05_20_120100_create_voice_schedule_overrides_table',86),
(188,'2026_05_29_000000_create_ai_listing_video_jobs_table',86),
(189,'2026_05_31_000001_add_internal_shoot_classification',87),
(190,'2026_06_01_000000_ensure_failed_at_on_messages',87),
(191,'2026_06_03_000000_extend_oauth_tokens_for_upload_sources',88),
(192,'2026_05_30_000000_create_ai_reel_jobs_table',89),
(193,'2026_06_08_000000_refresh_invoice_payment_reminder_system_data',89),
(194,'2026_06_08_010000_add_soft_deletes_and_normalize_user_status',89),
(195,'2026_06_08_010100_add_cubicasa_sync_state_to_shoots',89),
(196,'2026_06_08_010200_add_editing_requirement_flags_to_shoot_files',89),
(197,'2026_06_08_010300_create_service_areas_tables',89),
(198,'2026_06_08_010400_add_qa_fixes_columns_to_shoots_table',89),
(199,'2026_06_08_010500_add_scan_columns_to_shoot_files_table',89),
(200,'2026_06_08_010600_add_lock_fields_to_users_table',89),
(201,'2026_06_08_010700_add_target_to_user_activity_logs_table',89),
(202,'2026_06_08_010800_create_payment_reminders_table',89),
(203,'2026_06_12_000000_add_homepage_featured_shoot_fields',90),
(204,'2026_06_14_010000_add_requires_editing_to_services_table',91),
(205,'2026_06_14_020000_add_override_fields_to_message_templates',91),
(206,'2026_06_16_000001_refresh_manual_notification_and_account_templates',92),
(207,'2026_06_21_120000_add_vapi_normalized_voice_tables',93),
(208,'2026_06_22_000001_create_onboarding_events_table',93),
(209,'2026_06_23_000001_add_latitude_longitude_to_shoots_table',94),
(210,'2026_06_24_000001_add_external_booking_columns_to_shoots_table',94),
(211,'2026_06_29_160000_add_featured_request_approval_to_shoots_table',95),
(212,'2026_07_01_000000_add_restore_until_to_users_table',96),
(213,'2026_06_23_000001_create_projects_table',97),
(214,'2026_06_23_000002_create_project_media_table',97),
(215,'2026_06_23_000003_create_templates_table',97),
(216,'2026_06_23_000004_create_brand_state_table',97),
(217,'2026_06_23_000005_create_generated_assets_table',97),
(218,'2026_07_02_000100_add_project_and_request_ids_to_ai_job_tables',97),
(219,'2026_07_13_000200_add_studio_submission_fields',97),
(220,'2026_07_31_000001_add_photographer_pay_percent_to_services',98),
(221,'2026_07_31_000002_create_payment_refunds_table',98),
(222,'2026_07_31_000003_add_grid_path_to_shoot_files',98),
(223,'2026_08_01_000001_add_request_workflow_to_shoot_reschedule_requests',98),
(224,'2026_08_06_000001_add_delivery_media_order_to_shoots_table',99),
(225,'2026_08_16_000001_backfill_protected_email_template_overrides',99),
(226,'2026_08_16_000002_normalize_support_phone_in_message_templates',99),
(227,'2026_08_17_000001_create_photographer_address_change_requests_table',100),
(228,'2026_08_20_000002_create_shoot_upload_attempts_table',101),
(229,'2026_08_20_000006_create_legal_acceptances_table',101),
(230,'2026_08_20_000007_repair_known_protected_email_templates',101),
(231,'2026_08_20_000008_add_override_health_to_message_templates',101),
(232,'2026_08_20_010000_make_shoot_notes_canonical',101),
(233,'2026_08_20_020000_create_client_delivery_notifications_table',101),
(234,'2026_08_23_000001_add_uses_hdr_brackets_to_services',102),
(235,'2026_08_23_000002_add_default_bracket_mode_to_users',102),
(236,'2026_08_23_000003_add_bracket_mode_to_shoot_service',102),
(237,'2026_08_24_000001_add_upload_intake_type_to_services',103),
(238,'2026_08_24_000002_backfill_service_upload_capabilities',103),
(239,'2026_08_25_000001_add_treatment_to_shoot_files',104),
(240,'2026_08_31_000001_resubmit_legacy_payee_rejected_invoices',105),
(241,'2026_08_31_000002_create_iguide_offline_upload_sessions',106),
(242,'2026_09_01_000001_add_profile_security_fields_to_users_table',107),
(243,'2026_09_01_010000_add_complimentary_reshoot_lineage_to_shoots',108),
(244,'2026_09_01_010050_add_nominal_value_snapshot_to_shoot_service',108),
(245,'2026_09_01_010100_create_comp_reshoot_items_table',108),
(246,'2026_09_01_010200_create_shoot_compensations_table',108),
(247,'2026_09_01_010300_link_invoice_items_to_shoot_compensations',108),
(248,'2026_09_01_020000_add_document_semantics_to_invoices_table',108),
(249,'2026_09_01_030000_add_compensation_adjustments_and_payout_locks',108),
(250,'2026_09_01_040000_attest_existing_ready_iguide_offline_packages',109),
(251,'2026_09_02_000001_add_sales_rep_pay_enabled_to_shoots',110),
(252,'2026_09_03_000001_repair_invoice_00038_payee_contamination',111),
(253,'2026_09_04_000001_add_return_token_to_mmm_punchout_sessions',112),
(254,'2026_09_04_000001_add_status_to_payment_refunds_table',113),
(255,'2026_09_04_000002_create_stripe_checkout_attempts_tables',113),
(256,'2026_09_04_000003_add_unique_stripe_session_id_to_payments',113),
(257,'2026_09_06_000001_create_studio_workspaces',114),
(258,'2026_09_06_210000_create_user_tax_documents_table',115),
(259,'2026_09_06_200000_add_auth_security_rollout',116),
(260,'2026_09_08_120000_create_studio_provider_settings_table',117),
(261,'2026_09_11_180000_move_digital_enhancements_into_category',118),
(262,'2026_09_13_000001_add_editable_atelier_flow_templates',119),
(263,'2026_09_13_000002_register_direct_email_editor_templates',119),
(264,'2026_09_13_000003_add_missing_protected_atelier_templates',119),
(265,'2026_09_13_000004_add_editable_email_content_blocks',120),
(266,'2026_09_16_000001_add_permission_overrides_to_users_table',121),
(267,'2026_09_20_160000_ensure_delivered_at_and_error_message_on_messages',122),
(268,'2026_09_21_120000_create_short_links_table',123),
(269,'2026_09_21_010000_create_voice_follow_up_tasks_and_wrap_up_operations',124),
(270,'2026_09_21_020000_create_voice_automation_rules_and_runs',124),
(271,'2026_09_21_030000_create_voice_browser_tables',124),
(272,'2026_09_25_200000_link_studio_workspaces_to_shoot_dispatches',125),
(273,'2026_09_25_210000_add_bundled_video_editing_assignment',125),
(274,'2026_09_27_010000_repair_editable_system_automations',126),
(275,'2026_09_27_120000_create_invoice_accounts_notes_table',127),
(276,'2026_09_27_183000_create_sms_groups_tables',128),
(277,'2026_09_27_210000_add_sms_group_conversations',129),
(278,'2026_09_27_231500_limit_property_access_reminders',130),
(279,'2026_09_28_000001_add_multi_unit_bookings',131),
(280,'2026_09_28_000002_add_shoot_unit_tour_fields',131),
(281,'2026_09_28_000003_add_editor_payout_execution_identity',131),
(282,'2026_09_28_000004_add_studio_service_line_scope',131),
(283,'2026_09_28_000005_add_reschedule_units_revision',131),
(284,'2026_09_27_120000_create_listing_studio_requests_table',132),
(285,'2026_09_28_120000_add_migration_only_to_services',133),
(286,'2026_09_28_180000_compact_sms_template_content',134),
(287,'2026_09_30_000001_create_external_booking_submissions_table',135),
(288,'2026_09_30_200000_separate_shoot_payment_reminder_email',136),
(289,'2026_09_30_201000_make_stock_shoot_reminders_weekly',136),
(290,'2026_09_30_201000_register_shoot_summary_email',136),
(291,'2026_09_30_120000_enable_robbie_role_support_guides',137),
(292,'2026_09_30_120001_enable_voice_support_knowledge',137),
(293,'2026_10_01_010000_add_shared_voice_offers',137),
(294,'2026_10_01_010100_create_voice_transcript_recoveries',137),
(295,'2026_10_01_100000_create_support_tickets',137),
(296,'2026_10_01_110000_create_voice_push_tables',137),
(297,'2026_10_01_120000_route_shoot_requests_to_sales_reps',138),
(298,'2026_09_28_210000_create_listing_studio_subscription_sync',139),
(299,'2026_09_28_220000_create_listing_studio_subscription_refunds',139),
(300,'2026_09_28_230000_create_listing_studio_credit_entries',139),
(301,'2026_10_01_100000_align_payment_reminders_to_delivery_workflow',140),
(302,'2026_10_01_120100_schedule_client_appointment_emails',140),
(303,'2026_10_01_140000_add_shoot_duration_minutes_to_services',141),
(304,'2026_10_01_160000_add_support_message_sources_and_attachments',142),
(305,'2026_10_02_180000_apply_approved_service_duration_policy',143),
(306,'2026_10_02_220000_create_scheduling_route_usage_table',144),
(307,'2026_10_02_210000_create_shoot_upload_batches_table',145),
(308,'2026_10_03_051900_backfill_missing_service_upload_intake',146),
(309,'2026_10_03_091500_add_office_phone_to_users_table',147),
(310,'2026_10_03_120000_create_aryeo_integration_tables',148),
(311,'2026_10_03_120000_add_durable_raw_upload_reservations',149),
(312,'2026_10_03_140000_add_progress_to_aryeo_jobs',150),
(313,'2026_10_03_100000_create_media_editing_dispatches_and_versions',151),
(314,'2026_10_03_110000_create_desktop_editing_sessions',151),
(315,'2026_10_04_120000_assign_intake_editing_dispatch_lanes',152),
(316,'2026_10_05_180000_add_email_and_admin_payment_permissions',153),
(317,'2026_10_07_100000_add_shoot_approval_annotations',154),
(318,'2026_10_07_110000_set_standalone_floor_plan_duration',154),
(319,'2026_10_07_120000_add_payout_invoice_review_revisions',155),
(320,'2026_10_07_120000_add_scoped_shoot_management_permissions',155),
(321,'2026_10_09_160000_create_copilot_integration_tables',156),
(322,'2026_10_09_180000_add_copilot_integration_manage_permission',157),
(323,'2026_10_09_120000_allow_invoice_only_payment_records',158),
(324,'2026_10_10_010000_create_notification_read_states_table',159),
(325,'2026_10_10_050000_create_ai_provider_usage_table',160),
(326,'2026_10_10_100000_create_website_email_deliveries_table',161),
(327,'2026_10_11_170000_freeze_booked_photographer_payout_basis',162),
(328,'2026_10_12_090000_consolidate_photographer_weekly_invoice_email',163),
(329,'2026_10_13_180000_correct_digital_enhancement_assignments',164),
(330,'2026_10_14_060000_create_voice_line_policies_and_voicemails',165),
(331,'2026_10_14_160000_add_voice_line_recipient_roles',166),
(332,'2026_10_14_210000_add_voice_sip_devices',167),
(333,'2026_10_15_040000_add_groundwire_sms',168),
(334,'2026_10_16_160000_add_email_template_workspace',169),
(335,'2026_10_16_160000_enable_comped_reshoot_photo_uploads',169),
(336,'2026_10_17_120000_add_sms_inbox_state',170),
(337,'2026_10_17_130000_allow_sms_group_threads_per_sender',170),
(338,'2026_10_17_140000_register_configured_main_sms_sender',170),
(339,'2026_10_17_150000_update_client_notification_mornings',171),
(340,'2026_10_17_180000_enable_showcase_intake_and_additional_files',172),
(341,'2026_10_04_053000_add_overview_returns_to_editing_dispatch_items',173),
(342,'2026_10_21_040000_add_metadata_discovery_to_aryeo_connections',174),
(343,'2026_10_21_120000_create_listing_studio_trial_claims',175),
(344,'2026_10_24_180000_add_note_to_photographer_availabilities',176),
(345,'2026_10_25_160000_make_automated_sms_defaults_link_free',177),
(346,'2026_10_26_000001_create_email_workspace_tables',178),
(347,'2026_10_28_160000_add_shoot_media_read_indexes',179),
(348,'2026_10_29_030000_enable_showcase_premium_video_intake',180),
(349,'2026_10_29_050000_add_included_components_to_services',181),
(350,'2026_10_29_040000_create_mobile_notification_tables',182),
(351,'2026_10_29_120000_create_voice_mobile_tables',182),
(352,'2026_10_29_180000_create_sms_shared_conversations',182),
(353,'2026_10_30_050000_bind_voice_mobile_sessions_to_access_tokens',182),
(354,'2026_10_30_060000_add_mobile_notification_delivery_window',182),
(355,'2026_10_31_120000_create_listing_studio_guest_trials',183),
(356,'2026_10_31_140000_create_listing_studio_trial_admissions',184),
(357,'2026_10_31_180000_add_listing_studio_trial_monitoring',185),
(358,'2026_11_01_000000_create_listing_studio_financial_core',186),
(359,'2026_11_01_001000_create_listing_studio_processing',186),
(360,'2026_11_01_002000_create_listing_studio_payment_attempts',186),
(361,'2026_11_01_000100_add_explicit_studio_template_scopes',187),
(362,'2026_11_01_000400_add_studio_workspace_access_media',187),
(363,'2026_11_02_001000_create_listing_studio_billing_requests',188),
(364,'2026_11_02_002000_create_listing_studio_subscription_actions',188),
(365,'2026_10_31_150000_track_mobile_credential_rotation',189),
(366,'2026_11_03_160000_add_active_work_cycle_to_shoots',190),
(367,'2026_11_03_180000_add_search_relation_lookup_indexes',191),
(368,'2026_11_04_180000_restore_optional_messaging_columns',192),
(369,'2026_11_04_180100_preserve_aryeo_cascade_change_feed',192);
