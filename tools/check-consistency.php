<?php

/**
 * Tutarlılık denetimi — çerçeve ayağa kalkmadan çalışır.
 *
 * `php artisan test` çalıştırmak için composer install gerekir ve o da
 * ağ ister. Bu betik, ağ olmadan yakalanabilecek hataları yakalar:
 * dengesiz Blade yönergeleri, olmayan bir görünüme yapılan çağrı,
 * tanımsız bir rota adı, olmayan bir bileşen, göçlerde bulunmayan bir
 * tablo veya sütun.
 *
 * Bunlar "çalıştırınca hemen patlayan" türden hatalardır; derleyicisi
 * olmayan bir dilde bu denetimi birinin yapması gerekir.
 *
 *   php tools/check-consistency.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$problems = [];

function phpFiles(string $root): array
{
    $files = [];

    // tools/ taranmaz: bu betiğin kendi desenleri sahte bulgu üretir.
    foreach (['app', 'database', 'routes', 'config', 'bootstrap', 'tests'] as $dir) {
        if (! is_dir("$root/$dir")) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir"));

        foreach ($iterator as $file) {
            // bootstrap/cache, çerçevenin çalışma anında ürettiği
            // dosyaları tutar; kaynak kod değildir ve taranmaz.
            if (str_contains($file->getPathname(), '/bootstrap/cache/')) {
                continue;
            }

            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

function bladeFiles(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/resources/views"));

    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

$php = phpFiles($root);
$blades = bladeFiles($root);

function relative(string $path, string $root): string
{
    return ltrim(str_replace($root, '', $path), '/');
}

// ---------------------------------------------------------------------------
// 1. Blade yönergeleri dengeli mi?
// ---------------------------------------------------------------------------

/**
 * Blok açan yönergeler. Parantezle çağrılan satır içi biçimleri olanlar
 * (@php(...), @class(...)) ayrıca ele alınır.
 */
const BLOCK_OPENERS = [
    'if', 'unless', 'isset', 'empty', 'foreach', 'forelse', 'for', 'while',
    'switch', 'auth', 'guest', 'can', 'cannot', 'canany', 'error', 'section',
    'push', 'prepend', 'once', 'verbatim', 'php', 'production', 'env',
    'hasSection', 'sectionMissing', 'fragment',
];

