# CLAUDE.md — laravel-observability

## Compatibilité PHP

Ce package cible `php: ">=7.3"` (voir `composer.json`). Attention à ne pas utiliser de syntaxe indisponible en 7.3 :

- Pas d'arrow functions `fn () => ...` (PHP 7.4+) — utiliser des closures classiques `function () { return ...; }`
- Pas de typed properties (PHP 7.4+)
- Pas de null coalescing assignment `??=` (PHP 7.4+)
- Pas d'arguments nommés (PHP 8.0+), match expression (8.0+), nullsafe operator `?->` (8.0+), enums (8.1+), readonly properties (8.1+), first-class callable syntax (8.1+)

La CI fait tourner les tests sur plusieurs versions de PHP (dont 7.3) — une syntaxe trop récente casse le build avec une `ParseError` peu explicite plutôt qu'une erreur de test claire.
