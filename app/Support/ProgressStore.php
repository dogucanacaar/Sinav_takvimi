<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * İlerleme satırının yazıldığı yer.
 *
 * Önbellek burada isteğe bağlı bir süstür: ilerleme çubuğu göstermek
 * güzeldir ama çözümün kendisi buna bağlı değildir. Bu yüzden her çağrı
 * hatayı yutar.
 *
 * Bunun somut bir sebebi var: önbellek sürücüsü Redis'e ayarlıyken
 * phpredis eklentisi kurulu değilse Cache::put() "Class Redis not found"
 * fırlatır. Bu istisna motorun ilerleme geri çağrısının içinden gelir —
 * yani saatlerce sürebilecek bir aramayı, sırf ilerleme çubuğu
 * yazılamadığı için ortasından kesip çözümü "başarısız" yapardı.
 * Bozuk bir önbellek, en fazla ilerleme çubuğunu kaybettirmeli.
 */
final class ProgressStore
{
    /** Aynı arıza için kayıt dosyasını her iterasyonda doldurmamak adına. */
    private static bool $warned = false;

    public static function put(string $key, array $progress, int $seconds = 600): void
    {
        self::guard(fn () => Cache::put($key, $progress, $seconds));
    }

    /** @return array<string,mixed>|null */
    public static function get(string $key): ?array
    {
        $value = self::guard(fn () => Cache::get($key));

        return is_array($value) ? $value : null;
    }

    public static function forget(string $key): void
    {
        self::guard(fn () => Cache::forget($key));
    }

    /** Test yalıtımı için. */
    public static function resetWarning(): void
    {
        self::$warned = false;
    }

    private static function guard(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable $e) {
            if (! self::$warned) {
                self::$warned = true;
                Log::warning('İlerleme önbelleği kullanılamıyor: '.$e->getMessage());
            }

            return null;
        }
    }
}
