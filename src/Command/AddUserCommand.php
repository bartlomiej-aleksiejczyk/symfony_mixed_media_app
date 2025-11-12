<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:add-user')]
class AddUserCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('username', InputArgument::REQUIRED, 'Username')
            ->addArgument('password', InputArgument::REQUIRED, 'User password');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = new User();
        $user->setUsername($input->getArgument('username'));
        $user->setPassword($this->hasher->hashPassword($user, $input->getArgument('password')));
        $user->setRoles(['ROLE_USER']);

        $this->em->persist($user);
        $this->em->flush();

        $output->writeln('✅ User added: ' . $user->getUsername());
        return Command::SUCCESS;
    }
}
