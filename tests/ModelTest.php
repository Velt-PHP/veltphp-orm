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
        $this->requireSqlite();

        DB::setManager(new DatabaseManager(new ArrayConfigRepository([
            'database' => [
                'default' => 'sqlite',
                'connections' => [
                    'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
                ],
            ],
        ]), new ConnectionFactory()));

        DB::statement('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL)');
        DB::statement('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, title TEXT NOT NULL)');
        DB::table('users')->insert(['name' => 'Ada', 'email' => 'ada@example.com']);
        DB::table('posts')->insert(['user_id' => 1, 'title' => 'First post']);
        DB::table('posts')->insert(['user_id' => 1, 'title' => 'Second post']);
    }

    protected function tearDown(): void
    {
        DB::clearManager();
        parent::tearDown();
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
}

final class OrmUser extends Model
{
    protected static string $table = 'users';

    protected static array $fillable = ['name', 'email'];

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

    public function user(): ?Model
    {
        return $this->belongsTo(OrmUser::class, 'user_id');
    }
}
