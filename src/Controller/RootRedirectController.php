<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Annotation\Route;

final class RootRedirectController extends AbstractController
{
    #[Route('/', name: 'root_redirect')]
    public function __invoke(): RedirectResponse
    {
        return $this->redirectToRoute('anonymous_message_new');
    }
}
