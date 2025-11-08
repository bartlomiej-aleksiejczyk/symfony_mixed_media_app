<?php
// src/Controller/AnonymousMessageController.php
namespace App\Controller;

use App\Entity\AnonymousMessage;
use App\Entity\Attachment;
use App\Form\AnonymousMessageType;
use App\Service\FileUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AnonymousMessageController extends AbstractController
{
    #[Route('/anonymous-message/new', name: 'anonymous_message_new')]
    public function new(
        Request                $request,
        EntityManagerInterface $em,
        FileUploader           $fileUploader,
    ): Response
    {
        $message = new AnonymousMessage();
        $form = $this->createForm(AnonymousMessageType::class, $message);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile[] $files */
            $files = $form->get('attachments')->getData();

            // 'attachments' is not required, so handle only if something uploaded
            if ($files) {
                foreach ($files as $file) {
                    $storedFilename = $fileUploader->upload($file);

                    $attachment = (new Attachment())
                        ->setFilename($storedFilename)
                        ->setOriginalName($file->getClientOriginalName())
                        ->setMimeType($file->getClientMimeType() ?? '')
                        ->setSize($file->getSize());

                    $message->addAttachment($attachment);
                }
            }

            $em->persist($message);
            $em->flush();

            return $this->redirectToRoute('anonymous_message_new');
        }

        return $this->render('anonymous_message/new.html.twig', [
            'form' => $form,
        ]);
    }
}
