#!/usr/bin/env bash
# Run once (or again) as root on a fresh Ubuntu LTS node, never on the workstation.
set -euo pipefail
[[ "$EUID" == 0 ]] || { echo 'Run as root on the production node.' >&2; exit 1; }
# shellcheck source=/dev/null
source /etc/os-release
[[ "$ID" == ubuntu && "${VERSION_ID%%.*}" -ge 24 ]] || { echo 'Ubuntu 24.04 or newer required.' >&2; exit 1; }
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../../.." && pwd)"
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y ca-certificates curl gnupg software-properties-common git gh unzip jq nginx python3-venv python3-pip redis-tools prometheus-node-exporter sudo
# Official PHP image uses source builds; on native Ubuntu use the maintained Ondrej PPA.
add-apt-repository -y ppa:ondrej/php
curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor --yes -o /usr/share/keyrings/nodesource.gpg
printf 'deb [signed-by=/usr/share/keyrings/nodesource.gpg] https://deb.nodesource.com/node_24.x nodistro main\n' > /etc/apt/sources.list.d/nodesource.list
# PG18 tools read PG18 dumps; Ubuntu 24.04's default client cannot dump a newer server.
install -d /usr/share/postgresql-common/pgdg
curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc
printf 'deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt %s-pgdg main\n' "$VERSION_CODENAME" > /etc/apt/sources.list.d/pgdg.list
apt-get update
apt-get install -y php8.5-cli php8.5-fpm php8.5-pgsql php8.5-mongodb php8.5-redis php8.5-intl php8.5-mbstring php8.5-xml php8.5-curl php8.5-zip php8.5-bcmath nodejs postgresql-client-18
update-alternatives --set php /usr/bin/php8.5
# shellcheck disable=SC2016 # PHP variables are interpreted by PHP.
php8.5 -r 'foreach (["mongodb", "redis", "pdo_pgsql", "pcntl", "intl", "mbstring"] as $ext) { if (!extension_loaded($ext)) { exit(1); } }'
# Verify the installer signature before executing; do not replace an existing Composer binary.
if [[ ! -x /usr/local/bin/composer ]]; then
  installer="$(mktemp)"
  trap 'rm -f "$installer"' EXIT
  curl -fsSL https://getcomposer.org/installer -o "$installer"
  expected="$(curl -fsSL https://composer.github.io/installer.sig)"
  [[ "$(sha384sum "$installer" | cut -d ' ' -f1)" == "$expected" ]]
  php8.5 "$installer" --install-dir=/usr/local/bin --filename=composer
  rm -f "$installer"
  trap - EXIT
fi
id xcrawler >/dev/null 2>&1 || useradd --system --home-dir /var/www/xcrawler --shell /usr/sbin/nologin xcrawler
usermod -aG xcrawler www-data
install -d -o xcrawler -g xcrawler -m 2750 /var/www/xcrawler /var/www/xcrawler/releases /var/www/xcrawler/shared
for path in app/private app/public framework/cache/data framework/sessions framework/views logs; do
  install -d -o xcrawler -g xcrawler -m 2770 "/var/www/xcrawler/shared/storage/$path"
done
install -d -o xcrawler -g xcrawler -m 2770 /var/www/xcrawler/shared/huggingface
# Preserve an existing .env; the operator fills it privately before first deployment.
if [[ ! -e /var/www/xcrawler/shared/.env ]]; then
  install -o xcrawler -g xcrawler -m 0640 "$root/deploy/production/env.production.example" /var/www/xcrawler/shared/.env
fi
if [[ ! -x /var/www/xcrawler/shared/embedder-venv/bin/python ]]; then
  runuser -u xcrawler -- python3 -m venv /var/www/xcrawler/shared/embedder-venv
fi
runuser -u xcrawler -- /var/www/xcrawler/shared/embedder-venv/bin/pip install -r "$root/embedder/requirements.production.txt"
configuration_changed=0
install_if_changed() {
  local source_file="$1" target="$2" mode="$3"
  if ! cmp -s "$source_file" "$target" || [[ "$(stat -c '%a' "$target" 2>/dev/null || true)" != "${mode#0}" ]]; then
    install -m "$mode" "$source_file" "$target"
    configuration_changed=1
  fi
}
install_if_changed "$root/deploy/production/node/xcrawler-fpm.conf" /etc/php/8.5/fpm/pool.d/xcrawler.conf 0644
install_if_changed "$root/deploy/production/node/xcrawler.nginx.conf" /etc/nginx/sites-available/xcrawler 0644
if [[ ! -L /etc/nginx/sites-enabled/xcrawler || "$(readlink /etc/nginx/sites-enabled/xcrawler)" != /etc/nginx/sites-available/xcrawler ]]; then
  ln -sfn /etc/nginx/sites-available/xcrawler /etc/nginx/sites-enabled/xcrawler
  configuration_changed=1
fi
install_if_changed "$root/deploy/production/node/xcrawler-backup.service" /etc/systemd/system/xcrawler-backup.service 0644
install_if_changed "$root/deploy/production/node/xcrawler-backup.timer" /etc/systemd/system/xcrawler-backup.timer 0644
install -d -o xcrawler -g xcrawler -m 0700 /var/www/xcrawler/shared/backups
for service in horizon scheduler embedder; do
  install_if_changed "$root/deploy/production/node/xcrawler-$service.service" "/etc/systemd/system/xcrawler-$service.service" 0644
done
# The deployment account can control these units only, never arbitrary systemd services.
sudoers_file="$(mktemp)"
trap 'rm -f "$sudoers_file"' EXIT
cat > "$sudoers_file" <<'SUDOERS'
xcrawler ALL=(root) NOPASSWD: /usr/bin/systemctl stop xcrawler-scheduler xcrawler-horizon, /usr/bin/systemctl restart php8.5-fpm xcrawler-embedder xcrawler-horizon, /usr/bin/systemctl start xcrawler-scheduler, /usr/bin/systemctl restart php8.5-fpm xcrawler-embedder xcrawler-horizon xcrawler-scheduler, /usr/bin/systemctl stop xcrawler-horizon xcrawler-scheduler xcrawler-embedder
SUDOERS
if ! cmp -s "$sudoers_file" /etc/sudoers.d/xcrawler-units || [[ "$(stat -c '%a' /etc/sudoers.d/xcrawler-units 2>/dev/null || true)" != 440 ]]; then
  install -m 0440 "$sudoers_file" /etc/sudoers.d/xcrawler-units
  configuration_changed=1
fi
visudo -cf /etc/sudoers.d/xcrawler-units
nginx -t
php-fpm8.5 -t
systemctl daemon-reload
systemctl enable nginx php8.5-fpm prometheus-node-exporter xcrawler-horizon xcrawler-scheduler xcrawler-embedder
# App units start after the first release. Configure the node firewall to allow HTTP only from the TLS gatekeeper.
if (( configuration_changed )); then
  systemctl restart nginx php8.5-fpm
  systemctl try-restart xcrawler-horizon xcrawler-scheduler xcrawler-embedder
else
  systemctl start nginx php8.5-fpm
fi
systemctl start prometheus-node-exporter
# Timer can be enabled before the first release; the service condition skips absent code.
systemctl enable --now xcrawler-backup.timer
