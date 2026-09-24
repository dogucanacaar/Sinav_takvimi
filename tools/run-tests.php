<?php

/**
 * Vendor'sız test koşucusu.
 *
 * Normalde testler `php artisan test` ile PHPUnit altında çalışır. Bu
 * dosya, composer install yapılmamış bir ortamda (ya da sadece motoru
 * hızlıca doğrulamak için) aynı test sınıflarını çalıştırabilmek içindir.
 * Test dosyalarına hiç dokunmaz: PHPUnit\Framework\TestCase yoksa asgari
 * bir eşdeğerini tanımlar.
 *
 * Kullanım:  php tools/run-tests.php
 */

declare(strict_types=1);
use PHPUnit\Framework\TestCase;

$root = dirname(__DIR__);

// --- Asgari PSR-4 yükleyici -------------------------------------------------

spl_autoload_register(function (string $class) use ($root): void {
    $map = [
        'App\\' => $root.'/app/',
        'Tests\\' => $root.'/tests/',
        'Database\\Seeders\\' => $root.'/database/seeders/',
    ];

    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $path = $dir.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

            if (is_file($path)) {
                require_once $path;
            }

            return;
        }
    }
});

// --- PHPUnit eşdeğeri -------------------------------------------------------

if (! class_exists(TestCase::class)) {
    require __DIR__.'/phpunit-shim.php';
}

// --- Testleri bul ve çalıştır ----------------------------------------------

// Sadece tests/Unit taranır: Feature testleri Laravel çekirdeğini ayağa
// kaldırır, bu koşucunun amacı ise motoru tek başına doğrulamaktır.
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/tests/Unit'));

foreach ($iterator as $file) {
    if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
        $files[] = $file->getPathname();
    }
}

sort($files);

$before = get_declared_classes();

foreach ($files as $file) {
    require_once $file;
}

$classes = array_diff(get_declared_classes(), $before);

$passed = 0;
$failed = 0;
$failures = [];
$startedAt = microtime(true);

foreach ($classes as $class) {
    $reflection = new ReflectionClass($class);

    if (! $reflection->isSubclassOf(TestCase::class) || $reflection->isAbstract()) {
        continue;
    }

    echo "\n\033[1m".$reflection->getShortName()."\033[0m\n";

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (! str_starts_with($method->getName(), 'test')) {
            continue;
        }

        $instance = $reflection->newInstance();

        try {
            $instance->{$method->getName()}();
            $passed++;
            echo "  \033[32m✓\033[0m ".$method->getName()."\n";
        } catch (Throwable $e) {
            $failed++;
            $failures[] = $reflection->getShortName().'::'.$method->getName().' — '.$e->getMessage();
            echo "  \033[31m✗\033[0m ".$method->getName()."\n";
            echo '      '.str_replace("\n", "\n      ", $e->getMessage())."\n";
        }
    }
}

$elapsed = round(microtime(true) - $startedAt, 2);

echo "\n".str_repeat('-', 60)."\n";

if ($failed === 0) {
    echo "\033[32mTümü geçti\033[0m: {$passed} test, {$elapsed} sn\n";
    exit(0);
}

echo "\033[31mBAŞARISIZ\033[0m: {$failed} / ".($passed + $failed)." test, {$elapsed} sn\n";

foreach ($failures as $failure) {
    echo "  - {$failure}\n";
}

exit(1);
