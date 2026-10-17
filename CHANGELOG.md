# Changelog

All notable changes to SEOCart Gateway for Stripe are documented in this file.

The format is based on [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/), and
the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Before
version 1.0, a **minor** version may contain breaking changes and a **patch** version does
not.

## [Unreleased]

- The Stripe adapter: card payments through Stripe's PaymentIntents, authorized when an order is placed and
  captured later in full or in part, cancelled, refunded in full or in part, looked up when an answer was lost,
  and read from Stripe's signed webhook deliveries, whose endpoint is set up at Stripe when the settings are saved.
  Test and live modes; Stripe accounts in the United States, in USD, GBP and EUR.
- A dispute Stripe reports now names its order in SEOCart's log.

## [0.1.0]

- The extension's starting point, generated from SEOCart's extension template. It does not do
  anything yet.
