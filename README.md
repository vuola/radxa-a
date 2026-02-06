# Radxa-A weather archive setup

This repository documents the weather archive stack running on radxa-a.local and the upload workflow from moxa.local.

## Overview

- **radxa-a.local** runs a k3s cluster that hosts:
  - MariaDB for weather data (persisted to the SSD at /media/ssd250)
  - NGINX + PHP for a simple web UI and ingest endpoint
  - Daily DB backups to /media/ssd250/weather/backups
- **moxa.local** keeps the live SQLite database and uploads an online backup once per day.

## Components on radxa-a.local

### Local manifest overlay

Local manifests live at:

- /home/vuola/.kube-cron-jobs/local-manifests/weather.yaml

The update script applies both pubcluster manifests and local manifests:

- /home/vuola/.kube-cron-jobs/update-cluster.sh

Local manifests are applied every run and use HOSTNAME replacement to pin local paths to the correct node.

### Weather namespace resources

Deployed in namespace `weather`:

- **StatefulSet**: weather-mariadb (MariaDB 10.11)
- **Service**: weather-mariadb (ClusterIP:3306)
- **CronJob**: weather-mariadb-backup (nightly backups)
- **Deployment**: weather-web (NGINX + PHP-FPM)
- **Service**: weather-web (ClusterIP:80)
- **Ingress**: weather-web (Traefik)

### Storage paths (radxa-a.local)

- MariaDB data: /media/ssd250/weather/mariadb
- Backups: /media/ssd250/weather/backups
- Upload inbox: /media/ssd250/weather/inbox

### Endpoints

- Web UI: http://weather.radxa-a.local/
- Ingest endpoint:
  - POST JSON to http://weather.radxa-a.local/ingest.php
  - Upload SQLite file as multipart field `sqlite` to the same endpoint

## moxa.local upload workflow (Option A)

**Goal**: upload a daily online SQLite backup at midnight without stopping weather services.

### Script

Path on moxa.local:

- /usr/local/bin/moxa-upload-archive.sh

Behavior:

1. Creates an online SQLite backup of /var/lib/moxa/weather.db
2. Uploads the backup to http://weather.radxa-a.local/ingest.php
3. On success, deletes rows older than 24 hours and truncates WAL

### Systemd unit and timer

- /etc/systemd/system/moxa-archive-upload.service
- /etc/systemd/system/moxa-archive-upload.timer

Timer schedule:

- Daily at 00:00:00 (midnight)

### Install commands (run on radxa-a.local)

Copy files to moxa.local:

```
scp /home/vuola/moxa-upload/moxa-upload-archive.sh vuola@moxa.local:/tmp/
scp /home/vuola/moxa-upload/moxa-archive-upload.service vuola@moxa.local:/tmp/
scp /home/vuola/moxa-upload/moxa-archive-upload.timer vuola@moxa.local:/tmp/
```

Install on moxa.local:

```
ssh vuola@moxa.local <<'EOF'
sudo mv /tmp/moxa-upload-archive.sh /usr/local/bin/moxa-upload-archive.sh
sudo chmod 755 /usr/local/bin/moxa-upload-archive.sh

sudo mv /tmp/moxa-archive-upload.service /etc/systemd/system/moxa-archive-upload.service
sudo mv /tmp/moxa-archive-upload.timer /etc/systemd/system/moxa-archive-upload.timer

sudo systemctl daemon-reload
sudo systemctl enable --now moxa-archive-upload.timer
sudo systemctl status moxa-archive-upload.timer --no-pager
EOF
```

Optional immediate run:

```
ssh vuola@moxa.local "sudo /usr/local/bin/moxa-upload-archive.sh"
```

## DNS / mDNS notes

If radxa-a.local cannot resolve moxa.local, enable mDNS on radxa-a.local:

- Install `avahi-daemon` and `libnss-mdns`
- Start and enable avahi

If you need a quick workaround, add moxa’s IP to /etc/hosts on radxa-a.local.

## Quick checks

On radxa-a.local:

```
kubectl -n weather get pods,svc,ingress
curl -sSf http://192.168.68.111/ -H "Host: weather.radxa-a.local"
```

On moxa.local:

```
ls -l /var/lib/moxa/weather.db
sqlite3 -header -column /var/lib/moxa/weather.db "SELECT COUNT(*) FROM weather;"
sudo systemctl status moxa-archive-upload.timer --no-pager
```
