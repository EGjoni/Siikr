#!/bin/bash
set -eo pipefail

script_path="$(realpath "$0")"
script_dir="$(dirname "$script_path")"
node_specific_data="$script_dir/node_specific"
mkdir -p $node_specific_data

source "$script_dir/siikr.conf"

update_install_packages() {
    local confirm

    if sudo lsof -i :80 | grep -q apache2; then
        echo -e "\033[32mAn existing instance of Apache was detected running on your system on port 80. Siikr runs on nginx. Would you like to automatically disable Apache? (y/n)\033[0m"
        read confirm
        if [[ "$confirm" == [yY] || "$confirm" == [yY][eE][sS] ]]; then
            sudo systemctl stop apache2
            sudo systemctl disable apache2
            echo "Apache2 disabled."
        else 
            echo -e "\033[31mExiting due to user decision to keep Apache. Please make sure no other service is using port 80 and rerun this script.\033[0m"
            exit 1
        fi
    fi
    
    echo -e "\033[32mInstall necessary packages? (y/n) \033[36m[will attempt to install the default version your repos provide of php, postgres, hunspell, and certbot]\033[0m"
    read confirm
    if [[ "$confirm" == [yY] || "$confirm" == [yY][eE][sS] ]]; then
        if sudo apt-get update && sudo apt-get install -y nginx php php-fpm php-pgsql php-zmq php-curl php-mbstring postgresql postgresql-contrib apgdiff certbot python3-certbot-nginx hunspell hunspell-en-us; then
            echo -e "\033[34mPackages updated and installed successfully.\033[0m"
        else
            echo -e "\033[31mFailed to update system packages and install necessary packages.\033[0m"
            exit 1
        fi
    else
        echo -e "\033[33mSkipping package update and installation.\033[0m"
    fi
}

configure_php_setup() {
    php_version=$(php -v | head -n 1 | cut -d " " -f 2 | cut -d "." -f 1,2)
    local CONF_PATH

    # Determine PHP version and configuration file path   
    CONF_PATH=$(find /etc/php/"$php_version"/fpm/pool.d/ -name www.conf | head -1)

    # Check and update the configuration file
    if [ -z "$CONF_PATH" ]; then
        echo -e "\033[31mCould not find www.conf for PHP version $php_version\033[0m"
        exit 1
    else
        # Update max_children setting
        grep -q "pm.max_children" "$CONF_PATH" && \
        sed -i 's/^pm.max_children = .*/pm.max_children = 60/' "$CONF_PATH" || \
        echo "pm.max_children = 60" >> "$CONF_PATH"
        echo -e "\033[34mUpdated pm.max_children in $CONF_PATH to 60\033[0m"

        # Update pm setting to dynamic
        grep -q "pm =" "$CONF_PATH" && \
        sed -i 's/^pm = .*/pm = dynamic/' "$CONF_PATH" || \
        echo "pm = dynamic" >> "$CONF_PATH"
        echo -e "\033[34mUpdated pm mode in $CONF_PATH to dynamic\033[0m"
    fi
    sudo usermod -a -G www-data $SUDO_USER
}


