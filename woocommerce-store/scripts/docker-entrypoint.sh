#!/bin/bash
set -e

# Run the original WordPress entrypoint
docker-entrypoint.sh "$@" &

# Wait for WordPress to be ready
sleep 10

# Run initialization if not already done
if [ ! -f /var/www/html/.initialized ]; then
    /usr/local/bin/init-wordpress.sh
    touch /var/www/html/.initialized
fi

# Keep the container running
wait
