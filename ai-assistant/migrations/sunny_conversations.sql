-- Conversations and messages for Sunny
CREATE TABLE IF NOT EXISTS `sunny_conversations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `title` VARCHAR(120) NOT NULL,
  `started_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_message_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_user_last` (`user_id`,`last_message_at`),
  CONSTRAINT `fk_convos_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sunny_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` INT UNSIGNED NOT NULL,
  `role` ENUM('user','assistant') NOT NULL,
  `content` MEDIUMTEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_convo_time` (`conversation_id`,`created_at`),
  CONSTRAINT `fk_msgs_convo` FOREIGN KEY (`conversation_id`) REFERENCES `sunny_conversations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

