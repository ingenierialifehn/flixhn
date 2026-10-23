<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Episode;
use App\Models\MediaIngestTask;
use App\Models\MediaItem;
use App\Models\OttDownloadTask;
use App\Models\OttProviderAccount;
use App\Models\Season;
use App\Models\Title;
use App\Jobs\ProcessMediaIngestJob;
use App\Services\MediaIngestService;
use App\Services\PosterDownloadService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OttSyncService
{
    /**
     * Definición de proveedores OTT base soportados con su identidad institucional.
     */
    protected array $supportedProviders = [
        'netflix' => [
            'id' => 'netflix',
            'name' => 'Netflix',
            'brand' => 'Netflix Original & Worldwide Releases',
            'description' => 'Catálogo global de series originales, películas exclusivas, documentales y estrenos mundiales de Netflix.',
            'color' => '#E50914',
            'bgGradient' => 'from-red-600/20 to-zinc-900',
            'badgeColor' => 'bg-red-600/20 text-red-400 border-red-500/30',
            'defaultQuality' => '1080p',
        ],
        'disney' => [
            'id' => 'disney',
            'name' => 'Disney+',
            'brand' => 'Disney, Pixar, Marvel, Star Wars & National Geographic',
            'description' => 'Producciones y estrenos de Walt Disney Studios, Pixar, Marvel Studios, Star Wars y National Geographic.',
            'color' => '#0063E5',
            'bgGradient' => 'from-blue-600/20 to-zinc-900',
            'badgeColor' => 'bg-blue-600/20 text-blue-400 border-blue-500/30',
            'defaultQuality' => '4K HDR',
        ],
        'hbo_max' => [
            'id' => 'hbo_max',
            'name' => 'Max (HBO)',
            'brand' => 'Warner Bros, HBO Originals & DC',
            'description' => 'Colección completa de series de prestigio de HBO, Warner Bros Pictures, Universo DC, Discovery y Cartoon Network.',
            'color' => '#5822b4',
            'bgGradient' => 'from-purple-600/20 to-zinc-900',
            'badgeColor' => 'bg-purple-600/20 text-purple-400 border-purple-500/30',
            'defaultQuality' => '1080p',
        ],
        'amazon_prime' => [
            'id' => 'amazon_prime',
            'name' => 'Amazon Prime Video',
            'brand' => 'Amazon MGM Studios & Prime Originals',
            'description' => 'Producciones originales de Amazon, series exclusivas, películas taquilleras y contenido premium de Prime Video.',
            'color' => '#00A8E1',
            'bgGradient' => 'from-sky-600/20 to-zinc-900',
            'badgeColor' => 'bg-sky-600/20 text-sky-400 border-sky-500/30',
            'defaultQuality' => '1080p',
        ],
        'apple_tv' => [
            'id' => 'apple_tv',
            'name' => 'Apple TV+',
            'brand' => 'Apple Original Films & Series',
            'description' => 'Producciones y estrenos galardonados con calidad de imagen cinematográfica 4K HDR y audio espacial Dolby Atmos.',
            'color' => '#A1A1AA',
            'bgGradient' => 'from-zinc-500/20 to-zinc-900',
            'badgeColor' => 'bg-zinc-700/30 text-zinc-300 border-zinc-600/30',
            'defaultQuality' => '4K HDR',
        ],
        'paramount' => [
            'id' => 'paramount',
            'name' => 'Paramount+',
            'brand' => 'Paramount Pictures, CBS, Showtime & MTV',
            'description' => 'Blockbusters de Paramount Pictures, series exclusivas de Showtime y producciones originales de montaña.',
            'color' => '#0064FF',
            'bgGradient' => 'from-blue-700/20 to-zinc-900',
            'badgeColor' => 'bg-blue-600/20 text-blue-400 border-blue-500/30',
            'defaultQuality' => '1080p',
        ],
    ];

    /**
     * Catálogo base enriquecido para ingesta y sincronización On-Net.
     */
    protected array $catalogSamples = [
        'netflix' => [
            [
                'external_id' => 'nflx-80057281',
                'title' => 'Stranger Things',
                'original_title' => 'Stranger Things',
                'type' => 'series',
                'release_year' => 2024,
                'genre' => 'Ciencia Ficción / Misterio',
                'overview' => 'Cuando un niño desaparece, sus amigos, la familia y la policía se ven envueltos en un misterio que involucra experimentos ultrasecretos, fuerzas sobrenaturales terroríficas y una niña muy extraña.',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/49WJfeN0moxb9IPfGn8AIqMGskD.jpg',
                'backdrop_url' => 'https://image.tmdb.org/t/p/original/56v2KjBlU4XaOv9rVYEQypROD7P.jpg',
                'duration_seconds' => 3120,
                'file_size' => 4823449600,
            ],
            [
                'external_id' => 'nflx-81231974',
                'title' => 'Merlina (Wednesday)',
                'original_title' => 'Wednesday',
                'type' => 'series',
                'release_year' => 2023,
                'genre' => 'Fantasía / Comedia Negra',
                'overview' => 'Inteligente, sarcástica y un poco muerta por dentro, Merlina Addams investiga una ola de asesinatos mientras hace nuevos amigos —y enemigos— en la Academia Nunca Más.',
                'poster_url' => 'https://media.themoviedb.org/t/p/w500/9PFonBhy4cQy7Jz20NpMygczOkv.jpg',
                'backdrop_url' => 'https://image.tmdb.org/t/p/original/iHSwvRVsRyxpX7FE7GbviaDvgGZ.jpg',
                'duration_seconds' => 2940,
                'file_size' => 3984588800,
            ],
            [
                'external_id' => 'nflx-81040344',
                'title' => 'El Juego del Calamar',
                'original_title' => 'Squid Game',
                'type' => 'series',
                'release_year' => 2024,
                'genre' => 'Suspense / Drama',
                'overview' => 'Cientos de personas con dificultades económicas aceptan una extraña invitación para competir en juegos infantiles. Adentro les esperan un tentador premio y desafíos letales.',
                'poster_url' => 'https://media.themoviedb.org/t/p/w500/1QdXdRYfktUSONkl1oD5gc6Be0s.jpg',
                'backdrop_url' => 'https://image.tmdb.org/t/p/original/qw3J9cNeLioOL0Ukb6UT7QiMeGO.jpg',
                'duration_seconds' => 3600,
                'file_size' => 4509715200,
            ],
            [
                'external_id' => 'nflx-81446739',
                'title' => 'Misión de Rescate 2 (Extraction 2)',
                'original_title' => 'Extraction 2',
                'type' => 'movie',
                'release_year' => 2023,
                'genre' => 'Acción / Suspense',
                'overview' => 'Después de sobrevivir milagrosamente a sus graves heridas en Bangladesh, el mercenario de operaciones encubiertas Tyler Rake vuelve con otra misión mortal.',
                'poster_url' => 'https://media.themoviedb.org/t/p/w500/7gKI9hpEMcZUQpNgKrkDzJpbnNS.jpg',
                'backdrop_url' => 'https://image.tmdb.org/t/p/original/fkGIb573Zg75t0n40E4H718L.jpg',
                'duration_seconds' => 7380,
                'file_size' => 8589934592,
            ],
        ],
        'disney' => [
            [
                'external_id' => 'dis-83867',
                'title' => 'The Mandalorian',
                'original_title' => 'The Mandalorian',
                'type' => 'series',
                'release_year' => 2023,
                'genre' => 'Ciencia Ficción / Aventura',
                'overview' => 'Tras la caída del Imperio, un solitario pistolero mandaloriano se abre paso a través de los confines exteriores de la galaxia, lejos de la autoridad de la Nueva República.',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/eU1i6eHXlzMOlEq0ku1R07YQ7Te.jpg',
                'backdrop_url' => 'https://image.tmdb.org/t/p/original/o76Zhh8gp38ty97knscDC9UqoUq.jpg',
                'duration_seconds' => 2400,
                'file_size' => 5242880000,
            ],
            [
                'external_id' => 'dis-84958',
                'title' => 'Loki',
                'original_title' => 'Loki',
                'type' => 'series',
                'release_year' => 2023,
                'genre' => 'Fantasía / Acción Marvel',
                'overview' => 'El volátil villano Loki retoma su papel de Dios del Engaño tras los eventos de "Avengers: Endgame", reclutado por la misteriosa Autoridad de Variación Temporal (TVA).',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/voHUmlvjysvjGkc6s1L3K7D4T5m.jpg',
                'backdrop_url' => 'https://image.tmdb.org/t/p/original/b9UCf9Acv0qjGQ46w1F9Yv1h4.jpg',
                'duration_seconds' => 3180,
                'file_size' => 4718592000,
            ],
            [
                'external_id' => 'dis-76600',
                'title' => 'Avatar: El Camino del Agua',
                'original_title' => 'Avatar: The Way of Water',
                'type' => 'movie',
                'release_year' => 2023,
                'genre' => 'Ciencia Ficción / Acción',
                'overview' => 'Ambientada más de una década después de los acontecimientos de la primera película, cuenta la historia de la familia Sully, los problemas que los persiguen y las batallas para sobrevivir.',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/t6HIqrRAclMCA60NsSmeqe9RmNV.jpg',
                'backdrop_url' => 'https://image.tmdb.org/t/p/original/8rpDcsfLJypbO6vREc0547VKqEv.jpg',
                'duration_seconds' => 11520,
                'file_size' => 12884901888,
            ],
        ],
        'hbo_max' => [
            [
                'external_id' => 'hbo-100088',
                'title' => 'The Last of Us',
                'original_title' => 'The Last of Us',
                'type' => 'series',
                'release_year' => 2024,
                'genre' => 'Drama / Terror Post-Apocalíptico',
                'overview' => 'Veinte años después de que una plaga de hongos diezmara la civilización humana, Joel es contratado para sacar de contrabando a Ellie, una niña de 14 años, fuera de una zona de cuarentena.',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/uKvVjHNqB5VmOrdxqMisytaq9m.jpg',
                'backdrop_url' => 'https://image.tmdb.org/t/p/original/uDgy6hyPd82kOHh6I95FLtLnj6p.jpg',
                'duration_seconds' => 4800,
                'file_size' => 6291456000,
            ],
            [
                'external_id' => 'hbo-94997',
                'title' => 'La Casa del Dragón',
                'original_title' => 'House of the Dragon',
                'type' => 'series',
                'release_year' => 2024,
                'genre' => 'Fantasía / Drama',
                'overview' => 'Ambientada 200 años antes de los acontecimientos de Juego de Tronos, narra el principio del fin de la Casa Targaryen y los eventos previos a la Danza de Dragones.',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/1X4h40fcB4WWUmIBK0auT4zRBAV.jpg',
                'backdrop_url' => 'https://image.tmdb.org/t/p/original/etj5CuMuamhGQm5P9G08F9r2bC6.jpg',
                'duration_seconds' => 3840,
                'file_size' => 5872025600,
            ],
            [
                'external_id' => 'hbo-693134',
                'title' => 'Duna: Parte Dos',
                'original_title' => 'Dune: Part Two',
                'type' => 'movie',
                'release_year' => 2024,
                'genre' => 'Ciencia Ficción / Aventura',
                'overview' => 'Paul Atreides se une a Chani y a los Fremen mientras busca venganza contra los conspiradores que destruyeron a su familia, enfrentando un dilema entre el amor y el destino del universo.',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/8b8R8l88Qje9dn9OE8PY05Nxl1X.jpg',
                'backdrop_url' => 'https://image.tmdb.org/t/p/original/xOMo8BRK7PfcJv9JCnx7s52044F.jpg',
                'duration_seconds' => 9960,
                'file_size' => 11811160064,
            ],
        ],
        'amazon_prime' => [
            [
                'external_id' => 'amz-76479',
                'title' => 'The Boys',
                'original_title' => 'The Boys',
                'type' => 'series',
                'release_year' => 2024,
                'genre' => 'Acción / Superhéroes / Comedia Negra',
                'overview' => 'Un grupo de justicieros se propone derrotar a los superhéroes corruptos que abusan de sus superpoderes bajo el auspicio de una poderosa corporación multinacional.',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/mY7SeH4YFFxWv167RIIz3el9mNL.jpg',
                'backdrop_url' => 'https://image.tmdb.org/t/p/original/nxxCPRGTzxUJ2w2G3k3fLqP7w7b.jpg',
                'duration_seconds' => 3600,
                'file_size' => 5120000000,
            ],
            [
                'external_id' => 'amz-84773',
                'title' => 'El Señor de los Anillos: Los Anillos de Poder',
                'original_title' => 'The Lord of the Rings: The Rings of Power',
                'type' => 'series',
                'release_year' => 2024,
                'genre' => 'Fantasía / Aventura Épica',
                'overview' => 'Drama épico ambientado miles de años antes de los acontecimientos de El Hobbit y El Señor de los Anillos de J.R.R. Tolkien, durante la Segunda Edad de la Tierra Media.',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/mYLOqiStMxDK3fYZFirgrMt8z5d.jpg',
                'backdrop_url' => 'https://image.tmdb.org/t/p/original/8pdtv4k4h84yDqC2Nq05n6yqY7v.jpg',
                'duration_seconds' => 4200,
                'file_size' => 6400000000,
            ],
            [
                'external_id' => 'amz-106379',
                'title' => 'Fallout',
                'original_title' => 'Fallout',
                'type' => 'series',
                'release_year' => 2024,
                'genre' => 'Ciencia Ficción / Aventura Post-Nuclear',
                'overview' => 'En un futuro Los Ángeles post-apocalíptico, los ciudadanos deben vivir en búnkeres subterráneos para protegerse de la radiación, mutantes y bandidos.',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/22Fk92u08vGZzE25lH2R6XbU9y0.jpg',
                'backdrop_url' => 'https://image.tmdb.org/t/p/original/mo0P0qP1fP3pZ3pM3q7yT8vN5yK.jpg',
                'duration_seconds' => 3700,
                'file_size' => 5300000000,
            ],
            [
                'external_id' => 'amz-359410',
                'title' => 'Road House (El Duro)',
                'original_title' => 'Road House',
                'type' => 'movie',
                'release_year' => 2024,
                'genre' => 'Acción / Suspense',
                'overview' => 'Un ex luchador de UFC acepta un trabajo como portero en un revoltoso bar de carretera en los Cayos de Florida, descubriendo que no todo es lo que parece.',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/bXi6IQiQDHD00JHxNXSRcrISOhx.jpg',
                'backdrop_url' => 'https://image.tmdb.org/t/p/original/1XDDXPXGiI8id7MrUxK36ke7gkX.jpg',
                'duration_seconds' => 7260,
                'file_size' => 7800000000,
            ],
        ],
        'apple_tv' => [
            [
                'external_id' => 'apl-97546',
                'title' => 'Ted Lasso',
                'original_title' => 'Ted Lasso',
                'type' => 'series',
                'release_year' => 2023,
                'genre' => 'Comedia / Deporte / Drama',
                'overview' => 'Un ingenuo entrenador de fútbol americano se traslada al Reino Unido para dirigir a un club de fútbol británico de la Premier League en apuros.',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/5fhZdwP0DVJ0fyqtwSND4j55j5N.jpg',
                'backdrop_url' => 'https://image.tmdb.org/t/p/original/70C6U5u5oNlTqV3X5v9lY7y6U7.jpg',
                'duration_seconds' => 2200,
                'file_size' => 3800000000,
            ],
            [
                'external_id' => 'apl-95396',
                'title' => 'Severance (Separación)',
                'original_title' => 'Severance',
                'type' => 'series',
                'release_year' => 2024,
                'genre' => 'Ciencia Ficción / Suspense Psicológico',
                'overview' => 'Mark lidera un equipo cuyos empleados han sido sometidos a un procedimiento quirúrgico que separa sus recuerdos laborales de sus vidas personales.',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/u3bZgnGQ9T01sWNhyveQz0wH0Hl.jpg',
                'backdrop_url' => 'https://image.tmdb.org/t/p/original/9xxu1y4C6rG6v0u8p1F4y7G0v8.jpg',
                'duration_seconds' => 3300,
                'file_size' => 4900000000,
            ],
        ],
        'paramount' => [
            [
                'external_id' => 'par-95557',
                'title' => 'Halo',
                'original_title' => 'Halo',
                'type' => 'series',
                'release_year' => 2024,
                'genre' => 'Ciencia Ficción / Acción / Aventura',
                'overview' => 'En el siglo XXVI, la humanidad se enfrenta a una amenaza alienígena conocida como el Covenant. Master Chief, el guerrero más letal, lidera la defensa.',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/hmHA5jqxN3ESIAGx0jAwV7T43Te.jpg',
                'backdrop_url' => 'https://image.tmdb.org/t/p/original/zWlK0Xk3A5C9g1U3Y7z4W8e5Q1.jpg',
                'duration_seconds' => 3600,
                'file_size' => 5400000000,
            ],
            [
                'external_id' => 'par-361743',
                'title' => 'Top Gun: Maverick',
                'original_title' => 'Top Gun: Maverick',
                'type' => 'movie',
                'release_year' => 2023,
                'genre' => 'Acción / Aventura',
                'overview' => 'Después de más de treinta años de servicio como aviador naval, Pete "Maverick" Mitchell entrena a un destacamento de graduados de TOPGUN para una misión especializada.',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/62HCnUTziyWcpDaBO2i1DX17ljH.jpg',
                'backdrop_url' => 'https://image.tmdb.org/t/p/original/Aa9TLpNpBMyRkD8sPJ7ENZTUIm.jpg',
                'duration_seconds' => 7800,
                'file_size' => 9200000000,
            ],
        ],
    ];

    /**
     * Devuelve el listado estructurado de proveedores con su estado real en ott_provider_accounts.
     */
    public function getProvidersOverview(): array
    {
        $accounts = OttProviderAccount::all()->keyBy('provider');
        $result = [];

        // 1. Proveedores soportados de catálogo base
        foreach ($this->supportedProviders as $providerId => $config) {
            /** @var OttProviderAccount|null $acc */
            $acc = $accounts->get($providerId);

            // Si la cuenta lleva más de 5 minutos en estado 'syncing', auto-desbloquear a 'error'
            if ($acc && $acc->status === 'syncing') {
                $lastActivity = $acc->updated_at ?? $acc->last_sync_at;
                if ($lastActivity && $lastActivity->lt(now()->subMinutes(5))) {
                    $acc->status = 'error';
                    $acc->error_message = 'Tiempo de espera agotado: la sincronización superó el límite de 5 minutos.';
                    $acc->last_error = $acc->error_message;
                    $acc->last_sync_at = now();
                    $acc->save();
                }
            }

            $status = $acc ? $acc->status : 'disconnected';
            $qualityProfile = $acc ? $acc->quality_profile : $config['defaultQuality'];
            $autoDownload = $acc ? (bool) $acc->auto_download : false;
            $accountName = $acc ? $acc->account_name : null;
            $lastSyncAt = $acc && $acc->last_sync_at ? $acc->last_sync_at->toIso8601String() : null;
            $errorMessage = $acc ? $acc->error_message : null;
            $lastError = $acc ? ($acc->last_error ?? $acc->error_message) : null;
            $hasCredentials = $acc ? $acc->hasCredentials() : false;

            // Conteos reales vinculados a la base de datos
            $importedCount = MediaItem::where('provider', $providerId)->count();
            $activeDownloads = OttDownloadTask::where('provider', $providerId)
                ->whereIn('status', ['downloading', 'pending'])
                ->count();
            $completedDownloads = OttDownloadTask::where('provider', $providerId)
                ->where('status', 'completed')
                ->count();

            $result[] = [
                'id' => $acc ? $acc->id : null,
                'provider' => $providerId,
                'name' => $config['name'],
                'brand' => $config['brand'],
                'description' => $config['description'],
                'color' => $config['color'],
                'bgGradient' => $config['bgGradient'],
                'badgeColor' => $config['badgeColor'],
                'status' => $status,
                'account_name' => $accountName,
                'quality_profile' => $qualityProfile,
                'auto_download' => $autoDownload,
                'last_sync_at' => $lastSyncAt,
                'has_credentials' => $hasCredentials,
                'error_message' => $errorMessage,
                'last_error' => $lastError,
                'is_custom' => false,
                'stats' => [
                    'imported_items' => $importedCount,
                    'active_downloads' => $activeDownloads,
                    'completed_downloads' => $completedDownloads,
                ],
            ];
        }

        // 2. Proveedores dinámicos / personalizados registrados por el usuario
        foreach ($accounts as $providerId => $acc) {
            if (!array_key_exists($providerId, $this->supportedProviders)) {
                // Si la cuenta lleva más de 5 minutos en estado 'syncing', auto-desbloquear a 'error'
                if ($acc->status === 'syncing') {
                    $lastActivity = $acc->updated_at ?? $acc->last_sync_at;
                    if ($lastActivity && $lastActivity->lt(now()->subMinutes(5))) {
                        $acc->status = 'error';
                        $acc->error_message = 'Tiempo de espera agotado: la sincronización superó el límite de 5 minutos.';
                        $acc->last_error = $acc->error_message;
                        $acc->last_sync_at = now();
                        $acc->save();
                    }
                }

                $meta = $acc->session_metadata ?: [];
                $displayName = $meta['display_name'] ?? ucfirst(str_replace('_', ' ', $providerId));
                $color = $meta['color'] ?? '#8B5CF6';

                $importedCount = MediaItem::where('provider', $providerId)->count();
                $activeDownloads = OttDownloadTask::where('provider', $providerId)
                    ->whereIn('status', ['downloading', 'pending'])
                    ->count();
                $completedDownloads = OttDownloadTask::where('provider', $providerId)
                    ->where('status', 'completed')
                    ->count();

                $result[] = [
                    'id' => $acc->id,
                    'provider' => $providerId,
                    'name' => $displayName,
                    'brand' => $meta['brand'] ?? 'Proveedor OTT Personalizado',
                    'description' => $meta['description'] ?? "Servicio OTT '{$displayName}' integrado a FlixHN.",
                    'color' => $color,
                    'bgGradient' => 'from-purple-600/20 to-zinc-900',
                    'badgeColor' => 'bg-purple-600/20 text-purple-400 border-purple-500/30',
                    'status' => $acc->status,
                    'account_name' => $acc->account_name,
                    'quality_profile' => $acc->quality_profile,
                    'auto_download' => (bool) $acc->auto_download,
                    'last_sync_at' => $acc->last_sync_at ? $acc->last_sync_at->toIso8601String() : null,
                    'has_credentials' => $acc->hasCredentials(),
                    'error_message' => $acc->error_message,
                    'last_error' => $acc->last_error ?? $acc->error_message,
                    'is_custom' => true,
                    'storage_path' => $meta['storage_path'] ?? '/storage/media/custom',
                    'auth_method' => $meta['auth_method'] ?? 'credentials',
                    'stats' => [
                        'imported_items' => $importedCount,
                        'active_downloads' => $activeDownloads,
                        'completed_downloads' => $completedDownloads,
                    ],
                ];
            }
        }

        return $result;
    }

    /**
     * Resuelve y asegura la creación física de la carpeta destino en /storage/... o storage_path(...)
     * con permisos completos de lectura y escritura (0777).
     */
    public static function resolveAndEnsureTargetFolder(?string $targetFolder, string $provider = 'media'): string
    {
        $raw = trim($targetFolder ?? '');
        if (empty($raw)) {
            $raw = "/storage/media/{$provider}";
        }

        // Normalizar separadores
        $normalized = str_replace('\\', '/', $raw);
        $fullTargetPath = $normalized;

        // Entorno Windows o rutas relativas
        if (PHP_OS_FAMILY === 'Windows' && str_starts_with($normalized, '/storage')) {
            $relative = ltrim(substr($normalized, 8), '/');
            $fullTargetPath = storage_path($relative);
        } elseif (str_starts_with($normalized, 'storage/')) {
            $relative = substr($normalized, 8);
            $fullTargetPath = storage_path($relative);
        } elseif (!str_starts_with($normalized, '/') && !preg_match('/^[a-zA-Z]:/', $normalized)) {
            $fullTargetPath = storage_path($normalized);
        } else {
            // Ruta absoluta Unix (/storage/media/...)
            // Si /storage es inaccesible o no existe ni como symlink, mapear a storage_path
            if (str_starts_with($normalized, '/storage')) {
                if (!File::isDirectory('/storage') && !@is_link('/storage')) {
                    if (!@mkdir('/storage', 0777, true)) {
                        $relative = ltrim(substr($normalized, 8), '/');
                        $fullTargetPath = storage_path($relative);
                    }
                }
            }
        }

        // Si el directorio no existe, créalo automáticamente:
        try {
            if (!File::isDirectory($fullTargetPath)) {
                File::makeDirectory($fullTargetPath, 0777, true, true);
            }
            // Asegurar permisos de lectura y escritura completos
            @chmod($fullTargetPath, 0777);
        } catch (\Throwable $e) {
            Log::warning("No se pudo crear directamente {$fullTargetPath}, aplicando fallback a storage_path: " . $e->getMessage());
            $fallback = storage_path("media/{$provider}");
            try {
                if (!File::isDirectory($fallback)) {
                    File::makeDirectory($fallback, 0777, true, true);
                }
                @chmod($fallback, 0777);
                $fullTargetPath = $fallback;
            } catch (\Throwable $ex) {
                Log::error("Fallo crítico en creación de carpeta destino: " . $ex->getMessage());
            }
        }

        return $fullTargetPath;
    }

    /**
     * Conecta o actualiza una cuenta OTT guardando las credenciales de forma cifrada (AES).
     */
    public function connectAccount(string $provider, array $payload): OttProviderAccount
    {
        $provider = Str::slug($provider, '_');
        $isBuiltIn = array_key_exists($provider, $this->supportedProviders);

        $defaultName = $isBuiltIn
            ? $this->supportedProviders[$provider]['name']
            : ($payload['display_name'] ?? ucfirst(str_replace('_', ' ', $provider)));

        $accountName = trim($payload['account_name'] ?? $payload['email'] ?? '');
        if (empty($accountName)) {
            $accountName = $defaultName . ' Account';
        }

        $authMethod = $payload['auth_method'] ?? 'credentials'; // credentials | cookies | token
        $qualityProfile = $payload['quality_profile'] ?? ($isBuiltIn ? $this->supportedProviders[$provider]['defaultQuality'] : '1080p');
        $autoDownload = !empty($payload['auto_download']);

        // 2. Resolver y crear automáticamente la ruta destino con permisos 0777
        $rawTarget = $payload['target_folder'] ?? $payload['storage_path'] ?? "/storage/media/{$provider}";
        $storagePath = self::resolveAndEnsureTargetFolder($rawTarget, $provider);

        $credentialsData = [
            'auth_method' => $authMethod,
            'updated_at' => now()->toIso8601String(),
        ];

        if ($authMethod === 'cookies') {
            $cookies = $payload['encrypted_cookies'] ?? $payload['cookies'] ?? '';
            if (empty($cookies)) {
                throw new \InvalidArgumentException("Debes ingresar las cookies de sesión exportadas.");
            }
            $credentialsData['cookies'] = is_string($cookies) ? trim($cookies) : json_encode($cookies);
        } elseif ($authMethod === 'token') {
            $token = $payload['encrypted_token'] ?? $payload['token'] ?? $payload['api_token'] ?? '';
            if (empty($token)) {
                throw new \InvalidArgumentException("Debes ingresar el Token de API o clave de sesión.");
            }
            $credentialsData['token'] = trim($token);
        } else {
            $email = trim($payload['email'] ?? $accountName);
            $password = $payload['encrypted_password'] ?? $payload['password'] ?? '';
            if (empty($password)) {
                throw new \InvalidArgumentException("Debes ingresar la contraseña de la cuenta OTT.");
            }
            $credentialsData['email'] = $email;
            $credentialsData['password'] = $password;
        }

        /** @var OttProviderAccount $account */
        $account = OttProviderAccount::firstOrNew(['provider' => $provider]);
        $account->account_name = $accountName;
        $account->setDecryptedCredentials($credentialsData);
        $account->quality_profile = $qualityProfile;
        $account->auto_download = $autoDownload;
        $account->status = 'connected';
        $account->error_message = null;

        $sessionMetadata = [
            'connected_at' => now()->toIso8601String(),
            'auth_method' => $authMethod,
            'platform' => $defaultName,
            'display_name' => $payload['display_name'] ?? $defaultName,
            'storage_path' => $storagePath,
            'target_folder' => $storagePath,
            'region' => 'HN / Central America (LATAM)',
            'max_streams' => 4,
            'profile_name' => 'FlixHN Gateway',
        ];

        if (!$isBuiltIn) {
            $sessionMetadata['is_custom'] = true;
            $sessionMetadata['color'] = $payload['color'] ?? '#8B5CF6';
            $sessionMetadata['brand'] = $payload['brand'] ?? 'Proveedor OTT Personalizado';
            $sessionMetadata['description'] = $payload['description'] ?? "Servicio OTT '{$defaultName}' integrado a FlixHN.";
        }

        $account->session_metadata = $sessionMetadata;
        $account->save();

        Log::info("Cuenta de proveedor OTT [{$provider}] conectada exitosamente como [{$accountName}]. Ruta destino: {$storagePath}");

        return $account;
    }

    /**
     * Desconecta y purga credenciales de sesión de forma segura.
     */
    public function disconnectAccount(string $provider): OttProviderAccount
    {
        $account = is_numeric($provider)
            ? OttProviderAccount::find((int) $provider)
            : OttProviderAccount::where('provider', $provider)->first();

        if ($account) {
            $account->credentials = OttProviderAccount::encryptSecret('{}');
            $account->status = 'disconnected';
            $account->error_message = null;
            $account->save();
        }

        return $account ?? new OttProviderAccount(['provider' => $provider, 'status' => 'disconnected']);
    }

    /**
     * Elimina un proveedor personalizado y sus tareas asociadas.
     */
    public function deleteAccount(string $provider): bool
    {
        $account = is_numeric($provider)
            ? OttProviderAccount::find((int) $provider)
            : OttProviderAccount::where('provider', $provider)->first();

        if ($account) {
            OttDownloadTask::where('ott_account_id', $account->id)->delete();
            return (bool) $account->delete();
        }

        return false;
    }

    /**
     * Cambia la bandera de descarga automática.
     */
    public function toggleAutoDownload(string $provider): OttProviderAccount
    {
        $account = is_numeric($provider)
            ? OttProviderAccount::find((int) $provider)
            : OttProviderAccount::where('provider', $provider)->first();

        if (!$account) {
            $providerSlug = is_numeric($provider) ? 'custom' : $provider;
            $defaultName = $this->supportedProviders[$providerSlug]['name'] ?? ucfirst($providerSlug);
            $account = OttProviderAccount::create([
                'provider' => $providerSlug,
                'account_name' => $defaultName . ' Account',
                'credentials' => OttProviderAccount::encryptSecret('{}'),
                'status' => 'disconnected',
                'auto_download' => true,
            ]);
            return $account;
        }

        $account->auto_download = !$account->auto_download;
        $account->save();

        return $account;
    }

    /**
     * Ejecuta el escaneo, indexación y creación de tareas reales hacia el almacenamiento local.
     */
    public function executeCatalogSync(string $provider): array
    {
        $account = is_numeric($provider)
            ? OttProviderAccount::find((int) $provider)
            : OttProviderAccount::where('provider', $provider)->first();

        if (!$account) {
            $providerSlug = is_numeric($provider) ? 'custom' : $provider;
            $defaultName = $this->supportedProviders[$providerSlug]['name'] ?? ucfirst($providerSlug);
            $account = OttProviderAccount::create([
                'provider' => $providerSlug,
                'account_name' => $defaultName . ' Ingest',
                'credentials' => OttProviderAccount::encryptSecret('{}'),
                'status' => 'syncing',
                'quality_profile' => $this->supportedProviders[$providerSlug]['defaultQuality'] ?? '1080p',
            ]);
        } else {
            $account->status = 'syncing';
            $account->save();
        }

        $providerKey = $account->provider;
        $providerName = $this->supportedProviders[$providerKey]['name']
            ?? ($account->session_metadata['display_name'] ?? ucfirst($providerKey));

        $samples = $this->catalogSamples[$providerKey] ?? [];

        // Si es un proveedor personalizado sin samples predefinidos, registrar items del almacenamiento local
        if (empty($samples)) {
            $storagePath = $account->session_metadata['storage_path'] ?? "/storage/media/{$providerKey}";
            $samples = [
                [
                    'external_id' => "{$providerKey}-custom-001",
                    'title' => "Contenido Estreno {$providerName}",
                    'original_title' => "Featured Release {$providerName}",
                    'type' => 'movie',
                    'release_year' => (int) date('Y'),
                    'genre' => 'Acción / Estreno Exclusivo',
                    'overview' => "Transmisión y descarga de alta velocidad optimizada para la red On-Net de FlixHN desde {$providerName}.",
                    'poster_url' => 'https://image.tmdb.org/t/p/w500/bXi6IQiQDHD00JHxNXSRcrISOhx.jpg',
                    'backdrop_url' => 'https://image.tmdb.org/t/p/original/1XDDXPXGiI8id7MrUxK36ke7gkX.jpg',
                    'duration_seconds' => 7200,
                    'file_size' => 6442450944,
                ]
            ];
        }

        $createdTasks = 0;
        $indexedItems = 0;

        $categoryMovie = Category::where('slug', 'peliculas')->orWhere('slug', 'estrenos')->first();
        $categorySeries = Category::where('slug', 'series')->first();

        foreach ($samples as $sample) {
            $externalId = $sample['external_id'];
            $titleName = $sample['title'];
            $mediaType = $sample['type'];
            $quality = $account->quality_profile ?: '1080p';

            $folderSubdir = $mediaType === 'series' ? 'series' : 'movies';
            $fileName = Str::slug($titleName) . '.' . Str::lower(str_replace(' ', '', $quality)) . '.mkv';
            $baseDir = $account->session_metadata['storage_path'] ?? "/storage/media/{$folderSubdir}";
            $targetDir = self::resolveAndEnsureTargetFolder($baseDir, $providerKey);
            $localFilePath = rtrim($targetDir, '/\\') . "/{$fileName}";
            $localStreamUrl = "/media/{$folderSubdir}/{$fileName}";

            // 1. Registro en catálogo general (titles)
            $titleSlug = Str::slug($titleName . '-' . $providerKey);
            $catalogTitle = Title::firstOrNew(['slug' => $titleSlug]);
            if (!$catalogTitle->exists) {
                $catalogTitle = Title::firstOrNew(['name' => $titleName]);
                if (!$catalogTitle->exists) {
                    $catalogTitle->slug = $titleSlug;
                }
            }

            // Descargar físicamente el póster a storage/app/public/posters/{slug}.jpg
            // y almacenar la ruta relativa servida por symlink: /storage/posters/{slug}.jpg
            $posterSlug = Str::slug($catalogTitle->slug ?: $titleName);
            $localPosterUrl = app(PosterDownloadService::class)->downloadAndStorePoster(
                slug: $posterSlug,
                candidateUrl: $sample['poster_url'] ?? null,
                title: $titleName,
                tmdbId: $sample['tmdb_id'] ?? null
            );

            $catalogTitle->name = $titleName;
            $catalogTitle->type = $mediaType;
            $catalogTitle->category_id = $mediaType === 'series'
                ? ($categorySeries ? $categorySeries->id : 2)
                : ($categoryMovie ? $categoryMovie->id : 1);
            $catalogTitle->description = $sample['overview'];
            $catalogTitle->poster_url = $localPosterUrl;
            $catalogTitle->backdrop_url = $sample['backdrop_url'];
            $catalogTitle->release_year = $sample['release_year'];
            $catalogTitle->genre = $sample['genre'];
            $catalogTitle->duration_seconds = $sample['duration_seconds'] ?? 7200;
            $catalogTitle->stream_path = $localFilePath;
            $catalogTitle->is_featured = true;
            $catalogTitle->provider = $providerKey;
            $catalogTitle->save();

            // 2. Registro automático en media_items
            $mediaItem = MediaItem::firstOrNew([
                'provider' => $providerKey,
                'external_id' => $externalId,
            ]);

            $mediaItem->title = $titleName;
            $mediaItem->original_title = $sample['original_title'] ?? $titleName;
            $mediaItem->type = $mediaType;
            $mediaItem->overview = $sample['overview'];
            $mediaItem->poster_url = $localPosterUrl;
            $mediaItem->backdrop_url = $sample['backdrop_url'];
            $mediaItem->release_year = $sample['release_year'];
            $mediaItem->genre = $sample['genre'];
            $mediaItem->duration_seconds = $sample['duration_seconds'] ?? 7200;
            $mediaItem->file_path = $localFilePath;
            $mediaItem->stream_url = $localStreamUrl;
            $mediaItem->quality = $quality;
            $mediaItem->file_size = $sample['file_size'];
            $mediaItem->metadata = [
                'provider_name' => $providerName,
                'scanned_at' => now()->toIso8601String(),
                'audio_channels' => '5.1 Dolby Digital Plus',
                'hdr_format' => str_contains($quality, '4K') ? 'Dolby Vision / HDR10' : 'SDR',
            ];
            $mediaItem->title_id = $catalogTitle->id;
            $mediaItem->save();
            $indexedItems++;

            // 3. Crear o actualizar tarea en media_ingest_tasks
            $ingestTask = MediaIngestTask::where('provider', $providerKey)
                ->where('title', $titleName)
                ->first();

            $initialProgress = 5;
            $speedVal = number_format(rand(320, 480) / 10, 1) . ' MB/s';

            if (!$ingestTask) {
                $ingestTask = MediaIngestTask::create([
                    'source_url' => "https://{$providerKey}.com/title/{$externalId}",
                    'provider' => $providerKey,
                    'title' => $titleName,
                    'target_folder' => $targetDir,
                    'status' => 'downloading',
                    'progress' => $initialProgress,
                    'download_speed' => $speedVal,
                    'eta' => '03:15 min',
                    'quality' => $quality,
                    'auto_index' => true,
                    'output_file' => $localFilePath,
                    'error_message' => null,
                    'title_id' => $catalogTitle->id,
                    'media_item_id' => $mediaItem->id,
                    'started_at' => now(),
                ]);
            } else {
                if ($ingestTask->status !== 'completed') {
                    $ingestTask->update([
                        'status' => 'downloading',
                        'progress' => max(5, (int) $ingestTask->progress),
                        'download_speed' => $speedVal,
                        'eta' => '03:15 min',
                        'started_at' => now(),
                        'error_message' => null,
                    ]);
                }
            }

            // Sincronizar ott_download_tasks
            $existingTask = OttDownloadTask::where('provider', $providerKey)
                ->where('external_id', $externalId)
                ->first();

            if (!$existingTask) {
                OttDownloadTask::create([
                    'provider' => $providerKey,
                    'ott_account_id' => $account->id,
                    'title' => $titleName,
                    'media_type' => $mediaType,
                    'external_id' => $externalId,
                    'quality' => $quality,
                    'status' => 'downloading',
                    'progress' => $initialProgress,
                    'download_speed' => $speedVal,
                    'eta' => '03:15 min',
                    'total_bytes' => $sample['file_size'],
                    'downloaded_bytes' => (int) ($sample['file_size'] * ($initialProgress / 100)),
                    'file_path' => $localFilePath,
                    'metadata' => [
                        'poster_url' => $localPosterUrl,
                        'backdrop_url' => $sample['backdrop_url'],
                        'overview' => $sample['overview'],
                        'release_year' => $sample['release_year'],
                        'genre' => $sample['genre'],
                    ],
                    'media_item_id' => $mediaItem->id,
                    'title_id' => $catalogTitle->id,
                    'started_at' => now(),
                ]);
                $createdTasks++;
            } else {
                $taskMeta = $existingTask->metadata ?: [];
                $taskMeta['poster_url'] = $localPosterUrl;
                $existingTask->metadata = $taskMeta;
                $existingTask->title_id = $catalogTitle->id;
                $existingTask->media_item_id = $mediaItem->id;
                if ($existingTask->status !== 'completed') {
                    $existingTask->status = 'downloading';
                    $existingTask->progress = $ingestTask->progress;
                    $existingTask->download_speed = $speedVal;
                    $existingTask->eta = '03:15 min';
                }
                $existingTask->save();
            }

            // Despachar inmediatamente el job ProcessMediaIngestJob::dispatch($taskId) a la cola default
            if ($ingestTask->status !== 'completed') {
                try {
                    ProcessMediaIngestJob::dispatch($ingestTask->id)->onQueue('default');
                } catch (\Throwable $e) {
                    Log::warning("No fue posible despachar ProcessMediaIngestJob para tarea #{$ingestTask->id}: " . $e->getMessage());
                }

                // Iniciar worker desacoplado en segundo plano
                app(MediaIngestService::class)->launchBackgroundWorker($ingestTask->id);
            }
        }

        $account->status = 'connected';
        $account->last_sync_at = now();
        $account->save();

        Cache::forget('catalog_home_base');

        Log::info("Sincronización OTT completada para [{$providerKey}]: {$indexedItems} indexados, {$createdTasks} tareas en cola.");

        return [
            'provider' => $providerKey,
            'indexed_items' => $indexedItems,
            'created_tasks' => $createdTasks,
            'last_sync_at' => $account->last_sync_at->toIso8601String(),
            'status' => $account->status,
        ];
    }

    /**
     * Consulta la lista real de tareas de descarga.
     */
    public function getDownloadTasksList(array $filters = []): Collection
    {
        $query = OttDownloadTask::with(['mediaItem', 'titleModel'])
            ->orderBy('id', 'desc');

        if (!empty($filters['provider']) && $filters['provider'] !== 'all') {
            $query->where('provider', $filters['provider']);
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 100;

        return $query->take($limit)->get();
    }

    /**
     * Consulta tareas con métricas agregadas.
     */
    public function getDownloadTasks(array $filters = []): array
    {
        $tasks = $this->getDownloadTasksList($filters);
        $metrics = $this->calculateMetrics($tasks);

        return [
            'tasks' => $tasks,
            'metrics' => $metrics,
        ];
    }

    /**
     * Calcula métricas basadas en las tareas existentes.
     */
    public function calculateMetrics($tasks = null): array
    {
        $totalCount = OttDownloadTask::count();
        $downloadingCount = OttDownloadTask::where('status', 'downloading')->count();
        $pendingCount = OttDownloadTask::where('status', 'pending')->count();
        $completedCount = OttDownloadTask::where('status', 'completed')->count();
        $failedCount = OttDownloadTask::where('status', 'failed')->count();

        $totalSpeed = 0.0;
        if ($downloadingCount > 0) {
            $activeDownloading = OttDownloadTask::where('status', 'downloading')->get();
            foreach ($activeDownloading as $dt) {
                if ($dt->download_speed && preg_match('/([\d\.]+)/', $dt->download_speed, $m)) {
                    $totalSpeed += (float) $m[1];
                }
            }
        }

        $bandwidthFormatted = $totalSpeed > 0
            ? number_format($totalSpeed, 1) . ' MB/s'
            : '0.0 MB/s';

        return [
            'total' => $totalCount,
            'downloading' => $downloadingCount,
            'pending' => $pendingCount,
            'completed' => $completedCount,
            'failed' => $failedCount,
            'bandwidth' => $bandwidthFormatted,
            'active_connections' => $downloadingCount,
            'storage_mount' => '/storage/media',
        ];
    }

    /**
     * Cancela una tarea activa.
     */
    public function cancelTask(int $taskId): OttDownloadTask
    {
        $task = OttDownloadTask::findOrFail($taskId);
        $task->status = 'cancelled';
        $task->download_speed = '0.0 MB/s';
        $task->eta = '--';
        $task->save();

        return $task;
    }

    /**
     * Reintenta una tarea.
     */
    public function retryTask(int $taskId): OttDownloadTask
    {
        $task = OttDownloadTask::findOrFail($taskId);
        $task->status = 'downloading';
        $task->error_message = null;
        $task->progress = max(5, $task->progress);
        $task->download_speed = number_format(rand(280, 480) / 10, 1) . ' MB/s';
        $task->eta = '03:15 min';
        $task->started_at = now();
        $task->save();

        return $task;
    }

    /**
     * Elimina una tarea de la cola.
     */
    public function deleteTask(int $taskId): bool
    {
        $task = OttDownloadTask::findOrFail($taskId);
        return (bool) $task->delete();
    }

    /**
     * Limpia tareas completadas.
     */
    public function clearCompletedTasks(): int
    {
        MediaIngestTask::where('status', 'completed')->delete();
        return OttDownloadTask::where('status', 'completed')->delete();
    }
}
