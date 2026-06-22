<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\BrokerAccount;
use App\Entity\User;
use App\Exception\InsufficientSharesForSellException;
use App\Form\BrokerAccountType;
use App\Repository\BrokerAccountRepository;
use App\Service\PortfolioAnalyticsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BrokerAccountController extends AbstractController
{
    #[Route('/broker-accounts', name: 'app_broker_accounts')]
    public function index(
        Request $request,
        BrokerAccountRepository $brokerAccountRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        $brokerAccount = new BrokerAccount();
        $brokerAccount->setUser($user);

        $form = $this->createForm(BrokerAccountType::class, $brokerAccount);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $brokerAccount->setUser($user);
            $entityManager->persist($brokerAccount);
            $entityManager->flush();

            $this->addFlash('success', 'Broker account created.');

            return $this->redirectToRoute('app_broker_accounts');
        }

        return $this->render('broker_account/index.html.twig', [
            'brokerAccounts' => $brokerAccountRepository->findForUser($user),
            'form' => $form,
        ]);
    }

    #[Route('/broker-accounts/{id}/delete', name: 'app_broker_account_delete', methods: ['POST'])]
    public function delete(
        int $id,
        Request $request,
        BrokerAccountRepository $brokerAccountRepository,
        EntityManagerInterface $entityManager,
        PortfolioAnalyticsService $portfolioAnalytics,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        if (!$this->isCsrfTokenValid('delete_broker_account_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $brokerAccount = $brokerAccountRepository->findOneForUser($user, $id);
        if (!$brokerAccount instanceof BrokerAccount) {
            throw $this->createNotFoundException('Broker account not found.');
        }

        $displayName = $brokerAccount->getDisplayName();
        $entityManager->remove($brokerAccount);
        $entityManager->flush();

        try {
            $portfolioAnalytics->recalculateForUser($user);
            $this->addFlash('success', sprintf('Deleted %s and its associated transactions.', $displayName));
        } catch (InsufficientSharesForSellException $exception) {
            $this->addFlash('warning', sprintf('Deleted %s, but portfolio analytics need attention: %s', $displayName, $exception->getMessage()));
        }

        return $this->redirectToRoute('app_broker_accounts');
    }
}
