<?php
/**
 * MPVnet Departures - třída pro načítání odjezdů z MPVnet
 *
 * Podporovaná města/regiony:
 *   - zlin (Zlínský kraj - IDZK)
 *   - odis (Ostrava - ODIS)
 *   - idol (Liberec - IDOL)
 *   - jikord (Jihočeský kraj - JIKORD)
 *   - pid (Praha - PID)
 *
 * Použití:
 *   MpvnetDepartures::$cacheDir = __DIR__ . '/cache';
 *   MpvnetDepartures::$region = 'zlin';
 *
 *   $result = MpvnetDepartures::get([
 *       'stops' => [37445],
 *       'limit' => 10,
 *   ]);
 */

class MpvnetDepartures
{
    private const BASE_URL = 'https://mpvnet.cz';

    // Konfigurace cachování
    private const CACHE_REALTIME = 30;      // krátká cache
    private const CACHE_MAX = 900;          // maximální cache (15 min)
    private const REFRESH_BEFORE = 15;      // začít aktualizovat X minut před odjezdem

    // Globální konfigurace
    public static ?string $cacheDir = null;
    public static string $region = 'zlin';

    /**
     * Hlavní metoda pro získání odjezdů
     *
     * @param array $options [
     *   'stops' => array<int>,           // ID zastávek (povinné, max 3)
     *   'exclude_headsigns' => array<string>, // cílové stanice k vynechání
     *   'limit' => int,                  // max počet (výchozí 15)
     *   'min_minutes' => int,            // odjezd nejdříve za X minut
     * ]
     * @return array [
     *   'departures' => array,
     *   'cache_max_age' => int,
     *   'first_departure_minutes' => int|null,
     *   'from_cache' => bool,
     * ]
     */
    public static function get(array $options): array
    {
        $stops = $options['stops'] ?? [];
        $excludeHeadsigns = $options['exclude_headsigns'] ?? [];
        $limit = min(50, max(1, $options['limit'] ?? 15));
        $minMinutes = max(0, $options['min_minutes'] ?? 0);
        $cacheDir = self::$cacheDir;

        if (empty($stops)) {
            throw new InvalidArgumentException('Chybí povinný parametr: stops');
        }
        if (count($stops) > 3) {
            throw new InvalidArgumentException('Maximálně 3 zastávky');
        }

        // Cache
        if ($cacheDir) {
            $cached = self::cacheGet($cacheDir, $options);
            if ($cached !== null) {
                return $cached;
            }
        }

        // Načti data z API
        $allDepartures = [];
        $now = time();

        foreach ($stops as $stopId) {
            $apiData = self::fetchApi((int)$stopId);
            if ($apiData === null) {
                continue;
            }

            foreach ($apiData['departures'] as $dep) {
                // Filtr: exclude_headsigns
                $headsign = $dep['headsign'] ?? '';
                $skip = false;
                foreach ($excludeHeadsigns as $exclude) {
                    if (stripos($headsign, $exclude) !== false) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) {
                    continue;
                }

                // Filtr: min_minutes
                if ($minMinutes > 0 && $dep['minutes'] < $minMinutes) {
                    continue;
                }

                $allDepartures[] = [
                    'data' => self::transform($dep, (int)$stopId, $apiData['stop_name']),
                    'sort_time' => $dep['timestamp'],
                ];
            }
        }

        // Seřaď a ořež
        usort($allDepartures, fn($a, $b) => $a['sort_time'] <=> $b['sort_time']);
        $allDepartures = array_slice($allDepartures, 0, $limit);
        $departures = array_map(fn($item) => $item['data'], $allDepartures);

        // Vypočítej cache TTL
        $firstMin = !empty($departures) ? ($departures[0]['departure']['minutes'] ?? 999) : 999;

        if ($firstMin <= self::REFRESH_BEFORE) {
            $cacheMaxAge = self::CACHE_REALTIME;
        } else {
            $secondsUntilRefresh = ($firstMin - self::REFRESH_BEFORE) * 60;
            $cacheMaxAge = min($secondsUntilRefresh, self::CACHE_MAX);
        }

        $result = [
            'departures' => $departures,
            'cache_max_age' => $cacheMaxAge,
            'first_departure_minutes' => $firstMin,
            'from_cache' => false,
        ];

        // Ulož do cache
        if ($cacheDir) {
            self::cacheSet($cacheDir, $options, $result);
        }

        return $result;
    }

