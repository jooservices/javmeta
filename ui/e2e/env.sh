# Environment of the browser end-to-end run: a throwaway local PostgreSQL database plus a local Mongo and Redis
# (docker run, published on 127.0.0.1:27018 and :6380). Needs E2E_DB_PASSWORD (host/port default to the local docker Postgres).
export APP_ENV=local APP_DEBUG=false JAVMETA_ALLOW_SAMPLE_SECRETS=true
export APP_KEY="${APP_KEY:-base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=}"
export DB_CONNECTION=pgsql DB_URL= DB_HOST="${E2E_DB_HOST:-127.0.0.1}" DB_PORT="${E2E_DB_PORT:-15435}" DB_DATABASE="${E2E_DB_NAME:-jvmeta_e2e}" DB_USERNAME="${E2E_DB_USER:-postgres}" DB_PASSWORD="$E2E_DB_PASSWORD"
export MONGO_URI= MONGODB_URI= MONGO_HOST=127.0.0.1 MONGO_PORT=27018 MONGO_DATABASE=javmeta_e2e MONGODB_DATABASE=javmeta_e2e
export REDIS_HOST=127.0.0.1 REDIS_PORT=6380 REDIS_USERNAME= REDIS_PASSWORD= REDIS_PREFIX=javmeta_e2e: REDIS_DB=0 REDIS_CACHE_DB=1
export CACHE_STORE=database CACHE_PREFIX=javmeta_e2e_ SESSION_DRIVER=file QUEUE_CONNECTION=sync MAIL_MAILER=array
export ELASTICSEARCH_HOST=http://127.0.0.1:1 EMBEDDER_URL=http://127.0.0.1:1
