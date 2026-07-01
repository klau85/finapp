<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\JournalEntry;
use App\Entity\Stock;
use App\Entity\Transaction;
use App\Entity\User;
use App\Form\JournalEntryType;
use App\Repository\JournalEntryRepository;
use App\Repository\StockRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class JournalController extends AbstractController
{
    #[Route('/journal', name: 'app_journal', methods: ['GET'])]
    public function index(Request $request, JournalEntryRepository $journalEntries): Response
    {
        $user = $this->currentUser();
        $search = trim((string) $request->query->get('q', ''));
        $filter = strtoupper((string) $request->query->get('filter', ''));
        $filter = match ($filter) {
            'STOCKS' => JournalEntry::TARGET_STOCK,
            'TRANSACTIONS' => JournalEntry::TARGET_TRANSACTION,
            default => $filter,
        };

        return $this->render('journal/index.html.twig', [
            'entries' => $journalEntries->findForUser($user, $search, $filter),
            'search' => $search,
            'activeFilter' => $filter,
        ]);
    }

    #[Route('/journal/new', name: 'app_journal_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        TransactionRepository $transactions,
        StockRepository $stocks,
        EntityManagerInterface $entityManager,
    ): Response {
        $user = $this->currentUser();
        $entry = (new JournalEntry())->setUser($user);
        $lockedTarget = false;

        $transactionId = $request->query->getInt('transaction');
        $stockSymbol = trim((string) $request->query->get('stock', ''));
        if (strtolower((string) $request->query->get('target', '')) === 'portfolio') {
            $entry
                ->setTargetType(JournalEntry::TARGET_PORTFOLIO)
                ->setEntryDate(new \DateTimeImmutable('today', new \DateTimeZone('UTC')));
        }

        if ($transactionId > 0) {
            $transaction = $transactions->findOneForUser($user, $transactionId);
            if (!$transaction instanceof Transaction) {
                throw $this->createNotFoundException('Transaction not found.');
            }
            $entry
                ->setTargetType(JournalEntry::TARGET_TRANSACTION)
                ->setTransaction($transaction)
                ->setEntryType($transaction->getType() === Transaction::TYPE_SELL ? 'SELL_REASON' : 'BUY_REASON')
                ->setEntryDate($transaction->getTransactionDate()->setTime(0, 0));
            $lockedTarget = true;
        } elseif ($stockSymbol !== '') {
            $stock = $stocks->findOneForUserBySymbol($user, $stockSymbol);
            if (!$stock instanceof Stock) {
                throw $this->createNotFoundException('Stock not found.');
            }
            $entry->setTargetType(JournalEntry::TARGET_STOCK)->setStock($stock);
            $lockedTarget = true;
        }

        $form = $this->createForm(JournalEntryType::class, $entry, [
            'user' => $user,
            'locked_target' => $lockedTarget,
        ]);
        $form->handleRequest($request);
        $this->validateOwnership($entry, $user, $stocks, $form);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($entry);
            $entityManager->flush();
            $this->addFlash('success', 'Journal entry created.');

            return $this->redirectToRoute('app_journal_show', ['id' => $entry->getId()]);
        }

        return $this->render('journal/form.html.twig', [
            'form' => $form,
            'entry' => $entry,
            'pageTitle' => 'New Journal Entry',
            'lockedTarget' => $lockedTarget,
        ]);
    }

    #[Route('/journal/{id}', name: 'app_journal_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(int $id, JournalEntryRepository $journalEntries): Response
    {
        return $this->render('journal/show.html.twig', [
            'entry' => $this->entryForCurrentUser($id, $journalEntries),
        ]);
    }

    #[Route('/journal/portfolio/save', name: 'app_journal_portfolio_save', methods: ['POST'])]
    public function savePortfolioNote(
        Request $request,
        JournalEntryRepository $journalEntries,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
    ): Response {
        $user = $this->currentUser();
        if (!$this->isCsrfTokenValid('portfolio_journal', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $entryId = $request->request->getInt('entry_id');
        $entry = $entryId > 0 ? $journalEntries->findOneForUser($user, $entryId) : null;
        if ($entryId > 0 && (!$entry instanceof JournalEntry || $entry->getTargetType() !== JournalEntry::TARGET_PORTFOLIO)) {
            throw $this->createNotFoundException('Journal entry not found.');
        }

        $entry ??= (new JournalEntry())
            ->setUser($user)
            ->setTargetType(JournalEntry::TARGET_PORTFOLIO);

        $entryType = strtoupper((string) $request->request->get('entry_type', 'GENERAL'));
        $entryDateValue = (string) $request->request->get('entry_date', '');
        $entryDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $entryDateValue, new \DateTimeZone('UTC'));
        if (
            !in_array($entryType, JournalEntry::ENTRY_TYPES, true)
            || !$entryDate instanceof \DateTimeImmutable
            || $entryDate->format('Y-m-d') !== $entryDateValue
        ) {
            $this->addFlash('danger', 'Select a valid journal type and date.');

            return $this->redirectToRoute('app_portfolio');
        }

        $entry
            ->setEntryType($entryType)
            ->setEntryDate($entryDate)
            ->setTitle((string) $request->request->get('title', ''))
            ->setContent((string) $request->request->get('content', ''));

        if (count($validator->validate($entry)) > 0) {
            $this->addFlash('danger', 'Complete the required journal fields.');

            return $this->redirectToRoute('app_portfolio');
        }

        $entityManager->persist($entry);
        $entityManager->flush();

        $this->addFlash('success', $entryId > 0 ? 'Journal entry updated.' : 'Journal entry created.');

        return $this->redirectToRoute('app_portfolio');
    }

    #[Route('/journal/transaction/{transactionId}/save', name: 'app_journal_transaction_save', requirements: ['transactionId' => '\d+'], methods: ['POST'])]
    public function saveTransactionNote(
        int $transactionId,
        Request $request,
        TransactionRepository $transactions,
        JournalEntryRepository $journalEntries,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
    ): Response {
        $user = $this->currentUser();
        $transaction = $transactions->findOneForUser($user, $transactionId);
        if (!$transaction instanceof Transaction) {
            throw $this->createNotFoundException('Transaction not found.');
        }
        $symbol = $transaction->getStock()?->getSymbol();
        if ($symbol === null) {
            throw $this->createNotFoundException('Transaction stock not found.');
        }

        if (!$this->isCsrfTokenValid('transaction_journal_'.$transactionId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $entryId = $request->request->getInt('entry_id');
        $entry = $entryId > 0 ? $journalEntries->findOneForUser($user, $entryId) : null;
        if ($entryId > 0 && (!$entry instanceof JournalEntry || $entry->getTransaction()?->getId() !== $transactionId)) {
            throw $this->createNotFoundException('Journal entry not found.');
        }

        $entry ??= (new JournalEntry())
            ->setUser($user)
            ->setTargetType(JournalEntry::TARGET_TRANSACTION)
            ->setTransaction($transaction);

        $entryType = strtoupper((string) $request->request->get('entry_type', 'GENERAL'));
        $entryDateValue = (string) $request->request->get('entry_date', '');
        $entryDate = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $entryDateValue,
            new \DateTimeZone('UTC'),
        );
        if (
            !in_array($entryType, JournalEntry::ENTRY_TYPES, true)
            || !$entryDate instanceof \DateTimeImmutable
            || $entryDate->format('Y-m-d') !== $entryDateValue
        ) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => 'Select a valid journal type and date.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $this->addFlash('danger', 'Select a valid journal type and date.');

            return $this->redirectToRoute('app_stock_show', ['symbol' => $symbol]);
        }

        $entry
            ->setEntryType($entryType)
            ->setEntryDate($entryDate)
            ->setTitle((string) $request->request->get('title', ''))
            ->setContent((string) $request->request->get('content', ''));

        if (count($validator->validate($entry)) > 0) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => 'Complete the required journal fields.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $this->addFlash('danger', 'Complete the required journal fields.');

            return $this->redirectToRoute('app_stock_show', ['symbol' => $symbol]);
        }

        $entityManager->persist($entry);
        $entityManager->flush();

        if ($request->isXmlHttpRequest()) {
            $entries = $journalEntries->findByTransactionIdsForUser($user, [$transactionId])[$transactionId] ?? [];
            $entryTypes = array_combine(
                JournalEntry::ENTRY_TYPES,
                array_map(
                    static fn (string $type): string => ucwords(strtolower(str_replace('_', ' ', $type))),
                    JournalEntry::ENTRY_TYPES,
                ),
            );

            return $this->json([
                'success' => true,
                'transactionId' => $transactionId,
                'modalHtml' => $this->renderView('journal/_transaction_modals.html.twig', [
                    'transactions' => [[
                        'id' => $transactionId,
                        'date' => $transaction->getTransactionDate()->format('Y-m-d'),
                        'symbol' => $symbol,
                        'type' => $transaction->getType(),
                        'quantity' => $transaction->getQuantity(),
                        'price' => $transaction->getPrice(),
                        'currency' => $transaction->getCurrency(),
                    ]],
                    'entriesByTransactionId' => [$transactionId => $entries],
                    'entryTypes' => $entryTypes,
                ]),
                'controlsHtml' => $this->renderView('journal/_transaction_controls.html.twig', [
                    'transactionId' => $transactionId,
                    'hasNotes' => true,
                ]),
            ]);
        }

        $this->addFlash('success', $entryId > 0 ? 'Journal entry updated.' : 'Journal entry created.');

        return $this->redirectToRoute('app_stock_show', ['symbol' => $symbol]);
    }

    #[Route('/journal/stock/{symbol}/save', name: 'app_journal_stock_save', methods: ['POST'])]
    public function saveStockNote(
        string $symbol,
        Request $request,
        StockRepository $stocks,
        JournalEntryRepository $journalEntries,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
    ): Response {
        $user = $this->currentUser();
        $stock = $stocks->findOneForUserBySymbol($user, $symbol);
        if (!$stock instanceof Stock) {
            throw $this->createNotFoundException('Stock not found.');
        }

        if (!$this->isCsrfTokenValid('stock_journal_'.$stock->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $entryId = $request->request->getInt('entry_id');
        $entry = $entryId > 0 ? $journalEntries->findOneForUser($user, $entryId) : null;
        if ($entryId > 0 && (!$entry instanceof JournalEntry || $entry->getStock()?->getId() !== $stock->getId())) {
            throw $this->createNotFoundException('Journal entry not found.');
        }

        $entry ??= (new JournalEntry())
            ->setUser($user)
            ->setTargetType(JournalEntry::TARGET_STOCK)
            ->setStock($stock);

        $entryType = strtoupper((string) $request->request->get('entry_type', 'GENERAL'));
        $entryDateValue = (string) $request->request->get('entry_date', '');
        $entryDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $entryDateValue, new \DateTimeZone('UTC'));
        if (
            !in_array($entryType, JournalEntry::ENTRY_TYPES, true)
            || !$entryDate instanceof \DateTimeImmutable
            || $entryDate->format('Y-m-d') !== $entryDateValue
        ) {
            $this->addFlash('danger', 'Select a valid journal type and date.');

            return $this->redirectToRoute('app_stock_show', ['symbol' => $stock->getSymbol()]);
        }

        $entry
            ->setEntryType($entryType)
            ->setEntryDate($entryDate)
            ->setTitle((string) $request->request->get('title', ''))
            ->setContent((string) $request->request->get('content', ''));

        if (count($validator->validate($entry)) > 0) {
            $this->addFlash('danger', 'Complete the required journal fields.');

            return $this->redirectToRoute('app_stock_show', ['symbol' => $stock->getSymbol()]);
        }

        $entityManager->persist($entry);
        $entityManager->flush();
        $this->addFlash('success', $entryId > 0 ? 'Journal entry updated.' : 'Journal entry created.');

        return $this->redirectToRoute('app_stock_show', ['symbol' => $stock->getSymbol()]);
    }

    #[Route('/journal/{id}/edit', name: 'app_journal_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(
        int $id,
        Request $request,
        JournalEntryRepository $journalEntries,
        StockRepository $stocks,
        EntityManagerInterface $entityManager,
    ): Response {
        $user = $this->currentUser();
        $entry = $this->entryForCurrentUser($id, $journalEntries);
        $form = $this->createForm(JournalEntryType::class, $entry, ['user' => $user]);
        $form->handleRequest($request);
        $this->validateOwnership($entry, $user, $stocks, $form);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Journal entry updated.');

            return $this->redirectToRoute('app_journal_show', ['id' => $entry->getId()]);
        }

        return $this->render('journal/form.html.twig', [
            'form' => $form,
            'entry' => $entry,
            'pageTitle' => 'Edit Journal Entry',
            'lockedTarget' => false,
        ]);
    }

    #[Route('/journal/{id}/delete', name: 'app_journal_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function delete(
        int $id,
        Request $request,
        JournalEntryRepository $journalEntries,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$this->isCsrfTokenValid('delete_journal_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $entry = $this->entryForCurrentUser($id, $journalEntries);
        $entityManager->remove($entry);
        $entityManager->flush();
        $this->addFlash('success', 'Journal entry deleted.');

        return $this->redirectToRoute('app_journal');
    }

    #[Route('/stocks/{symbol}/journal', name: 'app_stock_journal', methods: ['GET'])]
    public function stockJournal(
        string $symbol,
        StockRepository $stocks,
        JournalEntryRepository $journalEntries,
    ): Response {
        $user = $this->currentUser();
        $stock = $stocks->findOneForUserBySymbol($user, $symbol);
        if (!$stock instanceof Stock) {
            throw $this->createNotFoundException('Stock not found.');
        }

        return $this->render('journal/stock.html.twig', [
            'stock' => $stock,
            'entries' => $journalEntries->findForUserAndStock($user, $stock),
        ]);
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }

    private function entryForCurrentUser(int $id, JournalEntryRepository $journalEntries): JournalEntry
    {
        $entry = $journalEntries->findOneForUser($this->currentUser(), $id);
        if (!$entry instanceof JournalEntry) {
            throw $this->createNotFoundException('Journal entry not found.');
        }

        return $entry;
    }

    private function validateOwnership(
        JournalEntry $entry,
        User $user,
        StockRepository $stocks,
        \Symfony\Component\Form\FormInterface $form,
    ): void {
        if (!$form->isSubmitted()) {
            return;
        }

        $transaction = $entry->getTransaction();
        if ($transaction !== null && $transaction->getUser()?->getId() !== $user->getId()) {
            $form->get('transaction')->addError(new FormError('Select one of your transactions.'));
        }

        $stock = $entry->getStock();
        if ($stock !== null && $stocks->findOneForUserBySymbol($user, $stock->getSymbol())?->getId() !== $stock->getId()) {
            $form->get('stock')->addError(new FormError('Select a stock from your portfolio.'));
        }
    }
}
