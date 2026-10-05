USE `flixhn`;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('admin','subscriber') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'subscriber',
  `customer_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `assigned_ip` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `max_screens` tinyint unsigned NOT NULL DEFAULT '2',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_username_unique` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` VALUES (1, 'admin', '$2y$12$x4xeLdIYQ6V53yMsSeMKz.HGHFqKYfq/lH5rd72.rgBherrmuDJQC', 'admin', 'Administrador de Red ISP', NULL, 5, 1, NULL, '2026-10-01 13:14:33', '2026-10-01 13:14:33'),
(3, 'fcaceres', '$2y$12$peutSmwf/7A3jyFaKPLMaOTyQJxuq6BnKgVLpEIeoKzWN98WsknXS', 'subscriber', 'Fernando Cáceres', NULL, 1, 1, NULL, '2026-10-05 12:22:21', '2026-10-05 12:30:07');

DROP TABLE IF EXISTS `profiles`;
CREATE TABLE `profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `avatar_color` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#E50914',
  `is_kids` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `profiles_user_id_foreign` (`user_id`),
  CONSTRAINT `profiles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `profiles` VALUES (1, 1, 'Admin', '#E50914', 0, '2026-10-01 13:14:34', '2026-10-01 13:14:34'),
(4, 3, 'Principal', '#E50914', 0, '2026-10-05 12:22:21', '2026-10-05 12:22:21');

DROP TABLE IF EXISTS `personal_access_tokens`;
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`)
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `personal_access_tokens` VALUES (26, 'App\\Models\\User', 1, 'flixhn-device', 'dea1c4ddecc7c952ca8c49ffa4de063edf6d8e12ac3ecfbe8b1e8f9b0e69e92a', '[\"*\"]', '2026-10-04 13:47:42', NULL, '2026-10-04 13:40:39', '2026-10-04 13:47:42'),
(27, 'App\\Models\\User', 1, 'flixhn-device', '83c97f34e0b898382a840eb447c6ce9dadd0a675a4566a5591d673de7939a4c2', '[\"*\"]', '2026-10-04 18:04:15', NULL, '2026-10-04 13:59:30', '2026-10-04 18:04:15'),
(29, 'App\\Models\\User', 1, 'flixhn-device', 'e2a8846a31db41ab62c30e0974bb5107f39e7d93c122a007ec7649e3b7707fce', '[\"*\"]', '2026-10-04 15:55:53', NULL, '2026-10-04 15:52:18', '2026-10-04 15:55:53'),
(34, 'App\\Models\\User', 1, 'test', '96c3d9560b8497b1def1d580bd28b67061fbc1dc5615aee886682a310f938727', '[\"*\"]', '2026-10-05 11:10:35', NULL, '2026-10-05 11:10:35', '2026-10-05 11:10:35');

DROP TABLE IF EXISTS `categories`;
CREATE TABLE `categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'movie',
  `folder_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `categories_slug_unique` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=63 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `categories` VALUES (1, 'Películas', 'peliculas', 'movie', 'peliculas', 'Largometrajes cinematográficos en alta definición On-Net.', 3, 1, '2026-10-02 09:47:35', '2026-10-02 12:49:54'),
