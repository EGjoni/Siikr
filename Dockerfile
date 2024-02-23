FROM php:8.2-fpm

# Install dependencies
RUN apt-get update && \
    apt-get install -y libpq-dev hunspell hunspell-en-us libzmq5-dev supervisor gcc git

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

RUN cd /tmp && \
    git clone "https://github.com/zeromq/php-zmq" && \
    cd php-zmq && \
    phpize && \
    ./configure && \
    make && make install && \
    cd .. && rm -rf php-zmq

# Enable PHP extensions
RUN docker-php-ext-configure pgsql && \
    docker-php-ext-install pgsql && \
    docker-php-ext-enable zmq

# Initialize supervisord
RUN mkdir -p /var/log/supervisor
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

WORKDIR /var/www/html

# TODO: set up php-fpm config like the setup_simple.sh script does

# Copy siikr
COPY siikr/. /var/www/html

CMD ["/usr/bin/supervisord"]
