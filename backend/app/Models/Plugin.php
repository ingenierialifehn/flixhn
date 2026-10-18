<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plugin extends Model
{
    use HasFactory;

    protected $table = 'plugins';

    protected $fillable = [
        'slug',
        'name',
        'category',
        'version',
        'description',
        'icon_name',
        'icon_url',
        'author',
        'is_installed',
        'is_enabled',
        'settings',
        'default_settings',
    ];

    protected $casts = [
        'is_installed' => 'boolean',
        'is_enabled' => 'boolean',
        'settings' => 'array',
        'default_settings' => 'array',
    ];

    /**
     * Definición de categorías del ecosistema de extensiones.
     */
    public static function categories(): array
    {
        return [
            'metadata' => [
                'id' => 'metadata',
                'name' => 'Metadatos y Carátulas',
                'description' => 'Descarga y sincronización de carátulas HD, sinopsis, elencos y calificaciones.',
                'icon' => 'Film',
            ],
            'livetv' => [
                'id' => 'livetv',
                'name' => 'Televisión y Guía EPG',
                'description' => 'Sintonizadores M3U/HLS, sincronización XMLTV y parrillas de programación.',
                'icon' => 'Tv',
            ],
            'subtitles' => [
                'id' => 'subtitles',
                'name' => 'Subtítulos y Reproducción',
                'description' => 'Soporte de subtítulos externos, trailers previos e intros de cine.',
                'icon' => 'Subtitles',
            ],
            'network' => [
                'id' => 'network',
                'name' => 'Red e Integraciones',
                'description' => 'Webhooks, mapeo de puertos UPnP/NAT-PMP y protocolos de servidor DLNA.',
                'icon' => 'Network',
            ],
            'system' => [
                'id' => 'system',
                'name' => 'Sistema y Herramientas',
                'description' => 'Copias de seguridad, análisis de tráfico y métricas avanzadas de telemetría.',
                'icon' => 'Cpu',
            ],
        ];
    }

    /**
     * Catálogo inicial de extensiones oficiales de FlixHN.
     */
    public static function defaultCatalog(): array
    {
        return [
            // --- METADATOS Y CARÁTULAS ---
            [
                'slug' => 'themoviedb',
                'name' => 'TheMovieDB (TMDb)',
                'category' => 'metadata',
                'version' => '2.4.1',
                'description' => 'Descarga automática de carátulas en alta definición, pósters, sinopsis en español, elenco y calificaciones desde la API oficial de TMDb.',
                'icon_name' => 'Film',
                'author' => 'FlixHN Core Team',
                'is_installed' => true,
                'is_enabled' => true,
                'default_settings' => [
                    'api_key' => 'tmdb_live_key_99f82d1',
                    'language' => 'es-ES',
                    'include_adult' => false,
                    'download_artwork_hd' => true,
                    'cache_ttl_days' => 30,
                ],
            ],
            [
                'slug' => 'thetvdb',
                'name' => 'TheTVDB',
                'category' => 'metadata',
                'version' => '1.8.3',
                'description' => 'Proveedor primario de información de series de TV, temporadas, orden de episodios, fechas de emisión y arte promocional.',
                'icon_name' => 'Tv',
                'author' => 'FlixHN Core Team',
                'is_installed' => true,
                'is_enabled' => true,
                'default_settings' => [
                    'api_key' => '',
                    'pin' => '',
                    'preferred_season_type' => 'official',
                    'language' => 'es-ES',
                ],
            ],
            [
                'slug' => 'omdb',
                'name' => 'OMDb',
                'category' => 'metadata',
                'version' => '1.3.0',
                'description' => 'Enriquece las películas y series con puntajes de IMDb, Rotten Tomatoes y Metascore mediante la API de Open Movie Database.',
                'icon_name' => 'Star',
                'author' => 'Community',
                'is_installed' => false,
                'is_enabled' => false,
                'default_settings' => [
                    'api_key' => '',
                    'include_tomatoes' => true,
                    'plot_type' => 'full',
                ],
            ],
            [
                'slug' => 'fanart-tv',
                'name' => 'Fanart.tv',
                'category' => 'metadata',
                'version' => '2.0.5',
                'description' => 'Extrae logos transparentes (clearlogo), discos de vinilo/bluray (disc art), banners y fondos de pantalla 4K creados por la comunidad.',
                'icon_name' => 'Palette',
                'author' => 'FlixHN Core Team',
                'is_installed' => false,
                'is_enabled' => false,
                'default_settings' => [
                    'api_key' => '',
                    'personal_key' => '',
                    'download_clearlogo' => true,
                    'download_landscapes' => true,
                ],
            ],
            [
                'slug' => 'nfo-metadata',
                'name' => 'NFO Metadata',
                'category' => 'metadata',
                'version' => '1.1.2',
                'description' => 'Lector y escritor de archivos .nfo compatibles con Kodi y Emby para almacenamiento local de metadatos junto a los archivos multimedia.',
                'icon_name' => 'FileText',
                'author' => 'FlixHN Core Team',
                'is_installed' => true,
                'is_enabled' => true,
                'default_settings' => [
                    'read_local_nfo' => true,
                    'save_artwork_locally' => true,
                    'nfo_format' => 'kodi',
                ],
            ],
            [
                'slug' => 'studio-images',
                'name' => 'Studio Images',
                'category' => 'metadata',
                'version' => '1.0.4',
                'description' => 'Biblioteca de logotipos vectoriales de productoras cinematográficas y cadenas de TV (Warner Bros, HBO, Netflix, Disney+, etc.).',
                'icon_name' => 'Building2',
                'author' => 'FlixHN Core Team',
                'is_installed' => false,
                'is_enabled' => false,
                'default_settings' => [
                    'format' => 'png',
                    'dark_theme_logos' => true,
                ],
            ],
            [
                'slug' => 'theaudiodb',
                'name' => 'TheAudioDb',
                'category' => 'metadata',
                'version' => '1.2.1',
                'description' => 'Metadatos de audio, pistas sonoras originales (OST), biografías de artistas musicales y carátulas de álbumes.',
                'icon_name' => 'Music',
                'author' => 'Community',
                'is_installed' => false,
                'is_enabled' => false,
                'default_settings' => [
                    'api_key' => '1',
                    'language' => 'es',
                ],
            ],
            [
                'slug' => 'musicbrainz',
                'name' => 'MusicBrainz',
                'category' => 'metadata',
                'version' => '1.4.0',
                'description' => 'Enciclopedia abierta de música para identificación y etiquetado preciso de bandas sonoras y conciertos grabados.',
                'icon_name' => 'Disc',
                'author' => 'Community',
                'is_installed' => false,
                'is_enabled' => false,
                'default_settings' => [
                    'rate_limit_req_per_sec' => 1,
                    'fetch_coverart_archive' => true,
                ],
            ],

            // --- TELEVISIÓN Y GUÍA EPG ---
            [
                'slug' => 'm3u-tv-tuner',
                'name' => 'M3U TV Tuner',
                'category' => 'livetv',
                'version' => '2.1.0',
                'description' => 'Sintonizador de listas M3U / M3U8 para recepción de canales IPTV multicast UDP, HLS y flujos On-Net de cabecera ISP.',
                'icon_name' => 'Tv2',
                'author' => 'FlixHN Core Team',
                'is_installed' => true,
                'is_enabled' => true,
                'default_settings' => [
                    'stream_buffer_ms' => 2000,
                    'max_simultaneous_streams' => 100,
                    'auto_reload_interval_hours' => 12,
                    'user_agent' => 'FlixHN-IPTV/2.1',
                ],
            ],
            [
                'slug' => 'xmltv',
                'name' => 'XmlTV',
                'category' => 'livetv',
                'version' => '1.6.0',
                'description' => 'Motor de análisis y sincronización de guías electrónicas de programas (EPG) en formato estándar XMLTV.',
                'icon_name' => 'ListOrdered',
                'author' => 'FlixHN Core Team',
                'is_installed' => true,
                'is_enabled' => true,
                'default_settings' => [
                    'xmltv_url' => 'http://127.0.0.1/epg.xml',
                    'sync_cron' => '0 */6 * * *',
                    'timezone_offset' => '-06:00',
                    'retention_days' => 7,
                ],
            ],
            [
                'slug' => 'iptv-guide-data',
                'name' => 'IPTV Guide Data',
                'category' => 'livetv',
                'version' => '1.1.0',
                'description' => 'Proveedor global de sincronización de parrilla para canales de TV por cable e internacionales con correspondencia automática de IDs.',
                'icon_name' => 'Radio',
                'author' => 'FlixHN Core Team',
                'is_installed' => false,
                'is_enabled' => false,
                'default_settings' => [
                    'provider_endpoint' => 'https://epg.flixhn.com/api/v1',
                    'auto_match_channel_names' => true,
                ],
            ],

            // --- SUBTÍTULOS Y REPRODUCCIÓN ---
            [
                'slug' => 'opensubtitles',
                'name' => 'OpenSubtitles',
                'category' => 'subtitles',
                'version' => '3.0.2',
                'description' => 'Búsqueda y descarga automática de subtítulos sincronizados mediante hash de archivo y texto usando la API REST v1 de OpenSubtitles.com.',
                'icon_name' => 'Subtitles',
                'author' => 'FlixHN Core Team',
                'is_installed' => true,
                'is_enabled' => true,
                'default_settings' => [
                    'username' => 'flixhn_isp',
                    'password' => '',
                    'api_key' => 'op_live_sec_key_481',
                    'download_languages' => ['es', 'en'],
                    'auto_download' => true,
                    'encoding' => 'UTF-8',
                ],
            ],
            [
                'slug' => 'cinema-intros',
                'name' => 'Cinema Intros',
                'category' => 'subtitles',
                'version' => '1.2.0',
                'description' => 'Reproduce intros personalizadas del ISP, bumpers de cine y avances de próximos estrenos antes de iniciar una película.',
                'icon_name' => 'Clapperboard',
                'author' => 'FlixHN Core Team',
                'is_installed' => false,
                'is_enabled' => false,
                'default_settings' => [
                    'intros_folder' => '/media/intros',
                    'play_before_movies' => true,
                    'play_before_episodes' => false,
                    'trailers_count' => 1,
                ],
            ],
            [
                'slug' => 'bluray-dvd-support',
                'name' => 'Bluray/DVD Folder Support',
                'category' => 'subtitles',
                'version' => '1.0.8',
                'description' => 'Soporte de lectura directa para estructuras de carpetas ISO, BDMV y VIDEO_TS sin necesidad de extracción manual previa.',
                'icon_name' => 'FolderGit2',
                'author' => 'Community',
                'is_installed' => false,
                'is_enabled' => false,
                'default_settings' => [
                    'auto_detect_iso' => true,
                    'skip_menus' => true,
                    'preferred_audio_lang' => 'es',
                ],
            ],

            // --- RED, INTEGRACIONES Y SISTEMA ---
            [
                'slug' => 'webhooks',
                'name' => 'Webhooks',
                'category' => 'network',
                'version' => '2.3.0',
                'description' => 'Envío de notificaciones HTTP POST en tiempo real hacia Discord, Telegram, Slack o flujos automatizados de N8N/Zapier ante eventos del servidor.',
                'icon_name' => 'Webhook',
                'author' => 'FlixHN Core Team',
                'is_installed' => true,
                'is_enabled' => true,
                'default_settings' => [
                    'webhook_url' => 'https://discord.com/api/webhooks/123456/abcdef',
                    'events' => ['playback_start', 'new_media_scanned', 'device_connected'],
                    'format' => 'discord_embed',
                    'secret_token' => '',
                ],
            ],
            [
                'slug' => 'port-mapper',
                'name' => 'Port Mapper (UPnP / NAT-PMP)',
                'category' => 'network',
                'version' => '1.1.5',
                'description' => 'Apertura automática de puertos en el enrutador de borde o gateway para facilitar acceso On-Net seguro de los abonados.',
                'icon_name' => 'Network',
                'author' => 'FlixHN Core Team',
                'is_installed' => false,
                'is_enabled' => false,
                'default_settings' => [
                    'protocol' => 'UPnP',
                    'external_port' => 80,
                    'lease_duration_minutes' => 60,
                    'auto_renew' => true,
                ],
            ],
            [
                'slug' => 'dlna-server',
                'name' => 'DLNA Media Server',
                'category' => 'network',
                'version' => '1.5.0',
                'description' => 'Servidor DLNA / UPnP AV para streaming directo hacia televisores antiguos, consolas PlayStation/Xbox y receptores de audio en LAN.',
                'icon_name' => 'Cast',
                'author' => 'FlixHN Core Team',
                'is_installed' => false,
                'is_enabled' => false,
                'default_settings' => [
                    'server_name' => 'FlixHN DLNA Node',
                    'client_discovery' => true,
                    'enable_transcoding' => true,
                ],
            ],
            [
                'slug' => 'backup-restore',
                'name' => 'Backup & Restore',
                'category' => 'system',
                'version' => '2.0.1',
                'description' => 'Programación y ejecución automatizada de volcados de base de datos MySQL, configuraciones de transcodificación y claves de seguridad.',
                'icon_name' => 'Archive',
                'author' => 'FlixHN Core Team',
                'is_installed' => true,
                'is_enabled' => true,
                'default_settings' => [
                    'backup_path' => '/storage/backups',
                    'retention_copies' => 7,
                    'compression' => 'gzip',
                    'include_database' => true,
                    'include_settings' => true,
                ],
            ],
            [
                'slug' => 'advanced-statistics',
                'name' => 'Advanced Statistics',
                'category' => 'system',
                'version' => '1.7.4',
                'description' => 'Métricas analíticas detalladas de horas de visualización por abonado, tráfico de red consumido, códecs más usados y picos de audiencia.',
                'icon_name' => 'BarChart3',
                'author' => 'FlixHN Core Team',
                'is_installed' => true,
                'is_enabled' => true,
                'default_settings' => [
                    'retention_days' => 90,
                    'telemetry_sampling_seconds' => 30,
                    'track_bandwidth' => true,
                    'anonymize_ips' => false,
                ],
            ],
        ];
    }
}
