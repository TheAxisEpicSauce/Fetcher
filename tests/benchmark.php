<?php

require __DIR__.'/../vendor/autoload.php';

use Fetcher\BaseFetcher;
use Fetcher\FetcherCache;
use Fetcher\MySqlFetcher;
use Tests\MySqlFetchers\PersonFetcher;
use Tests\MySqlFetchers\AddressFetcher;
use Tests\MySqlFetchers\JobFetcher;

// --- Setup ---
FetcherCache::Setup('tests/cache', 'tests/MySqlFetchers', 'Tests\\MySqlFetchers');
FetcherCache::CacheFetchers();

$iterations = (int)($argv[1] ?? 1000);

echo "Fetcher Benchmark — {$iterations} iterations per test\n";
echo str_repeat('=', 60) . "\n\n";

$results = [];

// --- Helpers ---
function bench(string $name, int $iterations, Closure $fn): array {
    // Warmup
    for ($i = 0; $i < 5; $i++) $fn();

    // Clear caches via reflection for cold runs
    $times = [];
    for ($i = 0; $i < $iterations; $i++) {
        $start = hrtime(true);
        $fn();
        $times[] = hrtime(true) - $start;
    }

    sort($times);
    $total = array_sum($times);
    $avg = $total / count($times);
    $median = $times[(int)(count($times) / 2)];
    $p95 = $times[(int)(count($times) * 0.95)];
    $min = $times[0];
    $max = end($times);

    return [
        'name' => $name,
        'avg' => $avg,
        'median' => $median,
        'p95' => $p95,
        'min' => $min,
        'max' => $max,
        'total' => $total,
    ];
}

function clearPathCache(): void {
    $ref = new ReflectionClass(BaseFetcher::class);
    $prop = $ref->getProperty('pathCache');
    $prop->setValue(null, []);
}

function clearTemplateCache(): void {
    $ref = new ReflectionClass(MySqlFetcher::class);
    $prop = $ref->getProperty('queryTemplateCache');
    $prop->setValue(null, []);
}

function ns(float $ns): string {
    if ($ns < 1000) return sprintf('%6.0f ns', $ns);
    if ($ns < 1_000_000) return sprintf('%6.2f us', $ns / 1000);
    return sprintf('%6.2f ms', $ns / 1_000_000);
}

function printResult(array $r): void {
    printf("  %-45s avg: %s  med: %s  p95: %s  min: %s\n",
        $r['name'], ns($r['avg']), ns($r['median']), ns($r['p95']), ns($r['min']));
}

// ============================================
// 1. Simple query build (no joins)
// ============================================
echo "1. Simple query build\n";
echo str_repeat('-', 60) . "\n";

$results[] = $r = bench('build + where + toSql', $iterations, function() {
    PersonFetcher::build()->where('id', 1)->toSql();
});
printResult($r);

$results[] = $r = bench('build + 3x where + toSql', $iterations, function() {
    PersonFetcher::build()
        ->where('id', 1)
        ->where('first_name', 'test')
        ->where('last_name', 'user')
        ->toSql();
});
printResult($r);

$results[] = $r = bench('build + where IN + toSql', $iterations, function() {
    PersonFetcher::build()->where('id', 'IN', [1, 2, 3, 4, 5])->toSql();
});
printResult($r);

echo "\n";

// ============================================
// 2. Join queries
// ============================================
echo "2. Join queries\n";
echo str_repeat('-', 60) . "\n";

$results[] = $r = bench('1-deep join (address.street)', $iterations, function() {
    PersonFetcher::build()->where('address.street', 'Main St')->toSql();
});
printResult($r);

$results[] = $r = bench('2-deep join (address.city.name)', $iterations, function() {
    PersonFetcher::build()->where('address.city.name', 'Amsterdam')->toSql();
});
printResult($r);

$results[] = $r = bench('2 joins (address + job)', $iterations, function() {
    PersonFetcher::build()
        ->where('address.street', 'Main St')
        ->where('job.name', 'dev')
        ->toSql();
});
printResult($r);

