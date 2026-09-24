<?php

namespace App\Solver;

/**
 * Bağımsız denetleyicinin bulduğu tek bir katı kural ihlali.
 */
final class Violation
{
    public function __construct(
        public readonly string $code,      // K1..K5
        public readonly string $message,
        public readonly array $context = [],
    ) {}

    public function __toString(): string
    {
        return "[{$this->code}] {$this->message}";
    }

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'context' => $this->context,
        ];
    }
}
