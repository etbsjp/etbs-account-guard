=== ETBS Account Guard ===
Contributors:      etbsjp
Donate link:       https://etbs.jp/product/donate/
Tags:              security, login, username, user enumeration, rest api
Stable tag:        1.0.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Protects the accounts that manage your site. Keeps the login names of your users away from visitors who are not logged in.

== Description ==

WordPress shows the login names of your users, or names made from them, in several places that anyone can reach without logging in: the REST API, oEmbed, the user sitemap, the `?author=` redirect and the messages of the login screen. Once a login name is known, only the password is left to guess.

ETBS Account Guard closes these places. Each one can be turned on or off from Settings > ETBS Account Guard.

= What it covers =

* **REST API** – The user endpoints (`/wp/v2/users` and everything below it) are removed for visitors who are not logged in, including the author data embedded with `_embed`. An existing user ID and a missing one give the same response. Logged-in users, including the block editor, are not affected.
* **oEmbed** – The author name and author URL of embedded posts are replaced with the site name and the home page URL.
* **Sitemap** – The user sitemap (`wp-sitemap-users-1.xml`) is removed.
* **Class names** – `comment-author-{name}` on comments by registered users and `author-{name}` on author pages are removed. Class names that contain the user ID are kept.
* **Author ID links** – `/?author=1` goes to the home page instead of the author page. The admin screens are not affected.
* **Login errors** – An unknown username and a wrong password show the same message. On the lost password screen, an unknown account leads to the same screen as a registered one, and no email is sent. Errors from other plugins, such as CAPTCHA or login lockout, are shown as they are.
* **Author pages** (off by default) – Author pages (`/author/{name}/`) return 404. Turn this on only if your theme does not link to them.
* **Public names** – The settings screen lists users whose display name or nickname is the same as their login name, so that you can change them. Nothing is changed automatically.

= What it does not do =

Two-factor authentication, login attempt limits, CAPTCHA and firewalls are not included. Use a dedicated security plugin for them. The `?author=` redirect and the login messages overlap with some of those plugins; having both does no harm.

= Known limitations =

* The lost password form of WooCommerce My Account shows its own messages and is not covered. The WooCommerce login form is covered.
* Links to author pages that your theme prints still contain the name used in the author page URL.

== Installation ==

1. Upload the `etbs-account-guard` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the Plugins screen.
3. The protections are active right away. Review them under Settings > ETBS Account Guard.

== Frequently Asked Questions ==

= Does it affect the block editor? =

No. The REST API user endpoints stay available to logged-in users, which is what the author panel of the block editor uses.

= I turned an item off. Does the plugin still change anything for it? =

No. Each item returns to the behavior of WordPress itself when it is turned off.

= What is removed when I delete the plugin? =

Nothing. The settings are kept so that they come back if you install the plugin again.

== Changelog ==

= 1.0.0 =
* Initial release.
