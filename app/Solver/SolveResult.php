<?php

namespace App\Solver;

/**
 * Tavlama benzetiminin çıktısı.
 */
final class SolveResult
{
    public function __construct(
        public readonly Schedule $schedule,
        public readonly int $initialPenalty,
        public readonly int $bestPenalty,
        public readonly int $iterations,
        public readonly int $acceptedMoves,
        public readonly int $rejectedByHard,
        public readonly float $elapsedSeconds,
        public readonly array $unplaced = [],
    ) {}

    public function improvement(): int
    {
        return $this->initialPenalty - $this->bestPenalty;
    }

    public function improvementPercent(): float
    {
        if ($this->initialPenalty === 0) {
            return 0.0;
        }

        return round($this->improvement() / $this->initialPenalty * 100, 2);
    }

    public function toArray(): array
    {
        return [
            'initial_penalty' => $this->initialPenalty,
            'best_penalty' => $this->bestPenalty,
            'improvement' => $this->improvement(),
            'improvement_percent' => $this->improvementPercent(),
            'iterations' => $this->iterations,
            'accepted_moves' => $this->acceptedMoves,
            'rejected_by_hard' => $this->rejectedByHard,
            'elapsed_seconds' => round($this->elapsedSeconds, 2),
            'unplaced_count' => count($this->unplaced),
        ];
    }
}
