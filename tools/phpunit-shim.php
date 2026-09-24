<?php

/**
 * PHPUnit\Framework\TestCase'in asgari eşdeğeri.
 *
 * Sadece tools/run-tests.php tarafından, composer install yapılmamış
 * ortamlarda kullanılır. Gerçek PHPUnit yüklüyse bu dosya hiç okunmaz.
 * Test dosyaları her iki durumda da aynı kalır.
 */

declare(strict_types=1);

namespace PHPUnit\Framework;

class AssertionFailedError extends \Exception {}

abstract class TestCase
{
    protected function fail(string $message): void
    {
        throw new AssertionFailedError($message !== '' ? $message : 'Test başarısız.');
    }

    private function describe(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_scalar($value) => var_export($value, true),
            default => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: gettype($value),
        };
    }

    private function check(bool $condition, string $message, string $detail = ''): void
    {
        if ($condition) {
            return;
        }

        $this->fail(trim($message.($detail !== '' ? "\n".$detail : '')));
    }

    public function assertTrue(mixed $value, string $message = ''): void
    {
        $this->check($value === true, $message, 'Beklenen: true, gelen: '.$this->describe($value));
    }

    public function assertFalse(mixed $value, string $message = ''): void
    {
        $this->check($value === false, $message, 'Beklenen: false, gelen: '.$this->describe($value));
    }

    public function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->check(
            $expected === $actual,
            $message,
            'Beklenen: '.$this->describe($expected)."\nGelen   : ".$this->describe($actual),
        );
    }

    public function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->check($expected !== $actual, $message, 'Değerler aynı olmamalıydı.');
    }

    public function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->check(
            $expected == $actual,
            $message,
            'Beklenen: '.$this->describe($expected)."\nGelen   : ".$this->describe($actual),
        );
    }

    public function assertNotEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->check($expected != $actual, $message, 'Değerler eşit olmamalıydı.');
    }

    public function assertContains(mixed $needle, iterable $haystack, string $message = ''): void
    {
        $found = false;

        foreach ($haystack as $item) {
            if ($item === $needle) {
                $found = true;
                break;
            }
        }

        $this->check($found, $message, $this->describe($needle).' listede bulunamadı.');
    }

    public function assertNotContains(mixed $needle, iterable $haystack, string $message = ''): void
    {
        foreach ($haystack as $item) {
            if ($item === $needle) {
                $this->fail($message !== '' ? $message : $this->describe($needle).' listede olmamalıydı.');
            }
        }
    }

    public function assertCount(int $expected, \Countable|array $haystack, string $message = ''): void
    {
        $this->check(
            count($haystack) === $expected,
            $message,
            "Beklenen adet: {$expected}, gelen: ".count($haystack),
        );
    }

    public function assertEmpty(mixed $value, string $message = ''): void
    {
        $this->check(empty($value), $message, 'Boş olmalıydı: '.$this->describe($value));
    }

    public function assertNotEmpty(mixed $value, string $message = ''): void
    {
        $this->check(! empty($value), $message, 'Boş olmamalıydı.');
    }

    public function assertNull(mixed $value, string $message = ''): void
    {
        $this->check($value === null, $message, 'null bekleniyordu, gelen: '.$this->describe($value));
    }

    public function assertNotNull(mixed $value, string $message = ''): void
    {
        $this->check($value !== null, $message, 'null olmamalıydı.');
    }

    public function assertLessThan(mixed $limit, mixed $actual, string $message = ''): void
    {
        $this->check($actual < $limit, $message, $this->describe($actual).' < '.$this->describe($limit).' olmalıydı.');
    }

    public function assertLessThanOrEqual(mixed $limit, mixed $actual, string $message = ''): void
    {
        $this->check($actual <= $limit, $message, $this->describe($actual).' <= '.$this->describe($limit).' olmalıydı.');
    }

    public function assertGreaterThan(mixed $limit, mixed $actual, string $message = ''): void
    {
        $this->check($actual > $limit, $message, $this->describe($actual).' > '.$this->describe($limit).' olmalıydı.');
    }

    public function assertGreaterThanOrEqual(mixed $limit, mixed $actual, string $message = ''): void
    {
        $this->check($actual >= $limit, $message, $this->describe($actual).' >= '.$this->describe($limit).' olmalıydı.');
    }

    public function assertArrayHasKey(string|int $key, array $array, string $message = ''): void
    {
        $this->check(array_key_exists($key, $array), $message, "'{$key}' anahtarı bulunamadı.");
    }

    public function assertInstanceOf(string $expected, mixed $actual, string $message = ''): void
    {
        $this->check($actual instanceof $expected, $message, $expected.' bekleniyordu, gelen: '.get_debug_type($actual));
    }

    public function assertIsArray(mixed $value, string $message = ''): void
    {
        $this->check(is_array($value), $message, 'Dizi bekleniyordu, gelen: '.get_debug_type($value));
    }
}
