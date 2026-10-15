=== WPIntegrations Form Connector for Brevo ===
Contributors: wpintegrations
Tags: brevo, sendinblue, contact form 7, wpforms, gravity forms
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send Contact Form 7, Elementor Pro, WPForms, Fluent Forms and Gravity Forms submissions to Brevo (formerly Sendinblue) as contacts.

== Description ==

**WPIntegrations Form Connector for Brevo** adds every form submission to your Brevo contacts — from whichever form plugin your site uses — and puts the contact on the lists you choose.

= Supported form plugins =

* Contact Form 7
* Elementor Pro Forms
* WPForms (Lite and paid editions)
* Fluent Forms
* Gravity Forms

One plugin covers them all: each integration picks a form plugin, then a form, then the Brevo fields it fills.

= Why this plugin =

* **Field mapping screen** — map your form's fields to the Brevo contact's email, first name and last name. No renaming form fields to match Brevo.
* **Name parts** — a WPForms, Fluent Forms or Gravity Forms name field offers first and last name separately, so they land in the right Brevo attributes.
* **Lists** — add every contact from a form to one or more Brevo lists.
* **Double opt-in** — Brevo emails a confirmation link and adds the person to your lists only after they click it. Pick the confirmation template from a list of your account's own double opt-in templates.
* **Doesn't hold up your form** — on hosts running PHP-FPM or LiteSpeed (most of them), delivery happens after the visitor already has their confirmation, so a slow API doesn't slow your form down.
* **Full delivery log** — see every request, response and failure. Nothing is silent.
* **Secure by design** — your API key is stored encrypted, every admin endpoint requires administrator capability, and nothing is sent anywhere except Brevo's API.

= What this free version includes =

* Unlimited integrations, across all five form plugins
* Email, first name (FIRSTNAME) and last name (LASTNAME) mapping
* Create contacts, or update the ones that already exist, identified by email
* Add contacts to any number of Brevo lists
* Double opt-in with your own Brevo confirmation template and thank-you page, optionally skipped for people already in Brevo
* Values resolved at submission time: page URL, submission time, the logged-in user's name and email, site title and URL, or a fixed value
* A delivery log with the full request and response for every send

= Pro version =

A separate Pro plugin, sold on wpintegrations.net, adds:


* Every Brevo contact attribute: SMS, WhatsApp, landline number, external ID and your account's own custom attributes
* Values converted to the type each attribute needs — numbers, dates, yes/no and category options
* Phone numbers formatted with a country code for the SMS, WhatsApp and landline attributes
* A delivery queue that retries failed sends automatically, and Resend for failed deliveries in the log
* Conditions — send only when the answers match, e.g. only when the consent box is ticked
* Lists chosen by the visitor's answers
* Brevo events, so a Brevo automation can start from a specific form

= Source code and third-party libraries =

