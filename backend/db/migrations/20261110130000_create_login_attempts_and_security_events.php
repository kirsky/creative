<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The login rate limiter's failures and the security event log. All timestamps are UTC.
 *
 * login_attempts holds one row per failed login. `username` is the attempted name, trimmed and
 * case-folded, in the same collation as users.username (utf8mb4_unicode_ci), so the database
 * itself decides which spellings are one account: Anna, ANNA, "anna " and ánna are one bucket.
 * `ip` is the address bucket, not the raw address: an IPv4 address as is, an IPv6 address as its /64
 * prefix ("2001:db8:1:2::/64"). Rows older than 24 hours are deleted as new failures arrive.
 *
 * security_events is the audit trail. `details` is a JSON object that never holds a password, a
 * hash or a token (SecurityEventLog refuses such keys). user_id is NULL when no account is
 * involved (a login as an unknown username) and survives the account as NULL.
 */
final class CreateLoginAttemptsAndSecurityEvents extends AbstractMigration
{
    // Phinx 0.16 makes columns nullable unless told otherwise, so every column says 'null'.
    public function change(): void
    {
        $this->table('login_attempts', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false, 'null' => false])
            ->addColumn('username', 'string', ['limit' => 100, 'encoding' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'null' => false])
            // Room for the longest bucket, a /64 prefix ("ffff:ffff:ffff:ffff::/64"), and an IPv4 address.
            ->addColumn('ip', 'string', ['limit' => 45, 'encoding' => 'ascii', 'collation' => 'ascii_bin', 'null' => false])
            ->addColumn('attempted_at', 'datetime', ['null' => false])
            // Both limits ask "the newest failures of this username (or address)".
            ->addIndex(['username', 'attempted_at'])
            ->addIndex(['ip', 'attempted_at'])
            // The purge.
            ->addIndex(['attempted_at'])
            ->create();

        $this->table('security_events', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false, 'null' => false])
            ->addColumn('type', 'string', ['limit' => 50, 'encoding' => 'ascii', 'collation' => 'ascii_bin', 'null' => false])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('details', 'json', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['user_id', 'created_at'])
            ->addIndex(['created_at'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'RESTRICT', 'constraint' => 'security_events_user_id_fk'])
            ->create();
    }
}
