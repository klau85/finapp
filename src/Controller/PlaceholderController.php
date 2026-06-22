<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
    public function settings(): Response
    {
        return $this->page('Settings');
    }

    private function page(string $title): Response
    {
        return $this->render('placeholder/page.html.twig', [
            'title' => $title,
        ]);
    }
}
