<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * AI providers and the AI credits a designer reports per round.
 *
 * ai_providers is the managed list: name is UNIQUE under utf8mb4_unicode_ci, which compares without
 * regard to case (and accents), so "Runway" and "runway" are one provider whoever inserts them; the
 * application also trims and collapses the spaces of a name before it gets here. The migration seeds
 * the common tools; providers a designer adds are created on first use. Providers are never deleted
 * by the application, and ai_usage.provider_id is RESTRICT so that a provider in use cannot go.
 *
 * ai_usage has one row per reported line of a round, in insertion order (id). A round may have
 * several lines of the same provider. credits is DECIMAL(12,2), never a float, and CHECK credits >= 0;
 * the application accepts 0 to 9999999.99. Lines go with their round (CASCADE), which goes with its
 * task (the demo reset).
 */
final class CreateAiUsage extends AbstractMigration
{
    private const array DEFAULT_PROVIDERS = ['Midjourney', 'Runway', 'Kling', 'ElevenLabs', 'OpenAI', 'Adobe Firefly'];

    public function up(): void
    {
        $this->execute(
            'CREATE TABLE ai_providers (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(50) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ai_providers_name_uq (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $this->execute(
            'CREATE TABLE ai_usage (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                round_id INT UNSIGNED NOT NULL,
                provider_id INT UNSIGNED NOT NULL,
                credits DECIMAL(12,2) NOT NULL,
                PRIMARY KEY (id),
                KEY ai_usage_round_id_idx (round_id, id),
                KEY ai_usage_provider_id_idx (provider_id),
                CONSTRAINT ai_usage_round_id_fk FOREIGN KEY (round_id) REFERENCES rounds (id) ON DELETE CASCADE ON UPDATE RESTRICT,
                CONSTRAINT ai_usage_provider_id_fk FOREIGN KEY (provider_id) REFERENCES ai_providers (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT ai_usage_credits_not_negative CHECK (credits >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        foreach (self::DEFAULT_PROVIDERS as $name) {
            $this->execute(sprintf("INSERT INTO ai_providers (name) VALUES ('%s')", $name));
        }
    }

    public function down(): void
    {
        $this->execute('DROP TABLE ai_usage');
        $this->execute('DROP TABLE ai_providers');
    }
}
