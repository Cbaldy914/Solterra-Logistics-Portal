-- Feedback on assistant messages
CREATE TABLE IF NOT EXISTS `sunny_feedback` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `conversation_id` INT UNSIGNED DEFAULT NULL,
  `frontend_message_id` VARCHAR(64) DEFAULT NULL,
  `server_message_id` BIGINT UNSIGNED DEFAULT NULL,
  `rating` TINYINT NOT NULL, -- 1 for upvote, -1 for downvote
  `comment` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`,`created_at`),
  CONSTRAINT `fk_feedback_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_feedback_convo` FOREIGN KEY (`conversation_id`) REFERENCES `sunny_conversations`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

