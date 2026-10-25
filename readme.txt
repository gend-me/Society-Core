=== GenD Society ===
Contributors: gendme
Tags: hosting, migration, dashboard, connector, community
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.6.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect a WordPress site to a gend.me business group, see if it is ready to move, and use the GenD dashboard or the standard WordPress admin.

== Description ==

GenD Society links a WordPress site to a business group on [gend.me](https://gend.me). It works on any WordPress install, and most of it works without connecting to anything.

= What works without connecting =

* **GenD page** with readiness checks. It checks the PHP version, the sodium extension, REST API and loopback access, password protection and public DNS, and lists anything to fix before a move to a gend.me container. The checks run on your own server and only contact your own site.
* **SEO title and description box** for posts and pages.
* **Live View** in the block editor, which previews the page you are editing.
* **GenD dashboard.** A redesigned admin look and dashboard, on by default. "Switch back to standard WordPress" at the top of the GenD dashboard always returns you to the standard WordPress admin, and you can turn the GenD dashboard on again from the GenD page.
* **GenD Society theme.** The GenD page shows a card or a download link for the theme. The plugin never installs or activates a theme for you.

= What needs a gend.me plan =

Core WordPress is never locked: every standard WordPress screen and feature keeps working with or without the plugin's dashboard, connected or not, and the usual WordPress admin addresses always open directly. What needs a gend.me plan is what gend.me provides: the gend.me features in the GenD sidebar (such as Store, Social and Projects) and the gend.me hosting services (such as containers, backups, compute and mail relay). Each of those menu items opens an upgrade page that names the plan which includes it. After you have a plan, the page links to the full GenD Society build on gend.me, which you download and install yourself; this version of the plugin never downloads or installs it.

= What Connect adds =

When an administrator clicks **Connect** on the GenD page, the site is paired with a gend.me business group. After connecting you can:

* see your gend.me plan and which features it includes;
<!-- @build-strip-begin wporg -->
* give gend.me support time-limited access when you ask for help;
<!-- @build-strip-end wporg -->
<!-- @build-only-begin wporg -->
* optionally send the site's email through the gend.me mail service, and optionally share the site's active plugin list (both off until you turn them on);
<!-- @build-only-end wporg -->
* coming soon: a paid move of the site into a gend.me container. This is still in progress and not available yet. A move will never happen automatically.

**Nothing is sent to gend.me until you click Connect.** Activating the plugin makes no outside requests. See "External services" below for exactly what is sent and when.
<!-- @build-only-begin wporg -->

**No remote administration.** gend.me never logs in to your site, never creates or changes users on it and never changes its settings or content. After you connect, every request is made by your site: it fetches information from gend.me (such as your plan, its features and your backups status) and opens gend.me pages when you click.
<!-- @build-only-end wporg -->

= Your public site =

The plugin adds no credit, badge or link to your public site.

== Installation ==

1. Install the plugin from Plugins > Add New, or upload the `gend-society` folder to `/wp-content/plugins/`.
2. Activate it from the Plugins screen.
3. Open the **GenD** page in the admin menu to see the readiness checks and settings.
4. Optional: click **Connect** to pair the site with your gend.me business group.

== Frequently Asked Questions ==

= Does it contact gend.me when I activate it? =

No. The plugin makes no outside requests until an administrator clicks Connect.

= What happens when I click Connect? =

A popup lists what will be sent before anything is: your site address, a random install ID, the site's public key and the admin email address. When you confirm, the site sends these (with its admin page address and plugin version, signed with the site's key) and you sign in to gend.me in a new window to choose the business group. The popup also says that your gend.me account and that group are linked to the site, and that the display name of the site's connection owner is shared. The details are in "External services" below.

= Will it change my admin area? =

Yes, it shows the GenD dashboard by default. "Switch back to standard WordPress" at the top of the GenD dashboard always returns you to the standard WordPress admin, and the plugin remembers your choice.

= Can I move my site to gend.me hosting now? =

Not yet. Moving a site into a gend.me container is coming soon and is still in progress. The readiness checks on the GenD page already show what a move would need.

