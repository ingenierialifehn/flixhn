<?php

namespace AppServicesFlix;

use IlluminateSupportFacadesCache;
use IlluminateSupportFacadesDB;
use IlluminateSupportFacadesSchema;

/**
 * Historial de búsquedas persistente portado desde plugin.video.flix (lib/storage.py::SearchHistory).
 */
class FlixSearchHistoryService
{
    protected const TABLE = 'flix_search_history';
    protected const FALLBACK_CACHE_KEY = 'flix_search_history_fallback';

    public function record(string $searchType, string $query, ?int $userId = null): void
    {
        if (!config('flix.search_history.enabled', true)) {
            return;
        }

        $cleanQuery = trim($query);
        if ($cleanQuery === '') {
            return;
        }
        $type = $this->normalizeType($searchType);

        if ($this->hasTable()) {
            $existing = DB::table(self::TABLE)
                ->where('user_id', $userId)
                ->where('search_type', $type)
                ->where('query', $cleanQuery)
                ->first();

            if ($existing) {
                DB::table(self::TABLE)
                    ->where('id', $existing->id)
                    ->update(['last_modified' => now(), 'updated_at' => now()]);
            } else {
                DB::table(self::TABLE)->insert([
                    'user_id' => $userId,
                    'search_type' => $type,
                    'query' => $cleanQuery,
                    'last_modified' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            return;
        }

        $items = Cache::get(self::FALLBACK_CACHE_KEY, []);
        $items = array_values(array_filter(
            is_array($items) ? $items : [],
            static fn ($row) => !(($row['search_type'] ?? '') === $type && mb_strtolower((string) ($row['query'] ?? '')) === mb_strtolower($cleanQuery))
        ));
        array_unshift($items, [
            'id' => (int) (microtime(true) * 1000),
            'search_type' => $type,
            'query' => $cleanQuery,
            'last_modified' => now()->toIso8601String(),
        ]);
        Cache::put(self::FALLBACK_CACHE_KEY, array_slice($items, 0, 100), now()->addDays(30));
    }

    public function getPage(string $searchType = 'all', int $page = 1, ?int $userId = null): array
    {
        $pageSize = max(1, (int) config('flix.search_history.page_size', 20));
        $page = max(1, $page);
        $type = $this->normalizeType($searchType);

        if ($this->hasTable()) {
            $qb = DB::table(self::TABLE);
            if ($userId !== null) {
                $qb->where(function ($w) use ($userId) {
                    $w->where('user_id', $userId)->orWhereNull('user_id');
                });
            }
            if ($type !== 'all') {
                $qb->where('search_type', $type);
            }

            $total = (clone $qb)->count();
            $rows = $qb->orderByDesc('last_modified')
                ->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get()
                ->map(static fn ($r) => [
                    'id' => (int) $r->id,
                    'search_type' => $r->search_type,
                    'query' => $r->query,
                    'last_modified' => $r->last_modified,
                ])
                ->toArray();

            return [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => (int) ceil($total / $pageSize),
                'items' => $rows,
            ];
        }

        $items = Cache::get(self::FALLBACK_CACHE_KEY, []);
        if ($type !== 'all') {
            $items = array_values(array_filter($items, static fn ($r) => ($r['search_type'] ?? '') === $type));
        }
        $total = count($items);
        $slice = array_slice($items, ($page - 1) * $pageSize, $pageSize);

        return [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'total_pages' => (int) ceil($total / $pageSize),
            'items' => $slice,
        ];
    }

    public function deleteById(int $id, ?int $userId = null): bool
    {
        if ($this->hasTable()) {
            $qb = DB::table(self::TABLE)->where('id', $id);
            if ($userId !== null) {
                $qb->where(function ($w) use ($userId) {
                    $w->where('user_id', $userId)->orWhereNull('user_id');
                });
            }
            return $qb->delete() > 0;
        }

        $items = Cache::get(self::FALLBACK_CACHE_KEY, []);
        $filtered = array_values(array_filter($items, static fn ($r) => (int) ($r['id'] ?? 0) !== $id));
        Cache::put(self::FALLBACK_CACHE_KEY, $filtered, now()->addDays(30));
        return count($filtered) !== count($items);
    }

    public function clear(?string $searchType = null, ?int $userId = null): int
    {
        if ($this->hasTable()) {
            $qb = DB::table(self::TABLE);
            if ($userId !== null) {
                $qb->where(function ($w) use ($userId) {
                    $w->where('user_id', $userId)->orWhereNull('user_id');
                });
            }
            if ($searchType && $searchType !== 'all') {
                $qb->where('search_type', $this->normalizeType($searchType));
            }
            return $qb->delete();
        }

        $items = Cache::get(self::FALLBACK_CACHE_KEY, []);
        $count = count($items);
        Cache::forget(self::FALLBACK_CACHE_KEY);
        return $count;
    }

    protected function hasTable(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function normalizeType(string $type): string
    {
        return match (strtolower(trim($type))) {
            'movie', 'movies', '0' => 'movie',
            'tv', 'show', 'shows', 'series', '1' => 'tv',
            'person', 'people', '2' => 'person',
            default => 'all',
        };
    }
}
