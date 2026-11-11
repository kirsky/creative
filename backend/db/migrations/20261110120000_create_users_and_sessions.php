<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Accounts and their server-side sessions.
 *
 * users.password_hash is NULL until credentials are generated: such an account cannot log in.
 * sessions stores only the SHA-256 of the cookie token, never the token itself, so a copy of the
 * table cannot be replayed as cookies. All timestamps are UTC.
 */
final class CreateUsersAndSessions extends AbstractMigration
{
    // Phinx 0.16 makes columns nullable unless told otherwise, so every column says 'null'.
    public function change(): void
    {
        $this->table('users', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false, 'null' => false])
            ->addColumn('username', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('display_name', 'string', ['limit' => 200, 'null' => false])
            ->addColumn('role', 'enum', ['values' => ['buyer', 'designer', 'manager'], 'null' => false])
            ->addColumn('locale', 'enum', ['values' => ['en', 'ru'], 'default' => 'en', 'null' => false])
            ->addColumn('password_hash', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['username'], ['unique' => true])
            ->create();

        $this->table('sessions', ['id' => false, 'primary_key' => 'token_hash'])
            // Hex SHA-256 of the cookie token.
            ->addColumn('token_hash', 'char', ['limit' => 64, 'encoding' => 'ascii', 'collation' => 'ascii_bin', 'null' => false])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => false])
            // Hex of 32 random bytes; sent to the client by GET /api/auth/me, echoed in X-CSRF-Token.
            ->addColumn('csrf_token', 'char', ['limit' => 64, 'encoding' => 'ascii', 'collation' => 'ascii_bin', 'null' => false])
            // Room for an IPv6 address in text form.
            ->addColumn('ip', 'string', ['limit' => 45, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('last_seen_at', 'datetime', ['null' => false])
            ->addIndex(['user_id'])
            ->addIndex(['last_seen_at'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'RESTRICT', 'constraint' => 'sessions_user_id_fk'])
            ->create();
    }
}
