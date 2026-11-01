#!/bin/sh
#
# The steps of the Stripe test mode workflow (.github/workflows/stripe-test-mode.yml), run from
# the directory that holds the extension's checkout and SEOCart's beside it, as ext/ and
# seocart/ are on the runner:
#
#   sh ext/bin/ci/stripe-test-mode.sh <step> [argument]
#
#   check-secrets   Fails, naming the secret and never its value, when STRIPE_TEST_RESTRICTED_KEY
#                   or STRIPE_TEST_PUBLISHABLE_KEY is empty or not a test mode's key.
#   settings        Writes the one file of test credentials the tests read, readable by its owner
#                   alone, in the runner's temporary directory: the two keys, the account's
#                   country and a signing secret made for the run and masked. Also starts the run
#                   list and the report the live tests write to.
#   heads           Gives the commits of the extension and of SEOCart as the job's outputs.
#   conformance     Runs SEOCart's conformance suite with every request sent to Stripe's test
#                   mode, adds the PaymentIntents it created to the run list from the tapes it
#                   recorded, and checks it ran the replay's tests with the replay's two skips.
#   soak-size       Refuses a soak-size input outside 7 to 60 (SOAK_SIZE).
#   live <part>     Runs the live tests of a part, three-d-secure or webhooks: exactly the part's
#                   tests must run, and each pass, none skipped.
#   cleanup [endpoints]
#                   Cancels what the run left waiting at Stripe and, given endpoints, deletes the
#                   webhook endpoint of the run's installation, which only the webhooks job sets
#                   up; then removes the settings file, the run list and the report.
#   verdict         Says, for each thing the run proves, which job proves it and how it went
#                   (STRIPE_TEST_MODE_NEEDS, STRIPE_TEST_MODE_PARTS), and fails a red run.
#
# Nothing here prints a key, a signing secret, a client secret or an event's body: PHPUnit's
# output is passed through the tests' own filter of credential-shaped text, and the shell never
# traces its commands. Outside GitHub Actions (a dry run), RUNNER_TEMP, GITHUB_OUTPUT and
# GITHUB_STEP_SUMMARY may be left unset.

set -eu

fail() {
	printf '::error::%s\n' "$*"
	exit 1
}

ext=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
core=$(CDPATH='' cd -- "$ext/../seocart" 2>/dev/null && pwd) || core=$ext/../seocart
step_php=$ext/tests/Integration/Live/workflow-step.php
work=${RUNNER_TEMP:-${TMPDIR:-/tmp}}/stripe-test-mode
settings=$work/settings.json
run_list=$work/run-list
report=$work/report
outputs=${GITHUB_OUTPUT:-/dev/stdout}

# Writes one output of the step.
output() {
	printf '%s=%s\n' "$1" "$2" >>"$outputs"
}

# Masks a value in the rest of the job's log; outside GitHub Actions there is no log to mask.
mask() {
	if [ "${GITHUB_ACTIONS:-}" = true ]; then
		printf '::add-mask::%s\n' "$1"
	fi
}

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
	printf 'whsec_%s' "$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')"
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

heads() {
	output ext-head "$(git -C "$ext" rev-parse HEAD)"
	output core-head "$(git -C "$core" rev-parse HEAD)"
}

# The run's nonce: fresh for every attempt, as Stripe answers a key it saw in the last day with
# its first answer.
nonce() {
	if [ -n "${GITHUB_RUN_ID:-}" ]; then
		printf 'r%s.%s' "$GITHUB_RUN_ID" "${GITHUB_RUN_ATTEMPT:-1}"
	else
		printf 'r%s.1' "$(date +%s)"
	fi
}

# Runs a command with its output filtered and kept in a file, and returns the command's status:
# the pipe's status would be the filter's.
filtered() {
	kept=$1
	shift
	status_file=$work/status
	{
		code=0
		"$@" 2>&1 || code=$?
		echo "$code" >"$status_file"
	} | php "$step_php" redact | tee "$kept"
	return "$(cat "$status_file")"
}

