#!/bin/sh
# Runs once, when the data volume is first initialised. The image has already
# created ${MARIADB_DATABASE} and ${MARIADB_USER}; add the database that
# PHPUnit runs against, with the same character set.
set -eu

mariadb -uroot -p"${MARIADB_ROOT_PASSWORD}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_TEST_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`${DB_TEST_NAME}\`.* TO '${MARIADB_USER}'@'%';
SQL
