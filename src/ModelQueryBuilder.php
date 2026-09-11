<?php

declare(strict_types=1);

namespace Velt\Orm;

use InvalidArgumentException;
use Velt\Database\DB;
use Velt\Orm\Pagination\Paginator;

final class ModelQueryBuilder
{
    /** @var list<array{column:string,operator:string,value:mixed}> */
    private array $wheres = [];

    /** @var list<array{column:string,direction:string}> */
    private array $orders = [];

    /** @var list<array{column:string,values:list<mixed>}> */
    private array $whereIns = [];

    /** @var list<string> */
    private array $with = [];

    private ?int $limit = null;

    /**
     * @param class-string<Model> $modelClass
     */
    public function __construct(private readonly string $modelClass)
    {
    }

    public function where(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        $operator = '=';

        // Supporte where('email', $email) et where('age', '>=', 18).
        if (func_num_args() >= 3) {
            $operator = (string) $operatorOrValue;
        } else {
            $value = $operatorOrValue;
        }

        $this->wheres[] = [
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
        ];

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->orders[] = [
            'column' => $column,
            'direction' => $direction,
        ];

        return $this;
    }

    /**
     * @param list<mixed> $values
     */
    public function whereIn(string $column, array $values): self
    {
        $this->whereIns[] = ['column' => $column, 'values' => array_values($values)];

        return $this;
    }

    public function with(string ...$relations): self
    {
        $this->with = array_values(array_unique(array_merge($this->with, $relations)));

        return $this;
    }

    public function limit(int $limit): self
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Limit must be greater than zero.');
        }

        $this->limit = $limit;

        return $this;
    }

    /**
     * @return array<int, Model>
     */
    public function get(): array
    {
        $query = $this->baseQuery();

        if ($this->limit !== null) {
            $query->limit($this->limit);
        }

        // Convertit les lignes database en instances de model a la frontiere ORM.
        $models = array_map(
            fn (array $row): Model => $this->modelClass::hydrate($row),
            $query->get(),
        );

        $this->modelClass::eagerLoad($models, $this->with);

        return $models;
    }

    public function first(): ?Model
    {
        $row = $this->baseQuery()->limit(1)->first();

        if ($row === null) {
            return null;
        }

        $model = $this->modelClass::hydrate($row);
        $this->modelClass::eagerLoad([$model], $this->with);

        return $model;
    }

    public function paginate(int $page = 1, int $perPage = 15): Paginator
    {
        if ($page < 1 || $perPage < 1) {
            throw new InvalidArgumentException('Pagination page and perPage must be greater than zero.');
        }

        $table = $this->modelClass::tableName();
        $countQuery = DB::table($table);

        foreach ($this->wheres as $where) {
            $countQuery->where($where['column'], $where['operator'], $where['value']);
        }
        foreach ($this->whereIns as $whereIn) {
            $countQuery->whereIn($whereIn['column'], $whereIn['values']);
        }

        $total = $countQuery->count();
        $offset = ($page - 1) * $perPage;
        if ($this->orders === []) {
            $this->orderBy($this->modelClass::primaryKey());
        }

        $query = $this->baseQuery()->limit($perPage)->offset($offset);
        $rows = array_map(
            fn (array $row): Model => $this->modelClass::hydrate($row),
            $query->get(),
        );
        $this->modelClass::eagerLoad($rows, $this->with);

        return new Paginator($rows, $page, $total, $perPage);
    }

    private function baseQuery(): \Velt\Database\Query\QueryBuilder
    {
        $query = DB::table($this->modelClass::tableName());

        // Rejoue les contraintes ORM stockees sur le query builder database.
        foreach ($this->wheres as $where) {
            $query->where($where['column'], $where['operator'], $where['value']);
        }

        foreach ($this->whereIns as $whereIn) {
            $query->whereIn($whereIn['column'], $whereIn['values']);
        }

        foreach ($this->orders as $order) {
            $query->orderBy($order['column'], $order['direction']);
        }

        return $query;
    }
}
