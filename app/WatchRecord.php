<?php

declare(strict_types=1);

final class WatchRecord
{
    private const ALLOWED_KINDS = ['movie', 'series', 'animation', 'anime'];
    private const ITEM_KEYS = [
        'id', 'title', 'originalTitle', 'kind', 'year', 'genre', 'synopsis', 'poster',
        'backdrop', 'page', 'duration', 'countries', 'rating', 'rezkaID', 'isSeries',
        'franchiseTitle', 'actors', 'directors', 'collections', 'rankings', 'franchise',
        'ratings', 'schedule', 'information',
    ];
    private const ARRAY_ITEM_KEYS = [
        'countries', 'translators', 'seasons', 'actors', 'directors', 'collections',
        'rankings', 'franchise', 'ratings', 'schedule',
    ];
    private const STRING_ITEM_KEYS = [
        'id', 'title', 'originalTitle', 'genre', 'synopsis', 'poster', 'backdrop',
        'page', 'rating', 'franchiseTitle', 'rezkaID',
    ];

    public static function clean(mixed $value): ?array
    {
        if (!$value instanceof stdClass) {
            return null;
        }

        $record = get_object_vars($value);
        $rawItem = $record['item'] ?? null;
        if (!$rawItem instanceof stdClass) {
            return null;
        }

        $source = get_object_vars($rawItem);
        $id = self::safeString($source['id'] ?? null, 300);
        $title = self::safeString($source['title'] ?? null, 500);
        $kind = self::safeString($source['kind'] ?? null, 30);
        $position = self::number($record['position'] ?? null);
        $duration = self::number($record['duration'] ?? null);
        $updatedAt = self::number($record['updatedAt'] ?? null);

        if (
            $id === null || $title === null || $kind === null
            || !in_array($kind, self::ALLOWED_KINDS, true)
            || $position === null || $position < 0
            || $duration === null || $duration < 0
            || $updatedAt === null || $updatedAt < 0
        ) {
            return null;
        }

        $item = [];
        foreach (self::ITEM_KEYS as $key) {
            if (!array_key_exists($key, $source) || $source[$key] === null) {
                continue;
            }
            $part = $source[$key];
            if (in_array($key, self::STRING_ITEM_KEYS, true)) {
                if ($key === 'synopsis' && is_string($part)) {
                    // iOS requires a synopsis, so shorten long text instead of dropping it.
                    $item[$key] = mb_substr($part, 0, 1200);
                } elseif (is_string($part) && mb_strlen($part) <= 2000) {
                    $item[$key] = $part;
                }
            } elseif (in_array($key, ['year', 'duration'], true)) {
                $number = self::number($part);
                if ($number !== null) {
                    $item[$key] = $number;
                }
            } elseif (in_array($key, self::ARRAY_ITEM_KEYS, true)) {
                if (is_array($part) && count($part) <= 1500) {
                    $item[$key] = $part;
                }
            } elseif ($key === 'information' && $part instanceof stdClass) {
                $item[$key] = $part;
            } elseif ($key === 'isSeries' && is_bool($part)) {
                $item[$key] = $part;
            }
        }

        $item['id'] = $id;
        $item['title'] = $title;
        $item['kind'] = $kind;
        $item['genre'] ??= '';
        $item['synopsis'] ??= '';
        $item['symbol'] = mb_strtoupper(mb_substr(trim($title) ?: 'R', 0, 1));
        $item['paletteIndex'] = 0;

        $posterUrl = self::safeString($source['posterURL'] ?? $source['poster'] ?? null, 2000);
        if ($posterUrl !== null) {
            $item['poster'] = $posterUrl;
            $item['posterURL'] = $posterUrl;
        }

        $durationMinutes = self::number($source['durationMinutes'] ?? $source['duration'] ?? null);
        if ($durationMinutes !== null && $durationMinutes > 0) {
            $item['durationMinutes'] = $durationMinutes;
        }

        if (is_array($source['countries'] ?? null)) {
            $item['countryCodes'] = array_slice(
                array_values(array_filter($source['countries'], 'is_string')),
                0,
                30,
            );
        }

        $rezkaSource = $source['rezka'] ?? null;
        $rezka = $rezkaSource instanceof stdClass ? get_object_vars($rezkaSource) : [];
        $page = self::safeString($source['page'] ?? $rezka['pageURL'] ?? null, 2000);
        if ($page !== null) {
            $pageUrl = filter_var($page, FILTER_VALIDATE_URL);
            if (is_string($pageUrl) && strtolower((string) parse_url($pageUrl, PHP_URL_SCHEME)) === 'https') {
                $translators = self::cleanTranslators($source['translators'] ?? $rezka['translators'] ?? null);
                $seasons = self::cleanSeasons($source['seasons'] ?? $rezka['seasons'] ?? null);
                $movieId = self::safeString($source['rezkaID'] ?? $rezka['movieID'] ?? null, 300) ?? $id;
                $isSeries = (bool) ($source['isSeries'] ?? $rezka['isSeries'] ?? ($kind === 'series'));
                $item['rezka'] = [
                    'movieID' => $movieId,
                    'pageURL' => $pageUrl,
                    'isSeries' => $isSeries,
                    'translators' => $translators,
                    'seasons' => $seasons,
                    'playerFlags' => new stdClass(),
                ];
                // Android reads the same media through flat fields.
                $item['page'] = $pageUrl;
                $item['rezkaID'] = $movieId;
                $item['isSeries'] = $isSeries;
                $item['translators'] = array_map(static fn (array $translator): array => [
                    'id' => $translator['id'],
                    'name' => $translator['name'],
                    'selected' => $translator['isSelected'],
                    'premium' => $translator['isPremium'],
                ], $translators);
                $item['seasons'] = array_map(static fn (array $season): array => [
                    'id' => $season['id'],
                    'title' => $season['title'],
                    'episodes' => array_map(static fn (array $episode): array => [
                        'id' => $episode['id'],
                        'title' => $episode['title'],
                        'season' => $episode['seasonID'],
                    ], $season['episodes']),
                ], $seasons);
            }
        }

        $clean = [
            'item' => $item,
            'position' => $position,
            'duration' => $duration,
            'updatedAt' => $updatedAt,
            'completed' => is_bool($record['completed'] ?? null)
                ? $record['completed']
                : ($duration > 0 && $position >= $duration - 1),
        ];

        foreach ([['season', 'seasonID', 80], ['episode', 'episodeID', 80], ['translator', 'translatorID', 300]] as [$key, $alias, $maximum]) {
            $text = self::safeString($record[$key] ?? $record[$alias] ?? null, $maximum);
            if ($text !== null) {
                $clean[$key] = $text;
                $clean[$alias] = $text;
            }
        }

        return $clean;
    }