conformance() {
	[ -f "$settings" ] || fail 'The settings file is missing: run the settings step first.'
	run_nonce=$(nonce)
	output nonce "$run_nonce"
	status=0
	# The suite rewrites the tape of each scenario it completes: those newer than this mark are the
	# run's. A file's time is read in whole seconds, so the suite starts in the second after the
	# mark's, and a tape from the mark's own second is an earlier recording's.
	touch "$work/conformance.mark"
	sleep 1
	# env gives the suite its recording's environment: a mode, the run's nonce and the settings
	# file's path, none of them a credential.
	filtered "$work/conformance.out" env SEOCART_TAPE=record SEOCART_TAPE_NONCE="$run_nonce" SEOCART_TAPE_SETTINGS="$settings" \
		sh "$core/bin/ci/extension.sh" conformance "$ext" payments || status=$?
	# Whatever became of the suite, the PaymentIntents it created go on the run list, so the cleanup
	# cancels those it left waiting.
	php "$step_php" harvest "$ext/tests/tapes" "$work/conformance.mark" "$run_list" || status=1
	php "$step_php" suite "$work/conformance.out" || status=1
	return "$status"
}

soak_size() {
	php "$step_php" soak-size "${SOAK_SIZE:-}"
}

live() {
	# The live tests of each part, as a PHPUnit filter of their classes. How many tests each part
	# must run is SuiteOutput::LIVE_TESTS: a test added to a class here is counted there.
	case ${1:-} in
		three-d-secure) filter='/Live\\(AbandonedWindow|ThreeDSecure)Test::/' ;;
		webhooks) filter='/Live\\(EndpointProvisioning|PolledEvents|Soak)Test::/' ;;
		*) fail "live: the part is three-d-secure or webhooks, not ${1:-nothing}." ;;
	esac
	[ -f "$settings" ] || fail 'The settings file is missing: run the settings step first.'
	mkdir -p "$work/tmp"
	run_nonce=$(nonce)
	status=0
	# As in the suite's step, env gives PHPUnit the run's environment: paths, a mode, the nonce and
	# the soak's size, none of them a credential.
	(
		cd "$ext"
		filtered "$work/live.out" env SEOCART_EXTENSION_PATH="$ext" SEOCART_TAPE=record SEOCART_TAPE_NONCE="$run_nonce" \
			SEOCART_TAPE_SETTINGS="$settings" SEOCART_LIVE_RUN_LIST="$run_list" SEOCART_LIVE_REPORT="$report" \
			SEOCART_SOAK_SIZE="${SOAK_SIZE:-}" TMPDIR="$work/tmp" \
			php "$core/vendor/bin/phpunit" --configuration "$ext/phpunit.xml.dist" --testsuite integration \
			--bootstrap "$core/tests/bootstrap-integration.php" --group stripe-live --filter "$filter" --fail-on-empty-test-suite --verbose
	) || status=$?
	php "$step_php" live "$1" "$work/live.out" "$report" || status=1
	if [ -n "${GITHUB_STEP_SUMMARY:-}" ] && [ -s "$report" ]; then
		{
			printf '### Live tests: %s\n\n' "$1"
			php "$step_php" redact <"$report" | sed 's/^/- /'
		} >>"$GITHUB_STEP_SUMMARY"
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
	rm -rf "$work"
	return "$status"
}

verdict() {
	php "$step_php" verdict
}

case ${1:-} in
	check-secrets) check_secrets ;;
	settings) write_settings ;;
	heads) heads ;;
	conformance) conformance ;;
	soak-size) soak_size ;;
	live) live "${2:-}" ;;
	cleanup) cleanup "${2:-}" ;;
	verdict) verdict ;;
	*) fail 'usage: stripe-test-mode.sh check-secrets|settings|heads|conformance|soak-size|live <part>|cleanup [endpoints]|verdict' ;;
esac
