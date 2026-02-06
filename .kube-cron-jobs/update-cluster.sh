#!/bin/bash
REPO_URL="https://github.com/vuola/pubcluster"
MAX_ATTEMPTS=5
SLEEP_TIME=10

attempt=1

username="vuola"
kube_cron_jobs_dir="/home/"$username"/.kube-cron-jobs"
pubcluster_dir="$kube_cron_jobs_dir/pubcluster"
manifests_dir="/var/lib/rancher/k3s/server/manifests"
local_manifests_dir="$kube_cron_jobs_dir/local-manifests"
timestamp_file="$kube_cron_jobs_dir/.timestamp"
new_host="$(hostname)"

apply_local_manifests() {
  if [ -d "$local_manifests_dir" ]; then
    for file in "$local_manifests_dir"/*.yaml; do
      [ -e "$file" ] || continue
      local_name="$(basename "$file")"
      sudo sed "s/HOSTNAME/$new_host/g" "$file" > "$kube_cron_jobs_dir"/"$local_name"
      sudo cp "$kube_cron_jobs_dir"/"$local_name" "$manifests_dir"/"$local_name"
    done
  fi
}

#  This section loads Kubernetes secrets to the cluster
cd "$kube_cron_jobs_dir"
if [ -f "secret.yaml" ]; then
   kubectl apply -f secret.yaml
fi

# Step 1: Check if .timestamp file exists in the current directory
if [ -f "$timestamp_file" ]; then

  # Step 2: Pull updates from the remote repository
  cd "$pubcluster_dir"
  git pull origin main --no-rebase
  latest_update=$(git rev-list HEAD --count)
  saved_timestamp=$(cat "$timestamp_file")

  if [ "$latest_update" -gt "$saved_timestamp" ]; then

    # Step 3: Update manifest
    # work through all .yaml files in repository,
    # replace all instances of string HOSTNAME by local hostname   
    # and move files to k3s auto-manifest directory
    for file in  *.yaml; do
        sudo sed "s/HOSTNAME/$new_host/g" "$file" > "$kube_cron_jobs_dir"/"$file"
        sudo cp "$kube_cron_jobs_dir"/"$file" "$manifests_dir"/"$file"
    done
    # Step 4: Write the latest update timestamp to .timestamp file
    echo "$latest_update" > "$timestamp_file"
  fi
else

# .timestamp file doesn't exist, clone the repository and create .timestamp file
  while [ $attempt -le $MAX_ATTEMPTS ]; do
      echo "Attempting to clone (Attempt $attempt)..."
      git clone --branch main $REPO_URL "$pubcluster_dir"

      if [ $? -eq 0 ]; then
          echo "Clone successful!"

	        cd "$pubcluster_dir"
 	        # work through all .yaml files in repository,
 	        # replace all instances of string HOSTNAME by local hostname
          # and move files to k3s auto-manifest directory
          for file in  *.yaml; do
            sudo sed "s/HOSTNAME/$new_host/g" "$file" > "$kube_cron_jobs_dir"/"$file"
            sudo cp "$kube_cron_jobs_dir"/"$file" "$manifests_dir"/"$file"
          done
          git rev-list HEAD --count > "$timestamp_file"
          break
      else
          echo "Clone failed. Retrying in $SLEEP_TIME seconds..."
          sleep $SLEEP_TIME
          attempt=$((attempt + 1))
      fi
  done

  if [ $attempt -gt $MAX_ATTEMPTS ]; then
    echo "Max attempts reached. Clone unsuccessful."
    exit 1  # Terminate the script with an error status
  fi

fi

apply_local_manifests
