DOCKER_COMPOSE ?= docker compose
PHP_SERVICE ?= api
MODE ?= local

# Profile flag groups (repeat --profile per value: comma form is not parsed).
PROFILE_ALL = --profile app --profile data --profile openobserve --profile flare --profile control --profile embed
PROFILE_CRAWLER = --profile app --profile flare
PROFILE_CONTROL = --profile app --profile control --profile embed
PROFILE_NODE = --profile app --profile flare --profile control --profile embed

# Worker instances come from .env (JAVMETA_WORKER_INSTANCES); override on the
# command line wins: `make up JAVMETA_WORKER_INSTANCES=4`.
JAVMETA_WORKER_INSTANCES ?= 2
ifneq ($(strip $(shell grep -E '^JAVMETA_WORKER_INSTANCES=' .env 2>/dev/null)),)
JAVMETA_WORKER_INSTANCES := $(shell grep -E '^JAVMETA_WORKER_INSTANCES=' .env 2>/dev/null | head -1 | cut -d= -f2-)
endif

API_SERVICES = api
WORKER_SERVICES = worker
CONTROL_SERVICES = scheduler mcp
DATA_SERVICES = postgres mongo elasticsearch
OBS_SERVICES = openobserve
FLARE_SERVICES = flaresolverr
EMBED_SERVICES = embedder
CRAWLER_SERVICES = $(WORKER_SERVICES) $(FLARE_SERVICES)
CONTROL_NODE_SERVICES = $(API_SERVICES) $(CONTROL_SERVICES) $(EMBED_SERVICES)
NODE_SERVICES = $(CONTROL_NODE_SERVICES) $(CRAWLER_SERVICES)
LOCAL_SERVICES = $(API_SERVICES) $(WORKER_SERVICES) $(DATA_SERVICES) $(OBS_SERVICES) $(FLARE_SERVICES) $(CONTROL_SERVICES) $(EMBED_SERVICES)

.PHONY: build crawl down install lint migrate scheduler setup shell test test-integration up validate

build:
	$(DOCKER_COMPOSE) build

install:
	$(DOCKER_COMPOSE) run --rm $(PHP_SERVICE) composer install

shell:
	$(DOCKER_COMPOSE) run --rm $(PHP_SERVICE) bash

validate:
	$(DOCKER_COMPOSE) run --rm $(PHP_SERVICE) composer validate --strict

lint:
	$(DOCKER_COMPOSE) run --rm $(PHP_SERVICE) composer lint

test:
	$(DOCKER_COMPOSE) run --rm $(PHP_SERVICE) composer test

test-integration:
	docker/ci/integration

migrate:
	@case "$(MODE)" in \
		local|"") \
			$(DOCKER_COMPOSE) run --rm $(PHP_SERVICE) php artisan migrate --force; \
			;; \
		fresh) \
			$(DOCKER_COMPOSE) run --rm $(PHP_SERVICE) sh -lc 'if [ "$$APP_ENV" != "local" ]; then echo "migrate MODE=fresh is allowed only when APP_ENV=local."; exit 2; fi; php artisan migrate:fresh --force'; \
			;; \
		*) \
			echo "Unsupported MODE='$(MODE)' for migrate. Use MODE=fresh or omit MODE."; \
			exit 2; \
			;; \
	esac

setup:
	@case "$(SERVICE)" in \
		elasticsearch) \
			if [ "$(REINDEX)" = "1" ]; then \
				$(DOCKER_COMPOSE) run --rm --no-deps $(PHP_SERVICE) php artisan es:setup --reindex; \
			else \
				$(DOCKER_COMPOSE) run --rm --no-deps $(PHP_SERVICE) php artisan es:setup; \
			fi; \
			;; \
		*) \
			echo "Unsupported SERVICE='$(SERVICE)'. Use SERVICE=elasticsearch."; \
			exit 2; \
			;; \
	esac

# Modes:
# - local: all bundled services (develop default).
# - crawler: worker + FlareSolverr; data/observability are external.
# - control: API + scheduler + MCP + embedder; crawl/data dependencies are external.
# - node: control + crawler on one host; data/observability are external.
up:
	@case "$(MODE)" in \
		local) \
			test -f .env || cp .env.example .env; \
			$(DOCKER_COMPOSE) $(PROFILE_ALL) up -d --scale worker=$(JAVMETA_WORKER_INSTANCES) $(LOCAL_SERVICES); \
			;; \
		crawler) \
			$(DOCKER_COMPOSE) $(PROFILE_CRAWLER) up -d --scale worker=$(JAVMETA_WORKER_INSTANCES) $(CRAWLER_SERVICES); \
			;; \
		control) \
			$(DOCKER_COMPOSE) $(PROFILE_CONTROL) up -d $(CONTROL_NODE_SERVICES); \
			;; \
		node) \
			$(DOCKER_COMPOSE) $(PROFILE_NODE) up -d --scale worker=$(JAVMETA_WORKER_INSTANCES) $(NODE_SERVICES); \
			;; \
		*) \
			echo "Unsupported MODE='$(MODE)'. Use MODE=local, crawler, control, or node."; \
			exit 2; \
			;; \
	esac

down:
	$(DOCKER_COMPOSE) $(PROFILE_ALL) down

scheduler:
	$(DOCKER_COMPOSE) run --rm scheduler

crawl:
	@case "$(ACTION)" in \
		tick) \
			$(DOCKER_COMPOSE) run --rm $(PHP_SERVICE) php artisan crawler:sync-sources && \
			$(DOCKER_COMPOSE) run --rm $(PHP_SERVICE) php artisan crawler:reclaim && \
			if [ -n "$(SITE)" ]; then \
				$(DOCKER_COMPOSE) run --rm $(PHP_SERVICE) php artisan crawler:seed --source=$(SITE) --limit=$(or $(LIMIT),50); \
			else \
				$(DOCKER_COMPOSE) run --rm $(PHP_SERVICE) php artisan crawler:seed --limit=$(or $(LIMIT),50); \
			fi; \
			;; \
		dispatch) \
			$(DOCKER_COMPOSE) run --rm $(PHP_SERVICE) php artisan crawler:feed-pool --limit=$(or $(LIMIT),50); \
			;; \
		source) \
			$(DOCKER_COMPOSE) run --rm $(PHP_SERVICE) php artisan crawler:run-source $(SITE) --dispatch --limit=$(or $(LIMIT),20); \
			;; \
		*) \
			echo "Unsupported ACTION='$(ACTION)'. Use ACTION=tick, dispatch, or source."; \
			exit 2; \
			;; \
	esac
