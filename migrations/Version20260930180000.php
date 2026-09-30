<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Expiration du token de réinitialisation du mot de passe.
 */
final class Version20260930180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute user.reset_token_requested_at (expiration du token de réinitialisation)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD reset_token_requested_at DATETIME DEFAULT NULL');
        // Les tokens existants n'ont pas de date : ils sont invalidés.
        $this->addSql('UPDATE user SET reset_token = NULL WHERE reset_token IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP reset_token_requested_at');
    }
}
