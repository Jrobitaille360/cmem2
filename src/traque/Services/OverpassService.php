<?php

namespace Traque\Services;

class OverpassService
{
    private const RADIUS_M  = 100;
    private const TIMEOUT   = 5;
    private const ENDPOINT  = 'https://overpass-api.de/api/interpreter';

    /** Détecte le biome OSM dominant dans un rayon de 100 m autour de (lat, lng). */
    public static function detect(float $lat, float $lng): string
    {
        $response = self::query(self::buildQuery($lat, $lng));
        if ($response === null) {
            return 'urban';
        }
        return self::parse($response);
    }

    private static function buildQuery(float $lat, float $lng): string
    {
        $r = self::RADIUS_M;
        return "[out:json][timeout:" . self::TIMEOUT . "];
(
  way[\"landuse\"=\"forest\"](around:{$r},{$lat},{$lng});
  relation[\"landuse\"=\"forest\"](around:{$r},{$lat},{$lng});
  way[\"natural\"=\"wood\"](around:{$r},{$lat},{$lng});
  node[\"natural\"=\"peak\"](around:{$r},{$lat},{$lng});
  way[\"natural\"=\"peak\"](around:{$r},{$lat},{$lng});
  way[\"natural\"=\"cliff\"](around:{$r},{$lat},{$lng});
  way[\"natural\"=\"water\"](around:{$r},{$lat},{$lng});
  relation[\"natural\"=\"water\"](around:{$r},{$lat},{$lng});
  way[\"waterway\"=\"river\"](around:{$r},{$lat},{$lng});
  way[\"landuse\"=\"cemetery\"](around:{$r},{$lat},{$lng});
  node[\"amenity\"=\"place_of_worship\"](around:{$r},{$lat},{$lng});
  way[\"amenity\"=\"place_of_worship\"](around:{$r},{$lat},{$lng});
  way[\"landuse\"=\"industrial\"](around:{$r},{$lat},{$lng});
);
out tags;";
    }

    private static function query(string $query): ?array
    {
        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'data=' . urlencode($query),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT + 2,
        ]);
        $body = curl_exec($ch);
        $err  = curl_errno($ch);
        curl_close($ch);

        if ($err || !$body) {
            return null;
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }

    private static function parse(array $data): string
    {
        $found = [];
        foreach ($data['elements'] ?? [] as $el) {
            $tags     = $el['tags'] ?? [];
            $landuse  = $tags['landuse']  ?? '';
            $natural  = $tags['natural']  ?? '';
            $waterway = $tags['waterway'] ?? '';
            $amenity  = $tags['amenity']  ?? '';

            if ($landuse === 'forest' || $natural === 'wood') {
                $found['forest']   = true;
            } elseif ($natural === 'peak' || $natural === 'cliff') {
                $found['peak']     = true;
            } elseif ($natural === 'water' || $waterway === 'river') {
                $found['water']    = true;
            } elseif ($landuse === 'cemetery') {
                $found['cemetery'] = true;
            } elseif ($amenity === 'place_of_worship') {
                $found['worship']  = true;
            } elseif ($landuse === 'industrial') {
                $found['industrial'] = true;
            }
        }

        foreach (['forest', 'peak', 'water', 'cemetery', 'worship', 'industrial'] as $biome) {
            if (!empty($found[$biome])) {
                return $biome;
            }
        }
        return 'urban';
    }
}
