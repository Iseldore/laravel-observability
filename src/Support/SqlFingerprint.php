<?php

namespace Iseldore\Observability\Support;

/**
 * Normalise une requête SQL pour pouvoir regrouper des exécutions "identiques à la valeur près".
 *
 * Les `IN (?, ?, … )` d'Eloquent changent de taille d'un appel à l'autre : sans normalisation,
 * chaque taille de liste est une requête distincte et rien n'est comptable.
 */
class SqlFingerprint
{
    /**
     * @return string SQL sans littéraux ni listes de valeurs, espaces compactés.
     */
    public static function make(string $sql): string
    {
        // Littéraux chaîne ('…' avec échappements \' ou ''), puis nombres isolés. Les
        // identifiants (`extra_1`, table2) ne sont pas touchés : pas de frontière de mot
        // entre une lettre/underscore et un chiffre.
        $normalized = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", '?', $sql);
        $normalized = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', (string) $normalized);

        // IN (?, ?, ?) → IN (?+)
        $normalized = preg_replace('/\bin\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', 'in (?+)', (string) $normalized);

        // INSERT … VALUES (?, ?), (?, ?), … → une seule ligne de valeurs
        $normalized = preg_replace(
            '/(\(\s*\?(?:\s*,\s*\?)*\s*\))(?:\s*,\s*\(\s*\?(?:\s*,\s*\?)*\s*\))+/',
            '$1',
            (string) $normalized
        );

        return trim((string) preg_replace('/\s+/', ' ', (string) $normalized));
    }

    public static function hash(string $fingerprint): string
    {
        return md5($fingerprint);
    }
}
