<?php

/**
 * Fonctions d'export SQL partagées entre les scripts de sauvegarde
 *
 * Inclure après _bootstrap.php dans chaque script de sauvegarde.
 *
 * Chunking activé par défaut : chaque table est lue par tranches de
 * CHUNK_SIZE lignes pour éviter les dépassements mémoire.
 * Toutes les tranches d'une même table sont écrites dans le même fichier.
 */

const CHUNK_SIZE = 5000;

/**
 * Retourne la valeur SQL correctement quotée.
 * NULL PHP → SQL NULL ; tout le reste → chaîne quotée via PDO::quote().
 */
function sqlQuote(PDO $pdo, mixed $value): string
{
    return $value === null ? 'NULL' : $pdo->quote((string) $value);
}

/**
 * Exporte une table vers le fichier déjà ouvert $fh.
 *
 * @param  resource $fh   Handle de fichier ouvert en écriture
 * @return int            Nombre de lignes exportées
 */
/**
 * Vérifie si une table existe dans la base courante.
 */
function tableExists(PDO $pdo, string $table): bool
{
    $exists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote($table)
    )->fetchColumn();
    return (int) $exists > 0;
}

function exportTable(PDO $pdo, mixed $fh, string $table): int
{
    if (!tableExists($pdo, $table)) {
        fwrite($fh, "\n-- TABLE: {$table} (ignorée — n'existe pas)\n");
        return 0;
    }

    $count = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();

    fwrite($fh, "\n-- TABLE: {$table}\n");
    fwrite($fh, "TRUNCATE TABLE `{$table}`;\n");

    if ($count === 0) {
        return 0;
    }

    $exported = 0;
    $offset   = 0;

    while ($offset < $count) {
        $rows = $pdo->query(
            "SELECT * FROM `{$table}` LIMIT " . CHUNK_SIZE . " OFFSET {$offset}"
        )->fetchAll();

        if (empty($rows)) {
            break;
        }

        $columns     = '`' . implode('`, `', array_keys($rows[0])) . '`';
        $valueGroups = [];

        foreach ($rows as $row) {
            $vals          = array_map(fn($v) => sqlQuote($pdo, $v), array_values($row));
            $valueGroups[] = '(' . implode(', ', $vals) . ')';
        }

        fwrite($fh, "INSERT INTO `{$table}` ({$columns}) VALUES\n");
        fwrite($fh, implode(",\n", $valueGroups) . ";\n");

        $exported += count($rows);
        $offset   += CHUNK_SIZE;
    }

    return $exported;
}

/**
 * Exécute une requête DELETE de ménage et retourne le nombre de lignes supprimées.
 * Retourne 0 silencieusement si la table n'existe pas.
 */
function cleanupTable(PDO $pdo, string $deleteSql): int
{
    // Extraire le nom de la table depuis "DELETE FROM `table` WHERE ..."
    if (preg_match('/FROM\s+`?(\w+)`?/i', $deleteSql, $m) && !tableExists($pdo, $m[1])) {
        return 0;
    }
    $pdo->exec($deleteSql);
    return (int) $pdo->query("SELECT ROW_COUNT()")->fetchColumn();
}

/**
 * Écrit l'en-tête du fichier SQL de backup.
 *
 * @param resource     $fh
 * @param list<string> $tables
 */
function writeSqlHeader(mixed $fh, string $module, string $db, array $tables, string $date): void
{
    fwrite($fh, "-- cmem2 backup | module: {$module} | date: {$date}\n");
    fwrite($fh, "-- Base: {$db} | Tables: " . count($tables) . "\n\n");
    fwrite($fh, "SET FOREIGN_KEY_CHECKS = 0;\n");
    fwrite($fh, "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;\n");
}

/**
 * Écrit le pied de page du fichier SQL de backup.
 *
 * @param resource $fh
 */
function writeSqlFooter(mixed $fh): void
{
    fwrite($fh, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
}
