<?php

/**
 * Script CLI — Génération d'une paire de clés VAPID (Web Push, RFC 8292).
 *
 * Usage :
 *   php src/push/generate_vapid.php
 *
 * Affiche les trois lignes à coller dans le .env du serveur. La clé privée ne doit
 * jamais être versionnée ni transmise au client : seule VAPID_PUBLIC_KEY est exposée
 * (GET /push/vapid-public-key).
 *
 * Regénérer la paire invalide toutes les subscriptions existantes : les navigateurs
 * refusent un envoi signé par une autre clé que celle de pushManager.subscribe().
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ce script doit être exécuté en ligne de commande.' . PHP_EOL);
}

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Minishlink\WebPush\VAPID;

$keys = VAPID::createVapidKeys();

echo PHP_EOL;
echo "# Clés VAPID générées le " . date('Y-m-d H:i:s') . PHP_EOL;
echo "VAPID_PUBLIC_KEY={$keys['publicKey']}" . PHP_EOL;
echo "VAPID_PRIVATE_KEY={$keys['privateKey']}" . PHP_EOL;
echo "VAPID_SUBJECT=mailto:support@journauxdebord.com" . PHP_EOL;
echo PHP_EOL;
echo "→ Coller ces lignes dans .env (serveur). Ne jamais committer la clé privée." . PHP_EOL;
