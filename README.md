# Velt ORM

Active record ORM, relations and model layer for the Velt PHP framework.

## Role

This package starts in Module 3. It builds on `veltphp/database` and exposes the expressive model API used by applications:

```php
User::find(1);
User::where('email', $email)->first();
$user->save();
```

## Scope

- Active record base model.
- Object hydration and persistence.
- Minimal mass assignment protection.
- Relations such as `hasMany` and `belongsTo`.
- Pagination result objects.

## Boundaries

- SQL primitives, query builder, schema builder, migrations and seeders start in `veltphp/database`.
- CLI commands for migrations and seeders live in `veltphp/cli`.
- Package assembly and compatibility documentation live in `veltphp/framework`.

## Module 3 Issues

- Issue 01: create the active model layer.
- Issue 02: add relations and pagination.

## Current API

```php
use Velt\Orm\Model;

final class User extends Model
{
    protected static string $table = 'users';

    protected static array $fillable = ['name', 'email'];
}

$user = User::find(1);
$user = User::where('email', $email)->first();

$user = new User(['name' => 'Ada', 'email' => 'ada@example.com']);
$user->save();

$user->name = 'Ada Lovelace';
$user->save();

$user->delete();
```

## Active Model Features

- Object hydration from database rows.
- `find`, `all`, `where`, `create`.
- Instance `save` for insert/update.
- Instance `delete`.
- Magic attribute access with `$user->name`.
- Minimal mass assignment protection via `$fillable` and `$guarded`.

## Relations

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

## Pagination

```php
$page = User::query()->orderBy('id')->paginate(page: 1, perPage: 15);

$page->toArray();
```

Serialized shape:

```php
[
    'data' => [...],
    'page' => 1,
    'total' => 50,
    'perPage' => 15,
]
```

## Testing

The ORM tests reuse the PHPUnit installation from `velt-database` in this local workspace:

```powershell
..\velt-database\vendor\bin\phpunit.bat --colors=always --testdox
```

The SQLite integration tests require `pdo_sqlite`. If the extension is not installed, those tests are skipped.
