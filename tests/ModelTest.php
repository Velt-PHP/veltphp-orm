<?php

declare(strict_types=1);

namespace Velt\Orm\Tests;

use PHPUnit\Framework\TestCase;
use Velt\Database\ConnectionFactory;
use Velt\Database\DatabaseManager;
use Velt\Database\DB;
use Velt\Orm\Model;
use Velt\Orm\Tests\Fakes\ArrayConfigRepository;

final class ModelTest extends TestCase
{
    use RequiresSqlite;

    protected function setUp(): void
    {
        parent::setUp();
        $driver = getenv('ORM_DB_DRIVER') ?: 'sqlite';
        $this->requireDriver($driver);

        $connection = match ($driver) {
            'mysql', 'pgsql' => [
                'driver' => $driver,
                'host' => getenv('ORM_DB_HOST') ?: '127.0.0.1',
                'port' => getenv('ORM_DB_PORT') ?: ($driver === 'mysql' ? 3306 : 5432),
                'database' => getenv('ORM_DB_DATABASE') ?: 'velt_test',
                'username' => getenv('ORM_DB_USERNAME') ?: ($driver === 'mysql' ? 'root' : 'postgres'),
                'password' => getenv('ORM_DB_PASSWORD') ?: 'password',
            ],
            default => ['driver' => 'sqlite', 'database' => ':memory:'],
        };

        DB::setManager(new DatabaseManager(new ArrayConfigRepository([
            'database' => [
                'default' => $driver,
                'connections' => [
                    $driver => $connection,
                ],
            ],
        ]), new ConnectionFactory()));

        $id = match ($driver) {
            'mysql' => 'INT AUTO_INCREMENT PRIMARY KEY',
            'pgsql' => 'SERIAL PRIMARY KEY',
            default => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        };
        DB::statement("CREATE TABLE users (id {$id}, name VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL)");
        DB::statement("CREATE TABLE posts (id {$id}, user_id INTEGER NOT NULL, title VARCHAR(255) NOT NULL)");
        DB::statement('CREATE TABLE profiles (id INTEGER PRIMARY KEY, active INTEGER NOT NULL, score DOUBLE PRECISION NOT NULL, settings TEXT, secret TEXT, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL)');
        DB::table('users')->insert(['name' => 'Ada', 'email' => 'ada@example.com']);
        DB::table('posts')->insert(['user_id' => 1, 'title' => 'First post']);
        DB::table('posts')->insert(['user_id' => 1, 'title' => 'Second post']);
    }

    protected function tearDown(): void
    {
        foreach (['posts', 'profiles', 'users'] as $table) {
            DB::statement('DROP TABLE IF EXISTS ' . $table);
        }
        DB::clearManager();
        parent::tearDown();
    }

    protected function requireDriver(string $driver): void
    {
        $extension = match ($driver) {
            'mysql' => 'pdo_mysql',
            'pgsql' => 'pdo_pgsql',
            default => 'pdo_sqlite',
        };

        if (!extension_loaded($extension)) {
            self::markTestSkipped(sprintf('The %s extension is required for this test.', $extension));
        }
    }

    public function test_find_where_all_and_hydration(): void
    {
        $user = OrmUser::find(1);
        $sameUser = OrmUser::where('email', 'ada@example.com')->first();
        $users = OrmUser::all();

        self::assertInstanceOf(OrmUser::class, $user);
        self::assertSame('Ada', $user->name);
        self::assertInstanceOf(OrmUser::class, $sameUser);
        self::assertCount(1, $users);
    }

    public function test_save_creates_and_updates_model(): void
    {
        $user = new OrmUser(['name' => 'Grace', 'email' => 'grace@example.com', 'admin' => true]);
        $user->save();

        self::assertIsInt($user->id);
        self::assertNull($user->admin);

        $user->name = 'Grace Hopper';
        $user->save();

        self::assertSame('Grace Hopper', OrmUser::find($user->id)->name);
    }

    public function test_delete_removes_model(): void
    {
        $user = OrmUser::find(1);

        self::assertTrue($user->delete());
        self::assertNull(OrmUser::find(1));
    }

    public function test_has_many_and_belongs_to_relations(): void
    {
        $user = OrmUser::find(1);
        $posts = $user->posts();
        $post = OrmPost::find(1);

        self::assertCount(2, $posts);
        self::assertSame('First post', $posts[0]->title);
        self::assertSame('Ada', $post->user()->name);
    }

    public function test_with_eager_loads_has_many_and_belongs_to_in_batches(): void
    {
        $users = OrmUser::query()->with('posts')->get();
        $posts = OrmPost::query()->with('user')->get();

        self::assertCount(2, $users[0]->posts);
        self::assertSame('Ada', $posts[0]->user->name);
    }

    public function test_paginate_returns_serializable_result(): void
    {
        OrmUser::create(['name' => 'Grace', 'email' => 'grace@example.com']);
        OrmUser::create(['name' => 'Linus', 'email' => 'linus@example.com']);

        $page = OrmUser::query()->orderBy('id')->paginate(page: 1, perPage: 2);
        $payload = $page->toArray();

        self::assertCount(2, $payload['data']);
        self::assertSame(1, $payload['page']);
        self::assertSame(3, $payload['total']);
        self::assertSame(2, $payload['perPage']);
        self::assertSame($payload, $page->jsonSerialize());
    }

    public function test_casts_dirty_tracking_and_hidden_attributes_are_explicit(): void
    {
        DB::table('profiles')->insert([
            'id' => 1,
            'active' => 1,
            'score' => 12.5,
            'settings' => json_encode(['offline' => true]),
            'secret' => 'token',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $profile = OrmProfile::find(1);

        self::assertIsBool($profile->active);
        self::assertIsFloat($profile->score);
        self::assertSame(['offline' => true], $profile->settings);
        self::assertFalse($profile->isDirty());

        $profile->settings = ['offline' => false];
        self::assertTrue($profile->isDirty('settings'));
        self::assertArrayNotHasKey('secret', $profile->toArray());
        self::assertSame('token', $profile->getAttribute('secret'));
    }
}

final class OrmUser extends Model
{
    protected static string $table = 'users';

    protected static array $fillable = ['name', 'email'];

    /** @var array<string, array{type:string,related:class-string<Model>,foreignKey:string,localKey?:string}> */
    protected static array $relations = [
        'posts' => ['type' => 'hasMany', 'related' => OrmPost::class, 'foreignKey' => 'user_id'],
    ];

    /**
     * @return array<int, Model>
     */
    public function posts(): array
    {
        return $this->hasMany(OrmPost::class, 'user_id');
    }
}

final class OrmPost extends Model
{
    protected static string $table = 'posts';

    protected static array $fillable = ['user_id', 'title'];

    /** @var array<string, array{type:string,related:class-string<Model>,foreignKey:string,ownerKey?:string}> */
    protected static array $relations = [
        'user' => ['type' => 'belongsTo', 'related' => OrmUser::class, 'foreignKey' => 'user_id'],
    ];

    public function user(): ?Model
    {
        return $this->belongsTo(OrmUser::class, 'user_id');
    }
}

final class OrmProfile extends Model
{
    protected static string $table = 'profiles';

    /** @var array<string, string> */
    protected static array $casts = [
        'active' => 'bool',
        'score' => 'float',
        'settings' => 'json',
        'created_at' => 'datetime',
    ];

    /** @var list<string> */
    protected static array $hidden = ['secret'];
}
