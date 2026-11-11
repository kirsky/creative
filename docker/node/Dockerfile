# Keep NODE_VERSION equal to .nvmrc (`make check` verifies it).
ARG NODE_VERSION=26
FROM node:${NODE_VERSION}

ARG APP_UID=1000
ARG APP_GID=1000

# Match the host user so node_modules/ and dist/ written into the bind mount
# belong to you.
RUN groupadd -o -g "${APP_GID}" app \
    && useradd -o -m -u "${APP_UID}" -g "${APP_GID}" -s /bin/bash app

USER app
WORKDIR /app
