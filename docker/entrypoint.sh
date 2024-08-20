#!/bin/bash

if [ -f siikr.env ]; then
  export $(cat siikr.env | xargs)
fi

cat > /var/www/html/auth/credentials.php <<EOF
<?php
\$api_key = '$tumblr_API_consumer_key';
\$db_name = '$POSTGRES_DB';
\$db_user = '$POSTGRES_USER';
\$db_pass = '$POSTGRES_PASSWORD';
\$db_host = 'postgres';
EOF

exec "$@"