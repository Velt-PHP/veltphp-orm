<?php

declare(strict_types=1);

namespace Velt\Orm\Tests\Fakes;

use Velt\Kernel\Contracts\ConfigRepositoryInterface;

final class ArrayConfigRepository implements ConfigRepositoryInterface
{
    /** @param array<string, mixed> $config */
    public function __construct(private array $config)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->config;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $current = &$this->config;

        foreach (explode('.', $key) as $segment) {
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }

            $current = &$current[$segment];
        }

        $current = $value;
    }

    public function has(string $key): bool
    {
        $value = $this->config;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }

            $value = $value[$segment];
        }

        return true;
    }

    public function all(): array
    {
        return $this->config;
    }
}
