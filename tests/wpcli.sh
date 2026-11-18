#!/bin/bash
# wp-cli against the throwaway test WordPress started by run.sh
exec docker run --rm --network jmbtest --volumes-from jmbtest-wp --user 33:33 \
  -e WORDPRESS_DB_HOST=jmbtest-db -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp \
  wordpress:cli wp "$@"
