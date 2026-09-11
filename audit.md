# Audit des changements Data / ORM

Date : 2026-09-11

## Périmètre

L'audit couvre les changements effectués dans `veltphp-orm` et `velt-database` pour le Module 3 Data & ORM. Les changements existants de l'utilisateur n'ont pas été réécrits.

## Changements ORM

### `src/Model.php`

- Ajout de casts explicites `bool`, `int`, `float`, `json`, `date` et `datetime`.
- Application des casts à l'hydratation, aux affectations et à la conversion avant écriture SQL.
- Ajout de `isDirty()` et `getDirty()` pour rendre le dirty tracking observable.
- Ajout de timestamps opt-in avec noms `created_at` et `updated_at` configurables.
- Ajout de `$hidden` et `$visible` pour limiter la sérialisation JSON.
- Prévention des cycles pendant la sérialisation des relations déjà chargées.
- Ajout de `hasOne`.
- Ajout de métadonnées de relations et d'un chargeur eager batché.

Raison : rendre l'hydratation et la persistance prévisibles entre web et Android, éviter les conversions implicites et empêcher qu'une relation eager retombe silencieusement en N+1.

### `src/ModelQueryBuilder.php`

- Ajout de `whereIn()`.
- Ajout de `with()` pour demander un eager loading explicite.
- Chargement des relations en batch après hydratation.
- Pagination exécutée côté SQL avec `COUNT`, `LIMIT` et `OFFSET`.
- Ordre par clé primaire appliqué par défaut quand aucun ordre n'est fourni.

Raison : éviter de charger toute la collection en mémoire, garantir une pagination déterministe et rendre le nombre de requêtes relationnelles borné par relation plutôt que par modèle.

### `tests/ModelTest.php`

- Tests de casts, dirty tracking et champs cachés.
- Test des relations eager `hasMany` et `belongsTo`.
- Configuration par `ORM_DB_DRIVER`, `ORM_DB_HOST`, `ORM_DB_PORT`, `ORM_DB_DATABASE`, `ORM_DB_USERNAME` et `ORM_DB_PASSWORD`.
- DDL adapté à SQLite, MySQL et PostgreSQL.
- Nettoyage des tables entre les tests pour isoler les exécutions sur les bases persistantes.

### CI et benchmark

- `.github/workflows/tests.yml` ajoute une matrice PHP 8.2/8.3/8.4 et SQLite/MySQL/PostgreSQL avec services réels.
- `benchmarks/hydration.php` mesure le temps d'hydratation, la mémoire allouée et le pic mémoire pour une taille de liste donnée.

## Changements Database

### `src/DB.php`

- Les transactions imbriquées utilisent des savepoints nommés.
- Une erreur dans une transaction interne revient au savepoint sans annuler automatiquement la transaction externe.
- Le niveau de transaction est nettoyé après commit ou rollback.

Raison : fournir un rollback fiable pour les services ORM et les transactions métier imbriquées.

### `src/Migrations/Migrator.php`

- Un batch de migrations est exécuté dans une transaction.
- Une migration enregistrée mais absente du système de fichiers est refusée explicitement.
- Les migrations échouées restent rejouables.

### `src/Exceptions/MigrationException.php`

- Ajout d'une exception typée pour les historiques de migrations divergents.

### `src/Query/QueryBuilder.php`

- Ajout de `whereIn()` avec placeholders préparés.
- Ajout de `offset()` et `count()` pour la pagination et l'eager loading.
- Les identifiants restent validés/cités et les valeurs restent bindées.

### Tests et documentation Database

- Tests de savepoints imbriqués.
- Tests de rollback atomique d'un batch de migrations.
- Test de détection d'un fichier de migration absent.
- Documentation d'architecture mise à jour.
- `DatabaseServiceProviderTest` aligné sur le contrat actuel `ApplicationInterface` et `RuntimeInterface` du Kernel.

## Raisons générales

1. Corriger les comportements qui dépendaient d'un état implicite ou d'un découpage PHP non borné.
2. Rendre les erreurs de migration et les relations mal déclarées visibles plutôt que silencieuses.
3. Préserver les frontières : PDO et SQL restent dans `velt-database`, les modèles et relations restent dans `veltphp-orm`.
4. Éviter les valeurs utilisateur concaténées au SQL.
5. Préparer une preuve CI reproductible sur les trois moteurs supportés.

## Vérifications effectuées

Commandes exécutées avec succès :

```text
composer validate --strict                 # veltphp-orm
php -l veltphp-orm/src/Model.php
php -l veltphp-orm/src/ModelQueryBuilder.php
php -l veltphp-orm/tests/ModelTest.php
php -l velt-database/src/Query/QueryBuilder.php
git -C veltphp-orm diff --check
git -C velt-database diff --check
```

Résultats PHPUnit observés le 2026-09-11 :

- Premier lancement : `veltphp-orm` s'arrêtait avant PHPUnit car `phpunit` n'était pas installé.
- Premier lancement : `velt-database` obtenait 5 tests passants et 25 erreurs car `vendor/velt/kernel` était une jonction cassée vers un ancien chemin.
- Correction : Composer a réinstallé `velt/kernel v0.1.1`, le fake `ApplicationInterface` a été aligné sur le contrat actuel et le bootstrap ORM charge le source Database du workspace quand il est disponible.
- Résultat final : `veltphp-orm` passe 7 tests et 26 assertions ; `velt-database` passe 30 tests et 64 assertions.

La workflow CI ajoute la commande suivante sur chaque combinaison de matrice :

```text
composer install --no-interaction --no-progress --prefer-dist
composer validate --strict
composer test
```

Benchmark local exécuté : `php benchmarks/hydration.php 10000` a mesuré environ
24 ms, 10 Mo alloués et un pic de 12 Mo sur PHP 8.4.21. Ces chiffres sont
indicatifs de cette machine et servent de point de comparaison, pas de seuil
portable.

## Vérifications non couvertes localement

Les limites suivantes restent hors de la preuve locale :

- `pdo_pgsql` n'est pas installé localement ;
- MySQL/PostgreSQL n'ont pas été démarrés localement ;
- les tests multi-moteurs doivent être confirmés par la matrice CI.

La CI doit confirmer les tests comportementaux sur les trois moteurs. Aucun contournement TLS, fake de production ou fallback silencieux n'a été utilisé.

## Limites connues

- La détection automatique de N+1 pour les accès lazy n'est pas implémentée ; `with()` exige des métadonnées explicites.
- Les relations many-to-many et les cascades ORM ne sont pas stabilisées.
- Le benchmark fournit une mesure de régression, mais aucun seuil universel n'est fixé entre machines.
- Les tests Android, process kill/restart, APK/AAB, SBOM, provenance et rotation des secrets appartiennent encore aux gates native/release et ne sont pas prouvés par ce changement.

## Conclusion

Les contrats locaux d'hydratation, de transaction, de migration, de pagination et d'eager loading sont renforcés. La validation de release reste conditionnée au passage de la matrice CI et aux gates Android/release mentionnées ci-dessus.
