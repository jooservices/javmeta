# Backup image

Build the pinned backup utility image from the repository root:

<pre><code>
docker build --file deploy/backup/Dockerfile --tag javmeta-backup:local .
</code></pre>

Run it with an owner-controlled env file and mounts for encrypted backup output
and the filesystem path that is also mounted into every Elasticsearch node:

<pre><code>
docker run --rm --user "$(id -u):$(id -g)" \
  --env-file /etc/javmeta/backup.env \
  --volume /srv/javmeta/backups:/backups \
  --volume /srv/javmeta/es-snapshots:/srv/javmeta/es-snapshots \
  javmeta-backup:local backup.sh --namespace=javmeta
</code></pre>

The image uses MongoDB's pinned Database Tools archive for amd64 or arm64 and
the official mongosh package for the same architecture. The env file supplies
<code>BACKUP_ROOT=/backups</code>, <code>BACKUP_NAMESPACE</code>,
<code>AGE_RECIPIENT</code>, <code>PGHOST</code>, <code>PGPORT</code>, <code>PGUSER</code>,
<code>PGPASSWORD</code>, <code>PGDATABASE</code>, <code>MONGO_URI</code>,
<code>ELASTICSEARCH_HOST</code>, and <code>ELASTICSEARCH_SNAPSHOT_PATH</code>. Elasticsearch with security
enabled also needs <code>ELASTICSEARCH_API_KEY</code>, or <code>ELASTICSEARCH_USERNAME</code> and <code>ELASTICSEARCH_PASSWORD</code>
(a login inside <code>ELASTICSEARCH_HOST</code> still works); they reach curl through its stdin config, never the command line. Keep the
private age identity separate from the public recipient and backup artifacts.
The script does not print connection values.

<code>ELASTICSEARCH_SNAPSHOT_PATH</code> must be an absolute path mounted at the
same location in the backup container and Elasticsearch nodes, and it must be
allowed by the server's static <code>path.repo</code> setting. Elasticsearch requires
each filesystem repository location to be under a configured <code>path.repo</code> entry
([official filesystem repository requirements](https://www.elastic.co/docs/reference/elasticsearch/configuration-reference/fs-repository-settings)).
Before writing a full backup, the script checks that the configured snapshot
path is allowed by <code>path.repo</code> on every node. It stops before creating backup
artifacts when the setting is absent or does not include that path.

Use <code>backup.sh --weekly</code> for a weekly copy. Retention defaults to seven daily
folders and four weekly folders. <code>backup.sh --dry-run --namespace=&lt;name&gt;</code> checks
configuration without connecting or writing. <code>backup.sh --prune-only
--namespace=&lt;name&gt;</code> applies retention only to folders with a valid manifest for
that same namespace.

Restore requires an explicit backup folder and namespace:

<pre><code>
docker run --rm --user "$(id -u):$(id -g)" \
  --env-file /etc/javmeta/restore.env \
  --volume /srv/javmeta/backups:/backups:ro \
  --volume /srv/javmeta/es-snapshots:/srv/javmeta/es-snapshots \
  --volume /etc/javmeta/age-identity:/run/secrets/age-identity:ro \
  javmeta-backup:local restore.sh \
    --backup-dir=/backups/daily/20261016T020000Z \
    --namespace=javmeta
</code></pre>

A non-empty target is refused unless <code>--force-namespace=&lt;same-name&gt;</code> is given.
That flag is exact-match checked before any target is changed. Restore verifies
all encrypted artifact hashes before decrypting or connecting.

For an isolated shared-service rehearsal, pass only a <code>javmeta_it*</code> namespace
to <code>rehearse.sh</code>; it creates unique fixtures and removes only those fixtures
and the backup folder it created.

When Elasticsearch filesystem snapshots are unavailable, run
<code>rehearse.sh --namespace=javmeta_it --postgres-mongo-only</code>. This explicitly
backs up and restores only PostgreSQL and MongoDB. The manifest lists the two
included components, the rehearsal reports Elasticsearch as pending, and the
partial backup cannot update <code>latest.json</code>. Elasticsearch is not considered
verified by this mode.

MongoDB uses the Database Tools archive and gzip options documented by MongoDB
([mongodump](https://www.mongodb.com/docs/database-tools/mongodump/),
[mongorestore](https://www.mongodb.com/docs/database-tools/mongorestore/)).
Artifacts are encrypted with age recipients and decrypted only from an
owner-provided identity ([age usage](https://github.com/FiloSottile/age)).

Staging daily backups and quick deployment dumps share `retention.sh`. Retention validates a positive integer (up to nine digits), sorts timestamp-based names with NUL delimiters, and removes only verified direct children of the canonical backup root. Directory symlinks are excluded; whitespace, quotes and option-like names remain single paths. `tests/Shell/retention.test.sh` verifies both retention modes and the daily script using temporary data and a dump stub.
