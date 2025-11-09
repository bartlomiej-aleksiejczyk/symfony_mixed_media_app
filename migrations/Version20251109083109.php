<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251109083109 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE anonymous_messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , title VARCHAR(255) NOT NULL, content CLOB NOT NULL, content_type VARCHAR(50) NOT NULL)');
        $this->addSql('CREATE TABLE attachments (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, message_id INTEGER NOT NULL, filename VARCHAR(255) NOT NULL, original_name VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, size INTEGER NOT NULL, CONSTRAINT FK_47C4FAD6537A1329 FOREIGN KEY (message_id) REFERENCES anonymous_messages (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_47C4FAD6537A1329 ON attachments (message_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE anonymous_messages');
        $this->addSql('DROP TABLE attachments');
    }
}
