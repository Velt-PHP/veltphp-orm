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

        // Convert database rows into active model instances at the ORM boundary.
        return array_map(
            fn (array $row): Model => $this->modelClass::hydrate($row),
            $query->get(),
        );
    }

    public function first(): ?Model
    {
        $row = $this->baseQuery()->limit(1)->first();

        return $row === null ? null : $this->modelClass::hydrate($row);
    }

    public function paginate(int $page = 1, int $perPage = 15): Paginator
    {
        if ($page < 1 || $perPage < 1) {
            throw new InvalidArgumentException('Pagination page and perPage must be greater than zero.');
        }

        $table = $this->modelClass::tableName();
        $countQuery = DB::table($table)->select('id');

        foreach ($this->wheres as $where) {
            $countQuery->where($where['column'], $where['operator'], $where['value']);
        }

        $total = count($countQuery->get());
        $offset = ($page - 1) * $perPage;
        // MVP pagination slices hydrated rows until the database query builder exposes offset().
        $rows = array_slice($this->get(), $offset, $perPage);

        return new Paginator($rows, $page, $total, $perPage);
    }

    private function baseQuery(): \Velt\Database\Query\QueryBuilder
    {
        $query = DB::table($this->modelClass::tableName());

        foreach ($this->wheres as $where) {
            $query->where($where['column'], $where['operator'], $where['value']);
        }

        foreach ($this->orders as $order) {
            $query->orderBy($order['column'], $order['direction']);
        }

        return $query;
    }
}
