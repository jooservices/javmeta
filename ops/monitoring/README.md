# JOOXCR-388 monitoring

Read-only baseline copies of the production Prometheus and Alertmanager configuration on `192.168.10.10`, maintained with the JOOXCR-388 change steps. Prometheus configuration and rules are under `prometheus/`; Alertmanager configuration is under `alertmanager/`.

Changes are validated offline with the production-matched Prometheus and Alertmanager tools, then copied to the VM one logical step at a time after a live backup. Prometheus and Alertmanager use SIGHUP reloads only; production services are never restarted.

Secrets and exporter credentials stay outside this tree.