The complete, human-readable source of this plugin is public at [github.com/wpintegrations/wpintegrations-connector-for-brevo](https://github.com/wpintegrations/wpintegrations-connector-for-brevo).

The admin screens are a React app. The compiled bundle in `build/assets/` is generated with Vite from the source in `resources/js/`, which ships with the plugin together with `package.json` and `vite.config.mjs`. To rebuild it, install Node.js 18 or later and pnpm, then run `pnpm install` and `pnpm run build` in the plugin folder.

The bundle includes these open-source libraries:

* React and React DOM 18.3.1 (MIT) — [github.com/facebook/react](https://github.com/facebook/react)
* Ant Design 5.29.3 (MIT) — [github.com/ant-design/ant-design](https://github.com/ant-design/ant-design)
* Ant Design Icons 5.6.1 (MIT) — [github.com/ant-design/ant-design-icons](https://github.com/ant-design/ant-design-icons)

This plugin is built by WPIntegrations and is not affiliated with, endorsed by, or sponsored by Brevo, Contact Form 7, Elementor, WPForms, Fluent Forms or Gravity Forms. All names are the trademarks of their respective owners, used here only to describe what the plugin connects.

= Requirements =

* At least one of the supported form plugins (Elementor forms need Elementor Pro)
* A Brevo account and an API key (Brevo → SMTP & API → API keys)

== External services ==

This plugin connects to the **Brevo API** (operated by Brevo, https://www.brevo.com/). Nothing is sent until you save an API key, and form data is sent only when a form that has an integration is submitted.

* **What is sent:** the email and the form fields you explicitly map, the lists you chose for the integration, and whether an existing contact may be updated. With double opt-in on, also the confirmation template you chose and the page the confirmation link leads to; with "skip the confirmation for people already in Brevo" on, the email is first looked up in your contacts. If you map them, it also sends the optional values the mapping screen offers: the page URL, the submission time, the logged-in user's name and email, the site title and URL, and any fixed text you enter.
* **What is read:** your account name (to confirm the connection), your contact lists (so you can pick them) and your email templates (so you can pick a double opt-in template).
* **Where:** `https://api.brevo.com/v3/contacts`, `https://api.brevo.com/v3/contacts/doubleOptinConfirmation`, `https://api.brevo.com/v3/contacts/{email}`, `https://api.brevo.com/v3/contacts/lists`, `https://api.brevo.com/v3/smtp/templates` and `https://api.brevo.com/v3/account`, authenticated with your own API key.

No data is sent to WPIntegrations or any other third party. Brevo terms of use: https://www.brevo.com/legal/termsofuse/ — privacy policy: https://www.brevo.com/legal/privacypolicy/

== Installation ==

1. Install and activate the plugin.
2. In Brevo, create an API key under **SMTP & API → API keys**. Paste it on the plugin's **Settings** tab, save, and use **Test connection**.
3. Open the **Integrations** tab and create an integration: pick the form plugin and the form, map the fields, choose lists, and save.

== Frequently Asked Questions ==

= Does it work with Sendinblue? =

Yes. Sendinblue renamed itself Brevo in 2023; the account, API keys and contacts are the same.

= Which fields do I have to map? =

**Email** — Brevo identifies the contact by it, and double opt-in sends its confirmation there. First name and last name are optional. A submission with an empty or invalid email is not sent, and the log says why.

= What happens when the same person submits twice? =

With "Update contacts that already exist in Brevo" on (the default), the contact's mapped attributes are updated and the lists are added. With it off, a returning email is left as it is in Brevo, and the log says so.

= Test connection says Brevo refused the request because of an unrecognised IP address =

Brevo can limit API keys to IP addresses you approve. Add your web server's IP address (the message names it) under **Security → Authorised IPs** in Brevo, or turn that restriction off, then test again.

= How do I set up double opt-in? =

In Brevo, create a double opt-in template (a template whose button links to “Double opt-in link”). In the integration, choose at least one list, turn on "Ask for confirmation by email", and pick that template. Brevo sends the email and adds the person to the lists once they confirm.

= Does it work with the free version of Elementor? =

The Forms widget is an Elementor Pro feature, so Elementor Pro is required for Elementor forms. The other form plugins work in their free editions, except Gravity Forms, which is paid only.

= What happens if Brevo is temporarily unavailable? =

The submission is not delivered, and the failure is recorded in the Logs tab with the full response.

= Where is the source code of the admin screens? =

In `resources/js/` inside the plugin, and publicly at [github.com/wpintegrations/wpintegrations-connector-for-brevo](https://github.com/wpintegrations/wpintegrations-connector-for-brevo). The compiled files in `build/` are generated from it with Vite: run `pnpm install` and then `pnpm run build` in the plugin folder. See "Source code and third-party libraries" above for the bundled libraries.

= Where do I see errors? =

The **Logs** tab shows every delivery with the full request and response.

== Changelog ==

= 1.0.1 =
* Clearer messages when your Brevo account has no lists or no double opt-in template yet.

= 1.0.0 =
* Initial release: Contact Form 7, Elementor Pro, WPForms, Fluent Forms and Gravity Forms submissions to Brevo contacts and lists, with email, first and last name mapping, double opt-in, a delivery log and encrypted API key storage.
