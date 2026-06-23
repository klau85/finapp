<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PlaceholderController extends AbstractController
{
    #[Route('/upload', name: 'app_upload')]
    public function upload(): Response
    {
        return $this->redirectToRoute('app_transaction_upload');
    }

    #[Route('/watchlist', name: 'app_watchlist')]
    public function watchlist(): Response
    {
        return $this->page('Watchlist');
    }

    #[Route('/settings', name: 'app_settings')]
    public function settings(Request $request, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        $form = $this->createFormBuilder($user)
            ->add('numberFormat', ChoiceType::class, [
                'label' => 'Number format',
                'choices' => [
                    '1,234.56' => User::NUMBER_FORMAT_COMMA_DOT,
                    '1.234,56' => User::NUMBER_FORMAT_DOT_COMMA,
                ],
                'expanded' => true,
                'help' => 'Choose how numbers and money values are displayed across the app.',
            ])
            ->add('save', SubmitType::class, [
                'label' => 'Save settings',
                'attr' => ['class' => 'btn btn-dark'],
            ])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Settings saved.');

            return $this->redirectToRoute('app_settings');
        }

        return $this->render('settings/index.html.twig', [
            'form' => $form,
        ]);
    }

    private function page(string $title): Response
    {
        return $this->render('placeholder/page.html.twig', [
            'title' => $title,
        ]);
    }
}
