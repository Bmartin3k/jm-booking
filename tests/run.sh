#!/bin/bash
# Runs the jm-booking test suites against a throwaway WordPress + MariaDB + mock Stripe API, all in Docker.
# Ports used on localhost: 18080 (WordPress) and 18081 (mock Stripe). Everything is removed again at the end.
#   tests/run.sh            API test suites
#   tests/run.sh --browser  also the headless-Chrome pay flow (needs the zenika/alpine-chrome:with-puppeteer image)
set -euo pipefail
cd "$(dirname "$0")"
ROOT=$(cd .. && pwd)
TESTS=$(pwd)

cleanup() {
  docker rm -f jmbtest-wp jmbtest-db stripe-mock >/dev/null 2>&1 || true
  docker network rm jmbtest >/dev/null 2>&1 || true
}
[ "${KEEP:-}" = 1 ] || trap cleanup EXIT   # KEEP=1 leaves the test site running for debugging
cleanup

echo "Starting WordPress 7.1.4, MariaDB 11 and the mock Stripe + Google APIs..."
docker network create jmbtest >/dev/null
docker run -d --name stripe-mock --network jmbtest -p 127.0.0.1:18081:8000 \
  -v "$TESTS":/m:ro python:3.12-alpine python -u /m/api_mock.py >/dev/null
docker run -d --name jmbtest-db --network jmbtest \
  -e MARIADB_ROOT_PASSWORD=t -e MARIADB_DATABASE=wp -e MARIADB_USER=wp -e MARIADB_PASSWORD=wp mariadb:11 >/dev/null
docker run -d --name jmbtest-wp --network jmbtest -p 127.0.0.1:18080:80 -e WORDPRESS_DEBUG=1 \
  -e WORDPRESS_DB_HOST=jmbtest-db -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp \
  -e "WORDPRESS_CONFIG_EXTRA=define('JMB_STRIPE_API','http://stripe-mock:8000'); define('JMB_GCAL_API','http://stripe-mock:8000/calendar/v3');" \
  -v "$ROOT/jm-booking":/var/www/html/wp-content/plugins/jm-booking:ro \
  -v "$TESTS/mu":/var/www/html/wp-content/mu-plugins:ro wordpress:7.1.4 >/dev/null

for i in $(seq 1 30); do
  ./wpcli.sh core install --url=http://127.0.0.1:18080 --title=Test --admin_user=admin --admin_password=admin \
    --admin_email=admin@example.com --skip-email >/dev/null 2>&1 && break
  [ "$i" = 30 ] && { echo "WordPress did not come up"; exit 1; }
  sleep 3
done
./wpcli.sh rewrite structure '/%postname%/' >/dev/null
./wpcli.sh plugin activate jm-booking >/dev/null
./wpcli.sh post create --post_type=page --post_title=Book --post_name=book --post_status=publish --post_content='[jm_booking]' >/dev/null

reset_state() {
  docker exec jmbtest-db mariadb -uwp -pwp wp -e \
    "TRUNCATE wp_jmb_bookings; DELETE FROM wp_options WHERE option_name LIKE '%transient%jmb_%' OR option_name IN ('jmb_settings','jmb_extra','jmb_gbusy_snapshot','jmb_gcal_status','jmb_test_mail','jmb_test_mail_fail','jmb_emails_last_run'); TRUNCATE wp_jmb_contracts; TRUNCATE wp_jmb_emails"
}

status=0
echo; echo "== Stripe checkout flow"; reset_state; python3 test_stripe.py || status=1
echo; echo "== Extra open times";    reset_state; python3 test_extra_times.py || status=1
echo; echo "== Google Calendar";     reset_state; python3 test_gcal.py || status=1
echo; echo "== Contracts";           reset_state; python3 test_contracts.py || status=1
echo; echo "== Automatic emails";    reset_state; python3 test_emails.py || status=1

if [ "${1:-}" = "--browser" ]; then
  echo; echo "== Browser pay flow"; reset_state
  ./wpcli.sh eval '$s=get_option("jmb_settings",[]); $s["stripe_secret"]="sk_test_mock"; $s["stripe_webhook"]="whsec_testsecret123"; $s["full_amount"]="150"; $s["mini_amount"]="50"; update_option("jmb_settings",$s);' >/dev/null
  docker run --rm --network host -v "$TESTS/browser":/t:ro --entrypoint sh zenika/alpine-chrome:with-puppeteer \
    -c 'cp /t/pay_flow.js /usr/src/app/ && node /usr/src/app/pay_flow.js' || status=1
  echo; echo "== Browser contract signing"; reset_state
  ./wpcli.sh eval '$s=get_option("jmb_settings",[]); $s["stripe_secret"]="sk_test_mock"; $s["stripe_webhook"]="whsec_testsecret123"; $s["full_amount"]="150"; $s["mini_amount"]="50"; $s["full_price"]="$350"; $s["locations"]="Mayfield Park\nRed Bud Isle"; $s["contract_on"]=1; $s["contract_full"]="# Photography Agreement\n\nThis agreement is between {business_name} and {client_name} for a {session_type} on {session_date}, {session_time}, at {location}.\n\n# Fees\n\nThe session fee is {price}. A non-refundable retainer of {retainer} is paid at booking.\n\n# Cancellations\n\nReschedules are free with 48 hours notice.\n\n# Image use\n\nThe photographer may share images on her website and social media unless the client opts out in writing."; update_option("jmb_settings",$s);' >/dev/null
  mkdir -p "${SHOTS:-/tmp/jmb-shots}" && chmod 777 "${SHOTS:-/tmp/jmb-shots}"
  docker run --rm --network host -v "$TESTS/browser":/t:ro -v "${SHOTS:-/tmp/jmb-shots}":/out --entrypoint sh zenika/alpine-chrome:with-puppeteer \
    -c 'cp /t/contract_flow.js /usr/src/app/ && node /usr/src/app/contract_flow.js' || status=1
fi
exit $status
