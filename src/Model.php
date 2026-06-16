<?php

declare(strict_types=1);

namespace Velt\Orm;

use JsonSerializable;
use LogicException;
use Velt\Database\DB;

abstract class Model implements JsonSerializable
{
    protected static string $table = '';

    protected static string $primaryKey = 'id';

    /** @var list<string> */
    protected static array $fillable = [];

    /** @var list<string> */
    protected static array $guarded = ['id'];

    /** @var array<string, mixed> */
    protected array $attributes = [];

    /** @var array<string, mixed> */
    protected array $original = [];

    protected bool $exists = false;

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    public static function find(int|string $id): ?static
    {
        return static::where(static::primaryKey(), $id)->first();
    }

    /**
     * @return array<int, static>
     */
    public static function all(): array
    {
        return static::query()->get();
    }

    public static function where(string $column, mixed $operatorOrValue, mixed $value = null): ModelQueryBuilder
    {
        return static::query()->where(...func_get_args());
    }

    public static function query(): ModelQueryBuilder
    {
        return new ModelQueryBuilder(static::class);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();

        return $model;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function hydrate(array $attributes): static
    {
        // Hydration restores database rows as-is; mass assignment rules only apply to user-provided input.
        $model = new static();
        $model->attributes = $attributes;
        $model->original = $attributes;
        $model->exists = true;

        return $model;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable((string) $key)) {
                $this->attributes[(string) $key] = $value;
            }
        }

        return $this;
    }

    public function save(): bool
    {
        if ($this->exists) {
            $key = static::primaryKey();
            $id = $this->attributes[$key] ?? null;

            if ($id === null) {
                throw new LogicException(sprintf('%s cannot be updated without primary key "%s".', static::class, $key));
            }

            // Existing models only write changed attributes, keeping updates small and predictable.
            $dirty = $this->dirtyAttributes();

            if ($dirty === []) {
                return true;
            }

            DB::table(static::tableName())->where($key, $id)->update($dirty);
            $this->original = $this->attributes;

            return true;
        }

        DB::table(static::tableName())->insert($this->attributes);
        $id = DB::connection()->lastInsertId();

        if ($id !== '0' && $id !== '') {
            $this->attributes[static::primaryKey()] = is_numeric($id) ? (int) $id : $id;
        }

        $this->original = $this->attributes;
        $this->exists = true;

        return true;
    }

    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $key = static::primaryKey();
        $id = $this->attributes[$key] ?? null;

        if ($id === null) {
            throw new LogicException(sprintf('%s cannot be deleted without primary key "%s".', static::class, $key));
        }

        DB::table(static::tableName())->where($key, $id)->delete();
        $this->exists = false;

        return true;
    }

    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function setAttribute(string $key, mixed $value): static
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function __get(string $key): mixed
    {
        return $this->getAttribute($key);
    }

    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public static function tableName(): string
    {
        if (static::$table !== '') {
            return static::$table;
        }

        $parts = explode('\\', static::class);
        $name = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', end($parts)));

        return $name . 's';
    }

    public static function primaryKey(): string
    {
        return static::$primaryKey;
    }

    /**
     * @param class-string<Model> $related
     * @return array<int, Model>
     */
    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): array
    {
        $localKey ??= static::primaryKey();
        $foreignKey ??= $this->foreignKey();

        return $related::where($foreignKey, $this->getAttribute($localKey))->get();
    }

    /**
     * @param class-string<Model> $related
     */
    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): ?Model
    {
        $ownerKey ??= $related::primaryKey();
        $foreignKey ??= $this->foreignKeyFor($related);

        return $related::where($ownerKey, $this->getAttribute($foreignKey))->first();
    }

    protected function isFillable(string $key): bool
    {
        // If a model declares fillable fields, only those fields can be mass assigned.
        if (static::$fillable !== []) {
            return in_array($key, static::$fillable, true);
        }

        return !in_array($key, static::$guarded, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function dirtyAttributes(): array
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }

        unset($dirty[static::primaryKey()]);

        return $dirty;
    }

    private function foreignKey(): string
    {
        $parts = explode('\\', static::class);
        $name = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', end($parts)));

        return $name . '_id';
    }

    /**
     * @param class-string<Model> $related
     */
    private function foreignKeyFor(string $related): string
    {
        $parts = explode('\\', $related);
        $name = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', end($parts)));

        return $name . '_id';
    }
}
