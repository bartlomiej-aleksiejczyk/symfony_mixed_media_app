<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MediaController extends AbstractController
{
    public function __construct(
        private readonly string $uploadsDirectory,
        ParameterBagInterface   $params
    )
    {
        $this->$uploadsDirectory = $params->get('uploads_directory');
    }
    #[Route('/media/{path}', name: 'media_path', requirements: ['path' => '.+'])]
    public function byPath(string $path, Request $request): Response
    {
        // 1) AUTHZ ONLY — decide yes/no (no DB if you can; e.g., roles/claims in session/JWT)
        // if (!$this->isGranted('DOWNLOAD_ALLOWED')) { // your check
        //     throw $this->createAccessDeniedException();
        // }

        // 2) Minimal path hygiene: reject absolute paths and traversal attempts
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
            throw $this->createNotFoundException(); // don’t leak info
        }

        // 3) Build filesystem path (no realpath, no stat — Apache will validate against XSendFilePath)
        $full = Path::join($this->uploadsDirectory, $path);

        // 4) Tell Apache to serve it; keep body empty
        $resp = new Response('', Response::HTTP_OK);
        $resp->headers->set('X-Sendfile', $full);

        // Optional: force a download name (if you want “original names”).
        // If you skip this, Apache serves with the file’s own name and type.
        // $resp->headers->set(
        //     'Content-Disposition',
        //     $resp->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_INLINE, basename($path))
        // );

        // Optional caching hints (Apache will still handle 206 ranges, length, etc.)
        // $resp->setPrivate(); // or ->setPublic() if files are not sensitive

        return $resp;
    }
}
