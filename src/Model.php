<?php

declare(strict_types=1);

namespace Velt\Orm;

use JsonSerializable;
use LogicException;
use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use Velt\Database\DB;

abstract class Model implements JsonSerializable
{
    protected static string $table = '';

    protected static string $primaryKey = 'id';

    /** @var list<string> */
    protected static array $fillable = [];

    /** @var list<string> */
    protected static array $guarded = ['id'];

    /** @var array<string, string> */
    protected static array $casts = [];

    protected static bool $timestamps = false;

    protected static string $createdAt = 'created_at';

    protected static string $updatedAt = 'updated_at';

    /** @var list<string> */
    protected static array $hidden = [];

    /** @var list<string> */
    protected static array $visible = [];

    /** @var array<string, array{type:string,related:class-string<Model>,foreignKey:string,localKey?:string,ownerKey?:string}> */
    protected static array $relations = [];

    /** @var array<string, mixed> */
    protected array $attributes = [];

    /** @var array<string, mixed> */
    protected array $original = [];

    protected bool $exists = false;

    /** @var array<string, mixed> */
    /** @var array<string, mixed> */
    protected array $loadedRelations = [];

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        // Le constructeur applique la protection mass assignment aux donnees utilisateur.
        $this->fill($attributes);
    }

    public static function find(int|string $id): ?static
    {
        // find est un raccourci explicite autour de la primary key configuree du modele.
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
        // Le builder ORM encapsule le query builder database et hydrate les resultats en objets.
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
        // L'hydratation restaure les lignes database telles quelles; fillable ne s'applique qu'aux entrees utilisateur.
        $model = new static();
        $model->attributes = $model->castAttributes($attributes);
        $model->original = $model->attributes;
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
                $this->setAttribute((string) $key, $value);
            }
        }

        return $this;
    }

    public function save(): bool
    {
        $this->touchTimestamps();

        if ($this->exists) {
            $key = static::primaryKey();
            $id = $this->attributes[$key] ?? null;

            if ($id === null) {
                throw new LogicException(sprintf('%s cannot be updated without primary key "%s".', static::class, $key));
            }

            // Les modeles existants n'ecrivent que les champs modifies.
            $dirty = $this->dirtyAttributes();

            if ($dirty === []) {
                return true;
            }

            DB::table(static::tableName())->where($key, $id)->update($this->storageAttributes($dirty));
            $this->original = $this->attributes;

            return true;
        }

        DB::table(static::tableName())->insert($this->storageAttributes($this->attributes));
        $id = DB::connection()->lastInsertId();

        if ($id !== '0' && $id !== '') {
            // Quand le driver expose un dernier id, on le reporte sur l'objet actif.
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
        $this->attributes[$key] = $this->castValue($key, $value);

        return $this;
    }

    public function __get(string $key): mixed
    {
        if (array_key_exists($key, $this->loadedRelations)) {
            return $this->loadedRelations[$key];
        }

        return $this->getAttribute($key);
    }

    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(array &$visited = []): array
    {
        $objectId = spl_object_id($this);
        if (isset($visited[$objectId])) {
            return [];
        }

        $visited[$objectId] = true;
        $attributes = $this->attributes;
        if (static::$visible !== []) {
            $attributes = array_intersect_key($attributes, array_flip(static::$visible));
        } else {
            $attributes = array_diff_key($attributes, array_flip(static::$hidden));
        }

        foreach ($this->loadedRelations as $key => $relation) {
            $attributes[$key] = $this->serializeValue($relation, $visited);
        }

        unset($visited[$objectId]);

        return $attributes;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function isDirty(?string $key = null): bool
    {
        $dirty = $this->dirtyAttributes();

        return $key === null ? $dirty !== [] : array_key_exists($key, $dirty);
    }

    /** @return array<string, mixed> */
    public function getDirty(): array
    {
        return $this->dirtyAttributes();
    }

    public function setRelation(string $key, mixed $value): static
    {
        $this->loadedRelations[$key] = $value;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getRelations(): array
    {
        return $this->loadedRelations;
    }

    /**
     * @return array<string, array{type:string,related:class-string<Model>,foreignKey:string,localKey?:string,ownerKey?:string}>
     */
    public static function relationDefinitions(): array
    {
        return static::$relations;
    }

    /**
     * @param list<static> $models
     * @param list<string> $names
     */
    public static function eagerLoad(array $models, array $names): void
    {
        foreach ($names as $name) {
            $definition = static::relationDefinitions()[$name] ?? null;
            if ($definition === null) {
                throw new LogicException(sprintf(
                    'Relation "%s" on %s has no eager-loading definition.',
                    $name,
                    static::class,
                ));
            }

            $related = $definition['related'];
            $localKey = $definition['localKey'] ?? static::primaryKey();
            $foreignKey = $definition['foreignKey'];
            $keys = array_values(array_unique(array_filter(
                array_map(static fn (Model $model): mixed => $model->getAttribute($localKey), $models),
                static fn (mixed $value): bool => $value !== null,
            ), SORT_REGULAR));

            if ($definition['type'] === 'belongsTo') {
                $keys = array_values(array_unique(array_filter(
                    array_map(static fn (Model $model): mixed => $model->getAttribute($foreignKey), $models),
                    static fn (mixed $value): bool => $value !== null,
                ), SORT_REGULAR));
                $ownerKey = $definition['ownerKey'] ?? $related::primaryKey();
                $relatedModels = $keys === [] ? [] : $related::query()->whereIn($ownerKey, $keys)->get();
                $indexed = [];
                foreach ($relatedModels as $relatedModel) {
                    $indexed[(string) $relatedModel->getAttribute($ownerKey)] = $relatedModel;
                }
                foreach ($models as $model) {
                    $model->setRelation($name, $indexed[(string) $model->getAttribute($foreignKey)] ?? null);
                }
                continue;
            }

            $relatedModels = $keys === [] ? [] : $related::query()->whereIn($foreignKey, $keys)->get();
            $grouped = [];
            foreach ($relatedModels as $relatedModel) {
                $grouped[(string) $relatedModel->getAttribute($foreignKey)][] = $relatedModel;
            }
            foreach ($models as $model) {
                $items = $grouped[(string) $model->getAttribute($localKey)] ?? [];
                $model->setRelation($name, $definition['type'] === 'hasOne' ? ($items[0] ?? null) : $items);
            }
        }
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

        // hasMany cherche les lignes dont la foreign key pointe vers la key locale du modele courant.
        return $related::where($foreignKey, $this->getAttribute($localKey))->get();
    }

    /**
     * @param class-string<Model> $related
     */
    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): ?Model
    {
        $localKey ??= static::primaryKey();
        $foreignKey ??= $this->foreignKey();

        return $related::where($foreignKey, $this->getAttribute($localKey))->first();
    }

    /**
     * @param class-string<Model> $related
     */
    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): ?Model
    {
        $ownerKey ??= $related::primaryKey();
        $foreignKey ??= $this->foreignKeyFor($related);

        // belongsTo remonte vers le parent via la foreign key stockee sur le modele courant.
        return $related::where($ownerKey, $this->getAttribute($foreignKey))->first();
    }

    protected function isFillable(string $key): bool
    {
        // Si fillable est defini, seuls ces champs peuvent etre assignes en masse.
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

        // La primary key ne doit jamais etre modifiee par un update ORM.
        unset($dirty[static::primaryKey()]);

        return $dirty;
    }

    private function touchTimestamps(): void
    {
        if (!static::$timestamps) {
            return;
        }

        $now = new DateTimeImmutable();
        if (!$this->exists && !array_key_exists(static::$createdAt, $this->attributes)) {
            $this->setAttribute(static::$createdAt, $now);
        }

        $this->setAttribute(static::$updatedAt, $now);
    }

    /** @param array<string, mixed> $attributes */
    private function castAttributes(array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            $attributes[$key] = $this->castValue((string) $key, $value);
        }

        return $attributes;
    }

    private function castValue(string $key, mixed $value): mixed
    {
        $cast = static::$casts[$key] ?? null;
        if ($value === null || $cast === null) {
            return $value;
        }

        return match ($cast) {
            'bool', 'boolean' => (bool) $value,
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'json', 'array' => is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value,
            'date', 'datetime' => $value instanceof DateTimeInterface ? $value : new DateTimeImmutable((string) $value),
            default => throw new LogicException(sprintf('Unsupported cast "%s" for %s::$%s.', $cast, static::class, $key)),
        };
    }

    /** @param array<string, mixed> $attributes */
    private function storageAttributes(array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if ($value instanceof DateTimeInterface) {
                $attributes[$key] = $value->format('Y-m-d H:i:s');
            } elseif (in_array(static::$casts[$key] ?? null, ['json', 'array'], true)) {
                try {
                    $attributes[$key] = json_encode($value, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new LogicException(sprintf('Unable to encode cast attribute "%s".', $key), 0, $exception);
                }
            }
        }

        return $attributes;
    }

    private function serializeValue(mixed $value, array &$visited): mixed
    {
        if ($value instanceof self) {
            return $value->toArray($visited);
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->serializeValue($item, $visited), $value);
        }

        return $value;
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
