#!/usr/bin/env bash
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "Run with sudo"
  exit 1
fi

SRC="/home/vuola/.kube-cron-jobs/host-config/k3s-config.yaml"
DST="/etc/rancher/k3s/config.yaml"

mkdir -p /etc/rancher/k3s

if [ ! -f "$DST" ] || ! cmp -s "$SRC" "$DST"; then
  [ -f "$DST" ] && cp "$DST" "$DST.bak.$(date +%Y%m%d-%H%M%S)"
  install -m 644 "$SRC" "$DST"
  systemctl restart k3s
  echo "Applied $DST and restarted k3s"
else
  echo "No k3s host-config changes"
fi