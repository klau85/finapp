<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BrokerAccount;
use App\Entity\User;
use App\Repository\PositionLotRepository;

final class PortfolioMetricsService
{
    public function __construct(private readonly PositionLotRepository $positionLotRepository)
    {
    }

    /**
     * @param list<array<string, mixed>> $pricedPositions
     * @return array{
     *     portfolioValue: array<string, string>,
     *     investedCapital: array<string, string>,
     *     realizedPl: array<string, string>,
     *     unrealizedPl: array<string, string>,
     *     totalPl: array<string, string>
     * }
     */
    public function calculate(User $user, array $pricedPositions, ?BrokerAccount $brokerAccount = null): array
    {
        $hasPricedPositions = $pricedPositions !== [];

        return [
            'portfolioValue' => $hasPricedPositions ? $this->sumByCurrency($pricedPositions, 'marketValue') : [],
            'investedCapital' => $this->positionLotRepository->getInvestedCapitalByCurrencyForUser($user, $brokerAccount),
            'realizedPl' => $hasPricedPositions ? $this->sumByCurrency($pricedPositions, 'realizedGain') : [],
            'unrealizedPl' => $hasPricedPositions ? $this->sumByCurrency($pricedPositions, 'unrealizedGain') : [],
            'totalPl' => $hasPricedPositions ? $this->sumByCurrency($pricedPositions, 'totalGain') : [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $positions
     * @return array<string, string>
     */
    private function sumByCurrency(array $positions, string $field): array
    {
        $totals = [];

        foreach ($positions as $position) {
            $currency = (string) $position['currency'];
            $totals[$currency] ??= DecimalMath::zero();
            $totals[$currency] = DecimalMath::add($totals[$currency], (string) $position[$field]);
        }

        ksort($totals);

        return $totals;
    }
}
