FROM php:8.2-fpm
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

# Install dependencies
RUN apt-get update && \
    apt-get install -y libpq-dev hunspell hunspell-en-us libzmq5-dev supervisor nginx gcc git

# Compile and install php-zmq
#
# PHP-ZMQ has some ... interesting issues with the Docker container.
# * There does not appear to be a package for it(?) and it doesn't come installed
#   with PHP.
# * The package available on PECL is about 8 years old and the repo is abandoned.
# So we have to compile it from source. However, this is not that painful. Just
# annoying that we have to do it ourselves.

# TODO: make this into a script and just copy it over rather than having this
#       all embedded in the Dockerfile
# TODO: choose a specific tag or commit to clone from rather than using master,
#       for reproducible builds

# Enable PHP extensions
RUN docker-php-ext-configure pgsql && \
    docker-php-ext-install pdo_pgsql pgsql && \
    docker-php-ext-enable pdo_pgsql pgsql && \
    install-php-extensions zmq && \
    docker-php-ext-enable zmq

# Add php-fpm config
COPY docker/php-fpm-siikr.conf /usr/local/etc/php-fpm.d

# Add nginx config
COPY docker/nginx-siikr.conf /etc/nginx/sites-available/default

# Initialize supervisord
RUN mkdir -p /var/log/supervisor
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

WORKDIR /var/www/html

# TODO: set up php-fpm config like the setup_simple.sh script does

# Clean out /var/www/html and then copy siikr
RUN rm /var/www/html/* -rf
COPY siikr/. /var/www/html

# Create database configuration
RUN mkdir -p /var/www/html/auth


RUN cat > /var/www/html/internal/disks.php <<EOF
<?php
\$db_disk = '$pg_disk';
EOF

COPY docker/entrypoint.sh /entrypoint.sh

ENTRYPOINT [ "/entrypoint.sh" ]

CMD ["/usr/bin/supervisord"]
