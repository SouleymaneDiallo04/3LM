# ADR 0001 — Laravel 13 au lieu de Laravel 12

- **Date** : 2026-07-08
- **Statut** : accepté (validé par le maître de stage le 08/07/2026)

## Contexte

Le cahier des charges (§5.2) et la note de cadrage France spécifient
« Laravel 12 / PHP 8.4 ». Au moment de l'initialisation du dépôt
(juillet 2026), la version stable courante du framework est **Laravel 13**
(`laravel/framework ^13.8`) : c'est elle qu'installe `laravel new`, elle
bénéficie du cycle de support le plus long, et l'écosystème requis par le
projet (Sanctum, Horizon, Scout, spatie/laravel-permission) la supporte.

## Options étudiées

1. **Laravel 13** (scaffold actuel) — version courante, support prolongé,
   aucune migration à prévoir en cours de projet.
2. **Rétrograder vers Laravel 12** — conformité littérale au CDC, mais
   travail à rebours sans gain fonctionnel et fin de support plus proche.

## Décision

Conserver **Laravel 13**. La mention « Laravel 12 » du CDC est lue comme
« la version stable courante du framework à l'ouverture du chantier »,
l'esprit de l'exigence (§5.2 : queues, notifications, scheduler et auth
natifs) étant intégralement couvert.

## Conséquences

- `composer.json` : `laravel/framework ^13.8`, PHP ≥ 8.3 (image Docker en 8.4).
- Toute documentation livrée mentionne Laravel 13.
- Aucun impact sur les exigences fonctionnelles ni sur les jalons.