foreach ($blades as $file) {
    $source = file_get_contents($file);
    $stack = [];

    preg_match_all('/@(\w+)\s*(\()?/', $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

    foreach ($matches as $match) {
        $name = $match[1][0];
        $offset = $match[0][1];
        $hasParen = isset($match[2]) && $match[2][0] === '(';
        $line = substr_count(substr($source, 0, $offset), "\n") + 1;

        // @forelse ... @empty ... @endforelse: buradaki @empty blok açmaz,
        // forelse'in "hiç kayıt yok" dalıdır.
        if ($name === 'empty' && end($stack) !== false && end($stack)['name'] === 'forelse') {
            continue;
        }

        // @php(...) satır içidir; @php ... @endphp bloktur.
        if ($name === 'php' && $hasParen) {
            continue;
        }

        if (in_array($name, BLOCK_OPENERS, true)) {
            $stack[] = ['name' => $name, 'line' => $line];

            continue;
        }

        if (str_starts_with($name, 'end')) {
            $closes = substr($name, 3);
            $open = array_pop($stack);

            if ($open === null) {
                $problems[] = relative($file, $root).":{$line}: @{$name} var ama açılmış blok yok.";

                continue;
            }

            if ($open['name'] !== $closes) {
                $problems[] = relative($file, $root).":{$line}: @{$name} bekleniyordu değil — "
                    ."{$open['line']}. satırdaki @{$open['name']} hâlâ açık.";
            }
        }
    }

    foreach ($stack as $open) {
        $problems[] = relative($file, $root).":{$open['line']}: @{$open['name']} kapatılmamış.";
    }
}

// ---------------------------------------------------------------------------
// 2. view('...') karşılığı var mı?
// ---------------------------------------------------------------------------

foreach ($php as $file) {
    foreach (preg_split('/\R/', file_get_contents($file)) as $number => $line) {
        if (preg_match("/view\(\s*'([a-z0-9_.\-]+)'/i", $line, $m)) {
            $path = "$root/resources/views/".str_replace('.', '/', $m[1]).'.blade.php';

            if (! is_file($path)) {
                $problems[] = relative($file, $root).':'.($number + 1).": view('{$m[1]}') karşılığı yok.";
            }
        }
    }
}

// ---------------------------------------------------------------------------
// 3. route('...') tanımlı mı?
// ---------------------------------------------------------------------------

$routes = [];

foreach (glob("$root/routes/*.php") as $file) {
    preg_match_all("/->name\('([^']+)'\)/", file_get_contents($file), $m);
    $routes = array_merge($routes, $m[1]);
}

foreach (array_merge($php, $blades) as $file) {
    foreach (preg_split('/\R/', file_get_contents($file)) as $number => $line) {
        if (preg_match("/\broute\(\s*'([a-z0-9_.\-]+)'/i", $line, $m) && ! in_array($m[1], $routes, true)) {
            $problems[] = relative($file, $root).':'.($number + 1).": route('{$m[1]}') tanımlı değil.";
        }
    }
}

// ---------------------------------------------------------------------------
// 4. <x-...> bileşeni var mı?
// ---------------------------------------------------------------------------

foreach ($blades as $file) {
    preg_match_all('/<x-([a-z0-9.\-]+)/', file_get_contents($file), $m);

    foreach (array_unique($m[1]) as $component) {
        $base = "$root/resources/views/components/".str_replace('.', '/', $component);

        if (! is_file("$base.blade.php") && ! is_file("$base/index.blade.php")) {
            $problems[] = relative($file, $root).": <x-{$component}> bileşeni bulunamadı.";
        }
    }
}

// ---------------------------------------------------------------------------
// 5. Göçlerdeki tablo ve sütunlar — sorgularla tutuyor mu?
// ---------------------------------------------------------------------------

$tables = [];

foreach (glob("$root/database/migrations/*.php") as $file) {
    $source = file_get_contents($file);

    // Schema::create('x', function ... ) blokları
    preg_match_all(
        "/Schema::(create|table)\(\s*'(\w+)'.*?\n(.*?)\n\s*\}\);/s",
        $source,
        $blocks,
        PREG_SET_ORDER,
    );

    foreach ($blocks as $block) {
        $table = $block[2];
        $body = $block[3];
        $tables[$table] ??= [];

        // $table->string('ad'), $table->foreignId('x_id'), $table->id()
        // '?([\w']*)'? yazımı kapanış tırnağını da sütun adına katıyordu
        // ("tenant_id'"); tırnaklar grubun dışında tutulur.
        preg_match_all("/\\\$table->(\w+)\(\s*(?:'(\w+)')?/", $body, $columns, PREG_SET_ORDER);

        foreach ($columns as $column) {
            $method = $column[1];
            $name = $column[2] ?? '';

            if ($method === 'id') {
                $tables[$table][] = 'id';

                continue;
            }

            if ($method === 'timestamps') {
                $tables[$table][] = 'created_at';
                $tables[$table][] = 'updated_at';

                continue;
            }

            if ($method === 'rememberToken') {
                $tables[$table][] = 'remember_token';

                continue;
            }

            if (in_array($method, ['primary', 'unique', 'index', 'foreign', 'dropColumn',
                'dropConstrainedForeignId', 'dropIfExists'], true)) {
                continue;
            }

            if ($name !== '') {
                $tables[$table][] = $name;
            }
        }
    }
}

foreach ($tables as $table => $columns) {
    $tables[$table] = array_values(array_unique($columns));
}

// DB::table('x') çağrıları
foreach ($php as $file) {
    $source = file_get_contents($file);

    $inConfigBlock = false;

    foreach (preg_split('/\R/', $source) as $number => $line) {
        if (preg_match_all("/DB::table\(\s*'(\w+)'/", $line, $m)) {
            foreach ($m[1] as $table) {
                if (! isset($tables[$table])) {
                    $problems[] = relative($file, $root).':'.($number + 1).": '{$table}' tablosu göçlerde yok.";
                }
            }
        }

        // 'tablo.sutun' biçimindeki referanslar.
        // config('cache.default') gibi yapılandırma anahtarları da bu
        // kalıba uyuyor ve 'cache' gerçek bir tablo adı — o yüzden
        // yapılandırma okunan satırlar dışarıda bırakılır.
        // config(['cache.default' => ...]) birden çok satıra yayılabildiği
        // için blok başlangıcı işaretlenir.
        if (str_contains($line, 'config([')) {
            $inConfigBlock = true;
        }

        $isConfigLine = $inConfigBlock
            || str_contains($line, 'config(')
            || str_contains($line, 'Config::');

        if (str_contains($line, ']);')) {
            $inConfigBlock = false;
        }

        if (! $isConfigLine && preg_match_all("/'(\w+)\.(\w+)'/", $line, $m, PREG_SET_ORDER)) {
            foreach ($m as $ref) {
                [$all, $table, $column] = $ref;

                if (! isset($tables[$table])) {
                    continue; // tablo değil (ör. dosya adı, config anahtarı)
                }

                if (! in_array($column, $tables[$table], true)) {
                    $problems[] = relative($file, $root).':'.($number + 1)
                        .": '{$table}.{$column}' — bu sütun göçlerde yok.";
                }
            }
        }
    }
}

// Modellerdeki $fillable sütunları
foreach (glob("$root/app/Models/*.php") as $file) {
    $source = file_get_contents($file);

    if (! preg_match("/protected \\\$table = '(\w+)'/", $source, $tableMatch)) {
        // Tablo adı belirtilmemişse sınıf adından türet (basit çoğul).
        $class = strtolower(basename($file, '.php'));
        $table = $class.'s';
    } else {
        $table = $tableMatch[1];
    }

    if (! isset($tables[$table]) || ! preg_match("/protected \\\$fillable = \[(.*?)\];/s", $source, $m)) {
        continue;
    }

    preg_match_all("/'(\w+)'/", $m[1], $fields);

    foreach ($fields[1] as $field) {
        if (! in_array($field, $tables[$table], true)) {
            $problems[] = relative($file, $root).": \$fillable'daki '{$field}' sütunu {$table} tablosunda yok.";
        }
    }
}

// ---------------------------------------------------------------------------
// 6. Göç sırası — yabancı anahtar hedefi önce oluşturulmuş mu?
// ---------------------------------------------------------------------------

$created = [];

foreach (glob("$root/database/migrations/*.php") as $file) {
    $source = file_get_contents($file);

    preg_match_all("/Schema::create\(\s*'(\w+)'/", $source, $m);
    $createdHere = $m[1];

    preg_match_all("/->constrained\(\s*'?(\w*)'?/", $source, $c);
    preg_match_all("/foreignId\(\s*'(\w+)'/", $source, $f);

    foreach ($f[1] as $index => $column) {
        $target = $c[1][$index] ?? '';

        if ($target === '') {
            // constrained() tablo adını sütundan türetir: lecturer_id -> lecturers
            $target = str_ends_with($column, '_id') ? substr($column, 0, -3).'s' : '';
        }

        if ($target !== '' && ! isset($created[$target]) && ! in_array($target, $createdHere, true)) {
            $problems[] = relative($file, $root).": '{$target}' tablosu bu göçten önce oluşturulmamış "
                ."('{$column}' yabancı anahtarı).";
        }
    }

    foreach ($createdHere as $table) {
        $created[$table] = true;
    }
}

// ---------------------------------------------------------------------------
// 7. App\ sınıf referansları gerçekten var mı?
// ---------------------------------------------------------------------------

$ownClasses = [];

foreach ($php as $file) {
    $source = file_get_contents($file);

    if (preg_match('/^namespace\s+([^;]+);/m', $source, $ns)
        && preg_match('/^(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+(\w+)/m', $source, $cls)) {
        $ownClasses[trim($ns[1]).'\\'.$cls[1]] = $file;
    }
}

foreach ($php as $file) {
    foreach (preg_split('/\R/', file_get_contents($file)) as $number => $line) {
        if (! preg_match('/^use\s+(App\\\\[\w\\\\]+)(?:\s+as\s+\w+)?;/', trim($line), $m)) {
            continue;
        }

        if (! isset($ownClasses[$m[1]])) {
            $problems[] = relative($file, $root).':'.($number + 1).": {$m[1]} sınıfı yok.";
        }
    }
}

// ---------------------------------------------------------------------------
// 8. config('...') anahtarları tanımlı mı?
// ---------------------------------------------------------------------------

$configs = [];

// Config dosyaları env() çağırır; çerçeve yüklü olmadığı için asgari
// bir karşılığı tanımlanır. Sadece bu projenin kendi config dosyası
// okunur — çerçevenin kendi anahtarları burada denetlenmez.
if (! function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return $default;
    }
}