(2, 'Series', 'series', 'series', 'series', 'Series completas, temporadas y episodios para maratonear.', 7, 1, '2026-10-02 09:47:35', '2026-10-02 12:49:54'),
(3, 'Infantiles', 'infantiles', 'movie', 'infantiles', 'Contenido infantil y caricaturas para toda la familia.', 5, 1, '2026-10-02 09:47:35', '2026-10-02 12:49:54'),
(4, 'Documéntales', 'documentales', 'movie', 'documentales', 'Naturaleza, ciencia, historia y exploraciones.', 10, 1, '2026-10-02 09:47:35', '2026-10-02 12:49:54'),
(5, 'Crimen', 'crimen', 'movie', 'crimen', 'Investigaciones policiales, suspense y drama criminal.', 14, 1, '2026-10-02 09:47:35', '2026-10-02 12:49:54'),
(6, 'Ficción', 'ficcion', 'movie', 'ficcion', 'Ciencia ficción, viajes espaciales y tecnología.', 9, 1, '2026-10-02 09:47:35', '2026-10-02 12:49:54'),
(7, 'Estrenos', 'estrenos', 'movie', 'estrenos', 'Lanzamientos recientes y estrenos del servidor.', 1, 1, '2026-10-02 11:03:23', '2026-10-02 11:03:23'),
(8, 'Animadas', 'animadas', 'movie', 'animadas', 'Películas animadas y animación familiar.', 4, 1, '2026-10-02 11:03:23', '2026-10-02 11:03:23'),
(9, 'Cristianas', 'cristianas', 'movie', 'cristianas', 'Películas y producciones cristianas de valores.', 13, 1, '2026-10-02 11:03:23', '2026-10-02 12:49:54'),
(10, 'Colecciones', 'colecciones', 'mixed', 'colecciones', 'Colecciones cinematográficas y sagas completas.', 10, 1, '2026-10-02 11:03:23', '2026-10-02 11:03:23'),
(11, 'Collections', 'collections', 'mixed', 'collections', NULL, 2, 1, '2026-10-02 12:49:54', '2026-10-02 12:49:54'),
(12, 'Playlists', 'playlists', 'mixed', 'playlists', NULL, 4, 1, '2026-10-02 12:49:54', '2026-10-02 12:49:54'),
(13, 'Series 2', 'series-2', 'series', 'series-2', NULL, 6, 1, '2026-10-02 12:49:54', '2026-10-02 12:49:54'),
(14, 'Animate', 'animate', 'series', 'animate', NULL, 8, 1, '2026-10-02 12:49:54', '2026-10-02 12:49:54'),
(15, 'Romanticas', 'romanticas', 'movie', 'romanticas', NULL, 11, 1, '2026-10-02 12:49:54', '2026-10-02 12:49:54'),
(16, 'Misterio Suspenso', 'misterio-suspenso', 'movie', 'misterio-suspenso', NULL, 12, 1, '2026-10-02 12:49:54', '2026-10-02 12:49:54'),
(17, 'Drama', 'drama', 'movie', 'drama', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(18, 'Acción', 'accion', 'movie', 'accion', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(19, 'Película', 'pelicula', 'movie', 'pelicula', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(20, 'Comedia', 'comedia', 'movie', 'comedia', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(21, 'Suspense', 'suspense', 'movie', 'suspense', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(22, 'Misterio', 'misterio', 'movie', 'misterio', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(23, 'Terror', 'terror', 'movie', 'terror', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(24, 'Fantasía', 'fantasia', 'movie', 'fantasia', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(25, 'Aventura', 'aventura', 'movie', 'aventura', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(26, 'Romance', 'romance', 'movie', 'romance', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(27, 'Ciencia ficción', 'ciencia-ficcion', 'movie', 'ciencia-ficcion', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(28, 'Animación', 'animacion', 'movie', 'animacion', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(29, 'Música', 'musica', 'movie', 'musica', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(30, 'Western', 'western', 'movie', 'western', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(31, 'Documental', 'documental', 'movie', 'documental', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(32, 'Comedy', 'comedy', 'movie', 'comedy', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(33, 'Adventure', 'adventure', 'movie', 'adventure', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(34, 'Familia', 'familia', 'movie', 'familia', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(35, 'Bélica', 'belica', 'movie', 'belica', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(36, 'Action', 'action', 'movie', 'action', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(37, 'Historia', 'historia', 'movie', 'historia', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(38, 'B�lico', 'blico', 'movie', 'blico', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(39, 'Horror', 'horror', 'movie', 'horror', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(40, 'Crime', 'crime', 'movie', 'crime', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(41, 'Short', 'short', 'movie', 'short', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(42, 'Sport', 'sport', 'movie', 'sport', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(43, 'Science Fiction', 'science-fiction', 'movie', 'science-fiction', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(44, 'Película de TV', 'pelicula-de-tv', 'movie', 'pelicula-de-tv', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(45, 'Documentary', 'documentary', 'movie', 'documentary', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(46, 'Thriller', 'thriller', 'movie', 'thriller', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(47, 'Musical', 'musical', 'movie', 'musical', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(48, 'Family', 'family', 'movie', 'family', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(49, 'Biography', 'biography', 'movie', 'biography', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(50, 'News', 'news', 'series', 'news', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(51, 'Mini-Series', 'mini-series', 'series', 'mini-series', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(52, 'Children', 'children', 'series', 'children', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(53, 'Fantasy', 'fantasy', 'series', 'fantasy', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(54, 'Reality', 'reality', 'series', 'reality', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(55, 'Soap', 'soap', 'series', 'soap', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(56, 'Talk Show', 'talk-show', 'series', 'talk-show', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(57, 'Serie', 'serie', 'series', 'serie', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(58, 'Action & Adventure', 'action-adventure', 'series', 'action-adventure', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(59, 'Animation', 'animation', 'series', 'animation', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(60, 'Anime', 'anime', 'series', 'anime', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(61, 'Sci-Fi & Fantasy', 'sci-fi-fantasy', 'series', 'sci-fi-fantasy', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13'),
(62, 'Food', 'food', 'series', 'food', NULL, 12, 1, '2026-10-02 13:07:13', '2026-10-02 13:07:13');

DROP TABLE IF EXISTS `titles`;
CREATE TABLE `titles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('movie','series') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'movie',
  `category_id` bigint unsigned DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `poster_url` text COLLATE utf8mb4_unicode_ci,
  `backdrop_url` text COLLATE utf8mb4_unicode_ci,
  `release_year` smallint unsigned NOT NULL,
  `genre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_featured` tinyint(1) NOT NULL DEFAULT '0',
  `stream_path` text COLLATE utf8mb4_unicode_ci,
  `source_path` text COLLATE utf8mb4_unicode_ci,
  `duration_seconds` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `titles_slug_unique` (`slug`),
  KEY `titles_category_id_idx` (`category_id`),
  KEY `titles_type_idx` (`type`),
  KEY `titles_release_year_idx` (`release_year`),
  KEY `titles_created_at_idx` (`created_at`),
  CONSTRAINT `titles_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=17800 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `seasons`;
CREATE TABLE `seasons` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `title_id` bigint unsigned NOT NULL,
  `season_number` smallint unsigned NOT NULL DEFAULT '1',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Temporada 1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `seasons_title_id_foreign` (`title_id`),
  CONSTRAINT `seasons_title_id_foreign` FOREIGN KEY (`title_id`) REFERENCES `titles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=262 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `episodes`;
CREATE TABLE `episodes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `title_id` bigint unsigned NOT NULL,
  `season_id` bigint unsigned DEFAULT NULL,
  `episode_number` smallint unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `stream_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `duration_seconds` int unsigned NOT NULL DEFAULT '0',
  `thumbnail_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `episodes_title_id_foreign` (`title_id`),
  KEY `episodes_season_id_foreign` (`season_id`),
  CONSTRAINT `episodes_season_id_foreign` FOREIGN KEY (`season_id`) REFERENCES `seasons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `episodes_title_id_foreign` FOREIGN KEY (`title_id`) REFERENCES `titles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4907 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `playback_progress`;
CREATE TABLE `playback_progress` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `profile_id` bigint unsigned NOT NULL,
  `title_id` bigint unsigned NOT NULL,
  `episode_id` bigint unsigned DEFAULT NULL,
  `current_seconds` int unsigned NOT NULL DEFAULT '0',
  `duration_seconds` int unsigned NOT NULL DEFAULT '0',
  `last_watched_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `playback_progress_profile_id_title_id_episode_id_unique` (`profile_id`,`title_id`,`episode_id`),
  KEY `playback_progress_title_id_foreign` (`title_id`),
  KEY `playback_progress_episode_id_foreign` (`episode_id`),
  CONSTRAINT `playback_progress_episode_id_foreign` FOREIGN KEY (`episode_id`) REFERENCES `episodes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `playback_progress_profile_id_foreign` FOREIGN KEY (`profile_id`) REFERENCES `profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `playback_progress_title_id_foreign` FOREIGN KEY (`title_id`) REFERENCES `titles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `tv_sources`;
CREATE TABLE `tv_sources` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'M3U',
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'm3u',
  `url` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `referer_mode` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Ninguno',
  `referrer_header` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stream_limit` int NOT NULL DEFAULT '0',
  `group_filter` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `import_guide_from_m3u` tinyint(1) NOT NULL DEFAULT '1',
  `preferred_image_source` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Sintonizador / M3U',
  `allow_channel_number_mapping` tinyint(1) NOT NULL DEFAULT '0',
  `tags` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `channels_count` int NOT NULL DEFAULT '0',
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tv_sources` VALUES (1, 'M3U', 'm3u', 'http://45.4.87.126:58004/get.php?username=emby&password=emby&type=m3u&output=hls', NULL, 'Ninguno', NULL, 0, NULL, 1, 'Sintonizador / M3U', 0, NULL, 1, 137, '2026-10-05 11:41:12', '2026-10-04 16:48:27', '2026-10-05 11:41:12');

DROP TABLE IF EXISTS `tv_channels`;
CREATE TABLE `tv_channels` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tv_source_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stream_url` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `logo_url` text COLLATE utf8mb4_unicode_ci,
  `group_title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'General',
  `channel_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tvg_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tvg_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tv_channels_tv_source_id_group_title_index` (`tv_source_id`,`group_title`),
  KEY `tv_channels_is_active_index` (`is_active`),
  CONSTRAINT `tv_channels_tv_source_id_foreign` FOREIGN KEY (`tv_source_id`) REFERENCES `tv_sources` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=138 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tv_channels` VALUES (1, 1, 'A&E HD', 'http://45.4.87.126:58004/live/emby/emby/121.m3u8', 'https://tvlogos.b-cdn.net/a-e.png', 'General', 1, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(2, 1, 'AMC HD', 'http://45.4.87.126:58004/live/emby/emby/122.m3u8', 'https://tvlogos.b-cdn.net/amc.png', 'General', 2, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(3, 1, 'CINECANAL HD', 'http://45.4.87.126:58004/live/emby/emby/132.m3u8', 'https://tvlogos.b-cdn.net/cinecanal.png', 'Cine y Series', 3, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(4, 1, 'Canal 3 Telesistemas HND', 'http://45.4.87.126:58004/live/emby/emby/136.m3u8', 'https://tvlogos.b-cdn.net/canal-3-telesistemas.png', 'Noticias', 4, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(5, 1, 'Canal 5 - HON', 'http://45.4.87.126:58004/live/emby/emby/137.m3u8', 'https://tvlogos.b-cdn.net/canal-5.png', 'Noticias', 5, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(6, 1, 'Cartoon Network', 'http://45.4.87.126:58004/live/emby/emby/139.m3u8', 'https://tvlogos.b-cdn.net/cartoon-network.png', 'Infantiles', 6, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:47'),
(7, 1, 'CineMax', 'http://45.4.87.126:58004/live/emby/emby/140.m3u8', 'https://tvlogos.b-cdn.net/cinemax.png', 'Cine y Series', 7, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:47'),
(8, 1, 'Comedy Central HD', 'http://45.4.87.126:58004/live/emby/emby/141.m3u8', 'https://tvlogos.b-cdn.net/comedy-central.png', 'General', 8, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(9, 1, 'Concert Channel', 'http://45.4.87.126:58004/live/emby/emby/142.m3u8', 'https://tvlogos.b-cdn.net/concert-channel.png', 'Noticias', 9, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:47'),
(10, 1, 'DHE HD', 'http://45.4.87.126:58004/live/emby/emby/143.m3u8', 'https://tvlogos.b-cdn.net/dhe.png', 'Cine y Series', 10, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(11, 1, 'DISC THEATHER HD', 'http://45.4.87.126:58004/live/emby/emby/144.m3u8', 'https://tvlogos.b-cdn.net/disc-theather.png', 'General', 11, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(12, 1, 'Discovery Channel HD', 'http://45.4.87.126:58004/live/emby/emby/145.m3u8', 'https://tvlogos.b-cdn.net/discovery-channel.png', 'Documentales', 12, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(13, 1, 'Discovery ID HD', 'http://45.4.87.126:58004/live/emby/emby/146.m3u8', 'https://tvlogos.b-cdn.net/discovery-id.png', 'Documentales', 13, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(14, 1, 'Discovery Kids', 'http://45.4.87.126:58004/live/emby/emby/147.m3u8', 'https://tvlogos.b-cdn.net/discovery-kids.png', 'Infantiles', 14, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:47'),
(15, 1, 'Discovery Science', 'http://45.4.87.126:58004/live/emby/emby/148.m3u8', 'https://tvlogos.b-cdn.net/discovery-science.png', 'Documentales', 15, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:47'),
(16, 1, 'Discovery Turbo', 'http://45.4.87.126:58004/live/emby/emby/149.m3u8', 'https://tvlogos.b-cdn.net/discovery-turbo.png', 'Documentales', 16, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:47'),
(17, 1, 'Discovery World HD', 'http://45.4.87.126:58004/live/emby/emby/150.m3u8', 'https://tvlogos.b-cdn.net/discovery-world.png', 'Documentales', 17, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(18, 1, 'Disney', 'http://45.4.87.126:58004/live/emby/emby/151.m3u8', 'https://tvlogos.b-cdn.net/disney.png', 'Infantiles', 18, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:47'),
(19, 1, 'Disney JR', 'http://45.4.87.126:58004/live/emby/emby/152.m3u8', 'https://tvlogos.b-cdn.net/disney-jr.png', 'Infantiles', 19, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(20, 1, 'Disney XD', 'http://45.4.87.126:58004/live/emby/emby/153.m3u8', 'https://tvlogos.b-cdn.net/disney-xd.png', 'Infantiles', 20, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(21, 1, 'ESPN 2 HD', 'http://45.4.87.126:58004/live/emby/emby/156.m3u8', 'https://tvlogos.b-cdn.net/espn-2.png', 'Deportes', 21, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(22, 1, 'ESPN 3 HD', 'http://45.4.87.126:58004/live/emby/emby/157.m3u8', 'https://tvlogos.b-cdn.net/espn-3.png', 'Deportes', 22, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(23, 1, 'HCH- HON', 'http://45.4.87.126:58004/live/emby/emby/178.m3u8', 'https://tvlogos.b-cdn.net/hch.png', 'Noticias', 23, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(24, 1, 'ESPN HD', 'http://45.4.87.126:58004/live/emby/emby/159.m3u8', 'https://tvlogos.b-cdn.net/espn.png', 'Deportes', 24, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(25, 1, 'El Gourmet', 'http://45.4.87.126:58004/live/emby/emby/161.m3u8', 'https://tvlogos.b-cdn.net/el-gourmet.png', 'Música y Entretenimiento', 25, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(26, 1, 'Exa TV', 'http://45.4.87.126:58004/live/emby/emby/163.m3u8', 'https://tvlogos.b-cdn.net/exa-tv.png', 'General', 26, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(27, 1, 'FOX SPORTS 3 HD', 'http://45.4.87.126:58004/live/emby/emby/165.m3u8', 'https://tvlogos.b-cdn.net/fox-sports-3.png', 'Deportes', 27, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(28, 1, 'FX HD', 'http://45.4.87.126:58004/live/emby/emby/166.m3u8', 'https://tvlogos.b-cdn.net/fx.png', 'Cine y Series', 28, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(29, 1, 'SONY Movies HD', 'http://45.4.87.126:58004/live/emby/emby/167.m3u8', 'https://tvlogos.b-cdn.net/sony-movies.png', 'Cine y Series', 29, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(30, 1, 'Film & arts HD', 'http://45.4.87.126:58004/live/emby/emby/168.m3u8', 'https://tvlogos.b-cdn.net/film-arts.png', 'Cine y Series', 30, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(31, 1, 'France24', 'http://45.4.87.126:58004/live/emby/emby/169.m3u8', 'https://tvlogos.b-cdn.net/france24.png', 'General', 31, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(32, 1, 'Golf Channel HD', 'http://45.4.87.126:58004/live/emby/emby/170.m3u8', 'https://tvlogos.b-cdn.net/golf-channel.png', 'Deportes', 32, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(33, 1, 'H2', 'http://45.4.87.126:58004/live/emby/emby/172.m3u8', 'https://tvlogos.b-cdn.net/h2.png', 'General', 33, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(34, 1, 'HBO', 'http://45.4.87.126:58004/live/emby/emby/173.m3u8', 'https://tvlogos.b-cdn.net/hbo.png', 'Cine y Series', 34, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(35, 1, 'HBO Family', 'http://45.4.87.126:58004/live/emby/emby/174.m3u8', 'https://tvlogos.b-cdn.net/hbo-family.png', 'Cine y Series', 35, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(36, 1, 'HBO Plus', 'http://45.4.87.126:58004/live/emby/emby/175.m3u8', 'https://tvlogos.b-cdn.net/hbo-plus.png', 'Cine y Series', 36, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(37, 1, 'HBO XTREME', 'http://45.4.87.126:58004/live/emby/emby/176.m3u8', 'https://tvlogos.b-cdn.net/hbo-xtreme.png', 'Cine y Series', 37, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(38, 1, 'HBO2', 'http://45.4.87.126:58004/live/emby/emby/177.m3u8', 'https://tvlogos.b-cdn.net/hbo2.png', 'Cine y Series', 38, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(39, 1, 'History HD', 'http://45.4.87.126:58004/live/emby/emby/180.m3u8', 'https://tvlogos.b-cdn.net/history.png', 'Documentales', 39, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(40, 1, 'Home & Health HD', 'http://45.4.87.126:58004/live/emby/emby/181.m3u8', 'https://tvlogos.b-cdn.net/home-health.png', 'General', 40, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(41, 1, 'HTV', 'http://45.4.87.126:58004/live/emby/emby/184.m3u8', 'https://tvlogos.b-cdn.net/htv.png', 'Música y Entretenimiento', 41, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(42, 1, 'Russia Today', 'http://45.4.87.126:58004/live/emby/emby/185.m3u8', 'https://tvlogos.b-cdn.net/russia-today.png', 'General', 42, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(43, 1, 'MTV', 'http://45.4.87.126:58004/live/emby/emby/186.m3u8', 'https://tvlogos.b-cdn.net/mtv.png', 'Música y Entretenimiento', 43, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(44, 1, 'ESPN 5 HD', 'http://45.4.87.126:58004/live/emby/emby/187.m3u8', 'https://tvlogos.b-cdn.net/espn-5.png', 'Deportes', 44, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(45, 1, 'Sin Limites', 'http://45.4.87.126:58004/live/emby/emby/188.m3u8', 'https://tvlogos.b-cdn.net/sin-limites.png', 'General', 45, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(46, 1, 'Multicinema', 'http://45.4.87.126:58004/live/emby/emby/190.m3u8', 'https://tvlogos.b-cdn.net/multicinema.png', 'Cine y Series', 46, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(47, 1, 'Nat Geo Kids', 'http://45.4.87.126:58004/live/emby/emby/193.m3u8', 'https://tvlogos.b-cdn.net/nat-geo-kids.png', 'Documentales', 47, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(48, 1, 'Tru TV', 'http://45.4.87.126:58004/live/emby/emby/194.m3u8', 'https://tvlogos.b-cdn.net/tru-tv.png', 'General', 48, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(49, 1, 'National Geographic', 'http://45.4.87.126:58004/live/emby/emby/195.m3u8', 'https://tvlogos.b-cdn.net/national-geographic.png', 'Documentales', 49, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(50, 1, 'Caracol', 'http://45.4.87.126:58004/live/emby/emby/196.m3u8', 'https://tvlogos.b-cdn.net/caracol.png', 'General', 50, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(51, 1, 'CLAN', 'http://45.4.87.126:58004/live/emby/emby/197.m3u8', 'https://tvlogos.b-cdn.net/clan.png', 'General', 51, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(52, 1, 'Paramount HD', 'http://45.4.87.126:58004/live/emby/emby/200.m3u8', 'https://tvlogos.b-cdn.net/paramount.png', 'Cine y Series', 52, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(53, 1, 'Pasiones HD', 'http://45.4.87.126:58004/live/emby/emby/201.m3u8', 'https://tvlogos.b-cdn.net/pasiones.png', 'General', 53, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(54, 1, 'RCN Novelas', 'http://45.4.87.126:58004/live/emby/emby/202.m3u8', 'https://tvlogos.b-cdn.net/rcn-novelas.png', 'General', 54, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(55, 1, 'SEMILLITAS', 'http://45.4.87.126:58004/live/emby/emby/204.m3u8', 'https://tvlogos.b-cdn.net/semillitas.png', 'General', 55, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(56, 1, 'SONY HD', 'http://45.4.87.126:58004/live/emby/emby/205.m3u8', 'https://tvlogos.b-cdn.net/sony.png', 'Cine y Series', 56, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(57, 1, 'SPACE HD', 'http://45.4.87.126:58004/live/emby/emby/206.m3u8', 'https://tvlogos.b-cdn.net/space.png', 'Cine y Series', 57, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(58, 1, 'STAR CHANNEL HD', 'http://45.4.87.126:58004/live/emby/emby/207.m3u8', 'https://tvlogos.b-cdn.net/star-channel.png', 'Cine y Series', 58, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(59, 1, 'STAR LIFE', 'http://45.4.87.126:58004/live/emby/emby/208.m3u8', 'https://tvlogos.b-cdn.net/star-life.png', 'General', 59, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(60, 1, 'Studio Universal HD', 'http://45.4.87.126:58004/live/emby/emby/212.m3u8', 'https://tvlogos.b-cdn.net/studio-universal.png', 'Cine y Series', 60, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(61, 1, 'TCM', 'http://45.4.87.126:58004/live/emby/emby/215.m3u8', 'https://tvlogos.b-cdn.net/tcm.png', 'General', 61, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(62, 1, 'TNT Series HD', 'http://45.4.87.126:58004/live/emby/emby/218.m3u8', 'https://tvlogos.b-cdn.net/tnt-series.png', 'Cine y Series', 62, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(63, 1, 'TeleMicro - RD', 'http://45.4.87.126:58004/live/emby/emby/221.m3u8', 'https://tvlogos.b-cdn.net/telemicro.png', 'General', 63, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(64, 1, 'Telecadena - HON', 'http://45.4.87.126:58004/live/emby/emby/222.m3u8', 'https://tvlogos.b-cdn.net/telecadena.png', 'General', 64, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(65, 1, 'Telemundo Internacional HD', 'http://45.4.87.126:58004/live/emby/emby/223.m3u8', 'https://tvlogos.b-cdn.net/telemundo-internacional.png', 'Documentales', 65, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(66, 1, 'UNIVERSAL COMEDY HD', 'http://45.4.87.126:58004/live/emby/emby/227.m3u8', 'https://tvlogos.b-cdn.net/universal-comedy.png', 'Cine y Series', 66, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(67, 1, 'Distrito Comedia', 'http://45.4.87.126:58004/live/emby/emby/239.m3u8', 'https://tvlogos.b-cdn.net/distrito-comedia.png', 'General', 67, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(68, 1, 'B Futbol Total', 'http://45.4.87.126:58004/live/emby/emby/240.m3u8', 'https://tvlogos.b-cdn.net/b-futbol-total.png', 'General', 68, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(69, 1, 'ESPN Premium', 'http://45.4.87.126:58004/live/emby/emby/241.m3u8', 'https://tvlogos.b-cdn.net/espn-premium.png', 'Deportes', 69, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(70, 1, 'S Sport La Liga', 'http://45.4.87.126:58004/live/emby/emby/242.m3u8', 'https://tvlogos.b-cdn.net/s-sport-la-liga.png', 'Deportes', 70, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(71, 1, 'Being Sport', 'http://45.4.87.126:58004/live/emby/emby/243.m3u8', 'https://tvlogos.b-cdn.net/being-sport.png', 'Deportes', 71, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(72, 1, 'AXN', 'http://45.4.87.126:58004/live/emby/emby/252.m3u8', 'https://tvlogos.b-cdn.net/axn.png', 'Cine y Series', 72, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(73, 1, 'La Liga Hondureña', 'http://45.4.87.126:58004/live/emby/emby/257.m3u8', 'https://tvlogos.b-cdn.net/la-liga-hondure-a.png', 'Deportes', 73, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(74, 1, 'TODO FUTBOL 1', 'http://45.4.87.126:58004/live/emby/emby/255.m3u8', 'https://tvlogos.b-cdn.net/todo-futbol-1.png', 'General', 74, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(75, 1, 'Canal 40', 'http://45.4.87.126:58004/live/emby/emby/261.m3u8', 'https://tvlogos.b-cdn.net/canal-40.png', 'General', 75, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(76, 1, 'Univision HD', 'http://45.4.87.126:58004/live/emby/emby/235.m3u8', 'https://tvlogos.b-cdn.net/univision.png', 'General', 76, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(77, 1, 'ESPN 4  HD', 'http://45.4.87.126:58004/live/emby/emby/158.m3u8', 'https://tvlogos.b-cdn.net/espn-4.png', 'Deportes', 77, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(78, 1, 'Animal Planet HD', 'http://45.4.87.126:58004/live/emby/emby/125.m3u8', 'https://tvlogos.b-cdn.net/animal-planet.png', 'Documentales', 78, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(79, 1, 'Antena 3', 'http://45.4.87.126:58004/live/emby/emby/126.m3u8', 'https://tvlogos.b-cdn.net/antena-3.png', 'General', 79, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(80, 1, 'Atres Series', 'http://45.4.87.126:58004/live/emby/emby/127.m3u8', 'https://tvlogos.b-cdn.net/atres-series.png', 'General', 80, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(81, 1, 'AZ click HD', 'http://45.4.87.126:58004/live/emby/emby/124.m3u8', 'https://tvlogos.b-cdn.net/az-click.png', 'General', 81, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(82, 1, 'Azcorazon', 'http://45.4.87.126:58004/live/emby/emby/128.m3u8', 'https://tvlogos.b-cdn.net/azcorazon.png', 'General', 82, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(83, 1, 'Azmundo', 'http://45.4.87.126:58004/live/emby/emby/129.m3u8', 'https://tvlogos.b-cdn.net/azmundo.png', 'Documentales', 83, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(84, 1, 'Boomerang', 'http://45.4.87.126:58004/live/emby/emby/131.m3u8', 'https://tvlogos.b-cdn.net/boomerang.png', 'Infantiles', 84, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(85, 1, 'Baby TV', 'http://45.4.87.126:58004/live/emby/emby/130.m3u8', 'https://tvlogos.b-cdn.net/baby-tv.png', 'Infantiles', 85, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(86, 1, 'CNNE', 'http://45.4.87.126:58004/live/emby/emby/134.m3u8', 'https://tvlogos.b-cdn.net/cnne.png', 'Noticias', 86, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(87, 1, 'CNN Internacional', 'http://45.4.87.126:58004/live/emby/emby/133.m3u8', 'https://tvlogos.b-cdn.net/cnn-internacional.png', 'Noticias', 87, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(88, 1, 'Cine Premium HD', 'http://45.4.87.126:58004/live/emby/emby/264.m3u8', 'https://tvlogos.b-cdn.net/cine-premium.png', 'Cine y Series', 88, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(89, 1, 'E! Entertairment HD', 'http://45.4.87.126:58004/live/emby/emby/154.m3u8', 'https://tvlogos.b-cdn.net/e-entertairment.png', 'Música y Entretenimiento', 89, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(90, 1, 'GOLDEN PREMIER', 'http://45.4.87.126:58004/live/emby/emby/256.m3u8', 'https://tvlogos.b-cdn.net/golden-premier.png', 'Deportes', 90, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(91, 1, 'EWTN', 'http://45.4.87.126:58004/live/emby/emby/160.m3u8', 'https://tvlogos.b-cdn.net/ewtn.png', 'General', 91, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(92, 1, 'Europa HD', 'http://45.4.87.126:58004/live/emby/emby/162.m3u8', 'https://tvlogos.b-cdn.net/europa.png', 'General', 92, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(93, 1, 'HOLA! TV HD', 'http://45.4.87.126:58004/live/emby/emby/179.m3u8', 'https://tvlogos.b-cdn.net/hola-tv.png', 'General', 93, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(94, 1, 'Guatevision - GUA', 'http://45.4.87.126:58004/live/emby/emby/171.m3u8', 'https://tvlogos.b-cdn.net/guatevision.png', 'General', 94, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(95, 1, 'Mas Chic', 'http://45.4.87.126:58004/live/emby/emby/189.m3u8', 'https://tvlogos.b-cdn.net/mas-chic.png', 'General', 95, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(96, 1, 'LIFE TIME', 'http://45.4.87.126:58004/live/emby/emby/182.m3u8', 'https://tvlogos.b-cdn.net/life-time.png', 'General', 96, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(97, 1, 'La voz de Maria', 'http://45.4.87.126:58004/live/emby/emby/183.m3u8', 'https://tvlogos.b-cdn.net/la-voz-de-maria.png', 'General', 97, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(98, 1, 'Nat Geo HD', 'http://45.4.87.126:58004/live/emby/emby/192.m3u8', 'https://tvlogos.b-cdn.net/nat-geo.png', 'Documentales', 98, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(99, 1, 'Multipremier', 'http://45.4.87.126:58004/live/emby/emby/191.m3u8', 'https://tvlogos.b-cdn.net/multipremier.png', 'General', 99, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(100, 1, 'Sembrador', 'http://45.4.87.126:58004/live/emby/emby/210.m3u8', 'https://tvlogos.b-cdn.net/sembrador.png', 'General', 100, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48');

INSERT INTO `tv_channels` VALUES (101, 1, 'RHEMA TV', 'http://45.4.87.126:58004/live/emby/emby/203.m3u8', 'https://tvlogos.b-cdn.net/rhema-tv.png', 'General', 101, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(102, 1, 'TBN ENLACE', 'http://45.4.87.126:58004/live/emby/emby/253.m3u8', 'https://tvlogos.b-cdn.net/tbn-enlace.png', 'General', 102, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(103, 1, 'SUYAPA TV', 'http://45.4.87.126:58004/live/emby/emby/209.m3u8', 'https://tvlogos.b-cdn.net/suyapa-tv.png', 'General', 103, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(104, 1, 'Sun Channel', 'http://45.4.87.126:58004/live/emby/emby/213.m3u8', 'https://tvlogos.b-cdn.net/sun-channel.png', 'General', 104, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(105, 1, 'Star TVE HD', 'http://45.4.87.126:58004/live/emby/emby/211.m3u8', 'https://tvlogos.b-cdn.net/star-tve.png', 'General', 105, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(106, 1, 'WARNER HD', 'http://45.4.87.126:58004/live/emby/emby/236.m3u8', 'https://tvlogos.b-cdn.net/warner.png', 'Cine y Series', 106, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(107, 1, 'UNIVERSAL REALITY HD', 'http://45.4.87.126:58004/live/emby/emby/233.m3u8', 'https://tvlogos.b-cdn.net/universal-reality.png', 'Cine y Series', 107, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(108, 1, 'UNIVERSAL PREMIERE O HD', 'http://45.4.87.126:58004/live/emby/emby/231.m3u8', 'https://tvlogos.b-cdn.net/universal-premiere-o.png', 'Cine y Series', 108, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(109, 1, 'UNIVERSAL PREMIERE HD', 'http://45.4.87.126:58004/live/emby/emby/230.m3u8', 'https://tvlogos.b-cdn.net/universal-premiere.png', 'Cine y Series', 109, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(110, 1, 'UNIVERSAL CRIME HD', 'http://45.4.87.126:58004/live/emby/emby/229.m3u8', 'https://tvlogos.b-cdn.net/universal-crime.png', 'Cine y Series', 110, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(111, 1, 'UNIVERSAL CINEMA HD', 'http://45.4.87.126:58004/live/emby/emby/225.m3u8', 'https://tvlogos.b-cdn.net/universal-cinema.png', 'Cine y Series', 111, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(112, 1, 'Universal', 'http://45.4.87.126:58004/live/emby/emby/234.m3u8', 'https://tvlogos.b-cdn.net/universal.png', 'Cine y Series', 112, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(113, 1, 'TVE', 'http://45.4.87.126:58004/live/emby/emby/220.m3u8', 'https://tvlogos.b-cdn.net/tve.png', 'General', 113, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(114, 1, 'TVChile', 'http://45.4.87.126:58004/live/emby/emby/219.m3u8', 'https://tvlogos.b-cdn.net/tvchile.png', 'General', 114, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(115, 1, 'TLC', 'http://45.4.87.126:58004/live/emby/emby/216.m3u8', 'https://tvlogos.b-cdn.net/tlc.png', 'Documentales', 115, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(116, 1, 'Tigo Sports HD', 'http://45.4.87.126:58004/live/emby/emby/224.m3u8', 'https://tvlogos.b-cdn.net/tigo-sports.png', 'General', 116, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(117, 1, 'Telefe Internacional', 'http://45.4.87.126:58004/live/emby/emby/265.m3u8', 'https://tvlogos.b-cdn.net/telefe-internacional.png', 'General', 117, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(118, 1, 'TUDN MX', 'http://45.4.87.126:58004/live/emby/emby/266.m3u8', 'https://tvlogos.b-cdn.net/tudn.png', 'Deportes', 118, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(119, 1, 'Canal 8 TNH HN', 'http://45.4.87.126:58004/live/emby/emby/267.m3u8', 'https://tvlogos.b-cdn.net/canal-8-tnh-hn.png', 'General', 119, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(120, 1, 'Todo Novelas', 'http://45.4.87.126:58004/live/emby/emby/268.m3u8', 'https://tvlogos.b-cdn.net/todo-novelas.png', 'General', 120, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(121, 1, 'Pelimex', 'http://45.4.87.126:58004/live/emby/emby/269.m3u8', 'https://tvlogos.b-cdn.net/pelimex.png', 'General', 121, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(122, 1, '7-Teletica', 'http://45.4.87.126:58004/live/emby/emby/120.m3u8', 'https://tvlogos.b-cdn.net/7-teletica.png', 'General', 122, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(123, 1, 'Canal 11 - HON', 'http://45.4.87.126:58004/live/emby/emby/135.m3u8', 'https://tvlogos.b-cdn.net/canal-11.png', 'General', 123, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(124, 1, 'Canal VTV HND', 'http://45.4.87.126:58004/live/emby/emby/138.m3u8', 'https://tvlogos.b-cdn.net/canal-vtv.png', 'General', 124, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(125, 1, 'TNT HD', 'http://45.4.87.126:58004/live/emby/emby/217.m3u8', 'https://tvlogos.b-cdn.net/tnt.png', 'Cine y Series', 125, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(126, 1, 'UNE TV', 'http://45.4.87.126:58004/live/emby/emby/244.m3u8', 'https://tvlogos.b-cdn.net/une-tv.png', 'General', 126, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(127, 1, 'Qhubo TV', 'http://45.4.87.126:58004/live/emby/emby/245.m3u8', 'https://tvlogos.b-cdn.net/qhubo-tv.png', 'General', 127, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(128, 1, 'ZooMoo', 'http://45.4.87.126:58004/live/emby/emby/238.m3u8', 'https://tvlogos.b-cdn.net/zoomoo.png', 'General', 128, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(129, 1, 'Warner', 'http://45.4.87.126:58004/live/emby/emby/237.m3u8', 'https://tvlogos.b-cdn.net/warner.png', 'Cine y Series', 129, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(130, 1, 'A&E', 'http://45.4.87.126:58004/live/emby/emby/251.m3u8', 'https://tvlogos.b-cdn.net/a-e.png', 'General', 130, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(131, 1, 'TEN Canal 10 HN', 'http://45.4.87.126:58004/live/emby/emby/254.m3u8', 'https://tvlogos.b-cdn.net/ten-canal-10-hn.png', 'General', 131, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(132, 1, 'TLNovelas', 'http://45.4.87.126:58004/live/emby/emby/246.m3u8', 'https://tvlogos.b-cdn.net/tlnovelas.png', 'General', 132, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(133, 1, 'DW', 'http://45.4.87.126:58004/live/emby/emby/250.m3u8', 'https://tvlogos.b-cdn.net/dw.png', 'Noticias', 133, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(134, 1, 'El Canal De Las Estrellas MX', 'http://45.4.87.126:58004/live/emby/emby/258.m3u8', 'https://tvlogos.b-cdn.net/el-canal-de-las-estrellas.png', 'General', 134, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(135, 1, 'Telemundo Miami', 'http://45.4.87.126:58004/live/emby/emby/259.m3u8', 'https://tvlogos.b-cdn.net/telemundo-miami.png', 'Documentales', 135, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48'),
(136, 1, 'AXN HD', 'http://45.4.87.126:58004/live/emby/emby/123.m3u8', 'https://tvlogos.b-cdn.net/axn.png', 'Cine y Series', 136, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:55:30'),
(137, 1, 'Canal 6 HN', 'http://45.4.87.126:58004/live/emby/emby/263.m3u8', 'https://tvlogos.b-cdn.net/canal-6-hn.png', 'General', 137, NULL, NULL, 1, '2026-10-05 11:41:12', '2026-10-05 11:54:48');

DROP TABLE IF EXISTS `tv_guide_sources`;
CREATE TABLE `tv_guide_sources` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'XMLTV Guía',
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'xmltv',
  `url` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tv_guide_sources` VALUES (1, 'Emby XMLTV EPG', 'xmltv', 'http://65.187.110.22:58004/xmltv.php?username=emby&password=emby', 1, '2026-10-04 16:48:27', '2026-10-04 16:48:27', '2026-10-04 16:48:27');

DROP TABLE IF EXISTS `media_nodes`;
CREATE TABLE `media_nodes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `port` int NOT NULL DEFAULT '6789',
  `api_key` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_master` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `media_nodes` VALUES (1, 'server01-PowerEdge-R720', '45.4.87.126', 6789, '0fe0eb20e78f431d9fa474e05ab2d82f', 1, 1, '2026-10-04 16:32:34', '2026-10-04 16:32:34');

DROP TABLE IF EXISTS `server_settings`;
CREATE TABLE `server_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci,
  `group` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general',
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'string',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `server_settings_key_unique` (`key`),
  KEY `server_settings_group_index` (`group`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `server_settings` VALUES (1, 'lan_networks', '10.0.0.0/8, 192.168.0.0/16, 172.16.0.0/16, 180.80.8.0/24', 'network', 'string', '2026-10-02 08:38:48', '2026-10-02 08:38:48'),
(2, 'local_ip_address', '', 'network', 'string', '2026-10-02 08:38:48', '2026-10-02 08:53:19'),
(3, 'local_http_port', 8789, 'network', 'integer', '2026-10-02 08:38:48', '2026-10-02 09:04:45'),
(4, 'local_https_port', 8920, 'network', 'integer', '2026-10-02 08:38:48', '2026-10-02 09:04:45'),
(5, 'allow_remote_connections', 1, 'network', 'boolean', '2026-10-02 08:38:48', '2026-10-02 08:38:48'),
(6, 'remote_ip_filter', '', 'network', 'string', '2026-10-02 08:38:48', '2026-10-02 08:38:48'),
(7, 'remote_ip_filter_mode', 'Whitelist', 'network', 'string', '2026-10-02 08:38:48', '2026-10-02 09:04:45'),
(8, 'public_http_port', 8789, 'network', 'integer', '2026-10-02 08:38:48', '2026-10-02 09:04:45'),
(9, 'public_https_port', 8920, 'network', 'integer', '2026-10-02 08:38:48', '2026-10-02 08:53:19'),
(10, 'external_domain', '', 'network', 'string', '2026-10-02 08:38:48', '2026-10-02 09:04:45'),
(11, 'read_proxy_headers', 'Only when they contain remote network addresses', 'network', 'string', '2026-10-02 08:38:48', '2026-10-02 09:04:45'),
(12, 'custom_ssl_cert_path', '', 'network', 'string', '2026-10-02 08:38:48', '2026-10-02 09:04:45'),
(13, 'certificate_password', '', 'network', 'string', '2026-10-02 08:38:48', '2026-10-02 08:38:48'),
(14, 'secure_connection_mode', 'Disabled', 'network', 'string', '2026-10-02 08:38:48', '2026-10-02 09:04:45'),
(15, 'enable_upnp', 0, 'network', 'boolean', '2026-10-02 08:38:48', '2026-10-02 09:04:45'),
(16, 'max_simultaneous_streams', 'Unlimited', 'network', 'string', '2026-10-02 08:38:48', '2026-10-02 09:04:45'),
(17, 'internet_streaming_bitrate_limit', '', 'network', 'string', '2026-10-02 08:38:48', '2026-10-02 09:04:45'),
(18, 'emby_server_url', 'http://45.4.87.126:6789', 'emby', 'string', '2026-10-02 11:02:52', '2026-10-02 13:07:07'),
(19, 'emby_server_name', 'server01-PowerEdge-R720', 'emby', 'string', '2026-10-02 11:02:53', '2026-10-02 11:02:53'),
(20, 'media_server_url', 'http://45.4.87.126:6789', 'media', 'string', '2026-10-02 11:41:49', '2026-10-02 13:07:07'),
(21, 'media_server_api_key', '0fe0eb20e78f431d9fa474e05ab2d82f', 'media', 'string', '2026-10-02 11:41:49', '2026-10-02 13:06:25'),
(22, 'emby_api_key', '0fe0eb20e78f431d9fa474e05ab2d82f', 'emby', 'string', '2026-10-02 11:41:49', '2026-10-02 13:06:25');

DROP TABLE IF EXISTS `migrations`;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` VALUES (1, '0001_01_01_000000_create_users_table', 1),
(2, '2026_09_28_000001_create_personal_access_tokens_table', 1),
(3, '2026_09_28_000002_create_profiles_table', 1),
(4, '2026_09_28_000003_create_titles_table', 1),
(5, '2026_09_28_000004_create_seasons_table', 1),
(6, '2026_09_28_000005_create_episodes_table', 1),
(7, '2026_09_28_000006_create_playback_progress_table', 1),
(8, '2026_10_02_000001_create_server_settings_table', 2),
(9, '2026_10_02_000002_create_categories_table', 3),
(10, '2026_10_02_000003_modify_titles_artwork_columns', 4),
(11, '2026_10_02_000004_expand_titles_stream_columns', 5),
(12, '2026_10_02_000005_add_performance_indexes_to_titles_table', 6),
(13, '2026_10_02_142748_create_cache_table', 7),
(14, '2026_10_04_000001_create_media_nodes_table', 8),
(15, '2026_10_04_000002_create_tv_sources_and_channels_tables', 9);

DROP TABLE IF EXISTS `password_reset_tokens`;
CREATE TABLE `password_reset_tokens` (
  `username` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `sessions`;
CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `cache`;
CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `cache_locks`;
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
