CREATE TABLE `zemzemeh_dlp_provenance` (
    `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` int(10) UNSIGNED NOT NULL,
    `oidc_sub` varchar(255) NOT NULL,
    `session_hash` varchar(64) NOT NULL,
    `compose_id` varchar(128) NOT NULL,
    `upload_id` varchar(64) NOT NULL,
    `zz_file_id` varchar(32) NOT NULL,
    `zz_owner` varchar(255) NOT NULL,
    `zz_version` varchar(64) NOT NULL,
    `sha256` varchar(64) NOT NULL,
    `filename` varchar(1024) NOT NULL,
    `mimetype` varchar(255) NOT NULL,
    `size` bigint NOT NULL,
    `spool_name` varchar(64) NOT NULL,
    `decision` varchar(16) NOT NULL,
    `selected_at` datetime NOT NULL,
    `expires_at` datetime NOT NULL,
    `consumed_at` datetime DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `ix_zemzemeh_dlp_provenance_upload` (`session_hash`, `upload_id`),
    INDEX `ix_zemzemeh_dlp_provenance_compose` (`compose_id`),
    INDEX `ix_zemzemeh_dlp_provenance_hash` (`user_id`, `sha256`),
    INDEX `ix_zemzemeh_dlp_provenance_expires` (`expires_at`),
    CONSTRAINT `fk_zemzemeh_dlp_provenance_user_id` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ROW_FORMAT=DYNAMIC ENGINE=INNODB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `system` (`name`, `value`) VALUES ('zemzemeh_dlp-version', '2026102700');
