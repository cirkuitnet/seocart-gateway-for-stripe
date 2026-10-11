=== SEOCart Gateway for Stripe ===
Contributors: cirkuitnet
Tags: seocart, payments
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

SEOCart Gateway for Stripe connects the SEOCart store plugin to Stripe.

== Description ==

SEOCart Gateway for Stripe is a free extension of SEOCart, the free, open-source store plugin for WordPress. It needs SEOCart, installed and active.

It takes card payments through Stripe. A payment is authorized when the order is placed and captured later, in full or in part, or cancelled; a captured payment can be refunded in full or in part. When the card's bank asks the shopper to confirm a payment (3D Secure), the shopper is sent to confirm it with the bank. Card details never reach your site: SEOCart sends Stripe only the reference that Stripe's own form gives it. Stripe reports what happens to each payment through a webhook endpoint, which the extension sets up at Stripe when its settings are saved.

It has a test mode and a live mode, each with its own keys. It takes payments for Stripe accounts in the United States, in US dollars, pounds sterling and euros.

To try it in test mode, use Stripe's test cards (https://docs.stripe.com/testing): 4242 4242 4242 4242 is approved, 4000 0000 0000 0002 is declined, and 4000 0000 0000 3220 asks for 3D Secure, each with any future expiry date, such as 12/34, and any three-digit CVC.

== Installation ==

1. Install and activate SEOCart.
2. In your site's dashboard, go to **Store > Extensions**, find SEOCart Gateway for Stripe and select **Install**, then **Activate**.
3. Or install it from WordPress.org: go to **Plugins > Add Plugin**, search for "SEOCart Gateway for Stripe", then select **Install Now** and **Activate**.

== External services ==

SEOCart connects to the external services listed below, each only for the purpose stated and only under the condition stated. It contacts no other server.

= Stripe =

Stripe is a payment processing service. SEOCart Gateway for Stripe uses its API to authorize, capture, cancel and refund the store's card payments, to look up a payment whose answer was lost, and to set up the webhook endpoint Stripe reports payment events to.

* Endpoint: `https://api.stripe.com/v1/*`
* Data sent: The order's amount and currency, its order number and SEOCart's reference for the payment, the payment method token that Stripe's own form created in the shopper's browser, the address Stripe returns the shopper to after a bank check, refund amounts and references, and this site's webhook address. No card number, name, address or email address.
* When: When a customer places an order paid with Stripe; when an order's payment is captured, cancelled or refunded; when SEOCart checks a payment whose answer it did not receive; and when a store manager saves the Stripe settings. Nothing is sent before Stripe is configured.
* Terms of use: https://stripe.com/legal/ssa
* Privacy policy: https://stripe.com/privacy

== Source code and build steps ==

The complete, human-readable source code of this plugin is public at https://github.com/cirkuitnet/seocart-gateway-for-stripe. It is built with SEOCart's tools, from a SEOCart checkout beside it: `php ../seocart/bin/build-zip.php --plugin=.` assembles the installable zip in `dist/`.

== Changelog ==

= 0.1.0 =
* The extension's starting point. It does not do anything yet.
