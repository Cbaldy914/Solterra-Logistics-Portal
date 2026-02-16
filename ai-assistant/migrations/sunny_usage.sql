CREATE TABLE IF NOT EXISTS `sunny_usage` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `usage_date` DATE NOT NULL,
  `model` VARCHAR(50) NOT NULL DEFAULT 'gemini-2.5-flash',
  `prompt_tokens` INT UNSIGNED NOT NULL DEFAULT 0,
  `completion_tokens` INT UNSIGNED NOT NULL DEFAULT 0,
  `request_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `estimated_cost_usd` DECIMAL(10,6) NOT NULL DEFAULT 0.000000,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_date_model` (`user_id`, `usage_date`, `model`),
  KEY `idx_user_date` (`user_id`, `usage_date`),
  CONSTRAINT `fk_usage_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
