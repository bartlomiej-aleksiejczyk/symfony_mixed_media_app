<?php
namespace App\Controller;

use App\Entity\Attachment;
use App\Entity\Message;
use App\Form\MessageType;
use App\Service\FileUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MessageController extends AbstractController
{
    #[Route('/message/new', name: 'message_new')]
    public function new(
        Request                $request,
        EntityManagerInterface $em,
        FileUploader           $fileUploader,
    ): Response
    {
        $message = new Message();
        $form = $this->createForm(MessageType::class, $message);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile[] $files */
            $files = $form->get('attachments')->getData();

            // 'attachments' is not required, so handle only if something uploaded
            if ($files) {
                foreach ($files as $file) {
                    $fileSize = $file->getSize();
                    $storedFilename = $fileUploader->upload($file);

                    $attachment = (new Attachment())
                        ->setFilename($storedFilename)
                        ->setOriginalName($file->getClientOriginalName())
                        ->setMimeType($file->getClientMimeType() ?? '')
                        ->setSize($fileSize);

                    $message->addAttachment($attachment);
                }
            }

            $em->persist($message);
            $em->flush();

            return $this->redirectToRoute('message_new');
        }

        return $this->render('message/new.html.twig', [
            'form' => $form,
        ]);
    }
}
