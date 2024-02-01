#!/bin/bash
set -eo pipefail

script_path="$(realpath "$0")"
script_dir="$(dirname "$script_path")"

source "$script_dir/siikr.conf"


update_install_packages() {
    echo "Install necessary packages? (y/n) [will attempt to install the default version your repos provide of php, postgres, hunspell, and certbot]"
    read confirm
    if [[ "$confirm" == [yY] || "$confirm" == [yY][eE][sS] ]]; then
        if sudo apt-get update && sudo apt-get install -y nginx php php-fpm php-pgsql php-zmq postgresql postgresql-contrib certbot python3-certbot-nginx hunspell hunspell-en-us; then
            echo "Packages updated and installed successfully."
        else
            echo "Failed to update system packages and install necessary packages."
            exit 1
        fi
    else
        echo "Skipping package update and installation."
    fi
}

configure_php_setup() {
    php_version=$(php -v | head -n 1 | cut -d " " -f 2 | cut -d "." -f 1,2)
    CONF_PATH=$(find /etc/php/"$php_version"/fpm/pool.d/ -name www.conf | head -1)

    if [ -z "$CONF_PATH" ]; then
        echo "Could not find www.conf for PHP version $php_version"
        exit 1
    else
        grep -q "pm.max_children" "$CONF_PATH" && sed -i 's/^pm.max_children = .*/pm.max_children = 25/' "$CONF_PATH" || echo "pm.max_children = 25" >> "$CONF_PATH"
        echo "Updated pm.max_children in $CONF_PATH to 25"
    fi
}

create_msg_router() {
    #create msgrouter
    cat > "$script_dir/msgrouter.service" << EOF
[Unit]
Description= Siikr Message Router
After=network.target
StartLimitIntervalSec=0
[Service]
Type=simple
Restart=always
RestartSec=1
User${php_user}
ExecStart=/usr/bin/php ${document_root}/routing/msgRouter.php

[Install]
WantedBy=multi-user.target
EOF
sudo cp "$script_dir/msgrouter.service" /etc/systemd/system/msgrouter.service
sudo systemctl daemon-reload
sudo systemctl enable msgrouter.service
sudo systemctl start msgrouter.service
}

create_db() {
    echo "create the siikr database? (y/n) [db will be named $siikr_db]"
    read confirm
    if [[ $confirm == [yY] || $confirm == [yY][eE][sS] ]]; then
        psql -U postgres -d $siikr_db -f "$script_dir/siikr/siikr_db_setup.sql"
    fi
}

set_credentials_file() {
    mkdir "$script_dir/siikr/auth"
    cat > "$script_dir/siikr/auth/credentials.php" << EOF
<?php
\$api_key = '$tumblr_API_consumer_key';
\$db_name = '$siikr_db';
\$db_user = '$pg_user';
\$db_pass = '$pg_pass';
EOF

    cat > "$script_dir/siikr/internal/disk.php" << EOF
<?php
\$db_disk = '$pg_disk';
EOF
}

copy_data() {
    sudo cp -R "$script_dir/siikr" $document_root
    sudo chown -R $php_user $document_root
    sudo chgrp -R $php_user $document_root
}


update_install_packages
configure_php_setup
create_msg_router
create_db
set_credentials_file
copy_data

echo "Setup complete. If this script didn't work for you, please fix it and contribute back."
