<?php

declare(strict_types=1);

namespace Velt\Orm\Pagination;

use JsonSerializable;

final class Paginator implements JsonSerializable
{
    /**
     * @param array<int, mixed> $data
     */
    public function __construct(
        private readonly array $data,
        private readonly int $page,
        private readonly int $total,
        private readonly int $perPage,
    ) {
    }

    /**
     * @return array<int, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    public function page(): int
    {
        return $this->page;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    /**
     * @return array{data:array<int, mixed>,page:int,total:int,perPage:int}
     */
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'page' => $this->page,
            'total' => $this->total,
            'perPage' => $this->perPage,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
