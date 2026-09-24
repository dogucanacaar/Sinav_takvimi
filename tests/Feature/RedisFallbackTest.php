<?php

namespace Tests\Feature;

use App\Support\RedisAvailability;
use Tests\TestCase;

/**
 * Redis yapılandırılmış ama kurulu değilse ne olur?
 *
 * Bu testin varlık sebebi gerçek bir arıza: imajda phpredis yoktu,
 * kuyruk işçisi açılır açılmaz ölüyordu ve arayüzde hiçbir şey
 * görünmüyordu — iş sonsuza kadar "kuyrukta bekliyor" kalıyordu.
 */
class RedisFallbackTest extends TestCase
{
    /** İstemci kurulu değilse üç sürücü de veritabanına düşer. */
    public function test_redis_yoksa_veritabani_surucusune_dusulur(): void
    {
        // predis istemcisi seçili ama paket kurulu değil: "Redis yok" hâli.
        config([
            'database.redis.client' => 'predis',
            'cache.default' => 'redis',
            'queue.default' => 'redis',
            'session.driver' => 'redis',
        ]);

        $this->assertFalse(RedisAvailability::usable());

        $changed = RedisAvailability::applyFallback();

        $this->assertSame(['önbellek', 'kuyruk', 'oturum'], $changed);
        $this->assertSame('database', config('cache.default'));
        $this->assertSame('database', config('queue.default'));
        $this->assertSame('database', config('session.driver'));
    }

    /** Redis kullanılabilir durumdaysa hiçbir ayara dokunulmaz. */
    public function test_redis_varsa_ayarlara_dokunulmaz(): void
    {
        if (! extension_loaded('redis')) {
            $this->markTestSkipped('Bu ortamda phpredis kurulu değil.');
        }

        config([
            'database.redis.client' => 'phpredis',
            'cache.default' => 'redis',
            'queue.default' => 'redis',
        ]);

        $this->assertSame([], RedisAvailability::applyFallback());
        $this->assertSame('redis', config('cache.default'));
        $this->assertSame('redis', config('queue.default'));
    }

    /** Redis zaten seçili değilse düşüş diye bir şey olmaz. */
    public function test_redis_secili_degilse_degisiklik_yok(): void
    {
        config([
            'database.redis.client' => 'predis',
            'cache.default' => 'array',
            'queue.default' => 'sync',
            'session.driver' => 'file',
        ]);

        $this->assertSame([], RedisAvailability::applyFallback());
        $this->assertSame('array', config('cache.default'));
    }
}
