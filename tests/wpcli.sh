#!/bin/bash
# wp-cli against the throwaway test WordPress started by run.sh (same config, including the mock API addresses)
exec docker run --rm --network jmbtest --volumes-from jmbtest-wp --user 33:33 \
  -e WORDPRESS_DB_HOST=jmbtest-db -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp \
  -e "WORDPRESS_CONFIG_EXTRA=define('JMB_STRIPE_API','http://stripe-mock:8000'); define('JMB_GCAL_API','http://stripe-mock:8000/calendar/v3');" \
  wordpress:cli wp "$@"
