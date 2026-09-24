<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Predis\Client;

/**
 * Redis yapılandırılmış ama kurulu değilse veritabanı sürücüsüne düş.
 *
 * Sebebi bir vakadan geliyor: CACHE_STORE=redis / QUEUE_CONNECTION=redis
 * ayarlıyken PHP imajında phpredis eklentisi yoktu. Sonuç, kullanıcının
 * göremediği bir arızaydı — kuyruk işçisi "Class Redis not found" ile
 * sürekli yeniden başlıyor, arayüzde ise iş sonsuza kadar "kuyrukta
 * bekliyor" görünüyordu. Hiçbir ekranda bunun sebebini söyleyen bir şey
 * yoktu.
 *
 * Yapılandırmayı sessizce doğru olana çevirmek yerine hatayı yükseltmek
 * de bir seçenekti; ama bu uygulamada Redis bir hız süsü, zorunluluk
 * değil: veritabanı sürücüsü aynı işi görür. Çalışan bir program,
 * "doğru yapılandırılmış" bir programdan iyidir. Düşüş kayıt dosyasına
 * yazılır ki fark edilmeden kalmasın.
 */
final class RedisAvailability
{
    public static function usable(): bool
    {
        return match (config('database.redis.client', 'phpredis')) {
            'predis' => class_exists(Client::class),
            default => extension_loaded('redis'),
        };
    }

    /** Değiştirilen ayarların adlarını döndürür (boşsa dokunulmamıştır). */
    public static function applyFallback(string $fallback = 'database'): array
    {
        if (self::usable()) {
            return [];
        }

        $changed = [];

        foreach ([
            ['cache', 'default', 'önbellek'],
            ['queue', 'default', 'kuyruk'],
            ['session', 'driver', 'oturum'],
        ] as [$file, $option, $label]) {
            $key = $file.'.'.$option;

            if (config($key) === 'redis') {
                config([$key => $fallback]);
                $changed[] = $label;
            }
        }

        if ($changed !== []) {
            Log::warning(
                'phpredis eklentisi yok; '.implode(', ', $changed)
                ." için Redis yerine '{$fallback}' sürücüsü kullanılıyor."
            );
        }

        return $changed;
    }
}
