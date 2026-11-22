#!/bin/sh
# shellcheck disable=SC2154 # The paths and helpers of SEOCart's lib are assigned there.
#
# Stripe's steps of the Stripe test mode workflow (.github/workflows/stripe-test-mode.yml), run from
# the directory that holds the extension's checkout and SEOCart's beside it, as ext/ and seocart/
# are on the runner:
#
#   sh ext/bin/ci/stripe-test-mode.sh <step> [argument]
#
#   check-secrets   Fails, naming the secret and never its value, when STRIPE_TEST_RESTRICTED_KEY
#                   or STRIPE_TEST_PUBLISHABLE_KEY is empty or not a test mode's key. It runs
#                   before SEOCart is checked out, so it alone does without SEOCart's lib.
#   settings        Writes the one file of test credentials the tests read, readable by its owner
#                   alone, in the work directory: the two keys, the account's country and a signing
#                   secret made for the run and masked. Also starts the run list and the report the
#                   live tests write to.
#   soak-size       Refuses a soak-size input outside 7 to 60 (SOAK_SIZE), and gives the size to the
#                   job's later steps as SEOCART_SOAK_SIZE.
#   configure       Configures the checkout's site's gateway in test mode from the settings file, its
#                   webhooks left out. Installs and builds nothing: the file holds the test account's
#                   key.
#   checkout        Runs the checkout's browser tests (tests/browser/specs) against that site: each
#                   must pass, none skipped. Whatever became of them, the PaymentIntents the site
#                   created go on the run list.
#   cleanup [endpoints]
#                   Cancels what the run left waiting at Stripe and, given endpoints, deletes the
#                   webhook endpoint of the run's installation, which only the webhooks job sets
#                   up; then stops the site's server, and removes the settings file, the run list,
#                   the report and the site.
#
# Every other step is SEOCart's (seocart/bin/ci/extension-live.sh), and so are the helpers and
# paths below that this script does not define (seocart/bin/ci/extension-live-lib.sh). Nothing here
# prints a key, a signing secret, a client secret, a password or an event's body: Playwright's
# output passes through the guard of the plugin's declaration (tests/live.php), and the shell never
# traces its commands.

set -eu

ext=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
core=$(CDPATH='' cd -- "$ext/../seocart" 2>/dev/null && pwd) || core=$ext/../seocart
# shellcheck source=/dev/null
[ "${1:-}" = check-secrets ] || . "$core/bin/ci/extension-live-lib.sh"

# Fails unless a secret's value begins with a prefix: require_key <name> <value> <prefix>. The
# message names the secret and the prefix, never the value. A function's arguments stay in this
# shell: no process list shows them.
require_key() {
	if [ -z "$2" ]; then
		printf '::error::The secret %s is empty: the repository'\''s Actions secrets must hold it.\n' "$1"
		return 1
	fi
	case $2 in
		"$3"*) ;;
		*)
			printf '::error::The secret %s does not begin %s: it must be a test mode key.\n' "$1" "$3"
			return 1
			;;
	esac
}

check_secrets() {
	status=0
	require_key STRIPE_TEST_RESTRICTED_KEY "${STRIPE_TEST_RESTRICTED_KEY:-}" rk_test_ || status=1
	require_key STRIPE_TEST_PUBLISHABLE_KEY "${STRIPE_TEST_PUBLISHABLE_KEY:-}" pk_test_ || status=1
	[ "$status" -eq 0 ] || exit 1
	echo 'Both secrets are set, each a test mode key.'
}

# The signing secret the store checks the deliveries of the run with: made for the run, never
# one of Stripe's.
run_secret() {
	printf 'whsec_%s' "$(random_hex)"
}

