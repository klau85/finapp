<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\BrokerAccount;
use App\Entity\ImportFile;
use App\Entity\Stock;
use App\Entity\Transaction;
use App\Entity\User;
use App\Exception\InsufficientSharesForSellException;
use App\Repository\BrokerAccountRepository;
use App\Repository\StockRepository;
use App\Service\CsvTransactionParser;
use App\Service\PortfolioAnalyticsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TransactionUploadController extends AbstractController
{
    private const PREVIEW_SESSION_KEY = 'transaction_upload_preview';

    #[Route('/transactions/upload', name: 'app_transaction_upload', methods: ['GET'])]
    public function upload(BrokerAccountRepository $brokerAccountRepository): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $this->render('transactions/upload.html.twig', [
            'brokerAccounts' => $brokerAccountRepository->findForUser($user),
        ]);
    }

    #[Route('/transactions/upload/preview', name: 'app_transaction_upload_preview', methods: ['POST'])]
    public function preview(
        Request $request,
        BrokerAccountRepository $brokerAccountRepository,
        CsvTransactionParser $parser,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        if (!$this->isCsrfTokenValid('transaction_upload', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $brokerAccount = $this->resolveBrokerAccount($request, $brokerAccountRepository, $user);
        $file = $request->files->get('csv_file');

        if (!$brokerAccount instanceof BrokerAccount) {
            $this->addFlash('danger', 'Select one of your broker accounts.');

            return $this->redirectToRoute('app_transaction_upload');
        }

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('danger', 'Upload a readable CSV file.');

            return $this->redirectToRoute('app_transaction_upload');
        }

        $parsedRows = $parser->parse($file, $brokerAccount);
        $rows = array_map(static fn ($row): array => [
            'rowNumber' => $row->rowNumber,
            'data' => $row->data,
            'errors' => $row->errors,
            'valid' => $row->isValid(),
        ], $parsedRows);
        $hasErrors = array_any($rows, static fn (array $row): bool => !$row['valid']);
        $validRowCount = count(array_filter($rows, static fn (array $row): bool => $row['valid']));
        $invalidRowCount = count($rows) - $validRowCount;
        $canImport = $validRowCount > 0;

        $request->getSession()->set(self::PREVIEW_SESSION_KEY, [
            'brokerAccountId' => $brokerAccount->getId(),
            'originalFileName' => $file->getClientOriginalName(),
            'rows' => $rows,
            'hasErrors' => $hasErrors,
            'validRowCount' => $validRowCount,
            'invalidRowCount' => $invalidRowCount,
            'canImport' => $canImport,
        ]);

        return $this->render('transactions/preview.html.twig', [
            'brokerAccount' => $brokerAccount,
            'originalFileName' => $file->getClientOriginalName(),
            'rows' => $rows,
            'hasErrors' => $hasErrors,
            'validRowCount' => $validRowCount,
            'invalidRowCount' => $invalidRowCount,
            'canImport' => $canImport,
        ], new Response(status: $canImport ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/transactions/upload/confirm', name: 'app_transaction_upload_confirm', methods: ['POST'])]
    public function confirm(
        Request $request,
        BrokerAccountRepository $brokerAccountRepository,
        StockRepository $stockRepository,
        EntityManagerInterface $entityManager,
        PortfolioAnalyticsService $portfolioAnalytics,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        if (!$this->isCsrfTokenValid('transaction_upload_confirm', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $preview = $request->getSession()->get(self::PREVIEW_SESSION_KEY);
        if (!is_array($preview) || ($preview['canImport'] ?? false) !== true) {
            $this->addFlash('danger', 'There is no valid preview to import.');

            return $this->redirectToRoute('app_transaction_upload');
        }

        $brokerAccount = $brokerAccountRepository->findOneForUser($user, (int) ($preview['brokerAccountId'] ?? 0));
        if (!$brokerAccount instanceof BrokerAccount) {
            throw $this->createAccessDeniedException('Broker account does not belong to the current user.');
        }

        $importedCount = 0;
        $skippedCount = (int) ($preview['invalidRowCount'] ?? 0);

        $entityManager->wrapInTransaction(function () use (
            $entityManager,
            $stockRepository,
            $user,
            $brokerAccount,
            $preview,
            &$importedCount,
        ): void {
            /** @var array<string, Stock> $stockCache */
            $stockCache = [];
            $importFile = (new ImportFile())
                ->setUser($user)
                ->setBrokerAccount($brokerAccount)
                ->setOriginalFileName((string) ($preview['originalFileName'] ?? 'transactions.csv'))
                ->setStatus('imported');

            $entityManager->persist($importFile);

            foreach ($preview['rows'] ?? [] as $row) {
                if (!is_array($row) || ($row['valid'] ?? false) !== true || !is_array($row['data'] ?? null)) {
                    continue;
                }

                /** @var array<string, string> $data */
                $data = $row['data'];
                $stockKey = $data['symbol'].':'.$data['currency'];
                $stock = $stockCache[$stockKey] ?? $stockRepository->findOneBy([
                    'symbol' => $data['symbol'],
                    'currency' => $data['currency'],
                ]);

                if (!$stock instanceof Stock) {
                    $stock = (new Stock())
                        ->setSymbol($data['symbol'])
                        ->setCurrency($data['currency'])
                        ->setCompanyName($data['symbol']);
                    $entityManager->persist($stock);
                }
                $stockCache[$stockKey] = $stock;

                $transactionDate = new \DateTimeImmutable($data['transactionDate'] ?? $data['date'].' 00:00:00', new \DateTimeZone('UTC'));
                $transaction = (new Transaction())
                    ->setUser($user)
                    ->setBrokerAccount($brokerAccount)
                    ->setImportFile($importFile)
                    ->setStock($stock)
                    ->setTransactionDate($transactionDate)
                    ->setType($data['type'])
                    ->setQuantity($data['quantity'])
                    ->setPrice($data['price'])
                    ->setCurrency($data['currency'])
                    ->setFees($data['fees'])
                    ->setBrokerAmount($data['brokerAmount'] ?? null)
                    ->setBrokerCurrency($data['brokerCurrency'] ?? null);

                $entityManager->persist($transaction);
                ++$importedCount;
            }
        });

        try {
            $portfolioAnalytics->recalculateForUser($user);
        } catch (InsufficientSharesForSellException $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute('app_transaction_upload');
        }

        $request->getSession()->remove(self::PREVIEW_SESSION_KEY);

        $message = sprintf('Imported %d transactions into %s.', $importedCount, $brokerAccount->getDisplayName());
        if ($skippedCount > 0) {
            $message .= sprintf(' Skipped %d invalid row%s.', $skippedCount, $skippedCount === 1 ? '' : 's');
        }

        $this->addFlash('success', $message);

        return $this->redirectToRoute('app_transactions');
    }

    private function resolveBrokerAccount(
        Request $request,
        BrokerAccountRepository $brokerAccountRepository,
        User $user,
    ): ?BrokerAccount {
        $brokerAccountId = $request->request->get('brokerAccountId');
        if (!is_scalar($brokerAccountId)) {
            return null;
        }

        return $brokerAccountRepository->findOneForUser($user, (int) $brokerAccountId);
    }
}