$results[] = $r = bench('join + select + order', $iterations, function() {
    PersonFetcher::build()
        ->select(['id', 'first_name', 'address.street', 'address.city.name'])
        ->where('address.city.name', 'Amsterdam')
        ->orderBy(['first_name'], 'asc')
        ->toSql();
});
printResult($r);

echo "\n";

// ============================================
// 3. Path cache impact
// ============================================
echo "3. Path cache impact (cold vs warm)\n";
echo str_repeat('-', 60) . "\n";

$results[] = $r = bench('2-deep join COLD (no path cache)', $iterations, function() {
    clearPathCache();
    PersonFetcher::build()->where('address.city.name', 'Amsterdam')->toSql();
});
printResult($r);

$results[] = $r = bench('2-deep join WARM (path cached)', $iterations, function() {
    PersonFetcher::build()->where('address.city.name', 'Amsterdam')->toSql();
});
printResult($r);

echo "\n";

// ============================================
// 4. Template cache impact
// ============================================
echo "4. Template cache impact (cold vs warm)\n";
echo str_repeat('-', 60) . "\n";

$results[] = $r = bench('simple where COLD (no template cache)', $iterations, function() {
    clearTemplateCache();
    PersonFetcher::build()->where('id', 1)->toSql();
});
printResult($r);

$results[] = $r = bench('simple where WARM (template cached)', $iterations, function() {
    PersonFetcher::build()->where('id', 1)->toSql();
});
printResult($r);

$results[] = $r = bench('join query COLD (no template cache)', $iterations, function() {
    clearTemplateCache();
    PersonFetcher::build()
        ->where('address.street', 'Main St')
        ->where('job.name', 'dev')
        ->toSql();
});
printResult($r);

$results[] = $r = bench('join query WARM (template cached)', $iterations, function() {
    PersonFetcher::build()
        ->where('address.street', 'Main St')
        ->where('job.name', 'dev')
        ->toSql();
});
printResult($r);

echo "\n";

// ============================================
// 5. Both caches cold vs both warm
// ============================================
echo "5. Full cold vs full warm\n";
echo str_repeat('-', 60) . "\n";

$results[] = $r = bench('complex query ALL COLD', $iterations, function() {
    clearPathCache();
    clearTemplateCache();
    PersonFetcher::build()
        ->select(['id', 'first_name', 'address.street', 'address.city.name'])
        ->where('address.city.name', 'Amsterdam')
        ->where('job.name', 'dev')
        ->orderBy(['first_name'], 'asc')
        ->toSql();
});
printResult($r);

$results[] = $r = bench('complex query ALL WARM', $iterations, function() {
    PersonFetcher::build()
        ->select(['id', 'first_name', 'address.street', 'address.city.name'])
        ->where('address.city.name', 'Amsterdam')
        ->where('job.name', 'dev')
        ->orderBy(['first_name'], 'asc')
        ->toSql();
});
printResult($r);

echo "\n";

// ============================================
// Summary
// ============================================
echo str_repeat('=', 60) . "\n";
echo "Speedup from caches:\n\n";

$pairs = [
    ['2-deep join COLD (no path cache)', '2-deep join WARM (path cached)', 'Path cache'],
    ['simple where COLD (no template cache)', 'simple where WARM (template cached)', 'Template cache (simple)'],
    ['join query COLD (no template cache)', 'join query WARM (template cached)', 'Template cache (join)'],
    ['complex query ALL COLD', 'complex query ALL WARM', 'Both caches (complex)'],
];

foreach ($pairs as [$coldName, $warmName, $label]) {
    $cold = null;
    $warm = null;
    foreach ($results as $r) {
        if ($r['name'] === $coldName) $cold = $r;
        if ($r['name'] === $warmName) $warm = $r;
    }
    if ($cold && $warm) {
        $speedup = $cold['avg'] / $warm['avg'];
        printf("  %-35s %5.1fx faster  (%s -> %s)\n", $label, $speedup, ns($cold['avg']), ns($warm['avg']));
    }
}

echo "\n";
