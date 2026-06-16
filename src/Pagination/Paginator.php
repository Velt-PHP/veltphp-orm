<?php

declare(strict_types=1);

namespace Velt\Orm\Pagination;

use JsonSerializable;

final class Paginator implements JsonSerializable
{
    /**
     * Transporte une page de resultats ORM dans une structure stable et serialisable.
     *
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
     * Retourne uniquement les elements de la page courante.
     *
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
     * Format public attendu par les couches HTTP/API.
     *
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
        // JsonSerializable delegue a toArray pour garder un seul format de sortie.
        return $this->toArray();
    }
}
