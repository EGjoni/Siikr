#!/bin/bash
set -eo pipefail

script_path="$(realpath "$0")"
script_dir="$(realpath "$(dirname "$script_path")"/..)"

if [[ ! -f "$script_dir/siikr.env" ]]; then
    echo "Could not find $script_dir/siikr.env. Make sure to copy siikr.example.env and fill it out with your deploy information."
    exit 1
fi

source "$script_dir/siikr.env"

docker-compose up -d postgres
echo "Waiting for the database to be created and to come online..."
sleep 3
docker-compose exec -T -u postgres postgres psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" < "$script_dir/siikr/siikr_db_setup.sql"
#docker-compose down postgres