foreach (['scheduling'] as $name) {
    $file = "$root/config/{$name}.php";

    if (is_file($file)) {
        $configs[$name] = include $file;
    }
}

function configHas(array $configs, string $key): bool
{
    $parts = explode('.', $key);
    $current = $configs;

    foreach ($parts as $part) {
        if (! is_array($current) || ! array_key_exists($part, $current)) {
            return false;
        }

        $current = $current[$part];
    }

    return true;
}

foreach (array_merge($php, $blades) as $file) {
    foreach (preg_split('/\R/', file_get_contents($file)) as $number => $line) {
        if (! preg_match_all("/config\(\s*'([a-z0-9_.\-]+)'/i", $line, $m)) {
            continue;
        }

        foreach ($m[1] as $key) {
            // Sadece bu projenin kendi config dosyalarını denetle;
            // çerçevenin kendi anahtarları burada yüklü değil.
            if (! str_starts_with($key, 'scheduling.')) {
                continue;
            }

            if (! configHas($configs, $key)) {
                $problems[] = relative($file, $root).':'.($number + 1).": config('{$key}') tanımlı değil.";
            }
        }
    }
}

// ---------------------------------------------------------------------------
// 9. Livewire: wire:model / wire:click hedefleri bileşende var mı?
// ---------------------------------------------------------------------------

