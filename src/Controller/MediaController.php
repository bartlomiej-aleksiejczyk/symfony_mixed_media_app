<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

final class MediaController extends AbstractController
{
    public function __construct(
        ParameterBagInterface   $params
    ) {
        $this->uploadsDirectory = $params->get('uploads_directory') ?? '';
    }
    private ?string $uploadsDirectory;

    #[Route('/uploads/{path}', name: 'media_path', requirements: ['path' => '.+'])]
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
        // $mime = MimeTypes::getDefault()->guessMimeType($full) ?? 'application/octet-stream';
        // 3) Build filesystem path (no realpath, no stat — Apache will validate against XSendFilePath)
        $full = Path::join($this->uploadsDirectory, $path);
        // 4) Tell Apache to serve it; keep body empty

        $forceDownload = $request->query->getBoolean('download'); // ?download=1

        $resp = new Response('', Response::HTTP_OK);
        $disposition = $resp->headers->makeDisposition(
            $forceDownload
                ? ResponseHeaderBag::DISPOSITION_ATTACHMENT
                : ResponseHeaderBag::DISPOSITION_INLINE,
            basename($path)
        );
        $resp->headers->set('Content-Disposition', $disposition);

        $resp->headers->set('X-Sendfile', $full);
        $resp->headers->set('X-Accel-Redirect', '/protected_uploads/' . $path);
        $resp->headers->set('Content-Type', '');
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
