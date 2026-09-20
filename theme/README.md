# OSIR theme for FOSSBilling

A client-area theme for FOSSBilling 0.8.7 in OSIR's design: violet for the one main action per page, Geist and
Geist Mono (self-hosted), bordered white cards on a light grey page, mono labels for machine values, tinted status
badges. The home page is a domain search with your TLD prices.

It is optional and independent of the registrar plugin: it works with any FOSSBilling 0.8.7 installation. One
template is aware of the plugin: the domain management page has a **DNS** tab, which is simply a link. With the
OSIR plugin installed it opens the customer's DNS records; without it the tab is a dead link, so remove it from
`html/mod_servicedomain_manage.html.twig` if you use the theme on its own.

## Install

1. Download `osir-fossbilling-theme-<version>.zip` and `SHA256SUMS` from the [latest release](https://github.com/Osir-Inc/fossbilling-osir/releases/latest) (under
   **Assets**), and check the zip:
   `sha256sum -c SHA256SUMS --ignore-missing`.
2. Extract it in your FOSSBilling root folder. It contains only `themes/osir/`.
3. Make `themes/osir/assets/` writable by the web server user (for example `chown -R www-data themes/osir/assets`):
   FOSSBilling refuses to save theme settings otherwise.
4. In the admin panel, open **System → Settings**, then **Themes**, and select **OSIR** as the client area theme.
5. Optional: to use the OSIR logo, set the company logo to `themes/osir/assets/osir-logo.svg` (and the dark logo to
   `themes/osir/assets/osir-logo-dark.svg`) in **System → Settings → System**, tab **Company Details**, or upload
   your own logo there.

Theme settings (menus, footer links, login options) are under the theme's **Settings** button, as with Huraga, and
are stored in the database. To upgrade, extract the new zip over the folder; your settings are kept.

## What it changes

Built on FOSSBilling's Huraga theme; only these differ from Huraga 0.8.7:

- `assets/osir.css`, `assets/fonts/`, `assets/osir-logo*.svg`: the design (overrides only; Huraga's build is unchanged)
- `html/layout_default.html.twig`, `html/layout_public.html.twig`: stylesheet and font, light mode only, quiet "Top" button
- `html/partials/theme_init.html.twig`: light mode only
- `html/partial_company_logo.html.twig`: mark + name when no logo image is set
- `html/partial_menu.html.twig`: account balance label
- `html/mod_index_dashboard.html.twig`: home page with domain search (uses the stock order form's `?name=&tld=` prefill)
- `html/mod_servicedomain_manage.html.twig`: FOSSBilling 0.8.7's domain page plus a **DNS** tab linking to the
  OSIR plugin's client page (`/osir/dns/<order id>`); a module cannot add a tab to that page, so the link lives here
- `config/settings.html.twig`, `config/settings_data.json`: no dark option; defaults (logo on, showcase off)

Dark mode is off until a dark palette is designed. Small labels use `#6a6d78` for WCAG AA contrast.

When FOSSBilling updates Huraga, compare the templates listed above with the new Huraga before upgrading.

## Licences

Huraga is part of FOSSBilling (Apache License 2.0). Geist and Geist Mono are under the SIL Open Font License 1.1
(`assets/fonts/OFL-Geist.txt`). The OSIR additions are Apache License 2.0, like this repository; the zip includes
the licence text as `LICENSE`.
