#!/bin/bash
set -eo pipefail

script_path="$(realpath "$0")"
script_dir="$(dirname "$script_path")"
source "$script_dir/siikr.conf"

ensure_prereq() {
    local tool_name=$1
    local install_cmd=$2

    if ! command -v $tool_name >/dev/null 2>&1; then
        echo "$tool_name is required, but it's not installed. Attempting to install..."
        eval $install_cmd        
        if ! command -v $tool_name >/dev/null 2>&1; then
            echo "Failed to install $tool_name. Please install it manually and retry."
            exit 1
        fi
    fi
}

ensure_prereq "pg_dump" "sudo apt-get update && sudo apt-get install -y postgresql-client"
ensure_prereq "apgdiff" "sudo apt-get install -y apgdiff"


# Ensure variables are set
if [ -z "$pg_user" ] || [ -z "$siikr_db" ] || [ -z "$pg_pass" ]; then
    echo "Database credentials are not fully configured in siikr.conf."
    exit 1
fi

mkdir -p "$script_dir/tmp_schema/"
existing_schema_dump="$script_dir/tmp_schema/existing_siikr.sql"
new_schema_file="$script_dir/siikr/siikr_db_setup.sql"
diff_file="$script_dir/tmp_schema/siikr_schema_migration.sql"

PGPASSWORD="$pg_pass" pg_dump -U "$pg_user" -h localhost -d "$siikr_db" --schema-only --no-owner > "$existing_schema_dump"
if [ $? -ne 0 ]; then
    echo "Failed to dump the existing database schema."
    exit 1
fi

apgdiff "$existing_schema_dump" "$new_schema_file" > "$diff_file"
if [ $? -ne 0 ]; then
    echo "Failed to generate schema migration script."
    exit 1
fi

sed -i '/^REVOKE /d' $diff_file

sudo cp "$diff_file" "/tmp/migration.sql"

echo "Schema migration script is generated and stored at: $diff_file"

echo "Running schema migration"

sudo -u postgres psql -U postgres -d $siikr_db -f "/tmp/migration.sql"



rsync -av --exclude '.vscode/' \
            --exclude 'auth/' \
            --exclude 'dev/' \
            --exclude 'nohup.out' \
            --exclude '*.save' \
            --exclude '~*' \
            --exclude '.*' "$script_dir/siikr/" "$document_root/siikr/"

sudo chown -R "$SUDO_USER" "$document_root/siikr"
sudo chgrp -R "$php_user" "$document_root/siikr"
sudo chmod 755 -R $document_root/siikr