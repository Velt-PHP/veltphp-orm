<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Install dependencies before running the benchmark.\n");
    exit(2);
}

require $autoload;

use Velt\Orm\Model;

final class BenchmarkModel extends Model
{
    protected static array $casts = [
        'active' => 'bool',
        'score' => 'float',
        'payload' => 'json',
    ];
}

$count = (int) ($argv[1] ?? 10000);
if ($count < 1) {
    fwrite(STDERR, "Count must be greater than zero.\n");
    exit(2);
}

$row = [
    'id' => 1,
    'active' => 1,
    'score' => '12.5',
    'payload' => '{"offline":true}',
];

$start = hrtime(true);
$before = memory_get_usage(true);
$models = [];
for ($index = 0; $index < $count; $index++) {
    $models[] = BenchmarkModel::hydrate($row);
}
$elapsedMs = (hrtime(true) - $start) / 1_000_000;
$allocatedMb = (memory_get_usage(true) - $before) / 1_048_576;

printf(
    "count=%d elapsed_ms=%.3f allocated_mb=%.3f peak_mb=%.3f\n",
    $count,
    $elapsedMs,
    $allocatedMb,
    memory_get_peak_usage(true) / 1_048_576,
);

unset($models);
