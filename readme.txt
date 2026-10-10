=== GenD Society ===
Contributors: gendme
Tags: hosting, migration, dashboard, connector, community
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect a WordPress site to a gend.me business group, see if it is ready to move, and optionally use the GenD admin look.

== Description ==

GenD Society links a WordPress site to a business group on [gend.me](https://gend.me). It works on any WordPress install, and most of it works without connecting to anything.

= What works without connecting =

* **GenD page** with readiness checks. It checks the PHP version, the sodium extension, REST API and loopback access, password protection and public DNS, and lists anything to fix before a move to a gend.me container. The checks run on your own server and only contact your own site.
* **SEO title and description box** for posts and pages.
* **Live View** in the block editor, which previews the page you are editing.
* **GenD admin experience (optional).** A redesigned admin look that you can turn on from the GenD page. It is off by default, and "Switch back" in the admin bar always returns you to the standard WordPress admin.
* **GenD Society theme.** The GenD page shows a card or a download link for the theme. The plugin never installs or activates a theme for you.

= What Connect adds =

When an administrator clicks **Connect** on the GenD page, the site is paired with a gend.me business group. After connecting you can:

* see your gend.me plan and which features it includes;
* give gend.me support time-limited access when you ask for help;
* later, choose a paid move of the site into a gend.me container. A move never happens automatically.

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

Only if you turn on the GenD admin experience. It is off by default, and "Switch back" in the admin bar always returns you to the standard WordPress admin.

= Does it install a theme? =

No. The GenD page can show a download link for the GenD Society theme, but installing and activating it is up to you.

= Does it add anything to my public site? =

No. The plugin adds no credit, badge or link to the front end of your site.

= What does uninstalling remove? =

Deleting the plugin from the Plugins screen removes its own settings, cached data, user preferences, scheduled tasks and the site's pairing keys. It does not touch your posts, pages, media, comments, menus, themes or theme settings, or the data of other plugins. On sites hosted by gend.me, the pairing is managed by the platform and uninstall leaves it in place.

= How do I disconnect? =

Use Disconnect on the Connect screen. Disconnecting stops all communication with gend.me.

== External services ==

This plugin connects to outside services only after a site administrator chooses to connect. Nothing below happens on a site that has not clicked Connect.

= gend.me =

[gend.me](https://gend.me) is the GenD business-group and hosting platform. The plugin uses it in these cases:

1. **Connect (started by an administrator).** When you click Connect, the plugin sends your site address (URL), a random install ID (UUID) created by the plugin, the site's public key, the site admin email address and the pairing code you entered to gend.me. This links the site to your business group and gives the site an install token.
2. **After connecting, using the install token:**
   * plan and feature lookups, so the GenD page can show what your plan includes;
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