configure_nginx() {
    if [[ -z "$siikr_domain" ]]; then
        echo -e "\033[31mThe siikr_domain variable is not set or empty. Please define it in siikr.conf.\033[0m"
        exit 1
    elif ! [[ "$siikr_domain" =~ ^[A-Za-z0-9.-]+$ ]]; then
        echo -e "\033[31mThe siikr_domain variable contains invalid characters. Please enter a valid domain name.\033[0m"
        exit 1
    else
        local nginx_conf="/etc/nginx/sites-available/$siikr_domain"
        # Check if nginx configuration already exists
        if [[ -f "$nginx_conf" ]]; then
            local cert_exists=0
            local nginx_ssl_conf="/etc/nginx/sites-available/${siikr_domain}-ssl"
            if grep -q -E 'listen\s+443|ssl_certificate' "$nginx_conf"; then
                cert_exists=1
            fi
            if [[ $cert_exists == 0 && -f "$nginx_ssl_conf" ]]; then
                if grep -q -E 'listen\s+443|ssl_certificate' "$nginx_ssl_conf"; then
                    cert_exists=1
                fi
            fi
            if [[ $cert_exists == 1 ]]; then
                echo -e "\033[33mSSL configuration already exists in $nginx_conf.\033[0m"
                local confirm
                echo -e "\033[33mWould you like to use your existing SSL certificate regeneration? (y/n) \033[36m['y' will skip certbot letsencrypt certificate generation]\033[0m"
                read confirm
                if [[ $confirm == [yY] || $confirm == [yY][eE][sS] ]]; then
                    return 0
                else
                    prompt_certbot
                fi
            fi           
        else
             sudo tee "$nginx_conf" > /dev/null <<EOF
server {
    listen 80;
    server_name $siikr_domain;
    root $document_root/siikr;

    index index.php index.html index.htm;
    location / {
        try_files \$uri \$uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php${php_version}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }
}
EOF
            sudo ln -sf "$nginx_conf" /etc/nginx/sites-enabled/
            sudo nginx -t && sudo systemctl restart nginx
            prompt_certbot
        fi
    fi
}

prompt_certbot() {
    local confirm
    echo -e "\033[32mWould you like to run certbot to automatically set up a Let's Encrypt SSL certificate for $siikr_domain? (y/n) \033[36m['y' means Let's Encrypt certificate will be generated for $siikr_domain. 'n' skips and assumes a certificate already exists]\033[0m"
    read confirm
    if [[ $confirm == [yY] || $confirm == [yY][eE][sS] ]]; then
        run_certbot
    fi	
}

run_certbot() {
    sudo certbot --nginx -d $siikr_domain --non-interactive --agree-tos --redirect
}

create_msg_router() {
    #create msgrouter
    mkdir -p "$node_specific_data/routing"
    cat > "$node_specific_data/routing/msgrouter.service" << EOF
[Unit]
Description= Siikr Message Router
After=network.target
StartLimitIntervalSec=0
[Service]
Type=simple
Restart=always
RestartSec=1
User=${php_user}
ExecStart=/usr/bin/php ${document_root}/siikr/routing/msgRouter.php

[Install]
WantedBy=multi-user.target
EOF
    sudo mkdir -p "$document_root/siikr/routing/"
    sudo cp "$document_root/siikr/routing/msgrouter.service" /etc/systemd/system/msgrouter.service
    sudo systemctl daemon-reload
    sudo systemctl enable msgrouter.service
    sudo systemctl start msgrouter.service
}

create_db() {
	local confirm
	echo -e "\033[32mcreate the siikr database? (y/n) \033[36m[db will be named $siikr_db]\033[0m"
	read confirm
	if [[ $confirm == [yY] || $confirm == [yY][eE][sS] ]]; then
		sudo cp "$script_dir/siikr/siikr_db_setup.sql" /tmp/siikr_db_setup.sql
	    sudo chown postgres:postgres /tmp/siikr_db_setup.sql
		sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='$pg_user'" | grep -q 1 || sudo -u postgres psql -d postgres -c "CREATE ROLE $pg_user WITH LOGIN PASSWORD '$pg_pass';"
        	#sudo -u postgres psql -d postgres -c "CREATE DATABASE $siikr_db;"
	    sudo -u postgres psql -d postgres -c "SELECT 1 FROM pg_database WHERE datname = '$siikr_db'" | grep -q 1 || sudo -u postgres psql -d postgres -c "CREATE DATABASE $siikr_db"
		if [[ -n "$db_dir" ]]; then
       	    echo "Tablespace directory does not exist. Creating directory at $db_dir."
            sudo mkdir -p "$db_dir"
            sudo chown postgres:postgres "$db_dir"
        else 
            db_dir=$(sudo -u postgres psql -U postgres -t -c "SHOW data_directory;" | xargs)
        fi
            local tablespace_name="siikr_tablespace"

        echo "Creating tablespace '$tablespace_name' at location '$db_dir'."
        sudo -u postgres psql -d $siikr_db -c "CREATE TABLESPACE $tablespace_name OWNER $pg_user LOCATION '$db_dir';"      
        echo "Setting the default tablespace for the database to '$tablespace_name'."
        sudo -u postgres psql -U postgres -d $siikr_db -f "/tmp/siikr_db_setup.sql"
        sudo -u postgres psql -d $siikr_db -c "GRANT ALL PRIVILEGES ON DATABASE $siikr_db TO $pg_user;"
        sudo -u postgres psql -d $siikr_db -c "GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO $pg_user;"
        sudo -u postgres psql -d $siikr_db -c "GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO $pg_user;"
        sudo -u postgres psql -d $siikr_db -c "GRANT ALL PRIVILEGES ON ALL FUNCTIONS IN SCHEMA public TO $pg_user;"
        sudo -u postgres psql -d $siikr_db -c "GRANT ALL PRIVILEGES ON ALL PROCEDURES IN SCHEMA public TO $pg_user;"
	fi
}