/**
 * Bileşenin public özelliklerini ve metotlarını kaynaktan çıkarır.
 * Yansıma (reflection) kullanılamaz: Livewire\Component yüklü değil.
 *
 * @return array{props:string[],methods:string[]}
 */
function componentApi(string $source): array
{
    // public string $ad = ...   /   public $ad;   /   public ?array $ad
    preg_match_all('/\bpublic\s+(?:readonly\s+)?(?:\??[\w\\\\|]+\s+)?\$(\w+)/', $source, $props);
    preg_match_all('/\bpublic\s+function\s+(\w+)\s*\(/', $source, $methods);

    return ['props' => $props[1], 'methods' => $methods[1]];
}

foreach (glob("$root/app/Livewire/*.php") as $file) {
    $source = file_get_contents($file);
    $api = componentApi($source);

    // Bileşen adı -> görünüm adı (import-wizard gibi)
    if (! preg_match("/view\(\s*'(livewire\.[\w\-]+)'/", $source, $viewMatch)) {
        continue;
    }

    $viewPath = "$root/resources/views/".str_replace('.', '/', $viewMatch[1]).'.blade.php';

    if (! is_file($viewPath)) {
        continue; // 2. adımda zaten bildirildi
    }

    $view = file_get_contents($viewPath);
    $component = basename($file, '.php');

    // wire:model, wire:model.live.debounce.300ms vb.
    preg_match_all('/wire:model[\w.]*="([^"]+)"/', $view, $models);

    foreach (array_unique($models[1]) as $target) {
        // Blade ifadesiyle üretilen hedefler ({{ $model }}) statik olarak
        // çözülemez; denetim dışı bırakılır.
        if (str_contains($target, '{{')) {
            continue;
        }

        $base = explode('.', $target)[0];

        if (! in_array($base, $api['props'], true)) {
            $problems[] = relative($viewPath, $root).": wire:model=\"{$target}\" — "
                ."{$component} bileşeninde \${$base} yok.";
        }
    }

    // wire:click / wire:submit — "$set(...)" gibi ifadeler atlanır
    preg_match_all('/wire:(click|submit)[\w.]*="([^"(]+)(\()?/', $view, $actions, PREG_SET_ORDER);

    foreach ($actions as $action) {
        $target = trim($action[2]);

        if ($target === '' || str_starts_with($target, '$') || str_contains($target, '{{')) {
            continue;
        }

        if (! in_array($target, $api['methods'], true)) {
            $problems[] = relative($viewPath, $root).": wire:{$action[1]}=\"{$target}\" — "
                ."{$component} bileşeninde {$target}() metodu yok.";
        }
    }

    // Görünümde $this->metot() çağrıları
    preg_match_all('/\$this->(\w+)\(/', $view, $calls);

    foreach (array_unique($calls[1]) as $method) {
        if (! in_array($method, $api['methods'], true)) {
            $problems[] = relative($viewPath, $root).": \$this->{$method}() — "
                ."{$component} bileşeninde böyle bir metot yok.";
        }
    }
}

