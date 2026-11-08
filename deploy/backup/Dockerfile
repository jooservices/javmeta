FROM postgres:18.6-trixie

ARG MONGODB_DATABASE_TOOLS_VERSION=100.19.1
ARG MONGOSH_VERSION=2.8.1

RUN apt-get update \
    && apt-get install -y --no-install-recommends age ca-certificates curl gnupg jq libgssapi-krb5-2 libkrb5-3 tar \
    && curl -fsSL https://pgp.mongodb.com/server-8.0.asc \
        | gpg --dearmor --yes -o /usr/share/keyrings/mongodb-server-8.0.gpg \
    && printf '%s\n' \
        'deb [arch=amd64,arm64 signed-by=/usr/share/keyrings/mongodb-server-8.0.gpg] https://repo.mongodb.org/apt/debian bookworm/mongodb-org/8.0 main' \
        > /etc/apt/sources.list.d/mongodb-org-8.0.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        "mongodb-mongosh=${MONGOSH_VERSION}" \
    && rm -rf /var/lib/apt/lists/*

RUN set -eu; \
    case "$(dpkg --print-architecture)" in \
        amd64) mongo_tools_platform='debian13-x86_64' ;; \
        arm64) mongo_tools_platform='ubuntu2404-arm64' ;; \
        *) printf 'Unsupported architecture for MongoDB Database Tools.\n' >&2; exit 1 ;; \
    esac; \
    curl -fsSL "https://fastdl.mongodb.org/tools/db/mongodb-database-tools-${mongo_tools_platform}-${MONGODB_DATABASE_TOOLS_VERSION}.tgz" \
        | tar -xz --strip-components=2 -C /usr/local/bin \
            "mongodb-database-tools-${mongo_tools_platform}-${MONGODB_DATABASE_TOOLS_VERSION}/bin/mongodump" \
            "mongodb-database-tools-${mongo_tools_platform}-${MONGODB_DATABASE_TOOLS_VERSION}/bin/mongorestore"; \
    chmod 0755 /usr/local/bin/mongodump /usr/local/bin/mongorestore; \
    mongodump --version; \
    mongorestore --version

COPY deploy/backup/common.sh deploy/backup/backup.sh deploy/backup/restore.sh deploy/backup/rehearse.sh /usr/local/bin/
RUN chmod 0755 /usr/local/bin/common.sh /usr/local/bin/backup.sh /usr/local/bin/restore.sh /usr/local/bin/rehearse.sh

WORKDIR /backups
CMD ["backup.sh", "--help"]
