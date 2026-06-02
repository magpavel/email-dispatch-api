<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260602194300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create emails table with status tracking and provider fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE emails (
            id UUID NOT NULL,
            recipient_email VARCHAR(320) NOT NULL,
            subject VARCHAR(998) NOT NULL,
            html_body TEXT NOT NULL,
            text_body TEXT DEFAULT NULL,
            metadata JSON NOT NULL,
            idempotency_key VARCHAR(255) DEFAULT NULL,
            preferred_provider VARCHAR(64) DEFAULT NULL,
            status VARCHAR(32) NOT NULL,
            provider VARCHAR(64) DEFAULT NULL,
            provider_message_id VARCHAR(255) DEFAULT NULL,
            retry_count INT NOT NULL,
            last_error TEXT DEFAULT NULL,
            request_id VARCHAR(64) DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            processed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_emails_idempotency_key ON emails (idempotency_key)');
        $this->addSql('CREATE INDEX idx_emails_provider_message_id ON emails (provider_message_id)');
        $this->addSql('CREATE INDEX idx_emails_status ON emails (status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE emails');
    }
}
