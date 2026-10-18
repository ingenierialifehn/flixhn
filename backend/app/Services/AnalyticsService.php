<?php

namespace App\Services;

use App\Models\User;
use App\Models\Title;
use App\Models\Episode;
use App\Models\TvChannel;
use App\Models\DeviceSession;
use App\Models\PlaybackProgress;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AnalyticsService
{
    /**
     * Bitrate promedio para cálculo de ancho de banda On-Net si no está especificado (4.5 Mbps = 562,500 B/s)
     */
    protected const DEFAULT_BITRATE_BPS = 4500000;

    /**
     * Resuelve el rango temporal a objetos Carbon de inicio y fin.
     */
    public function resolveDateRange(string $range = '7d'): array
    {
        $now = Carbon::now();

        switch ($range) {
            case 'today':
                $start = $now->copy()->startOfDay();
                $end = $now->copy();
                $prevStart = $now->copy()->subDay()->startOfDay();
                $prevEnd = $now->copy()->subDay()->endOfDay();
                $label = 'Hoy';
                break;

            case '30d':
                $start = $now->copy()->subDays(29)->startOfDay();
                $end = $now->copy();
                $prevStart = $now->copy()->subDays(59)->startOfDay();
                $prevEnd = $now->copy()->subDays(30)->endOfDay();
                $label = 'Últimos 30 días';
                break;

            case 'month':
            case 'this_month':
                $start = $now->copy()->startOfMonth();
                $end = $now->copy();
                $prevStart = $now->copy()->subMonth()->startOfMonth();
                $prevEnd = $now->copy()->subMonth()->endOfMonth();
                $label = 'Este Mes';
                break;

            case 'all':
            case 'total':
                $start = Carbon::create(2020, 1, 1, 0, 0, 0);
                $end = $now->copy();
                $prevStart = $start->copy();
                $prevEnd = $end->copy();
                $label = 'Histórico Total';
                break;

            case '7d':
            default:
                $start = $now->copy()->subDays(6)->startOfDay();
                $end = $now->copy();
                $prevStart = $now->copy()->subDays(13)->startOfDay();
                $prevEnd = $now->copy()->subDays(7)->endOfDay();
                $label = 'Últimos 7 días';
                $range = '7d';
                break;
        }

        return [$start, $end, $prevStart, $prevEnd, $label, $range];
    }

    /**
     * Obtener resumen de KPIs principales para el período seleccionado.
     */
    public function getOverview(string $range = '7d', bool $fresh = false): array
    {
        [$start, $end, $prevStart, $prevEnd, $label, $normalizedRange] = $this->resolveDateRange($range);
        $cacheKey = "flixhn_stats_overview_{$normalizedRange}";

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 120, function () use ($start, $end, $prevStart, $prevEnd, $label, $normalizedRange) {
            // 1. Usuarios / Contratos ISP
            $totalSubscribers = User::where('role', 'subscriber')->count();
            if ($totalSubscribers === 0) {
                $totalSubscribers = User::count();
            }
            $totalUsers = User::count();

            // Usuarios activos en el rango actual
            $activeUserIdsCurrent = $this->getActiveUserIdsInRange($start, $end);
            $activeSubscribersCount = count($activeUserIdsCurrent);

            // Usuarios activos en rango previo para calcular tendencia
            $activeUserIdsPrev = $this->getActiveUserIdsInRange($prevStart, $prevEnd);
            $activeSubscribersPrev = count($activeUserIdsPrev);
            $usersGrowthPercent = $this->calculateGrowthPercent($activeSubscribersCount, $activeSubscribersPrev);

            // Usuarios concurrentes en línea en este instante
            $onlineDevicesCount = DeviceSession::where('playback_state', '!=', 'stopped')
                ->where('last_heartbeat_at', '>=', now()->subSeconds(35))
                ->count();

            // 2. Horas y Segundos de Reproducción
            $playbackSecondsCurrent = $this->getTotalPlaybackSecondsInRange($start, $end);
            $playbackSecondsPrev = $this->getTotalPlaybackSecondsInRange($prevStart, $prevEnd);
            $playbackGrowthPercent = $this->calculateGrowthPercent($playbackSecondsCurrent, $playbackSecondsPrev);

            $playbackHours = round($playbackSecondsCurrent / 3600, 1);
            $playbackFormatted = $this->formatDuration($playbackSecondsCurrent);

            // 3. Ancho de Banda Estimado On-Net
            $bandwidthBytesCurrent = $this->getEstimatedBandwidthBytes($start, $end);
            $bandwidthBytesPrev = $this->getEstimatedBandwidthBytes($prevStart, $prevEnd);
            $bandwidthGrowthPercent = $this->calculateGrowthPercent($bandwidthBytesCurrent, $bandwidthBytesPrev);

            $bandwidthFormatted = $this->formatBytes($bandwidthBytesCurrent);

            // 4. Catálogo On-Net
            $moviesCount = Title::where('type', 'movie')->count();
            $seriesCount = Title::where('type', 'series')->count();
            $episodesCount = Episode::count();
            $channelsCount = TvChannel::where('is_active', true)->count();
            $totalCatalogItems = $moviesCount + $seriesCount + $channelsCount;

            // 5. Distribución de Consumo por Tipo de Contenido
            $contentDistribution = $this->getContentDistribution($start, $end);

            return [
                'range' => $normalizedRange,
                'range_label' => $label,
                'start_date' => $start->toIso8601String(),
                'end_date' => $end->toIso8601String(),
                'kpis' => [
                    'active_users' => [
                        'current' => $activeSubscribersCount,
                        'total_contracts' => $totalSubscribers,
                        'total_users' => $totalUsers,
                        'online_now' => $onlineDevicesCount,
                        'percentage' => $totalSubscribers > 0 ? round(($activeSubscribersCount / $totalSubscribers) * 100, 1) : 0,
                        'growth_percent' => $usersGrowthPercent,
                        'is_positive' => $usersGrowthPercent >= 0,
                    ],
                    'playback_time' => [
                        'total_seconds' => $playbackSecondsCurrent,
                        'total_hours' => $playbackHours,
                        'formatted' => $playbackFormatted,
                        'growth_percent' => $playbackGrowthPercent,
                        'is_positive' => $playbackGrowthPercent >= 0,
                    ],
                    'bandwidth' => [
                        'total_bytes' => $bandwidthBytesCurrent,
                        'formatted' => $bandwidthFormatted,
                        'growth_percent' => $bandwidthGrowthPercent,
                        'is_positive' => $bandwidthGrowthPercent >= 0,
                        'on_net_efficiency' => '100% Local ISP',
                    ],
                    'catalog' => [
                        'total' => $totalCatalogItems,
                        'movies' => $moviesCount,
                        'series' => $seriesCount,
                        'episodes' => $episodesCount,
                        'channels' => $channelsCount,
                    ],
                ],
                'content_distribution' => $contentDistribution,
                'cached_at' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * Gráfico temporal de reproducciones y concurrencia por día u hora.
     */
    public function getPlaybackChart(string $range = '7d', bool $fresh = false): array
    {
        [$start, $end, , , $label, $normalizedRange] = $this->resolveDateRange($range);
        $cacheKey = "flixhn_stats_chart_{$normalizedRange}";

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 120, function () use ($start, $end, $normalizedRange) {
            $dataPoints = [];

            if ($normalizedRange === 'today') {
                // 24 intervalos horarios
                for ($h = 0; $h < 24; $h++) {
                    $slotStart = $start->copy()->hour($h)->minute(0)->second(0);
                    $slotEnd = $start->copy()->hour($h)->minute(59)->second(59);

                    if ($slotStart > now()) {
                        // Horas futuras del día
                        $dataPoints[] = [
                            'label' => sprintf('%02d:00', $h),
                            'date' => $slotStart->format('Y-m-d H:i'),
                            'plays' => 0,
                            'concurrency' => 0,
                            'duration_minutes' => 0,
                            'is_future' => true,
                        ];
                        continue;
                    }

                    $stats = $this->getSlotStats($slotStart, $slotEnd);
                    $dataPoints[] = [
                        'label' => sprintf('%02d:00', $h),
                        'date' => $slotStart->format('Y-m-d H:i'),
                        'plays' => $stats['plays'],
                        'concurrency' => $stats['concurrency'],
                        'duration_minutes' => round($stats['seconds'] / 60, 1),
                        'is_future' => false,
                    ];
                }
            } else {
                // Intervalos diarios (7d, 30d, month, all)
                $currentDay = $start->copy()->startOfDay();
                $endDay = $end->copy()->endOfDay();

                $dayNames = [
                    0 => 'Dom', 1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb'
                ];

                while ($currentDay <= $endDay) {
                    $dayStart = $currentDay->copy()->startOfDay();
                    $dayEnd = $currentDay->copy()->endOfDay();

                    $stats = $this->getSlotStats($dayStart, $dayEnd);

                    $shortDay = $dayNames[$currentDay->dayOfWeek] ?? '';
                    $labelFormatted = $normalizedRange === '7d' 
                        ? "{$shortDay} " . $currentDay->format('d')
                        : $currentDay->format('d/m');

                    $dataPoints[] = [
                        'label' => $labelFormatted,
                        'date' => $currentDay->format('Y-m-d'),
                        'full_date' => $currentDay->format('d M Y'),
                        'plays' => $stats['plays'],
                        'concurrency' => $stats['concurrency'],
                        'duration_hours' => round($stats['seconds'] / 3600, 1),
                        'duration_minutes' => round($stats['seconds'] / 60, 1),
                    ];

                    $currentDay->addDay();
                }
            }

            return [
                'range' => $normalizedRange,
                'points' => $dataPoints,
            ];
        });
    }

    /**
     * Top 10 Contenido Más Visto (Películas, Series y Canales en Vivo).
     */
    public function getTopContent(string $range = '7d', int $limit = 10, bool $fresh = false): array
    {
        [$start, $end, , , , $normalizedRange] = $this->resolveDateRange($range);
        $cacheKey = "flixhn_stats_top_content_{$normalizedRange}_{$limit}";

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 120, function () use ($start, $end, $limit) {
            $items = [];

            // 1. Títulos desde playback_progress
            $progressRecords = DB::table('playback_progress')
                ->select(
                    'title_id',
                    DB::raw('COUNT(*) as play_count'),
                    DB::raw('SUM(current_seconds) as total_seconds')
                )
                ->where('last_watched_at', '>=', $start)
                ->where('last_watched_at', '<=', $end)
                ->groupBy('title_id')
                ->orderByDesc('total_seconds')
                ->limit($limit * 2)
                ->get();

            $titleIds = $progressRecords->pluck('title_id')->filter()->unique();
            $titlesMap = Title::whereIn('id', $titleIds)->get()->keyBy('id');

            foreach ($progressRecords as $rec) {
                $t = $titlesMap->get($rec->title_id);
                if (!$t) continue;

                $typeLabel = $t->type === 'series' ? 'Serie' : 'Película';
                $items[] = [
                    'id' => $t->id,
                    'title' => $t->name ?: ($t->title ?: 'Sin título'),
                    'type' => $t->type ?: 'movie',
                    'type_label' => $typeLabel,
                    'poster_url' => $t->poster_url,
                    'backdrop_url' => $t->backdrop_url,
                    'play_count' => (int) $rec->play_count,
                    'duration_seconds' => (int) $rec->total_seconds,
                    'duration_formatted' => $this->formatDuration((int) $rec->total_seconds),
                    'rating' => $t->community_rating ? (float) $t->community_rating : null,
                    'category' => $t->genre ?: 'General',
                ];
            }

            // 2. Sesiones desde device_sessions
            $sessions = DeviceSession::where(function ($q) use ($start, $end) {
                $q->whereBetween('last_heartbeat_at', [$start, $end])
                  ->orWhereBetween('session_started_at', [$start, $end])
                  ->orWhereBetween('updated_at', [$start, $end]);
            })
            ->whereNotNull('current_media_title')
            ->where('current_media_title', '!=', '')
            ->get();

            $sessionsGrouped = $sessions->groupBy('current_media_title');
            foreach ($sessionsGrouped as $mediaTitle => $group) {
                $first = $group->first();
                $type = $first->current_media_type ?: 'movie';

                $sessionSeconds = $group->sum(function ($s) {
                    if ($s->playback_position_seconds > 0) {
                        return $s->playback_position_seconds;
                    }
                    if ($s->session_started_at && $s->last_heartbeat_at) {
                        return max(15, (int) $s->session_started_at->diffInSeconds($s->last_heartbeat_at));
                    }
                    return 30;
                });

                $typeLabel = 'Película';
                if ($type === 'livetv' || str_contains(strtolower($mediaTitle), 'ch ') || str_contains(strtolower($mediaTitle), 'canal')) {
                    $type = 'livetv';
                    $typeLabel = 'TV en Vivo';
                } elseif ($type === 'episode' || $type === 'series') {
                    $type = 'series';
                    $typeLabel = 'Serie';
                }

                // Verificar si ya existe en $items para sumar
                $existingIdx = null;
                foreach ($items as $idx => $existing) {
                    if (strcasecmp($existing['title'], $mediaTitle) === 0) {
                        $existingIdx = $idx;
                        break;
                    }
                }

                if ($existingIdx !== null) {
                    $items[$existingIdx]['play_count'] += $group->count();
                    $items[$existingIdx]['duration_seconds'] += $sessionSeconds;
                    $items[$existingIdx]['duration_formatted'] = $this->formatDuration($items[$existingIdx]['duration_seconds']);
                } else {
                    $items[] = [
                        'id' => $first->id,
                        'title' => $mediaTitle,
                        'type' => $type,
                        'type_label' => $typeLabel,
                        'poster_url' => $first->current_media_poster,
                        'backdrop_url' => null,
                        'play_count' => $group->count(),
                        'duration_seconds' => $sessionSeconds,
                        'duration_formatted' => $this->formatDuration($sessionSeconds),
                        'rating' => 8.5,
                        'category' => $type === 'livetv' ? 'IPTV On-Net' : 'Catálogo',
                    ];
                }
            }

            // Ordenar por tiempo total y conteo de reproducciones
            usort($items, function ($a, $b) {
                if ($b['duration_seconds'] === $a['duration_seconds']) {
                    return $b['play_count'] <=> $a['play_count'];
                }
                return $b['duration_seconds'] <=> $a['duration_seconds'];
            });

            // Asignar posición ranking #1..#N
            $ranked = [];
            $pos = 1;
            foreach (array_slice($items, 0, $limit) as $item) {
                $item['rank'] = $pos++;
                $ranked[] = $item;
            }

            return $ranked;
        });
    }

    /**
     * Top Usuarios / Contratos ISP Más Activos.
     */
    public function getTopUsers(string $range = '7d', int $limit = 10, bool $fresh = false): array
    {
        [$start, $end, , , , $normalizedRange] = $this->resolveDateRange($range);
        $cacheKey = "flixhn_stats_top_users_{$normalizedRange}_{$limit}";

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 120, function () use ($start, $end, $limit) {
            // Obtener sesiones en el rango agrupadas por user_id
            $sessions = DeviceSession::with('user')
                ->where(function ($q) use ($start, $end) {
                    $q->whereBetween('last_heartbeat_at', [$start, $end])
                      ->orWhereBetween('session_started_at', [$start, $end])
                      ->orWhereBetween('updated_at', [$start, $end]);
                })
                ->get();

            $usersList = [];
            $byUser = $sessions->groupBy('user_id');

            foreach ($byUser as $userId => $userSessions) {
                $user = $userSessions->first()->user;
                if (!$user && $userId) {
                    $user = User::find($userId);
                }

                $identifier = $user ? $user->username : ($userSessions->first()->client_identifier ?: 'Contrato #' . $userId);
                $customerName = $user ? ($user->customer_name ?: $user->username) : 'Abonado Anónimo';
                $role = $user ? $user->role : 'subscriber';

                $devicesUsed = $userSessions->pluck('device_type')->unique()->filter()->values()->all();
                $devicesCount = $userSessions->pluck('device_id')->unique()->count();

                $totalSeconds = $userSessions->sum(function ($s) {
                    if ($s->playback_position_seconds > 0) {
                        return $s->playback_position_seconds;
                    }
                    if ($s->session_started_at && $s->last_heartbeat_at) {
                        return max(15, (int) $s->session_started_at->diffInSeconds($s->last_heartbeat_at));
                    }
                    return 30;
                });

                $latestSession = $userSessions->sortByDesc('last_heartbeat_at')->first();
                $lastActive = $latestSession ? ($latestSession->last_heartbeat_at ?: $latestSession->updated_at) : null;

                $usersList[] = [
                    'user_id' => $userId,
                    'username' => $identifier,
                    'customer_name' => $customerName,
                    'role' => $role,
                    'is_active' => $user ? (bool) $user->is_active : true,
                    'devices_count' => max(1, $devicesCount),
                    'device_types' => empty($devicesUsed) ? ['desktop'] : $devicesUsed,
                    'play_count' => $userSessions->count(),
                    'total_seconds' => $totalSeconds,
                    'duration_formatted' => $this->formatDuration($totalSeconds),
                    'last_active_at' => $lastActive ? $lastActive->toIso8601String() : null,
                    'last_active_human' => $lastActive ? $lastActive->diffForHumans() : 'Recientemente',
                ];
            }

            // Si hay usuarios registrados que no tuvieron sesiones, agregarlos si aún no se llega al límite
            if (count($usersList) < $limit) {
                $existingIds = array_filter(array_column($usersList, 'user_id'));
                $otherUsers = User::whereNotIn('id', $existingIds)
                    ->where('role', 'subscriber')
                    ->limit($limit - count($usersList))
                    ->get();

                foreach ($otherUsers as $u) {
                    $usersList[] = [
                        'user_id' => $u->id,
                        'username' => $u->username,
                        'customer_name' => $u->customer_name ?: $u->username,
                        'role' => $u->role,
                        'is_active' => (bool) $u->is_active,
                        'devices_count' => 0,
                        'device_types' => [],
                        'play_count' => 0,
                        'total_seconds' => 0,
                        'duration_formatted' => '0h 0m',
                        'last_active_at' => null,
                        'last_active_human' => 'Sin actividad en el rango',
                    ];
                }
            }

            usort($usersList, function ($a, $b) {
                if ($b['total_seconds'] === $a['total_seconds']) {
                    return $b['play_count'] <=> $a['play_count'];
                }
                return $b['total_seconds'] <=> $a['total_seconds'];
            });

            $ranked = [];
            $pos = 1;
            foreach (array_slice($usersList, 0, $limit) as $item) {
                $item['rank'] = $pos++;
                $ranked[] = $item;
            }

            return $ranked;
        });
    }

    /**
     * Distribución de Dispositivos (Sistema Operativo, Navegador y Tipo de Cliente).
     */
    public function getDeviceDistribution(string $range = '7d', bool $fresh = false): array
    {
        [$start, $end, , , , $normalizedRange] = $this->resolveDateRange($range);
        $cacheKey = "flixhn_stats_device_dist_{$normalizedRange}";

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 120, function () use ($start, $end) {
            $sessions = DeviceSession::where(function ($q) use ($start, $end) {
                $q->whereBetween('last_heartbeat_at', [$start, $end])
                  ->orWhereBetween('session_started_at', [$start, $end])
                  ->orWhereBetween('updated_at', [$start, $end]);
            })->get();

            if ($sessions->isEmpty()) {
                // Fallback a todos los dispositivos registrados en inventario
                $sessions = DeviceSession::all();
            }

            $total = max(1, $sessions->count());

            // 1. Por Tipo de Dispositivo
            $byTypeRaw = $sessions->groupBy('device_type');
            $deviceTypeLabels = [
                'android' => 'Android TV / Móvil',
                'smart_tv' => 'Smart TV (Tizen/WebOS)',
                'browser' => 'Navegadores Web',
                'ios' => 'Apple iOS / tvOS',
                'desktop' => 'PC / Laptop (Windows/Mac)',
            ];

            $types = [];
            foreach ($deviceTypeLabels as $k => $label) {
                $count = isset($byTypeRaw[$k]) ? $byTypeRaw[$k]->count() : 0;
                $types[] = [
                    'key' => $k,
                    'label' => $label,
                    'count' => $count,
                    'percent' => round(($count / $total) * 100, 1),
                ];
            }

            // Si hay otros tipos no mapeados
            foreach ($byTypeRaw as $k => $group) {
                if (!isset($deviceTypeLabels[$k])) {
                    $types[] = [
                        'key' => $k,
                        'label' => ucfirst($k),
                        'count' => $group->count(),
                        'percent' => round(($group->count() / $total) * 100, 1),
                    ];
                }
            }

            // Ordenar de mayor a menor porcentaje
            usort($types, fn($a, $b) => $b['count'] <=> $a['count']);

            // 2. Por Sistema Operativo (OS)
            $byOsRaw = $sessions->groupBy(fn($s) => $s->os_name ?: 'Desconocido');
            $osList = [];
            foreach ($byOsRaw as $osName => $group) {
                $osList[] = [
                    'label' => $osName,
                    'count' => $group->count(),
                    'percent' => round(($group->count() / $total) * 100, 1),
                ];
            }
            usort($osList, fn($a, $b) => $b['count'] <=> $a['count']);

            // 3. Por Navegador
            $byBrowserRaw = $sessions->groupBy(fn($s) => $s->browser_name ?: 'Desconocido');
            $browserList = [];
            foreach ($byBrowserRaw as $browserName => $group) {
                $browserList[] = [
                    'label' => $browserName,
                    'count' => $group->count(),
                    'percent' => round(($group->count() / $total) * 100, 1),
                ];
            }
            usort($browserList, fn($a, $b) => $b['count'] <=> $a['count']);

            return [
                'total_devices' => $sessions->count(),
                'by_type' => $types,
                'by_os' => array_slice($osList, 0, 5),
                'by_browser' => array_slice($browserList, 0, 5),
            ];
        });
    }

    /**
     * Canales de TV en Vivo Más Sintonizados On-Net.
     */
    public function getLiveTvRanking(string $range = '7d', int $limit = 10, bool $fresh = false): array
    {
        [$start, $end, , , , $normalizedRange] = $this->resolveDateRange($range);
        $cacheKey = "flixhn_stats_livetv_{$normalizedRange}_{$limit}";

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 120, function () use ($start, $end, $limit) {
            // Sesiones LiveTV reales
            $sessions = DeviceSession::where(function ($q) use ($start, $end) {
                $q->whereBetween('last_heartbeat_at', [$start, $end])
                  ->orWhereBetween('session_started_at', [$start, $end]);
            })
            ->where(function ($q) {
                $q->where('current_media_type', 'livetv')
                  ->orWhere('current_media_title', 'like', 'CH %')
                  ->orWhere('current_media_title', 'like', '%Canal%');
            })
            ->get();

            $channels = [];
            $byTitle = $sessions->groupBy('current_media_title');

            foreach ($byTitle as $chTitle => $group) {
                $first = $group->first();
                $seconds = $group->sum(function ($s) {
                    if ($s->playback_position_seconds > 0) {
                        return $s->playback_position_seconds;
                    }
                    if ($s->session_started_at && $s->last_heartbeat_at) {
                        return max(15, (int) $s->session_started_at->diffInSeconds($s->last_heartbeat_at));
                    }
                    return 45;
                });

                // Buscar canal en base de datos para detalles como grupo y número
                $chModel = TvChannel::where('name', $chTitle)
                    ->orWhere('name', 'like', "%{$chTitle}%")
                    ->first();

                $channels[] = [
                    'id' => $chModel ? $chModel->id : $first->id,
                    'name' => $chTitle,
                    'logo_url' => $first->current_media_poster ?: ($chModel ? $chModel->logo_url : null),
                    'group_title' => $chModel ? ($chModel->group_title ?: 'Nacional') : 'IPTV On-Net',
                    'channel_number' => $chModel ? $chModel->channel_number : null,
                    'sessions_count' => $group->count(),
                    'total_seconds' => $seconds,
                    'duration_formatted' => $this->formatDuration($seconds),
                    'is_online' => $chModel ? (bool) $chModel->is_online : true,
                ];
            }

            usort($channels, fn($a, $b) => $b['total_seconds'] <=> $a['total_seconds']);

            $ranked = [];
            $pos = 1;
            foreach (array_slice($channels, 0, $limit) as $ch) {
                $ch['rank'] = $pos++;
                $ranked[] = $ch;
            }

            return $ranked;
        });
    }

    /**
     * Generar archivo CSV consolidado para exportación formal.
     */
    public function exportCsv(string $range = '7d', string $type = 'consolidated'): string
    {
        $overview = $this->getOverview($range, true);
        $topContent = $this->getTopContent($range, 15, true);
        $topUsers = $this->getTopUsers($range, 15, true);
        $devices = $this->getDeviceDistribution($range, true);
        $liveTv = $this->getLiveTvRanking($range, 10, true);

        // UTF-8 BOM para compatibilidad con Excel
        $output = "\xEF\xBB\xBF";
        $output .= "REPORTE DE ESTADÍSTICAS Y ANALÍTICAS - FLIXHN STREAMING ON-NET\n";
        $output .= "Generado el: " . now()->format('Y-m-d H:i:s') . "\n";
        $output .= "Período seleccionado: " . $overview['range_label'] . " (" . $overview['start_date'] . " a " . $overview['end_date'] . ")\n\n";

        // 1. Resumen de KPIs
        $output .= "=== RESUMEN EJECUTIVO DE TELEMETRÍA ===\n";
        $output .= "Métrica,Valor,Detalles / Tendencia\n";
        $output .= "Abonados Activos," . $overview['kpis']['active_users']['current'] . " de " . $overview['kpis']['active_users']['total_contracts'] . "," . $overview['kpis']['active_users']['percentage'] . "% de tasa de uso\n";
        $output .= "Dispositivos en Línea Ahora," . $overview['kpis']['active_users']['online_now'] . ",Conexiones concurrentes activas\n";
        $output .= "Horas de Reproducción Acumuladas," . $overview['kpis']['playback_time']['formatted'] . "," . $overview['kpis']['playback_time']['total_hours'] . " horas (" . $overview['kpis']['playback_time']['growth_percent'] . "%)\n";
        $output .= "Tráfico On-Net Servido," . $overview['kpis']['bandwidth']['formatted'] . ",100% Red local ISP con Direct Play\n";
        $output .= "Títulos Totales en Catálogo," . $overview['kpis']['catalog']['total'] . "," . $overview['kpis']['catalog']['movies'] . " Películas | " . $overview['kpis']['catalog']['series'] . " Series | " . $overview['kpis']['catalog']['channels'] . " Canales IPTV\n\n";

        // 2. Top Contenido
        $output .= "=== TOP CONTENIDO MÁS VISTO ===\n";
        $output .= "Posición,Tipo,Título,Reproducciones,Tiempo Total,Calificación,Categoría\n";
        if (empty($topContent)) {
            $output .= "Sin datos de reproducción en este período,,,,,\n";
        } else {
            foreach ($topContent as $c) {
                $titleClean = str_replace(['"', ','], ['', ' '], $c['title']);
                $rating = $c['rating'] ? number_format($c['rating'], 1) : 'N/A';
                $output .= "#{$c['rank']},{$c['type_label']},\"{$titleClean}\",{$c['play_count']},{$c['duration_formatted']},{$rating},\"{$c['category']}\"\n";
            }
        }
        $output .= "\n";

        // 3. Top Usuarios ISP
        $output .= "=== TOP CONTRATOS Y USUARIOS ISP MÁS ACTIVOS ===\n";
        $output .= "Posición,Contrato / Usuario,Nombre Cliente,Dispositivos,Horas Reproducidas,Última Conexión\n";
        if (empty($topUsers)) {
            $output .= "Sin actividad registrada en este período,,,,,\n";
        } else {
            foreach ($topUsers as $u) {
                $nameClean = str_replace(['"', ','], ['', ' '], $u['customer_name']);
                $devTypes = implode(' / ', $u['device_types'] ?: ['desktop']);
                $output .= "#{$u['rank']},\"{$u['username']}\",\"{$nameClean}\",\"{$u['devices_count']} ({$devTypes})\",{$u['duration_formatted']},\"{$u['last_active_human']}\"\n";
            }
        }
        $output .= "\n";

        // 4. Distribución de Dispositivos
        $output .= "=== DISTRIBUCIÓN POR TIPO DE DISPOSITIVO CLIENTE ===\n";
        $output .= "Tipo de Dispositivo,Cantidad de Sesiones,Porcentaje\n";
        foreach ($devices['by_type'] as $dt) {
            $output .= "\"{$dt['label']}\",{$dt['count']},{$dt['percent']}%\n";
        }
        $output .= "\n";

        // 5. Canales de TV en Vivo
        $output .= "=== CANALES DE TV EN VIVO MÁS SINTONIZADOS ===\n";
        $output .= "Posición,Canal,Grupo / Categoría,Sintonizaciones,Tiempo Total en Vivo,Estado\n";
        if (empty($liveTv)) {
            $output .= "Sin datos de sintonización de TV en Vivo en este período,,,,,\n";
        } else {
            foreach ($liveTv as $ch) {
                $chName = str_replace(['"', ','], ['', ' '], $ch['name']);
                $state = $ch['is_online'] ? 'En Línea' : 'Inactivo';
                $output .= "#{$ch['rank']},\"{$chName}\",\"{$ch['group_title']}\",{$ch['sessions_count']},{$ch['duration_formatted']},{$state}\n";
            }
        }

        return $output;
    }

    // =========================================================================
    // Métodos Auxiliares y Agregaciones SQL
    // =========================================================================

    /**
     * Obtiene los IDs únicos de usuarios activos dentro del rango especificado.
     */
    protected function getActiveUserIdsInRange(Carbon $start, Carbon $end): array
    {
        $sessionUserIds = DeviceSession::where(function ($q) use ($start, $end) {
            $q->whereBetween('last_heartbeat_at', [$start, $end])
              ->orWhereBetween('session_started_at', [$start, $end])
              ->orWhereBetween('updated_at', [$start, $end]);
        })
        ->whereNotNull('user_id')
        ->pluck('user_id')
        ->all();

        $progressUserIds = DB::table('playback_progress')
            ->join('profiles', 'playback_progress.profile_id', '=', 'profiles.id')
            ->whereBetween('playback_progress.last_watched_at', [$start, $end])
            ->pluck('profiles.user_id')
            ->all();

        return array_values(array_unique(array_merge($sessionUserIds, $progressUserIds)));
    }

    /**
     * Calcula los segundos totales de reproducción en un rango temporal.
     */
    protected function getTotalPlaybackSecondsInRange(Carbon $start, Carbon $end): int
    {
        // 1. playback_progress
        $progressSeconds = (int) DB::table('playback_progress')
            ->whereBetween('last_watched_at', [$start, $end])
            ->sum('current_seconds');

        // 2. device_sessions
        $sessions = DeviceSession::where(function ($q) use ($start, $end) {
            $q->whereBetween('last_heartbeat_at', [$start, $end])
              ->orWhereBetween('session_started_at', [$start, $end])
              ->orWhereBetween('updated_at', [$start, $end]);
        })->get();

        $sessionSeconds = (int) $sessions->sum(function ($s) {
            if ($s->playback_position_seconds > 0) {
                return $s->playback_position_seconds;
            }
            if ($s->session_started_at && $s->last_heartbeat_at) {
                return max(15, (int) $s->session_started_at->diffInSeconds($s->last_heartbeat_at));
            }
            return 30;
        });

        return $progressSeconds + $sessionSeconds;
    }

    /**
     * Calcula el ancho de banda estimado transferido en bytes.
     */
    protected function getEstimatedBandwidthBytes(Carbon $start, Carbon $end): int
    {
        $totalSeconds = $this->getTotalPlaybackSecondsInRange($start, $end);
        // Bitrate 4.5 Mbps = 562,500 bytes por segundo
        $bytesPerSec = (int) (self::DEFAULT_BITRATE_BPS / 8);
        return $totalSeconds * $bytesPerSec;
    }

    /**
     * Obtiene métricas agregadas para una ranura temporal específica.
     */
    protected function getSlotStats(Carbon $slotStart, Carbon $slotEnd): array
    {
        $progressCount = DB::table('playback_progress')
            ->whereBetween('last_watched_at', [$slotStart, $slotEnd])
            ->count();

        $progressSeconds = (int) DB::table('playback_progress')
            ->whereBetween('last_watched_at', [$slotStart, $slotEnd])
            ->sum('current_seconds');

        $sessions = DeviceSession::where(function ($q) use ($slotStart, $slotEnd) {
            $q->whereBetween('last_heartbeat_at', [$slotStart, $slotEnd])
              ->orWhereBetween('session_started_at', [$slotStart, $slotEnd])
              ->orWhereBetween('updated_at', [$slotStart, $slotEnd]);
        })->get();

        $sessionCount = $sessions->count();
        $sessionSeconds = (int) $sessions->sum(function ($s) {
            if ($s->playback_position_seconds > 0) {
                return $s->playback_position_seconds;
            }
            if ($s->session_started_at && $s->last_heartbeat_at) {
                return max(15, (int) $s->session_started_at->diffInSeconds($s->last_heartbeat_at));
            }
            return 30;
        });

        // Concurrencia estimada = conteo de sesiones simultáneas
        $concurrency = $sessions->pluck('device_id')->unique()->count();

        return [
            'plays' => $progressCount + $sessionCount,
            'seconds' => $progressSeconds + $sessionSeconds,
            'concurrency' => $concurrency,
        ];
    }

    /**
     * Distribución relativa de contenido: Películas vs Series vs TV en Vivo.
     */
    protected function getContentDistribution(Carbon $start, Carbon $end): array
    {
        $sessions = DeviceSession::where(function ($q) use ($start, $end) {
            $q->whereBetween('last_heartbeat_at', [$start, $end])
              ->orWhereBetween('session_started_at', [$start, $end])
              ->orWhereBetween('updated_at', [$start, $end]);
        })->get();

        $vodMoviePlays = $sessions->where('current_media_type', 'movie')->count();
        $seriesPlays = $sessions->whereIn('current_media_type', ['episode', 'series'])->count();
        $liveTvPlays = $sessions->where('current_media_type', 'livetv')->count();

        // Si current_media_title tiene prefijo 'CH ' asignarlo a LiveTV
        $otherLive = $sessions->filter(function ($s) {
            return $s->current_media_type !== 'livetv' && (
                str_starts_with((string)$s->current_media_title, 'CH ') || 
                str_contains(strtolower((string)$s->current_media_title), 'canal')
            );
        })->count();
        $liveTvPlays += $otherLive;

        // También sumar desde playback_progress
        $progress = DB::table('playback_progress')
            ->whereBetween('last_watched_at', [$start, $end])
            ->get();

        foreach ($progress as $p) {
            if (!empty($p->episode_id)) {
                $seriesPlays++;
            } else {
                $vodMoviePlays++;
            }
        }

        $totalPlays = $vodMoviePlays + $seriesPlays + $liveTvPlays;

        if ($totalPlays === 0) {
            // Si aún no hay reproducciones en el rango, calcular la proporción basada en catálogo real como proyección
            $moviesCatalog = Title::where('type', 'movie')->count();
            $seriesCatalog = Title::where('type', 'series')->count();
            $liveCatalog = TvChannel::where('is_active', true)->count();
            $catTotal = max(1, $moviesCatalog + $seriesCatalog + $liveCatalog);

            return [
                'has_data' => false,
                'total_plays' => 0,
                'categories' => [
                    [
                        'key' => 'movies',
                        'label' => 'Películas (VOD)',
                        'plays' => 0,
                        'percent' => round(($moviesCatalog / $catTotal) * 100, 1),
                        'color' => '#E50914',
                    ],
                    [
                        'key' => 'series',
                        'label' => 'Series & Episodios',
                        'plays' => 0,
                        'percent' => round(($seriesCatalog / $catTotal) * 100, 1),
                        'color' => '#00e5ff',
                    ],
                    [
                        'key' => 'livetv',
                        'label' => 'TV en Vivo (IPTV)',
                        'plays' => 0,
                        'percent' => round(($liveCatalog / $catTotal) * 100, 1),
                        'color' => '#10b981',
                    ],
                ],
            ];
        }

        return [
            'has_data' => true,
            'total_plays' => $totalPlays,
            'categories' => [
                [
                    'key' => 'movies',
                    'label' => 'Películas (VOD)',
                    'plays' => $vodMoviePlays,
                    'percent' => round(($vodMoviePlays / $totalPlays) * 100, 1),
                    'color' => '#E50914',
                ],
                [
                    'key' => 'series',
                    'label' => 'Series & Episodios',
                    'plays' => $seriesPlays,
                    'percent' => round(($seriesPlays / $totalPlays) * 100, 1),
                    'color' => '#00e5ff',
                ],
                [
                    'key' => 'livetv',
                    'label' => 'TV en Vivo (IPTV)',
                    'plays' => $liveTvPlays,
                    'percent' => round(($liveTvPlays / $totalPlays) * 100, 1),
                    'color' => '#10b981',
                ],
            ],
        ];
    }

    /**
     * Calcula porcentaje de crecimiento o decremento respecto al período previo.
     */
    protected function calculateGrowthPercent(float|int $current, float|int $previous): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        $growth = (($current - $previous) / $previous) * 100;
        return round($growth, 1);
    }

    /**
     * Formatea segundos a cadena legible (ej: 142h 35m).
     */
    public function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0h 0m';
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        if ($hours === 0) {
            return "{$minutes}m";
        }

        return "{$hours}h {$minutes}m";
    }

    /**
     * Formatea bytes a cadena legible (ej: 45.8 GB).
     */
    public function formatBytes(float|int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 MB';
        }

        if ($bytes >= 1099511627776) { // >= 1 TB
            return round($bytes / 1099511627776, 2) . ' TB';
        }

        if ($bytes >= 1073741824) { // >= 1 GB
            return round($bytes / 1073741824, 2) . ' GB';
        }

        if ($bytes >= 1048576) { // >= 1 MB
            return round($bytes / 1048576, 1) . ' MB';
        }

        return round($bytes / 1024, 1) . ' KB';
    }
}