// ---------------------------------------------------------------------------
// 10b. Livewire bileşeninde "return redirect()" tuzağı
// ---------------------------------------------------------------------------

/**
 * Bileşenin içinde redirect() helper'ı Livewire'ın kendi Redirector'ünü
 * döndürür; akıcı olduğu için kendini geri verir ve onu return etmek
 * çalışma zamanında 500 üretir. Doğrusu $this->redirect(...).
 */
foreach (glob("$root/app/Livewire/*.php") as $file) {
    foreach (preg_split('/\R/', file_get_contents($file)) as $number => $line) {
        if (preg_match('/\breturn\s+redirect\s*\(/', $line)) {
            $problems[] = relative($file, $root).':'.($number + 1)
                .': Livewire bileşeninde "return redirect()" çalışmaz — $this->redirect(...) kullanın.';
        }
    }
}

// ---------------------------------------------------------------------------
// 10. Rotalardaki denetleyici metotları var mı?
// ---------------------------------------------------------------------------

foreach (glob("$root/routes/*.php") as $file) {
    $source = file_get_contents($file);

    preg_match_all('/\[(\w+)::class,\s*\x27(\w+)\x27\]/', $source, $m, PREG_SET_ORDER);

    foreach ($m as $ref) {
        [$all, $class, $method] = $ref;

        $candidate = "App\\Http\\Controllers\\{$class}";

        if (! isset($ownClasses[$candidate])) {
            $problems[] = relative($file, $root).": {$class} denetleyicisi yok.";

            continue;
        }

        if (! preg_match('/\bfunction\s+'.preg_quote($method, '/').'\s*\(/', file_get_contents($ownClasses[$candidate]))) {
            $problems[] = relative($file, $root).": {$class}::{$method}() metodu yok.";
        }
    }
}

// ---------------------------------------------------------------------------
// 10c. XML yapılandırma dosyaları iyi biçimli mi?
// ---------------------------------------------------------------------------

/**
 * phpunit.xml bozuksa test takımı hiç başlamaz. XML'in kendine özgü
 * kuralları var: örneğin yorum içinde çift tire (--) bulunamaz, ki bunu
 * elle yazarken fark etmek kolay değil.
 */
foreach (glob("$root/*.xml") as $file) {
    $previous = libxml_use_internal_errors(true);
    libxml_clear_errors();

    simplexml_load_file($file);

    foreach (libxml_get_errors() as $error) {
        $problems[] = relative($file, $root).':'.$error->line.': '.trim($error->message);
    }

    libxml_clear_errors();
    libxml_use_internal_errors($previous);
}

// ---------------------------------------------------------------------------
// 11. Görünümdeki değişkenler gerçekten sağlanıyor mu?
// ---------------------------------------------------------------------------

/**
 * "Undefined variable" hatası, arayüzde en sık karşılaşılan ve en geç
 * fark edilen hatadır: sayfa açılana kadar kimse görmez. Görünümde
 * kullanılan her değişkenin ya bileşenin public özelliği, ya view()'e
 * verilen bir anahtar, ya da görünümün kendi içinde tanımlanmış olması
 * gerekir.
 */