    public static function key(array $record): string
    {
        return implode(':', [
            (string) $record['item']['id'],
            (string) ($record['season'] ?? 'movie'),
            (string) ($record['episode'] ?? 'movie'),
        ]);
    }

    private static function safeString(mixed $value, int $maximum): ?string
    {
        return is_string($value) && mb_strlen($value) <= $maximum ? $value : null;
    }

    private static function number(mixed $value): int|float|null
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return null;
        }
        $number = (float) $value;
        if (!is_finite($number)) {
            return null;
        }
        return floor($number) === $number ? (int) $number : $number;
    }

    private static function cleanTranslators(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach (array_slice($value, 0, 1500) as $entry) {
            if (!$entry instanceof stdClass) {
                continue;
            }
            $translator = get_object_vars($entry);
            if (!is_string($translator['id'] ?? null) || !is_string($translator['name'] ?? null)) {
                continue;
            }
            $result[] = [
                'id' => $translator['id'],
                'name' => $translator['name'],
                'isPremium' => (bool) ($translator['premium'] ?? $translator['isPremium'] ?? false),
                'isSelected' => (bool) ($translator['selected'] ?? $translator['isSelected'] ?? false),
                'flags' => new stdClass(),
            ];
        }
        return $result;
    }

    private static function cleanSeasons(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach (array_slice($value, 0, 1500) as $entry) {
            if (!$entry instanceof stdClass) {
                continue;
            }
            $season = get_object_vars($entry);
            if (!is_string($season['id'] ?? null) || !is_array($season['episodes'] ?? null)) {
                continue;
            }
            $episodes = [];
            foreach (array_slice($season['episodes'], 0, 1500) as $episodeEntry) {
                if (!$episodeEntry instanceof stdClass) {
                    continue;
                }
                $episode = get_object_vars($episodeEntry);
                if (!is_string($episode['id'] ?? null)) {
                    continue;
                }
                $episodes[] = [
                    'id' => $episode['id'],
                    'title' => is_string($episode['title'] ?? null) ? $episode['title'] : 'Episode ' . $episode['id'],
                    'seasonID' => is_string($episode['seasonID'] ?? null)
                        ? $episode['seasonID']
                        : (is_string($episode['season'] ?? null) ? $episode['season'] : $season['id']),
                ];
            }
            $result[] = [
                'id' => $season['id'],
                'title' => is_string($season['title'] ?? null) ? $season['title'] : 'Season ' . $season['id'],
                'episodes' => $episodes,
            ];
        }
        return $result;
    }
}
