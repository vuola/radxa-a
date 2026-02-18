#!/bin/bash
username="vuola"
kube_cron_jobs_dir="/home/"$username"/.kube-cron-jobs"
manifests_dir="/var/lib/rancher/k3s/server/manifests"
local_manifests_dir="$kube_cron_jobs_dir/local-manifests"
web_files_dir="$kube_cron_jobs_dir/weather-web"
web_config_manifest="$local_manifests_dir/weather-web-config.yaml"
scripts_dir="$kube_cron_jobs_dir/weather-scripts"
entsoe_manifest="$local_manifests_dir/entsoe-importer-script.yaml"
fmi_manifest="$local_manifests_dir/fmi-importer-script.yaml"
sqlite_manifest="$local_manifests_dir/weather-sqlite-import-script.yaml"
fusion_view_manifest="$local_manifests_dir/create-fusion-view-script.yaml"
new_host="$(hostname)"

generate_weather_web_config() {
  if [ -d "$web_files_dir" ]; then
    echo "Generating weather-web-config ConfigMap from: $web_files_dir"
    kubectl create configmap weather-web-config \
      --from-file=nginx.conf="$web_files_dir/nginx.conf" \
      --from-file=index.php="$web_files_dir/index.php" \
      --from-file=export.php="$web_files_dir/export.php" \
      --from-file=ingest.php="$web_files_dir/ingest.php" \
      --from-file=php-ext.ini="$web_files_dir/php-ext.ini" \
      -n weather \
      --dry-run=client -o yaml > "$web_config_manifest"
  fi
}

generate_weather_script_configs() {
  if [ -f "$scripts_dir/entsoe_import.py" ]; then
    echo "Generating entsoe-importer-script ConfigMap from: $scripts_dir/entsoe_import.py"
    kubectl create configmap entsoe-importer-script \
      --from-file=entsoe_import.py="$scripts_dir/entsoe_import.py" \
      -n weather \
      --dry-run=client -o yaml > "$entsoe_manifest"
  fi

  if [ -f "$scripts_dir/fmi_forecast_import.py" ]; then
    echo "Generating fmi-importer-script ConfigMap from: $scripts_dir/fmi_forecast_import.py"
    kubectl create configmap fmi-importer-script \
      --from-file=fmi_forecast_import.py="$scripts_dir/fmi_forecast_import.py" \
      -n weather \
      --dry-run=client -o yaml > "$fmi_manifest"
  fi

  if [ -f "$scripts_dir/sqlite_import.py" ]; then
    echo "Generating weather-sqlite-import-script ConfigMap from: $scripts_dir/sqlite_import.py"
    kubectl create configmap weather-sqlite-import-script \
      --from-file=sqlite_import.py="$scripts_dir/sqlite_import.py" \
      -n weather \
      --dry-run=client -o yaml > "$sqlite_manifest"
  fi

  if [ -f "$scripts_dir/create_fusion_view.sql" ]; then
    echo "Generating create-fusion-view-script ConfigMap from: $scripts_dir/create_fusion_view.sql"
    kubectl create configmap create-fusion-view-script \
      --from-file=create_fusion_view.sql="$scripts_dir/create_fusion_view.sql" \
      -n weather \
      --dry-run=client -o yaml > "$fusion_view_manifest"
  fi

  if [ -f "$scripts_dir/export_fusion_parquet.py" ]; then
    echo "Generating export-fusion-parquet-script ConfigMap from: $scripts_dir/export_fusion_parquet.py"
    kubectl create configmap export-fusion-parquet-script \
      --from-file=export_fusion_parquet.py="$scripts_dir/export_fusion_parquet.py" \
      -n weather \
      --dry-run=client -o yaml > "$local_manifests_dir/export-fusion-parquet-script.yaml"
  fi
}

apply_local_manifests() {
  if [ -d "$local_manifests_dir" ]; then
    echo "Applying local manifests from: $local_manifests_dir"
    for file in "$local_manifests_dir"/*.yaml; do
      [ -e "$file" ] || continue
      local_name="$(basename "$file")"
      echo "  - Rendering $local_name with HOSTNAME=$new_host"
      sudo sed "s/HOSTNAME/$new_host/g" "$file" > "$kube_cron_jobs_dir"/"$local_name"
      sudo cp "$kube_cron_jobs_dir"/"$local_name" "$manifests_dir"/"$local_name"
      if grep -q "HOSTNAME" "$kube_cron_jobs_dir"/"$local_name"; then
        echo "  ! WARNING: HOSTNAME placeholder still present in $kube_cron_jobs_dir/$local_name"
      fi
      echo "  - Copied to $manifests_dir/$local_name"
    done
  fi
}

#  This section loads Kubernetes secrets to the cluster
cd "$kube_cron_jobs_dir"
if [ -f "secret.yaml" ]; then
   kubectl apply -f secret.yaml
fi

generate_weather_web_config
generate_weather_script_configs
apply_local_manifests
