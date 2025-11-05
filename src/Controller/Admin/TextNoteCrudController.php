<?php

namespace App\Controller\Admin;

use App\Entity\TextNote;
use DateTime;
use DateTimeImmutable;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;

class TextNoteCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return TextNote::class;
    }

    public function createEntity(string $entityFqcn): TextNote
    {
        $note = new TextNote();
        $note->setCreatedAt(new DateTimeImmutable());
        $note->setLastModifiedAt(new DateTime());

        return $note;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            Field::new('title'),
            Field::new('content'),
            DateTimeField::new('createdAt')->setFormTypeOptions(['disabled' => true]),  // Disable editing
            DateTimeField::new('lastModifiedAt')->setFormTypeOptions(['disabled' => true]),  // Disable editing
        ];
    }
}
