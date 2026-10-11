# Stripe fixtures

These JSON files are Stripe API answers and webhook deliveries **written by hand** from the shapes Stripe documents
for API version `2026-09-30.endive`: the PaymentIntent, Refund, Error, Event, Dispute and Webhook Endpoint objects
and the list and search answers in Stripe's API reference (https://docs.stripe.com/api). They are not recordings of
Stripe's test mode; recorded exchanges replace them as they become available.

Every id, key and secret in them is made up (`pi_3Fixture0001`, `whsec_createdFixtureSecret`, …). The card details
they carry (`last4`, `exp_month`, `exp_year`, `fingerprint`) use Stripe's published test values, and are there on
purpose: Stripe returns card details inside a declined PaymentIntent, a card error and a Charge, and the tests prove
that the gateway never writes them anywhere.

Tests change a fixture where they need another state (`Fixtures::with()`), and build lists, searches and signed
deliveries in code (`Fixtures::listOf()`, `Fixtures::signature()`).
