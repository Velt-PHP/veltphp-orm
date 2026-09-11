# Velt ORM

Velt ORM fournit la couche Active Record du framework Velt. Il transforme les lignes retournées par `velt/database` en objets métier persistables, expose une API de requête orientée modèle, prend en charge les relations essentielles et retourne des résultats paginés sérialisables.

Le package privilégie une surface réduite et lisible. Il ne réimplémente pas PDO, les migrations ou le compilateur SQL : ces responsabilités appartiennent à [`velt/database`](https://github.com/Velt-PHP/velt-database).

> Statut : préversion. Les casts, la pagination SQL et la sérialisation de base sont disponibles ; l’eager loading batché, les événements et la matrice multi-SGBD restent à stabiliser avant `1.0`.

## Installation

```bash
composer require velt/orm
```

Prérequis : PHP 8.2 ou supérieur, `ext-pdo`, une connexion configurée par `velt/database` et le pilote PDO correspondant à la base utilisée.

## Premier modèle

```php
<?php

namespace App\Users\Models;

use Velt\Orm\Model;

final class User extends Model
{
    protected static string $table = 'users';

    protected static array $relations = [
        'posts' => [
            'type' => 'hasMany',
            'related' => Post::class,
            'foreignKey' => 'user_id',
        ],
    ];

    protected static array $fillable = [
        'name',
        'email',
    ];
}
```

Pour charger cette relation en batch, utilisez `with()`. Le builder exécute
une requête liée `IN (...)` pour la collection, puis rattache les modèles sans
exécuter la méthode de relation pour chaque ligne :

```php
$users = User::query()->with('posts')->get();
```

Les relations demandées par `with()` doivent avoir une entrée dans
`$relations`; l’ORM lève une `LogicException` si elle manque au lieu de
retomber silencieusement sur un N+1. `hasOne` et `belongsTo` utilisent la même
forme avec `type` correspondant et, si nécessaire, `ownerKey` ou `localKey`.

```php
$user = User::find(1);
$users = User::all();
$active = User::where('active', true)->get();

$ada = User::create([
    'name' => 'Ada Lovelace',
    'email' => 'ada@example.com',
]);

$ada->name = 'Ada';
$ada->save();
$ada->delete();
```

## Responsabilités et frontières

| Couche | Responsabilité |
| --- | --- |
| `velt/database` | connexions PDO, requêtes préparées, query builder, schéma, migrations, seeders |
| `velt/orm` | hydratation, identité du modèle, persistance, relations, pagination |
| `velt/cli` | génération de modèles et commandes de migration |
| application | règles métier, validation d’entrée, autorisation et transactions métier |

L’ORM ne doit jamais accéder directement aux variables globales HTTP, générer des réponses ou prendre une décision d’autorisation.

## Lecture des données

### Recherche par clé primaire

```php
$user = User::find(42);

if ($user === null) {
    // Le modèle n’existe pas.
}
```

### Requête par attribut

```php
$user = User::where('email', 'ada@example.com')->first();

$users = User::query()
    ->where('active', 1)
    ->orderBy('created_at', 'desc')
    ->limit(25)
    ->get();
```

`ModelQueryBuilder` adapte le query builder de la couche Database et hydrate chaque résultat dans la classe de modèle appelée.

## Création et mise à jour

```php
$user = new User([
    'name' => 'Grace Hopper',
    'email' => 'grace@example.com',
]);

$user->save();

$user->name = 'Rear Admiral Grace Hopper';
$user->save();
```

Lors du premier `save()`, l’ORM insère le modèle. Lorsque sa clé primaire est connue, il met à jour la ligne correspondante. L’application doit entourer les opérations multiples d’une transaction fournie par la couche Database.

## Protection des attributs

```php
final class User extends Model
{
    protected static string $table = 'users';

    protected static array $fillable = ['name', 'email'];

    protected static array $guarded = ['id', 'is_admin'];
}
```

La protection de masse est une barrière de programmation, pas une politique d’autorisation. Le fait qu’un attribut soit remplissable ne signifie jamais que l’utilisateur courant a le droit de le modifier. Les payloads entrants doivent être validés et autorisés avant de parvenir au modèle.

## Attributs et sérialisation

Les attributs hydratés sont accessibles avec la syntaxe de propriété :

```php
echo $user->name;
$user->email = 'new@example.com';
```

Les casts sont déclarés explicitement et s’appliquent à l’hydratation comme à la
persistance :

```php
protected static array $casts = [
    'active' => 'bool',
    'score' => 'float',
    'settings' => 'json',
    'published_at' => 'datetime',
];
```

Les types supportés sont `bool`, `int`, `float`, `json`, `date` et `datetime`.
`isDirty()` et `getDirty()` permettent d’observer les modifications avant un
`save()`. Les timestamps sont opt-in avec `protected static bool $timestamps = true`
et utilisent `created_at`/`updated_at` par défaut; les noms sont personnalisables.
`$hidden` exclut des champs de `toArray()`, tandis que `$visible` définit une
liste blanche. La sérialisation des relations déjà chargées coupe les cycles.

Pour une API, sérialisez uniquement les champs que le contrat public autorise. Ne retournez pas mécaniquement tous les attributs d’une table contenant mots de passe, jetons ou informations personnelles.

## Relations

### Relation un-à-plusieurs

```php
final class User extends Model
{
    protected static string $table = 'users';

    public function posts(): array
    {
        return $this->hasMany(Post::class, 'user_id');
    }
}
```

### Relation inverse

```php
final class Post extends Model
{
    protected static string $table = 'posts';

    public function author(): ?Model
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
```

Les relations actuelles sont chargées explicitement. Le chargement anticipé
batché est disponible pour `hasMany`, `hasOne` et `belongsTo`. Les relations
plusieurs-à-plusieurs et la détection automatique des accès lazy restent à
stabiliser; une collection importante doit donc être mesurée.

## Pagination

```php
$page = User::query()
    ->orderBy('id')
    ->paginate(page: 1, perPage: 15);

$payload = $page->toArray();
```

Forme sérialisée :

```php
[
    'data' => [...],
    'page' => 1,
    'total' => 50,
    'perPage' => 15,
]
```

L’application doit imposer une limite maximale à `perPage` lorsqu’elle accepte ce paramètre depuis une requête publique.

## Architecture interne

```text
src/
  Model.php                    cycle de vie Active Record
  ModelQueryBuilder.php        requêtes et hydratation typée
  Pagination/
    Paginator.php              résultat paginé sérialisable
```

Le flux principal est :

```text
Model::query()
    -> QueryBuilder de velt/database
    -> PDO avec paramètres liés
    -> ligne associative
    -> hydratation Model
    -> objet ou collection applicative
```

Les détails sont présentés dans [`docs/orm-architecture.md`](docs/orm-architecture.md).

## Configuration de la connexion

L’ORM utilise la connexion active de `velt/database`. Dans une application Velt standard, le provider Database initialise cette connexion depuis la configuration :

```env
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite
```

Pour MySQL ou PostgreSQL, installez l’extension PDO appropriée et utilisez les variables d’environnement du skeleton. Les secrets ne doivent pas être committés dans `.env.example` ou dans les fixtures.

## Transactions

Une opération métier qui écrit plusieurs modèles doit rester atomique :

```php
DB::transaction(function () use ($payload): void {
    $user = User::create($payload['user']);
    Profile::create(['user_id' => $user->id] + $payload['profile']);
});
```

Si la méthode transactionnelle n’est pas disponible dans la version de `velt/database` installée, utilisez explicitement la connexion PDO. Une future API ne doit pas simuler l’atomicité en masquant une absence de transaction.

## Erreurs et observabilité

- une erreur SQL reste une exception et ne doit pas être convertie silencieusement en liste vide ;
- les logs peuvent inclure la durée et le nom logique de la requête, jamais les secrets liés ;
- une recherche sans résultat retourne `null` lorsque l’API le documente ;
- la couche HTTP décide ensuite de produire un `404`, pas l’ORM ;
- les erreurs de schéma doivent être visibles pendant le développement.

## Tests

```bash
composer install
composer validate --strict
composer test
```

La suite SQLite nécessite `pdo_sqlite`. Un test ignoré faute d’extension n’est pas une validation de cette base : la CI de release doit disposer du pilote et exécuter réellement le scénario.

La matrice cible couvre :

- PHP 8.2, 8.3 et 8.4 ;
- création, lecture, mise à jour et suppression ;
- valeurs nulles, booléens, dates et identifiants ;
- protection `fillable`/`guarded` ;
- relations avec résultat vide ou clé absente ;
- pagination aux bornes ;
- rollback transactionnel ;
- SQLite, MySQL et PostgreSQL dans les tests d’intégration.

Les tests multi-moteurs utilisent `ORM_DB_DRIVER` (`sqlite`, `mysql` ou
`pgsql`) et, pour une base distante, `ORM_DB_HOST`, `ORM_DB_PORT`,
`ORM_DB_DATABASE`, `ORM_DB_USERNAME` et `ORM_DB_PASSWORD`. Exemple :

```bash
$env:ORM_DB_DRIVER = 'mysql'
$env:ORM_DB_HOST = '127.0.0.1'
$env:ORM_DB_PORT = '3306'
$env:ORM_DB_DATABASE = 'velt_test'
$env:ORM_DB_USERNAME = 'root'
$env:ORM_DB_PASSWORD = 'password'
vendor/bin/phpunit -c phpunit.xml
```

Un pilote absent est marqué comme skip par la suite; la preuve de release doit
exécuter chaque variante sur un runner disposant réellement du pilote et du
serveur correspondant.

## Performance

Active Record privilégie la commodité. Pour des imports massifs ou agrégations complexes, le query builder de `velt/database` est souvent plus approprié. Toute optimisation doit être étayée par un benchmark reproductible et conserver les requêtes préparées.

Le benchmark d’hydratation mesure le temps et la mémoire d’une liste de taille
contrôlée :

```bash
php benchmarks/hydration.php 10000
```

Il doit être exécuté avec les versions PHP et dépendances de la CI de release.
Les valeurs sont des observations de régression, pas un seuil universel entre
machines différentes.

## Sécurité

- ne concaténez jamais une valeur utilisateur dans un fragment SQL ;
- validez les identifiants dynamiques avec les primitives Database ;
- appliquez validation et autorisation avant l’affectation ;
- masquez les attributs sensibles dans les ressources API ;
- utilisez un compte de base de données aux privilèges minimaux ;
- signalez une vulnérabilité sans publier immédiatement un exploit.

## Compatibilité et versionnement

Le package suit SemVer. Avant `1.0`, toute préversion peut encore ajuster son API, mais un tag publié doit rester reproductible. `velt/framework` documente la combinaison de versions supportée entre Kernel, Database, ORM et Skeleton.

## Limites avant une version stable

- événements de modèle et observers absents ;
- détection automatique des accès lazy et relations many-to-many absentes ;
- relations many-to-many non stabilisées ;
- matrice MySQL/PostgreSQL à automatiser en CI.

## Contribution

Toute modification doit inclure un test de régression et préserver la séparation avec `velt/database`. Pour un changement public, documentez l’exemple, l’exception attendue, l’impact de compatibilité et la migration nécessaire. Les travaux planifiés sont suivis par les issues et milestones du dépôt.

## Licence

Velt ORM est distribué sous licence MIT.
