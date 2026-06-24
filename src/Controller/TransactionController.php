<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\InsufficientSharesForSellException;
use App\Repository\BrokerAccountRepository;
use App\Repository\TransactionRepository;
use App\Service\DecimalMath;
use App\Service\PortfolioAnalyticsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TransactionController extends AbstractController
{
    private const TRANSACTIONS_PER_PAGE = 50;

    #[Route('/transactions', name: 'app_transactions')]
    public function index(
        Request $request,
        TransactionRepository $transactionRepository,
        BrokerAccountRepository $brokerAccountRepository,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        $filters = $this->buildFilters($request);
        $totalTransactions = $transactionRepository->countFilteredForUser($user, $filters);
        $totalPages = max(1, (int) ceil($totalTransactions / self::TRANSACTIONS_PER_PAGE));
        $currentPage = max(1, $request->query->getInt('page', 1));
        $currentPage = min($currentPage, $totalPages);
        $transactions = $transactionRepository->findFilteredForUser(
            $user,
            $filters,
            self::TRANSACTIONS_PER_PAGE,
            ($currentPage - 1) * self::TRANSACTIONS_PER_PAGE,
        );
        $queryParams = $request->query->all();
        unset($queryParams['page']);

        return $this->render('transactions/index.html.twig', [
            'transactions' => array_map(static fn ($transaction): array => [
                'entity' => $transaction,
                'totalAmount' => DecimalMath::add(
                    DecimalMath::mul($transaction->getQuantity(), $transaction->getPrice()),
                    $transaction->getFees()
                ),
            ], $transactions),
            'brokerAccounts' => $brokerAccountRepository->findForUser($user),
            'filters' => [
                'symbol' => (string) $request->query->get('symbol', ''),
                'brokerAccountId' => (string) $request->query->get('brokerAccountId', ''),
                'type' => (string) $request->query->get('type', ''),
                'dateFrom' => (string) $request->query->get('dateFrom', ''),
                'dateTo' => (string) $request->query->get('dateTo', ''),
            ],
            'pagination' => [
                'currentPage' => $currentPage,
                'totalPages' => $totalPages,
                'perPage' => self::TRANSACTIONS_PER_PAGE,
                'totalItems' => $totalTransactions,
                'queryParams' => $queryParams,
                'firstItem' => $totalTransactions === 0 ? 0 : (($currentPage - 1) * self::TRANSACTIONS_PER_PAGE) + 1,
                'lastItem' => min($currentPage * self::TRANSACTIONS_PER_PAGE, $totalTransactions),
            ],
        ]);
    }

    #[Route('/transactions/{id}/delete', name: 'app_transaction_delete', methods: ['POST'])]
    public function delete(
        int $id,
        Request $request,
        TransactionRepository $transactionRepository,
        EntityManagerInterface $entityManager,
        PortfolioAnalyticsService $portfolioAnalytics,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        if (!$this->isCsrfTokenValid('delete_transaction_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $transaction = $transactionRepository->findOneForUser($user, $id);
        if ($transaction === null) {
            throw $this->createNotFoundException('Transaction not found.');
        }

        $entityManager->remove($transaction);
        $entityManager->flush();

        try {
            $portfolioAnalytics->recalculateForUser($user);
            $this->addFlash('success', 'Transaction deleted.');
        } catch (InsufficientSharesForSellException $exception) {
            $this->addFlash('warning', sprintf('Transaction deleted, but portfolio analytics need attention: %s', $exception->getMessage()));
        }

        return $this->redirectToRoute('app_transactions');
    }

    /**
     * @return array{
     *     symbol?: string|null,
     *     brokerAccountId?: int|null,
     *     type?: string|null,
     *     dateFrom?: \DateTimeImmutable|null,
     *     dateTo?: \DateTimeImmutable|null
     * }
     */
    private function buildFilters(Request $request): array
    {
        $type = strtoupper((string) $request->query->get('type', ''));

        return [
            'symbol' => trim((string) $request->query->get('symbol', '')) ?: null,
            'brokerAccountId' => $request->query->get('brokerAccountId') !== null && $request->query->get('brokerAccountId') !== ''
                ? (int) $request->query->get('brokerAccountId')
                : null,
            'type' => in_array($type, ['BUY', 'SELL'], true) ? $type : null,
            'dateFrom' => $this->parseDate((string) $request->query->get('dateFrom', ''), false),
            'dateTo' => $this->parseDate((string) $request->query->get('dateTo', ''), true),
        ];
    }

    private function parseDate(string $value, bool $endOfDay): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $endOfDay ? $date->setTime(23, 59, 59) : $date->setTime(0, 0);
    }
}
