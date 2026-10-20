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

Core WordPress is never locked: every standard WordPress screen and feature keeps working with or without the plugin's dashboard, connected or not. Only services that gend.me itself provides, such as hosting, backups, compute and mail relay, need a gend.me plan. For those, the GenD dashboard shows an upgrade page that names the plan which includes the service.

= What Connect adds =

When an administrator clicks **Connect** on the GenD page, the site is paired with a gend.me business group. After connecting you can:

* see your gend.me plan and which features it includes;
* give gend.me support time-limited access when you ask for help;
* coming soon: a paid move of the site into a gend.me container. This is still in progress and not available yet. A move will never happen automatically.

**Nothing is sent to gend.me until you click Connect.** Activating the plugin makes no outside requests. See "External services" below for exactly what is sent and when.

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

The site sends its address, a random install ID, its public key, the admin email address and the pairing code to gend.me so the two can be linked. The details are in "External services" below.

= Will it change my admin area? =

Yes, it shows the GenD dashboard by default. "Switch back to standard WordPress" at the top of the GenD dashboard always returns you to the standard WordPress admin, and the plugin remembers your choice.

= Can I move my site to gend.me hosting now? =

Not yet. Moving a site into a gend.me container is coming soon and is still in progress. The readiness checks on the GenD page already show what a move would need.

= Is anything in WordPress locked behind a plan? =

No. Core WordPress is never locked. A gend.me plan is only needed for services that gend.me provides, such as hosting, backups, compute and mail relay.

= Does it install a theme? =

No. The GenD page can show a download link for the GenD Society theme, but installing and activating it is up to you.

= Does it add anything to my public site? =

No. The plugin adds no credit, badge or link to the front end of your site.

= What does uninstalling remove? =

Deleting the plugin from the Plugins screen removes its own settings, cached data, user preferences, scheduled tasks and the site's pairing keys. It does not touch your posts, pages, media, comments, menus, themes or theme settings, or the data of other plugins. On sites hosted by gend.me, the pairing is managed by the platform and uninstall leaves it in place.

= How do I disconnect? =

Open the Connect popup from the Integration Hub card on the GenD dashboard and click Disconnect. Disconnecting stops all communication with gend.me.

== Screenshots ==

1. The GenD page with the readiness checks for a move to gend.me.
2. The GenD dashboard before connecting.
3. The Connect popup on the Integration Hub card.
4. The dashboard after connecting, showing the plan and its features.
5. "Switch back to standard WordPress" at the top of the GenD dashboard.

== External services ==

This plugin connects to outside services only after a site administrator chooses to connect. Nothing below happens on a site that has not clicked Connect.

= gend.me =

[gend.me](https://gend.me) is the GenD business-group and hosting platform. The plugin uses it in these cases:

1. **Connect (started by an administrator).** When you click Connect, the plugin sends your site address (URL), a random install ID (UUID) created by the plugin, the site's public key, the site admin email address and the pairing code you entered to gend.me. This links the site to your business group and gives the site an install token.
2. **After connecting, using the install token.** The install token is sent in a request header, never in a web address. The site sends its install ID with each request; gend.me answers with the details listed here. None of these requests happen before you connect.
   * **Plan and features** (`/wp-json/gdc-app-manager/v1/install/<install ID>/features` and `.../membership`): your gend.me plan, its price and renewal date, the plan owner's display name, and which features the plan includes, so the GenD dashboard can show them. The answers are kept for up to an hour, and fetched again when an administrator logs in or clicks Refresh.
   * **Plan catalogue** (`.../install/<install ID>/catalog`): the gend.me plans you can choose, with prices and features, so the dashboard can name the plan that unlocks a feature.
   * **Compute Gas** (`.../install/<install ID>/gas`): your gend.me account's compute gas (earned, used and owed), its billing status, your servers and your Gas Station devices, for the Compute Gas panel on the dashboard. The answer is kept for as long as gend.me says (at least a minute).
   * **Connection health** (`.../install/<install ID>/health`): a check that the install token is still accepted, so the dashboard can show a Reconnect notice. Only the install ID and the token are sent.
   * **Backups** (`.../install/<install ID>/backups-status`): whether backups are available for this site on gend.me and the link to choose a backup plan, for the Backups panel on the dashboard. The answer is kept for as long as gend.me says (at least a minute).
   * **Popups to gend.me pages:** buttons such as Choose a plan, Change plan, Choose a server plan, Pay and Choose backups open the matching page on gend.me in a popup window or new tab (you sign in to gend.me there). The popups send no data themselves: the address is a page on gend.me and holds no token. Payment details are entered on gend.me, never on your site. When a plan checkout finishes, the gend.me window sends your admin page a short message (plan changed, with the install ID), and the page fetches the plan again.
   * membership panel actions that you start from the GenD screens;
   * a list of the site's active plugins, sent when a plugin is activated or deactivated and once a day;
   * mail relay through gend.me, only if an administrator chooses gend.me mail.
3. **After connecting: images.** The GenD admin screens load some images that are hosted on gend.me. Your browser requests them from gend.me, which sends your IP address and browser details as with any web request.
4. **Support access (only after connecting).** gend.me can send the site a signed, time-limited admin-access grant when you ask for support. The site checks the signature with gend.me's public key before accepting it.

gend.me [Terms of Service](https://gend.me/terms-of-service/) and [Privacy Policy](https://gend.me/privacy-policy/).

= QR Server (api.qrserver.com) =

After connecting, the Gas Station panel shows a QR code image loaded from api.qrserver.com, provided by goQR.me. The only data in the request is the public address https://gend.me/gas-station/mobile/. Your browser loads the image directly, so the service receives your IP address and browser details as with any web request. goQR.me [Terms of Service and Privacy Policy](https://goqr.me/privacy-safety-security/).

Disconnecting the site from gend.me stops all of the above.

== Changelog ==

= 1.6.1 =
* Fixes a fatal error on single-site installs in the admin menu.

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