write_settings() {
	check_secrets
	umask 077
	mkdir -p "$work"
	secret=$(run_secret)
	mask "$secret"
	# jq reads the values from its environment: none is an argument a process list would show.
	STRIPE_TEST_WEBHOOK_SECRET=$secret jq -n '{
		secret_key: env.STRIPE_TEST_RESTRICTED_KEY,
		publishable_key: env.STRIPE_TEST_PUBLISHABLE_KEY,
		account_country: "US",
		webhook_secret: env.STRIPE_TEST_WEBHOOK_SECRET
	}' >"$settings"
	: >"$run_list"
	: >"$report"
	chmod 600 "$settings" "$run_list" "$report"
	echo "The settings file is written, readable by its owner alone: secret_key, publishable_key, account_country, webhook_secret."
}

soak_size() {
	php "$ext/tests/Integration/Live/soak-size.php" "${SOAK_SIZE:-}"
}

# Configures the site's gateway from the settings file, once it is written; nothing is installed or
# built from then on.
configure() {
	[ -f "$settings" ] || fail 'The settings file is missing: run the settings step first.'
	[ -f "$site_env" ] || fail 'The site is missing: run the site step first.'
	site_wp seocart gateway configure stripe --mode=test --from-file="$settings" --no-webhooks --user=admin </dev/null
	echo 'The gateway takes payments in test mode, its webhooks left out.'
}

# Adds the site's Stripe PaymentIntents to the run list, each as Stripe's id and the store's uuid.
harvest_site() {
	prefix=$(site_wp db prefix </dev/null)
	site_wp db query "SELECT provider_intent_id, uuid FROM ${prefix}seocart_payment_intents WHERE gateway_id = 'stripe' AND provider_intent_id LIKE 'pi\\_%'" \
		--skip-column-names </dev/null | tr '\t' ' ' >>"$run_list"
	echo "$(grep -c '^pi_' "$run_list" || true) PaymentIntents on the run list."
}

checkout() {
	[ -f "$settings" ] || fail 'The settings file is missing: run the settings step first.'
	[ -f "$site_env" ] || fail 'The site is missing: run the site step first.'
	status=0
	# The site's address and administrator, and the settings file's path, which the tests give PHP
	# to read the publishable key from: none is an argument a process list would show. Playwright is
	# run as the set-up installed it, never through npx, which would fetch a package it did not find.
	(
		cd "$ext/tests/browser"
		set -a
		# shellcheck disable=SC1090 # The site's file, written by the site step.
		. "$site_env"
		set +a
		SEOCART_TAPE_SETTINGS=$settings
		export SEOCART_TAPE_SETTINGS
		filtered "$work/checkout.out" node_modules/.bin/playwright test --config playwright.config.mjs --forbid-only
	) || status=$?
	harvest_site || status=1
	passed=$(count "$work/checkout.out" passed)
	skipped=$(count "$work/checkout.out" skipped)
	output tests "${passed:-0}"
	output skipped "${skipped:-0}"
	if [ "${passed:-0}" -eq 0 ] || [ "${skipped:-0}" -ne 0 ]; then
		printf '::error::The checkout'\''s browser tests passed %s and skipped %s: each must run and pass.\n' "${passed:-0}" "${skipped:-0}"
		status=1
	fi
	return "$status"
}

cleanup() {
	case ${1:-} in
		'' | endpoints) ;;
		*) fail "cleanup: the argument is endpoints or nothing, not $1." ;;
	esac
	status=0
	if [ -f "$settings" ]; then
		php "$ext/tests/Integration/Live/cleanup.php" "$settings" "$run_list" ${1:+"$1"} || status=$?
	else
		echo 'cleanup: no settings file, so this job made no request to Stripe.'
	fi
	if [ -f "$work/server.pid" ]; then
		kill "$(cat "$work/server.pid")" 2>/dev/null || true
	fi
	rm -rf "$work"
	return "$status"
}

case ${1:-} in
	check-secrets) check_secrets ;;
	settings) write_settings ;;
	soak-size) soak_size ;;
	configure) configure ;;
	checkout) checkout ;;
	cleanup) cleanup "${2:-}" ;;
	*) fail 'usage: stripe-test-mode.sh check-secrets|settings|soak-size|configure|checkout|cleanup [endpoints]' ;;
esac
