# Odjezdy MPVnet pro Živý Obraz

PHP knihovna a API pro načítání odjezdů z MPVnet (mpvnet.cz) ve formátu kompatibilním s RFC Živák https://zivyobraz.eu/.

## Podporované regiony

| Region | Popis |
|--------|-------|
| `zlin` | Zlínský kraj - IDZK |
| `odis` | Ostrava - ODIS |
| `idol` | Liberec - IDOL |
| `jikord` | Jihočeský kraj - JIKORD |
| `pid` | Praha - PID |

## Funkce

- Načítání odjezdů z MPVnet pro až 3 zastávky současně
- Filtrování podle cílových stanic
- Parametr pro minimální čas do odjezdu
- Cachování podle času do odjezdu

## Instalace

Soubory:
- `MpvnetDepartures.php` - hlavní knihovna
- `departures.php` - ukázkový HTTP API endpoint
- `cache/` - adresář pro cache (musí být zapisovatelný)

## Použití jako HTTP API

```
GET departures.php?region=zlin&stops=37445&limit=10
```

### Parametry

| Parametr | Typ | Povinný | Popis |
|----------|-----|---------|-------|
| `region` | string | ne | Region (výchozí: zlin) |
| `stops` | string | ano | ID zastávek oddělené čárkou (max 3) |
| `exclude_headsigns` | string | ne | Cílové stanice k vynechání, oddělené čárkou |
| `limit` | int | ne | Max počet odjezdů (výchozí 15, max 50) |
| `min_minutes` | int | ne | Odjezd nejdříve za X minut |

### Příklady

```bash
# Základní dotaz
curl "http://example.com/departures.php?region=zlin&stops=37445"

# Více zastávek s filtrem
curl "http://example.com/departures.php?region=zlin&stops=37445,37446&exclude_headsigns=terminál&limit=5"

# Odjezdy nejdříve za 5 minut
curl "http://example.com/departures.php?region=zlin&stops=37445&min_minutes=5"
```

## Použití jako PHP knihovna

```php
<?php
require_once 'MpvnetDepartures.php';

// Konfigurace (jednou na začátku)
MpvnetDepartures::$cacheDir = __DIR__ . '/cache';
MpvnetDepartures::$region = 'zlin';

// Získání odjezdů
$result = MpvnetDepartures::get([
    'stops' => [37445],
    'exclude_headsigns' => ['terminál'],
    'limit' => 10,
    'min_minutes' => 5,
]);

// Výsledek
print_r($result['departures']);
echo "Cache max age: " . $result['cache_max_age'] . "s\n";
echo "First departure in: " . $result['first_departure_minutes'] . " min\n";
```

### Čištění cache

```php
// Smazat staré soubory (starší než 1 hodina)
$deleted = MpvnetDepartures::cleanCache();

// Smazat celou cache
$deleted = MpvnetDepartures::cleanCache(true);
```

## Formát odpovědi

Odpověď je kompatibilní s RFC Živák (PID):

```json
[[
    {
        "departure": {
            "timestamp_scheduled": "2024-01-15T14:30:00+01:00",
            "timestamp_predicted": "2024-01-15T14:32:00+01:00",
            "delay_seconds": 120,
            "minutes": 5
        },
        "stop": {
            "id": "MPVNET_zlin_37445",
            "name": "Uherský Brod,Hlavní",
            "sequence": null,
            "platform_code": "1"
        },
        "route": {
            "type": "bus",
            "short_name": "320"
        },
        "trip": {
            "id": "28",
            "headsign": "Uherský Brod,Újezdec,žel.st.",
            "is_canceled": false
        },
        "vehicle": {
            "id": null,
            "is_wheelchair_accessible": true,
            "is_air_conditioned": null,
            "has_charger": null
        }
    }
]]
```

## Jak zjistit ID zastávky

ID zastávky lze zjistit z URL na mpvnet.cz. Například:
- URL: `https://mpvnet.cz/zlin/map/showStation/37445`
- ID zastávky: `37445`

Nebo v parametru `StopKey` v požadavku webu: `"stopNum":37445`

## Cachování

Knihovna používá cachování podle času do nejbližšího odjezdu:

- **< 15 minut do odjezdu**: cache 30 sekund (kvůli stavu aktuálního zpoždění)
- **>= 15 minut do odjezdu**: cache až do 15 minut před odjezdem (max 15 minut)

Garbage collector automaticky maže soubory starší než 1 hodina (spouští se s 1% pravděpodobností při každém requestu).

## Omezení

- MPVnet API vrací HTML, které se parsuje - při změně struktury může přestat fungovat
- Některé informace (klimatizace, ID vozidla) nejsou v MPVnet dostupné
- Zpoždění nemusí být vždy k dispozici

## Požadavky

- PHP 8.0+
- cURL extension
- DOM extension
- Zapisovatelný adresář pro cache

## Licence

MIT