configure_postgresql_auth() {
    echo -e "\033[32mConfiguring PostgreSQL authentication for user $pg_user...\033[0m"    
    PG_HBA_CONF=$(sudo -u postgres psql -t -c "SHOW hba_file;" | xargs)

    if [[ -z "$PG_HBA_CONF" ]]; then
        echo -e "\033[31mFailed to locate pg_hba.conf\033[0m"
        exit 1
    fi
    sudo cp "$PG_HBA_CONF" "$PG_HBA_CONF.backup"
    # Ensure md5 authentication is added *before* the more general peer entry
    sudo sed -i "/^local\s\+all\s\+all\s\+peer/i local   all             $pg_user                                md5" "$PG_HBA_CONF"
    sudo systemctl restart postgresql
    echo -e "\033[34mPostgreSQL configured to use md5 authentication for $pg_user.\033[0m"
}

set_credentials_file() {
    mkdir -p "$node_specific_data/auth"
    cat > "$node_specific_data/auth/credentials.php" << EOF
<?php
\$api_key = '$tumblr_API_consumer_key';
\$db_name = '$siikr_db';
\$db_user = '$pg_user';
\$db_pass = '$pg_pass';
EOF
}

set_server_conf_file() {
    
    if [ $rate_limited = true ]; then
        am_unlimited=false
    else
        am_unlimited=true
    fi

    meta_host="$hub_url"
    
    if [ $default_meta = true ]; then #unquoted because I want it to error if it's not a boolean
        meta_host=$siikr_domain
    fi
    cat > "$node_specific_data/auth/config.php" << EOF
<?php
\$default_meta = $default_meta;
\$content_text_config = '$content_text_config';
\$language = '$language';
\$am_unlimited = $am_unlimited;
\$hub_url = "$meta_host";
EOF
}

copy_data() {
    if [ -d "$document_root" ]; then
        if [ -d "$script_dir/previous_version" ]; then
            sudo rm -rf "$script_dir/previous_version"
        fi
        sudo mv $document_root "$script_dir/previous_version"
    fi
    echo "making $node_specific_data/internal"
    mkdir -p "$node_specific_data/internal"
    db_dir=$(sudo -u postgres psql -U postgres -t -c "SHOW data_directory;" | xargs)
    pg_disk=$(df "$db_dir" | awk 'NR==2{print $6}')
	    cat > "$node_specific_data/internal/disks.php" << EOF
<?php
\$db_disk = '$pg_disk';
\$db_min_disk_headroom = '$min_disk_headroom';
EOF
    sudo mkdir -p $document_root
    sudo cp -R "$script_dir/siikr" $document_root
    sudo cp -R "$node_specific_data/"* $document_root/siikr/
    sudo chown -R "$SUDO_USER" "$document_root/siikr"
    sudo chgrp -R "$php_user" "$document_root/siikr"
    sudo chmod 755 -R $document_root/siikr
}



# Execution flow
update_install_packages
configure_php_setup
create_msg_router
set_credentials_file
configure_nginx
create_db
configure_postgresql_auth
set_server_conf_file
copy_data
echo -e "\033[33mSetup complete. If this script didn't work for you, please fix it and contribute back.\033[0m"
