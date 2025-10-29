-- Sunny per-user quick actions
CREATE TABLE IF NOT EXISTS `sunny_quick_actions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `label` VARCHAR(20) NOT NULL,
  `message` TEXT NOT NULL,
  `position` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_position` (`user_id`,`position`),
  CONSTRAINT `fk_quick_actions_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ensure only up to 5 positions (0..4) are used per user (application-enforced)

