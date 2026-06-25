<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

final readonly class FifoRecalculator
{
    public function __construct(
        private PortfolioAnalyticsService $portfolioAnalytics,
    ) {
    }

    /**
     * Rebuilds FIFO lots and realized trades for a user. Deleting first makes repeated runs idempotent.
     *
     * @return list<string>
     */
    public function recalculateForUser(User $user): array
    {
        return $this->portfolioAnalytics->recalculateForUser($user);
    }
}
