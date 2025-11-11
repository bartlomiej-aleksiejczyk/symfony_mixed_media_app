<?php

namespace App\Controller;

use App\Entity\Attachment;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

final class MediaController extends AbstractController
{
    #[Route('/media/{path}', name: 'media_path', requirements: ['path' => '.+'])]
    public function byPath(string $path, ParameterBagInterface $params): Response
    {
//        die();
        $baseDir = rtrim($params->get('message_attachments_directory'), DIRECTORY_SEPARATOR);

        $candidate = $baseDir . DIRECTORY_SEPARATOR . $path;
        $real = realpath($candidate);
        if ($real === false || strncmp($real, $baseDir . DIRECTORY_SEPARATOR, strlen($baseDir) + 1) !== 0) {
            throw $this->createNotFoundException();
        }

        $mime = mime_content_type($real) ?: 'application/octet-stream';
        $response = new Response();
        $response->headers->set('Content-Type', $mime);
        $response->headers->set('Content-Disposition',
            $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_INLINE, basename($real))
        );
        $response->headers->set('X-Sendfile', $real);
        return $response;
    }

}
