<?php

declare(strict_types=1);

namespace App\Pow;

/**
 * PoW Challenge data object.
 */
final readonly class Challenge
{
    public function __construct(
        public string $seed,
        public string $salt,
        public int $difficulty,
    ) {}

    /**
     * Get the command to run the solver.
     */
    public function getSolverCommand(): string
    {
        return sprintf(
            './unblock-solver.sh "%s" "%s" %d',
            $this->seed,
            $this->salt,
            $this->difficulty
        );
    }
}
