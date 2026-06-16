# Architecture du composant Velt ORM

Ce document decrit l'architecture actuelle de `veltphp-orm`, les fichiers importants, leurs roles, et leur importance dans le module Data/ORM.

## Objectif du package

`veltphp-orm` fournit la couche Active Record du framework Velt.

Il s'appuie sur `velt-database` pour :

- les connexions PDO ;
- la facade `DB` ;
- le query builder SQL ;
- les requetes preparees.

Le package ORM ne gere pas :

- les migrations ;
- le schema builder ;
- les seeders ;
- les commandes CLI.

Ces responsabilites restent dans `velt-database` et `veltphp-cli`.

## API cible couverte

```php
User::find(1);
User::where('email', $email)->first();

$user = new User(['name' => 'Ada', 'email' => 'ada@example.com']);
$user->save();
$user->delete();
```

Relations et pagination :

```php
$posts = $user->posts();
$author = $post->user();

$page = User::query()->orderBy('id')->paginate(page: 1, perPage: 15);
$page->toArray();
```

## Architecture globale

```text
veltphp-orm/
├── composer.json
├── phpunit.xml
├── README.md
├── docs/
│   └── orm-architecture.md
├── src/
│   ├── Model.php
│   ├── ModelQueryBuilder.php
│   └── Pagination/
│       └── Paginator.php
└── tests/
    ├── bootstrap.php
    ├── RequiresSqlite.php
    ├── ModelTest.php
    └── Fakes/
        └── ArrayConfigRepository.php
```

## Flux d'execution

```text
Application Velt
    │
    ▼
velt-database configure DB::setManager()
    │
    ▼
Velt\Orm\Model
    │
    ├── find / all / where
    │       │
    │       ▼
    │   ModelQueryBuilder
    │       │
    │       ▼
    │   Velt\Database\DB::table()
    │       │
    │       ▼
    │   QueryBuilder database + PDO
    │
    ├── hydrate()
    ├── save()
    ├── delete()
    ├── hasMany()
    └── belongsTo()
```

## Dependances

### `composer.json`

Role :

- declare le package `velt/orm` ;
- expose le namespace `Velt\Orm\` ;
- depend de `velt/database`.

Importance :

- l'ORM reste separe du package database ;
- le code SQL reste centralise dans `velt-database` ;
- les apps peuvent installer l'ORM seulement quand elles en ont besoin.

## Fichiers source

### `src/Model.php`

Role :

- classe Active Record de base ;
- hydrate les lignes SQL en objets ;
- persiste les objets ;
- protege les champs mass assignable ;
- expose les relations simples.

Responsabilites principales :

```php
User::find(1);
User::all();
User::where('email', $email)->first();
User::create([...]);

$user->fill([...]);
$user->save();
$user->delete();
$user->toArray();
```

Proprietes importantes :

```php
protected static string $table = '';
protected static string $primaryKey = 'id';
protected static array $fillable = [];
protected static array $guarded = ['id'];
```

Importance :

- `Model` donne l'API developer-friendly ;
- il transforme les arrays database en objets ;
- il garde un snapshot `$original` pour detecter les champs modifies ;
- il empeche l'assignation massive des champs non autorises.

### Hydratation

```php
User::hydrate($row);
```

L'hydratation :

- cree un objet sans passer par `fill()` ;
- copie les attributs depuis la base ;
- marque le modele comme existant ;
- initialise `$original`.

Pourquoi ne pas utiliser `fill()` ici :

- `fill()` applique la protection `$fillable` ;
- les donnees venant de la base doivent etre restaurees completement.

### Sauvegarde

`save()` gere deux cas :

- modele nouveau : `INSERT` ;
- modele existant : `UPDATE` seulement des champs modifies.

Le dirty tracking vient de :

```php
$attributes
$original
```

Quand un champ change, il apparait dans `dirtyAttributes()`.

### Suppression

```php
$user->delete();
```

Le modele utilise la primary key pour supprimer la ligne.

Si le modele n'existe pas encore, `delete()` retourne `false`.

### Relations

Relations supportees :

- `hasMany`
- `belongsTo`

Exemple :

```php
final class User extends Model
{
    protected static string $table = 'users';

