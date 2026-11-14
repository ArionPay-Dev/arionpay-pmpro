# ArionPay for Paid Memberships Pro

Sell memberships for crypto (Bitcoin, USDT, Litecoin, Ethereum, TRON and more) with Paid Memberships Pro and [ArionPay](https://arionpay.com). The money goes straight to your own wallet: ArionPay never holds it.

Tested on WordPress 7.1.4 with Paid Memberships Pro 3.9.1 and PHP 8.3. The tests covered one-time and recurring levels, renewals, coming back to pay later, late payments, payment tolerance, changed amounts, double payments, the admin "check payment" button and webhook checks.

## Install

1. **Plugins > Add New > Upload Plugin**, choose `arionpay-for-pmpro.zip`, install and activate.
2. **Memberships > Settings > Payment Gateways**: open **ArionPay (crypto)**, fill in the keys (see below) and save.
3. On the same page, under **Payment Gateways**, make **ArionPay (crypto)** the gateway, and set **Gateway Environment** to **Sandbox/Testing** for now.

| Setting | Where in the ArionPay dashboard | Starts with |
| --- | --- | --- |
| API key | Open your store, **API key** | `pk_` |
| API secret | Open your store, **API secret** (asks for your authenticator code) | `sk_` |
| Webhook signing secret | **Webhooks** page, **signing secret** | `whsec_` |

The secrets are never shown again on the settings page; leave the fields empty to keep them. Nothing to paste into ArionPay: each invoice tells ArionPay where to send its payment updates (the address is shown on the settings page).

Also check, in your ArionPay store settings, that **Old ArionPay (v1) integration compatibility** is off.

## Try it first

1. With **Sandbox/Testing** on, open a level's checkout page (log out first, or use a private window), make an account and press **Pay with crypto**.
2. On the ArionPay page, choose a coin, then in the ArionPay dashboard open the invoice and use **Try a test payment**. No real money moves.
3. Within a minute the membership is active, the order is **Success**, and its notes say "[TEST - no real money] ArionPay invoice ... is paid".
4. Set **Gateway Environment** to **Live/Production** before you take real payments.

## How it works

- At checkout the order waits as **Token** and the member goes to the ArionPay page. If they come back later, paying again opens the same ArionPay page while it is still open.
- When ArionPay confirms the full payment, the order becomes **Success** and the member gets the level, with the usual Paid Memberships Pro emails. The ArionPay invoice id is the order's transaction id; the blockchain transaction is in the order notes.
- A payment that is not exact goes to **Payment Radar** in your ArionPay dashboard. A small difference inside your store's payment tolerance (default 1%) is accepted automatically. Anything else you decide, and if you accept it the membership is given here too.
- A late payment still completes the order.
- "Payment seen", partial, expired and "needs review" updates become order notes.
- The membership is given only if the paid invoice matches the order: same amount and currency, and test or live as the order. Coming back from the payment page never gives it.
- If an update did not arrive, open the order in **Memberships > Orders** and use Paid Memberships Pro's check-payment button for waiting (token) orders. It asks ArionPay directly.
- If a second invoice for an already completed order is paid, the order gets a note about a possible double payment. Nothing else changes.

## Recurring levels

Crypto cannot charge a member automatically. With ArionPay, a recurring level is sold as one billing period:

- the checkout says, for example, "$12.00 now. Membership expires after 1 Month";
- Paid Memberships Pro reminds the member before it expires;
- the member renews by checking out the same level again. A member who still has the level pays the recurring price (for example $10.00), and the days they had left are kept.

No subscription is made, so there is nothing to cancel.

## Good to know

- Paid Memberships Pro uses one site currency. It must be one ArionPay takes: USD, EUR, GBP, CAD, AUD, CHF, JPY, TRY, AED, INR, BRL, RUB, CNY, SGD, HKD, KRW, MXN, PLN, SEK, NOK, ZAR.
- Paid Memberships Pro has one main payment gateway. ArionPay works as that gateway.
- Refunds are made from your own wallet; then mark the order refunded by hand.
- The plugin sends ArionPay the order code, amount, currency and level name. It does not send the member's name or email.
- The settings page shows the last 30 ArionPay events (never any keys).
