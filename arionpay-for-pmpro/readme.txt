=== ArionPay for Paid Memberships Pro ===
Contributors: arionpay
Tags: crypto, bitcoin, usdt, memberships, payments
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: MIT

Sell memberships for Bitcoin, USDT, Litecoin, Ethereum, TRON and more. The money goes straight to your own wallet.

== Description ==

ArionPay is a non-custodial crypto payment gateway: members pay to your own wallet, ArionPay never holds the money.

* Members pay on the secure ArionPay page; the membership starts as soon as the network confirms the payment.
* Only a signed ArionPay update (or a signed check made by your site) gives a membership.
* Late payments, payment tolerance and Payment Radar are handled by ArionPay; the order notes say what happened.
* Recurring levels are sold as one billing period that members renew by checking out again (crypto cannot charge automatically).
* Test mode follows the Paid Memberships Pro Gateway Environment (Sandbox/Testing).

Needs Paid Memberships Pro 3.5 or newer and an ArionPay account.

== Installation ==

1. Upload and activate the plugin.
2. Memberships > Settings > Payment Gateways > ArionPay (crypto): paste your API key, API secret and webhook signing secret.
3. Make ArionPay (crypto) your gateway. Try it with Gateway Environment set to Sandbox/Testing first.

== Changelog ==

= 1.0.1 =
* Fix: the order note of a confirmed payment now names its blockchain transaction. 1.0.0 looked for the transaction under the wrong name, so the note never showed it.

= 1.0.0 =
* First release.