    public function posts(): array
    {
        return $this->hasMany(Post::class, 'user_id');
    }
}

final class Post extends Model
{
    protected static string $table = 'posts';

    public function user(): ?Model
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
```

Importance :

- couvre les relations essentielles ;
- garde une API simple ;
- laisse la porte ouverte a des objets `Relation` plus avances plus tard.

### `src/ModelQueryBuilder.php`

Role :

- adapter le query builder database au monde ORM ;
- retourner des objets `Model` au lieu de tableaux ;
- fournir `first`, `get`, `paginate`.

API :

```php
User::query()->where('email', $email)->first();
User::query()->orderBy('id')->get();
User::query()->paginate(page: 1, perPage: 15);
```

Importance :

- separe la construction de requete ORM de la classe `Model` ;
- evite de dupliquer le SQL ;
- centralise l'hydratation des resultats.

### Pagination actuelle

La pagination retourne :

```php
[
    'data' => [...],
    'page' => 1,
    'total' => 50,
    'perPage' => 15,
]
```

Note technique :

- l'implementation actuelle compte via une requete simple puis coupe les resultats en PHP ;
- c'est acceptable pour le MVP ;
- une evolution devra ajouter `offset()` dans `velt-database` pour paginer directement en SQL.

### `src/Pagination/Paginator.php`

Role :

- transporter les resultats pagines ;
- exposer `toArray()` ;
- implementer `JsonSerializable`.

Importance :

- structure stable pour les APIs ;
- serialisable facilement en JSON ;
- utilisable par HTTP/API plus tard.

## Tests

### `tests/bootstrap.php`

Role :

- charger `vendor/autoload.php` du repo ORM si disponible ;
- sinon reutiliser l'autoload de `velt-database` dans le workspace local.

Importance :

- permet de tester le package meme si Composer est bloque localement ;
- garde les tests utilisables en CI avec `vendor/autoload.php`.

### `tests/RequiresSqlite.php`

Role :

- ignorer proprement les tests demandant `pdo_sqlite` si l'extension manque.

Importance :

- evite les erreurs `PDOException: could not find driver` ;
- rend la suite stable sur les machines sans SQLite.

### `tests/ModelTest.php`

Role :

- couvre l'API active model ;
- couvre relations ;
- couvre pagination ;
- couvre mass assignment.

Cas verifies :

- `find`, `where`, `all` ;
- hydratation en objet ;
- `save` insert/update ;
- `delete` ;
- `$fillable` ;
- `hasMany` ;
- `belongsTo` ;
- `paginate`.

## Limitations actuelles

Le MVP ne couvre pas encore :

- eager loading ;
- lazy relation objects ;
- scopes ;
- events model ;
- casts ;
- timestamps automatiques ;
- offset SQL natif ;
- composite primary keys ;
- transactions au niveau model.

## Evolutions recommandees

1. Ajouter `offset()` dans `velt-database\QueryBuilder`.
2. Modifier `ModelQueryBuilder::paginate()` pour utiliser `LIMIT/OFFSET`.
3. Creer des objets `Relation` pour `hasMany` et `belongsTo`.
4. Ajouter `created_at` / `updated_at` automatiques.
5. Ajouter casts simples (`int`, `bool`, `datetime`).
6. Ajouter hooks `creating`, `created`, `updating`, `updated`, `deleting`, `deleted`.

## Commandes utiles

Depuis `veltphp-orm` :

```powershell
..\velt-database\vendor\bin\phpunit.bat --colors=always --testdox
composer validate --no-check-publish
```

Si `vendor/` existe localement dans `veltphp-orm` :

```powershell
vendor\bin\phpunit.bat --colors=always --testdox
```