function bladeLocals(string $view): array
{
    $locals = [];

    // @foreach ($a as $b) / ($a as $k => $v) / ([[..],[..]] as [$a, $b])
    // Döngü değişkenleri her zaman "as" ifadesinin sağındadır; kaynak
    // tarafı bir dizi değişmezi de olabileceği için "ilkini at" kuralı
    // yanlış sonuç verir.
    preg_match_all('/@(?:foreach|forelse)\s*\((.*)\)/', $view, $loops);

    foreach ($loops[1] as $expression) {
        $parts = preg_split('/\s+as\s+/', $expression, 2);

        if (count($parts) !== 2) {
            continue;
        }

        preg_match_all('/\$(\w+)/', $parts[1], $names);
        $locals = array_merge($locals, $names[1]);
    }

    // @if ($x = ...) gibi yönerge içi atamalar
    preg_match_all('/@\w+\s*\(\s*\$(\w+)\s*=[^=]/', $view, $directiveAssigns);
    $locals = array_merge($locals, $directiveAssigns[1]);

    // @for ($i = 0; ...)
    preg_match_all('/@for\s*\(\s*\$(\w+)/', $view, $forLoops);
    $locals = array_merge($locals, $forLoops[1]);

    // @php($x = ...) ve @php ... @endphp içindeki atamalar
    preg_match_all('/@php\s*\(\s*\$(\w+)\s*=/', $view, $inlinePhp);
    $locals = array_merge($locals, $inlinePhp[1]);

    preg_match_all('/@php(.*?)@endphp/s', $view, $phpBlocks);

    foreach ($phpBlocks[1] as $block) {
        preg_match_all('/\$(\w+)\s*=[^=]/', $block, $assigned);
        preg_match_all('/as\s+\$(\w+)(?:\s*=>\s*\$(\w+))?/', $block, $blockLoops);
        $locals = array_merge($locals, $assigned[1], $blockLoops[1], array_filter($blockLoops[2]));
    }

    // Kapanış parametreleri: fn ($x) => ... / function ($x, $y)
    preg_match_all('/(?:fn|function)\s*\(([^)]*)\)/', $view, $closures);

    foreach ($closures[1] as $params) {
        preg_match_all('/\$(\w+)/', $params, $names);
        $locals = array_merge($locals, $names[1]);
    }

    // @props(['a' => ..., 'b'])
    preg_match_all("/@props\(\s*\[(.*?)\]\s*\)/s", $view, $props);

    foreach ($props[1] as $list) {
        preg_match_all("/'(\w+)'/", $list, $names);
        $locals = array_merge($locals, $names[1]);
    }

    return array_unique($locals);
}

const BLADE_BUILTINS = ['loop', 'slot', 'attributes', 'errors', 'this', 'message', 'component', 'key', 'value'];

foreach (glob("$root/app/Livewire/*.php") as $file) {
    $source = file_get_contents($file);
    $api = componentApi($source);
    $component = basename($file, '.php');

    // view()'e verilen tüm anahtarlar (bir bileşende birden çok çağrı olabilir)
    $provided = $api['props'];

    preg_match_all("/view\(\s*'livewire\.[\w\-]+'\s*,\s*\[(.*?)\n\s*\]\s*\)/s", $source, $arrays);

    foreach ($arrays[1] as $array) {
        preg_match_all("/'(\w+)'\s*=>/", $array, $keys);
        $provided = array_merge($provided, $keys[1]);
    }

    if (! preg_match("/view\(\s*'(livewire\.[\w\-]+)'/", $source, $viewMatch)) {
        continue;
    }

    $viewPath = "$root/resources/views/".str_replace('.', '/', $viewMatch[1]).'.blade.php';

    if (! is_file($viewPath)) {
        continue;
    }

    $view = file_get_contents($viewPath);
    $known = array_merge($provided, bladeLocals($view), BLADE_BUILTINS);

    // wire:click="$set(...)" gibi Livewire ifadeleri PHP değişkeni değildir;
    // içlerindeki {{ }} blokları korunarak gerisi taranmaz.
    $scanned = preg_replace_callback(
        '/wire:[\w.:-]+="([^"]*)"/',
        function (array $m): string {
            preg_match_all('/\{\{(.*?)\}\}/s', $m[1], $interpolations);

            return implode(' ', $interpolations[0]);
        },
        $view,
    );

    // Nesne özelliği ($x->y) ve metot çağrısı değil, düz değişken kullanımı
    preg_match_all('/\$(\w+)/', $scanned, $used, PREG_OFFSET_CAPTURE);

    $reported = [];

    foreach ($used[1] as $match) {
        $name = $match[0];

        if (in_array($name, $known, true) || isset($reported[$name])) {
            continue;
        }

        $reported[$name] = true;
        $line = substr_count(substr($scanned, 0, $match[1]), "\n") + 1;

        $problems[] = relative($viewPath, $root).":{$line}: \${$name} — "
            ."{$component} bileşeni bu değişkeni vermiyor.";
    }
}

// ---------------------------------------------------------------------------

if ($problems === []) {
    echo "\033[32mTutarlılık denetimi temiz\033[0m — "
        .count($blades).' blade, '.count($php).' php dosyası, '
        .count($tables).' tablo, '.count($routes)." rota.\n";

    exit(0);
}

echo "\033[31m".count($problems)." sorun bulundu:\033[0m\n";

foreach ($problems as $problem) {
    echo "  - {$problem}\n";
}

exit(1);