    /**
     * Načte odjezdy z MPVnet API a parsuje HTML
     */
    private static function fetchApi(int $stopId): ?array
    {
        $url = self::BASE_URL . '/' . self::$region . '/tab/departures';

        $payload = json_encode([
            'isDepartures' => true,
            'StopKey' => json_encode([
                'cat' => 2,
                'subCat' => 0,
                'stopNum' => $stopId,
                'departures' => null,
            ]),
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: */*',
                'Origin: ' . self::BASE_URL,
                'Referer: ' . self::BASE_URL . '/' . self::$region . '/',
            ],
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return null;
        }

        return self::parseHtml($response, $stopId);
    }

    /**
     * Parsuje HTML odpověď z MPVnet
     */
    private static function parseHtml(string $html, int $stopId): ?array
    {
        // Extrahuj název zastávky z hlavičky
        $stopName = null;
        if (preg_match('/<span class="box-title tab">([^<]+)<\/span>/', $html, $m)) {
            $stopName = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Najdi všechny řádky s odjezdy (ne header)
        $departures = [];
        $now = time();

        // Regex pro jednotlivé řádky timetable
        preg_match_all('/<div class="timetable-row">(.*?)<\/div>\s*<\/div>\s*<\/div>\s*<\/div>\s*<\/div>/s', $html, $rows);

        // Alternativní přístup - parsuj podle struktury
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($dom);

        $rowNodes = $xpath->query('//div[contains(@class, "timetable-row") and .//div[contains(@class, "timetable-line")]/div[contains(@class, "timetable-value")]]');

        foreach ($rowNodes as $row) {
            $departure = self::parseRow($xpath, $row, $now);
            if ($departure) {
                $departures[] = $departure;
            }
        }

        return [
            'stop_name' => $stopName,
            'departures' => $departures,
        ];
    }

    /**
     * Parsuje jeden řádek odjezdu
     */
    private static function parseRow(DOMXPath $xpath, DOMElement $row, int $now): ?array
    {
        // Číslo linky
        $lineNode = $xpath->query('.//div[contains(@class, "timetable-line")]//div[contains(@class, "timetable-value")]/div[1]', $row)->item(0);
        $line = $lineNode ? trim($lineNode->textContent) : null;

        if (!$line) {
            return null;
        }

        // Číslo spoje (směr)
        $tripNode = $xpath->query('.//div[contains(@class, "timetable-line")]//div[contains(@class, "timetable-value")]/div[contains(@class, "timetable-direction")]', $row)->item(0);
        $tripId = $tripNode ? trim($tripNode->textContent) : null;

        // Cílová zastávka (headsign)
        $destNode = $xpath->query('.//div[contains(@class, "timetable-destination")]//div[contains(@class, "timetable-value")]', $row)->item(0);
        $headsign = '';
        if ($destNode) {
            // Vezmeme první textový uzel (bez vnořených divů)
            foreach ($destNode->childNodes as $child) {
                if ($child->nodeType === XML_TEXT_NODE) {
                    $headsign .= trim($child->textContent);
                }
            }
            $headsign = html_entity_decode(trim($headsign), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Čas odjezdu
        $timeNodes = $xpath->query('.//div[contains(@class, "timetable-item") and not(contains(@class, "timetable-line")) and not(contains(@class, "timetable-destination")) and not(contains(@class, "timetable-icon")) and not(contains(@class, "timetable-delay"))]', $row);

        $departureTime = null;
        $platform = null;

        foreach ($timeNodes as $i => $node) {
            $text = trim($node->textContent);
            // První je čas (HH:MM), druhý je nástupiště
            if (preg_match('/^\d{1,2}:\d{2}$/', $text)) {
                $departureTime = $text;
            } elseif (is_numeric($text) && $departureTime !== null) {
                $platform = $text;
            }
        }

        if (!$departureTime) {
            return null;
        }

        // Zpoždění
        $delayNode = $xpath->query('.//div[contains(@class, "timetable-delay")]//span', $row)->item(0);
        $delayText = $delayNode ? trim($delayNode->textContent) : '';
        $delayMinutes = null;

        if (preg_match('/(\d+)\s*min/i', $delayText, $m)) {
            $delayMinutes = (int)$m[1];
        }

        // Typ vozidla
        $isBus = $xpath->query('.//div[contains(@class, "bus-icon")]', $row)->length > 0;
        $isTram = $xpath->query('.//div[contains(@class, "tram-icon")]', $row)->length > 0;
        $isTrolley = $xpath->query('.//div[contains(@class, "trolley-icon")]', $row)->length > 0;

        $type = 'bus';
        if ($isTram) $type = 'tram';
        if ($isTrolley) $type = 'trolleybus';

        // Bezbariérovost
        $isWheelchair = $xpath->query('.//div[contains(@class, "ztp-icon")]', $row)->length > 0;

        // Výpočet timestamp
        $todayDate = date('Y-m-d');
        $timestamp = strtotime($todayDate . ' ' . $departureTime);

        // Pokud je čas v minulosti, přidej den
        if ($timestamp < $now - 300) {
            $timestamp += 86400;
        }

        $predictedTime = $timestamp + (($delayMinutes ?? 0) * 60);
        $minutes = max(0, (int)(($predictedTime - $now) / 60));

        return [
            'line' => $line,
            'trip_id' => $tripId,
            'headsign' => $headsign,
            'type' => $type,
            'timestamp' => $timestamp,
            'predicted_time' => $predictedTime,
            'delay_minutes' => $delayMinutes,
            'minutes' => $minutes,
            'platform' => $platform,
            'is_wheelchair_accessible' => $isWheelchair,
        ];
    }

    /**
     * Transformuje odjezd do výstupního formátu
     */
    private static function transform(array $dep, int $stopId, ?string $stopName): array
    {
        return [
            'departure' => [
                'timestamp_scheduled' => date('c', $dep['timestamp']),
                'timestamp_predicted' => date('c', $dep['predicted_time']),
                'delay_seconds' => $dep['delay_minutes'] !== null ? $dep['delay_minutes'] * 60 : null,
                'minutes' => $dep['minutes'],
            ],
            'stop' => [
                'id' => 'MPVNET_' . self::$region . '_' . $stopId,
                'name' => $stopName,
                'sequence' => null,
                'platform_code' => $dep['platform'],
            ],
            'route' => [
                'type' => $dep['type'],
                'short_name' => $dep['line'],
            ],
            'trip' => [
                'id' => $dep['trip_id'],
                'headsign' => $dep['headsign'],
                'is_canceled' => false,
            ],
            'vehicle' => [
                'id' => null,
                'is_wheelchair_accessible' => $dep['is_wheelchair_accessible'],
                'is_air_conditioned' => null,
                'has_charger' => null,
            ],
        ];
    }

    /**
     * Generuje cache klíč
     */
    private static function cacheKey(array $options): string
    {
        return 'mpvnet_' . self::$region . '_' . md5(json_encode([
            $options['stops'] ?? [],
            $options['exclude_headsigns'] ?? [],
            $options['limit'] ?? 15,
            $options['min_minutes'] ?? 0,
        ])) . '.json';
    }

    /**
     * Vyčistí cache soubory
     */
    public static function cleanCache(bool $all = false): int
    {
        if (!self::$cacheDir) {
            return 0;
        }

        $deleted = 0;
        $files = glob(self::$cacheDir . '/mpvnet_*.json');
        if (is_array($files)) {
            foreach ($files as $f) {
                if ($all || time() - filemtime($f) > 3600) {
                    if (unlink($f)) {
                        $deleted++;
                    }
                }
            }
        }
        return $deleted;
    }

    /**
     * Načte z cache
     */
    private static function cacheGet(string $cacheDir, array $options): ?array
    {
        // Garbage collection (1% šance)
        if (mt_rand(1, 100) === 1) {
            self::cleanCache();
        }

        $file = $cacheDir . '/' . self::cacheKey($options);
        if (!file_exists($file)) {
            return null;
        }

        $data = json_decode(file_get_contents($file), true);
        if (!$data || ($data['expires'] ?? 0) <= time()) {
            return null;
        }

        return [
            'departures' => $data['departures'],
            'cache_max_age' => $data['expires'] - time(),
            'first_departure_minutes' => $data['first_min'] ?? null,
            'from_cache' => true,
        ];
    }

    /**
     * Uloží do cache
     */
    private static function cacheSet(string $cacheDir, array $options, array $result): void
    {
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true)) {
            return;
        }

        $file = $cacheDir . '/' . self::cacheKey($options);
        $data = [
            'departures' => $result['departures'],
            'expires' => time() + $result['cache_max_age'],
            'first_min' => $result['first_departure_minutes'],
        ];

        file_put_contents($file, json_encode($data), LOCK_EX);
    }
}