= Is anything in WordPress locked behind a plan? =

No. Core WordPress is never locked, and its admin pages always open directly. A gend.me plan is only needed for what gend.me provides: the gend.me features in the GenD sidebar (such as Store, Social and Projects) and gend.me hosting services (such as containers, backups, compute and mail relay). With a plan, the upgrade page links to the full GenD Society build download on gend.me.

= Does it install a theme? =

No. The GenD page can show a link to the free GenD Society theme download at [gend.me/grow/downloads/gend-society-theme](https://gend.me/grow/downloads/gend-society-theme/), but downloading, installing and activating it is up to you.

= What is the full GenD Society build? =

The full build bundles the GenD Society theme and updates itself from gend.me with signed releases. It is a free download at [gend.me/grow/downloads/digital-society-plugin](https://gend.me/grow/downloads/digital-society-plugin/). This version of the plugin only links to that page. It never downloads or installs the full build.

= Does it add anything to my public site? =

No. The plugin adds no credit, badge or link to the front end of your site.

= What does uninstalling remove? =

Deleting the plugin from the Plugins screen removes its own settings, cached data, user preferences, scheduled tasks and the site's pairing keys. It does not touch your posts, pages, media, comments, menus, themes or theme settings, or the data of other plugins. On sites hosted by gend.me, the pairing is managed by the platform and uninstall leaves it in place.

= How do I disconnect? =

Open the Connect popup from the Integration Hub card on the GenD dashboard and click Disconnect. Disconnecting stops all communication with gend.me.
<!-- @build-only-begin wporg -->

= Can gend.me log in to my site or change it? =

No. This version of the plugin has no support access, no remote login and no way for gend.me to create users or change settings. The only request gend.me makes to your site is a signed challenge while a Connect that an administrator started is waiting: the site answers it only for that pending request (with a one-time request ID that expires) and the endpoint is rate limited. At all other times it refuses.

= Is my email or my plugin list sent to gend.me? =

Only if you turn it on. After connecting, the Connected card in the Connect popup has two boxes, both off by default: "Send this site's email through the gend.me mail service" and "Share this site's active plugin list with gend.me". Turning a box off stops that at once, and disconnecting turns both off.
<!-- @build-only-end wporg -->

== Screenshots ==

1. The GenD dashboard on a new install, with the sidebar open (Dashboard, App, Store, Social, Tools, Settings) and "Switch back to standard WordPress" at the top.
2. The Connect popup: what is sent to gend.me when you connect. Nothing is sent until you click Agree and connect.
3. The dashboard after connecting: the Integration Hub card and the gend.me plan card (Connected, Free) with Upgrade.
4. A gend.me feature page (Store Management) on a connected site: it runs on a gend.me container after migration, with links to the container plans and your plan.
5. Content Campaigns > Pages: the standard WordPress pages list inside the GenD tabs, free on every install.

== External services ==

This plugin connects to outside services only after a site administrator chooses to connect. Nothing below happens on a site that has not clicked Connect, except opening the two download links described at the end of the gend.me section when you click them.

= gend.me =

[gend.me](https://gend.me) is the GenD business-group and hosting platform. The plugin uses it in these cases:

1. **Connect (started by an administrator).** Clicking Connect opens a popup that lists what will be sent; nothing is sent until you confirm it. The plugin then sends gend.me your site address (URL), a random install ID (UUID) created by the plugin, the site's public key and the site admin email address, together with the site's admin page address, the plugin version, a timestamp and a signature made with the site's key. You sign in to gend.me in a new window and choose the business group; gend.me then sends back a one-time code, and the site exchanges it (with its install ID and a signature) for an install token. Your gend.me account and the business group you choose are linked to the site on gend.me. If you use a pairing code from your group page instead, the same site details are sent with that code. When you set or change the site's connection owner, the owner's display name is sent to gend.me, which shows it to the business group.
2. **After connecting, using the install token.** The install token is sent in a request header, never in a web address. The site sends its install ID with each request; gend.me answers with the details listed here. None of these requests happen before you connect.
   * **Plan and features** (`/wp-json/gdc-app-manager/v1/install/<install ID>/features` and `.../membership`): your gend.me plan, its price and renewal date, the plan owner's display name, and which features the plan includes, so the GenD dashboard can show them. The answers are kept for up to an hour, and fetched again when an administrator logs in or clicks Refresh.
   * **Plan catalogue** (`.../install/<install ID>/catalog`): the gend.me plans you can choose, with prices and features, so the dashboard can name the plan that unlocks a feature.
   * **Compute Gas** (`.../install/<install ID>/gas`): your gend.me account's compute gas (earned, used and owed), its billing status, your servers and your Gas Station devices, for the Compute Gas panel on the dashboard. The answer is kept for as long as gend.me says (at least a minute).
   * **Connection health** (`.../install/<install ID>/health`): a check that the install token is still accepted, so the dashboard can show a Reconnect notice. Only the install ID and the token are sent.
   * **Backups** (`.../install/<install ID>/backups-status`): whether backups are available for this site on gend.me and the link to choose a backup plan, for the Backups panel on the dashboard. The answer is kept for as long as gend.me says (at least a minute).
   * **Popups to gend.me pages:** buttons such as Choose a plan, Change plan, Choose a server plan, Pay and Choose backups open the matching page on gend.me in a popup window or new tab (you sign in to gend.me there). The popups send no data themselves: the address is a page on gend.me and holds no token. Payment details are entered on gend.me, never on your site. When a plan checkout finishes, the gend.me window sends your admin page a short message (plan changed, with the install ID), and the page fetches the plan again.
   * membership panel actions that you start from the GenD screens;
<!-- @build-strip-begin wporg -->
   * a list of the site's active plugins, sent when a plugin is activated or deactivated and once a day;
   * mail relay through gend.me, only if an administrator chooses gend.me mail.
<!-- @build-strip-end wporg -->
<!-- @build-only-begin wporg -->
   * **Only if you turn it on (off by default):** a list of the site's active plugins (`.../install/<install ID>/features-state`), sent when a plugin is activated or deactivated and once a day. Turn on "Share this site's active plugin list with gend.me" on the Connected card.
   * **Only if you turn it on (off by default):** mail relay. WordPress email from the site (recipients, subject, message and attachments) is sent to gend.me (`/wp-json/gend-mail/v1/relay`) for delivery and counted against your gend.me Emails plan, and once a day the site reports how much inbox data it stores (`/wp-json/gend-mail/v1/storage`). Turn on "Send this site's email through the gend.me mail service" on the Connected card. When it is off, WordPress sends mail as usual.
<!-- @build-only-end wporg -->
3. **After connecting: images.** The GenD admin screens load some images that are hosted on gend.me. Your browser requests them from gend.me, which sends your IP address and browser details as with any web request.
<!-- @build-strip-begin wporg -->
4. **Support access (only after connecting).** gend.me can send the site a signed, time-limited admin-access grant when you ask for support. The site checks the signature with gend.me's public key before accepting it.
<!-- @build-strip-end wporg -->
<!-- @build-only-begin wporg -->
4. **No remote administration.** gend.me cannot log in to the site, create or change users, or change settings or content. The only request gend.me makes to the site is the signed connect challenge (`/wp-json/gs/v1/connect/challenge`) while a Connect started by an administrator is waiting; the site answers only that pending request and refuses at all other times.
<!-- @build-only-end wporg -->
5. **Download links (opened only when you click them).** The GenD page links to the GenD Society theme download, https://gend.me/grow/downloads/gend-society-theme/, and to the full GenD Society build, https://gend.me/grow/downloads/digital-society-plugin/ (on a connected site, the same page on your paired gend.me). These are plain links: the plugin does not request these pages and adds no site data to them. Your browser opens the page in a new tab, and gend.me receives your IP address and browser details as with any web request. The downloads are free store products on gend.me, so getting a file means completing a free ($0) checkout there.

gend.me [Terms of Service](https://gend.me/terms-of-service/) and [Privacy Policy](https://gend.me/privacy-policy/).

= QR Server (api.qrserver.com) =

After connecting, the Gas Station panel shows a QR code image loaded from api.qrserver.com, provided by goQR.me. The only data in the request is the public address https://gend.me/gas-station/mobile/. Your browser loads the image directly, so the service receives your IP address and browser details as with any web request. goQR.me [Terms of Service and Privacy Policy](https://goqr.me/privacy-safety-security/).

Disconnecting the site from gend.me stops all of the above.

== Changelog ==

= 1.6.1 =
* Fixes a fatal error on single-site installs in the admin menu.
<!-- @build-only-begin wporg -->
* Removed from this version: support access (gend.me no longer has any way to sign in to a site) and the raw database query tool.
* Mail relay through gend.me and the active-plugin list are now off until you turn them on in the Connected card.
<!-- @build-only-end wporg -->

= 1.6.0 =
* The GenD dashboard is now on by default. "Switch back to standard WordPress" returns you to the normal WordPress admin at any time.
* Connect, Reconnect and Disconnect now open in a popup on the dashboard's Integration Hub card.
* Your plan, Compute Gas and Backups are shown on the dashboard after you connect.
* Removed: the separate Connect, Plan & features, Groups and Feature Suite pages. Old links to them now open the dashboard.

= 1.5.1 =
* Choose a plan now opens your gend.me Change Plan page in a popup; the separate plan launcher was removed.
* Disconnect now warns when your paid gend.me plan keeps billing, with a link to manage or cancel it on gend.me.

= 1.5.0 =
* New: see your gend.me plan, its price and renewal date in wp-admin (GenD > Plan & features).
* New: choose or change a plan in a gend.me window. Your admin page updates when the checkout finishes.
* New: a Feature Access card shows each area as Included, Upgrade (with the plan that unlocks it) or After migration.
* New: a plan comparison of the gend.me plans.
* New: if your business group already has a higher plan, the site inherits it.
* New: Groups and Feature Suite pages from gend.me inside wp-admin, shown with the plugin's own templates.
* New: a Refresh button fetches your plan again from gend.me.
* Nothing in the plugin is locked behind a plan. Plans only decide what gend.me provides.

= 1.4.0 =
* New: connect your site to a gend.me business group without buying anything. Log in to gend.me, pick or create a group, and approve.
* New: group admins on gend.me can get a pairing code, with a short link and a QR code, from the group page. Groups without a paid site show them a "Connect your WordPress site" link.
* New: the Connected card shows the group, the owner, and lets you transfer ownership.
* New: Disconnect revokes the connection on gend.me first.
* New: a Reconnect notice and button appear when gend.me no longer recognises the site.

= 1.3.1 =
* New: the Plugins and Updates screens explain that update checks start after you connect to gend.me.

= 1.3.0 =
* New: the full build from gend.me now updates itself from gend.me. Every update is checked against a gend.me signature before it is installed, and refused if anything does not match.
* New: update checks on self-hosted sites start only after an administrator allows gend.me connections.
* Removed: the old GitHub-based updater.

= 1.2.1 =
* New: first-run GenD page with readiness checks for moving to gend.me.
* New: explicit consent. Nothing is sent to gend.me until an administrator clicks Connect.
* New: the GenD admin experience is opt-in on self-hosted sites, with Switch back always in the admin bar.
* New: GenD Society theme card or download link. The plugin no longer changes your theme.
* New: uninstall removes the plugin's settings, user preferences, scheduled tasks and pairing keys, and leaves content and themes untouched.

= 1.2.0 =
* Changed: every function, option, hook and constant now uses the gend_society prefix. Existing settings are copied to the new names on update.

== Upgrade Notice ==

= 1.2.1 =
Adds the first-run GenD page and explicit consent before any connection to gend.me. The GenD admin look is now opt-in on self-hosted sites.

= 1.2.0 =
Renames the plugin's internal prefix. Settings are migrated automatically.
