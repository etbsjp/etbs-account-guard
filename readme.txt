=== ETBS Account Guard ===
Contributors:      etbsjp
Donate link:       https://etbs.jp/product/donate/
Tags:              security, login, username, user enumeration, rest api
Requires PHP:      7.3
Tested up to:      7.1
Stable tag:        1.2.2
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Protects the accounts that manage your site. Hides the login names of your users from visitors who are not logged in.

== Description ==

WordPress shows the login names of your users, or names made from them, in several places that anyone can reach without logging in: the REST API, oEmbed, the user sitemap, the `?author=` redirect and the messages of the login screen. Once a login name is known, only the password is left to guess.

ETBS Account Guard closes these places. Each one can be turned on or off from Settings > ETBS Account Guard.

= What it covers =

* **REST API** – The user endpoints (`/wp/v2/users` and everything below it) are removed for visitors who are not logged in, including the author data embedded with `_embed`. An existing user ID and a missing one give the same response. Logged-in users, including the block editor, are not affected.
* **oEmbed** – The author name and author URL of embedded posts are replaced with the site name and the home page URL.
* **Sitemap** – The user sitemap (`wp-sitemap-users-1.xml`) is removed.
* **Class names** – `comment-author-{name}` on comments by registered users and `author-{name}` on author pages are removed. Class names that contain the user ID are kept.
* **Author ID links** – `/?author=1` goes to the home page instead of the author page. The admin screens are not affected.
* **Login errors** – An unknown username and a wrong password show the same message. Failed application password (Basic authentication) requests to the REST API get the same error. On the lost password screen, an unknown username or email address shows the same screen as a registered one. Registered users receive the password reset email as before. Errors from other plugins, such as CAPTCHA or login lockout, are shown as they are.
* **Author pages** (off by default) – Author pages (`/author/{name}/`) return 404 for visitors who are not logged in. Before turning this on, open a post on your site and click the author name. If a page whose address contains `/author/` opens, your theme links to author pages, and those links will lead to "Page not found". Logged-in users still see author pages, so check the result in a private window of your browser.
* **Public names** – The settings screen lists users whose display name or nickname is the same as their login name, so that you can change them. Nothing is changed automatically.

= Access Restriction (IP restriction) =

On the Access Restriction tab of Settings > ETBS Account Guard, you can require an IP address check for a role, for a specific user, or both. The **administrator** role itself is always unrestricted; a specific administrator can still be restricted from their own user edit screen. A user's own setting always wins over their role's setting, and a user held to more than one requirement (through more than one role) must satisfy all of them.

* **At login** (wp-login.php, XML-RPC, and anything that authenticates a username and password) – a restricted account connecting from an address not on its allowed list is refused with the same message as a wrong password; the reason is never revealed. This is judged after any CAPTCHA plugin (such as SiteGuard WP Plugin) has already run, so a CAPTCHA failure and an IP restriction never look different from each other.
* **On every later request** (the admin screens, admin-ajax.php, admin-post.php, the front end and the REST API) – a restricted account connecting from a disallowed address has only that one session discarded; the request continues as if signed out. Nothing is blocked with an error page, so public pages and forms that do not require sign-in keep working normally.
* **Application passwords** are turned off for a restricted user, checked again on every REST API request.
* The IP list combines one site-wide list with any addresses added just for one user. Each line is a single IPv4 or IPv6 address or a range in CIDR notation; text after `#` is a note. Only the address the server itself sees for the connection (`REMOTE_ADDR`) is used; headers such as `X-Forwarded-For` are never read, since a visitor can set those themselves.
* Saving the Access Restriction tab, or a user's own restriction on their user edit screen, is refused (with an explanation) if it would leave no unrestricted administrator (or other user who can manage options), if it would lock out the very access you are saving from, or (for BASIC authentication, see below) if a user who would end up in that mode has not set their own credentials yet.
* The last 100 denials are listed on the Denial Log tab.
* If Access Restriction ever malfunctions, it turns itself off and shows a warning on the Access Restriction tab and, on other admin screens, as an admin notice, rather than locking anyone out by mistake.

= Access Restriction (BASIC authentication) =

BASIC authentication is a third mode, alongside "no restriction" and "IP restriction", for a role or a specific user. It asks for a separate username and password (not the WordPress login) with a native browser sign-in prompt, on top of your normal WordPress login.

* **Credentials** – Each user has their own BASIC authentication ID and password, set on their user edit screen. The ID must be unique on the site; the password is never shown again once saved.
* **Confirmation screen** – Once signed in, a BASIC-mode user who has not yet supplied the BASIC credentials for this browser session is sent to a screen that triggers the browser's own username/password prompt (not a custom login form). Answering it correctly returns them to the admin page they were trying to reach.
* **The WordPress session is kept** – Unlike IP restriction, a missing or wrong BASIC credential only makes that one request anonymous; it does not sign the user out.
* **Setting up your own account** – Open your own Profile screen (also reachable from the toolbar or the admin menu). The same Access Restriction section shown here for other users appears there too, for anyone who can manage options. Before saving BASIC authentication mode for yourself, click "Verify" to confirm your new ID and password through a real sign-in prompt.
* **Receive diagnosis** – Some server setups do not pass the BASIC authentication header through to WordPress, or already use BASIC authentication for the whole site at the server level. The Access Restriction tab has a diagnosis to check this; BASIC authentication mode cannot be turned on until it succeeds. A `.htaccess` snippet is shown for servers that need it, for you to review and add yourself — this plugin never edits `.htaccess` automatically.
* **HTTPS is recommended** – BASIC authentication sends the username and password with every request. A warning is shown when the site is not using HTTPS, but saving is still allowed.

= Two-Step Verification (verification code by email) =

On the Two-Step Verification tab of Settings > ETBS Account Guard, you can require a verification code, sent to the user's registered email address, after the password has been accepted. It is off by default: updating the plugin changes nothing until you turn it on for a role or a user.

* **Who needs a code** – Choose "None" or "Verification code (email)" for each role, including the administrator role, and override it for a specific user on their user edit screen. A user's own setting wins over the role, and a user with more than one role needs a code if any of them requires one. Only users who can manage options can see or change these settings; users cannot turn it on or off for themselves.
* **Separate from Access Restriction** – A user held to both goes through both: the password and IP restriction first, then the verification code, then BASIC authentication on the next screen.
* **The code** – Six digits, valid for 10 minutes, stored only as a hash. After 5 wrong entries the sign-in starts over from the password. At most 5 emails per user per hour, at least 60 seconds apart; sending a new code makes the previous one stop working. Accounts are never locked. Full-width digits, spaces and hyphens in the typed code are accepted.
* **Where it applies** – Any login form that signs in through WordPress's own `wp_signon()`, including the WooCommerce My Account login form. No login cookie is issued until the code has been entered. Sign-ins that cannot show the code screen (XML-RPC with the ordinary password, AJAX sign-ins and similar) are refused with the same message as a wrong password, and the user receives an email saying so (not for XML-RPC).
* **Application passwords** keep working for the REST API and XML-RPC without a code. They can only be created while signed in. The settings tab and the user edit screen show how many each user has.
* **Trusted devices** – On the code screen, a user can check "Skip the verification code on this device for 30 days" (unchecked by default). The number of days is set on the tab: 7, 30 (the default) or off. Only the code is skipped: the password is needed every time, and IP restriction and BASIC authentication still apply. The browser keeps a random value in an HttpOnly cookie, and the site stores only its hash (up to 20 devices per user; the oldest go first). A change of the number of days applies at once to devices already trusted. Changing the password (by a reset, on the profile screen or by any other means) revokes every trusted device of that user, and so does the "Revoke all trusted devices on save" checkbox on the user edit screen — use either if a device is lost.
* **Sessions** – A session of a user who needs a code is kept only if it went through the code (or was created inside such a session). Sessions from before the feature was turned on, and sessions created by other plugins' sign-in methods, are signed out on their next request. The `acgd_two_step_session_exempt` filter can keep them.
* **Turning it on for yourself** – First click "Send a confirmation code" at the top of the tab (or in the Two-Step Verification section of your own Profile screen) and enter the code you receive. A save that would turn two-step verification on for your own account is refused until you have done this within the last 10 minutes, so you cannot lock yourself out with an address that does not receive email. Turning it on for someone else requires that their account has a valid email address.
* **Emails** are plain text, without links, in the user's own language, and the code is never in the subject. They are sent from your site's usual sender address.
* **Denial log** – Wrong or expired codes, limits, refused sign-ins, failed emails and discarded sessions are added to the Denial Log tab.
* If its settings are broken, it keeps asking for a code from users set to a verification code and from users who can manage options (other users can still sign in), and shows a warning. Unlike Access Restriction, it never turns itself off.

= Emergency switch =

If Access Restriction ever locks everyone out, add `define( 'ACGD_DISABLE_RESTRICTION', true );` to `wp-config.php`. This stops Access Restriction only; Login Name Protection keeps working.

If nobody can sign in because verification codes do not arrive, add `define( 'ACGD_DISABLE_TWO_STEP', true );` to `wp-config.php`. This stops Two-Step Verification only; Access Restriction and Login Name Protection keep working. Define it also on a copy of your site whose email sending is turned off. When the switch is taken off again, users who need a code and signed in while it was on are signed out once on their next request. The two switches are independent. While a switch is on, a warning is shown on the admin screens.

= What it does not do =

Authenticator apps (TOTP), login attempt limits, CAPTCHA and firewalls are not included. Two-Step Verification is limited to a code sent by email. Use a dedicated security plugin for the others. The `?author=` redirect and the login messages overlap with some of those plugins; having both does no harm.

= Known limitations =

* The lost password form of WooCommerce My Account shows its own messages and is not covered. The WooCommerce login form is covered.
* Links to author pages that your theme prints still contain the name used in the author page URL.
* If you also use SiteGuard WP Plugin, keep its "Same Login Error Message" setting turned on (it is on by default on a single site). When it is off, the CAPTCHA error message of SiteGuard appears only for existing accounts, and separately, only for a restricted account's own IP restriction. This is how SiteGuard itself behaves, and this plugin cannot change it.
* On the lost password screen, when the email to an existing account cannot be sent, that error is shown as it is, so that problems with sending email on your site are noticed. This error, and the difference in response time between sending an email and not sending one, remain.
* On the login screen, WordPress checks the password only when the account exists, so the response time can differ between an existing account and an unknown one. Like the difference in response time on the lost password screen, this is not addressed yet.
* Whether the BASIC authentication confirmation screen (the browser's native sign-in prompt) can be shown on a device that goes through a corporate remote browser isolation service is untested; check this yourself before relying on it at such a site.
* Two-Step Verification is only as strong as the user's email account. Password reset emails go to the same inbox, so anyone who controls the inbox can get past both the password and the code. Use it for users whose email account is protected with multi-factor authentication. Password reset is not blocked, to avoid adding another way to be locked out.
* Two-Step Verification does not protect against relay (adversary-in-the-middle) phishing sites, or against someone who persuades the user to read out the code. The email says never to share it.
* Someone who knows a user's password can use up that user's hourly sending limit and keep them out for up to an hour. Changing the password releases the limit. A trusted device can still sign in.
* Someone who has both a user's password and the trusted device cookie from that user's browser can sign in without a code until the trust ends or the password is changed. Do not trust shared computers.
* A browser remembers one trusted device per site: when another user trusts the same browser, the earlier user needs a code again there.
* Repeated attacks can push older entries, including IP restriction and BASIC authentication denials, out of the Denial Log (the latest 100 are kept).
* Application passwords do not go through Two-Step Verification.
* Users who need a code cannot use sign-in methods of other plugins (magic links, social login and so on).
* Two-Step Verification is not available on multisite.
* The save-time checks of Two-Step Verification (your own receive check, a valid email address for others) run only on the Two-Step Verification tab and the user edit screen. A role or email address changed elsewhere (bulk role changes on the Users screen, the REST API, WP-CLI, imports) is not checked, and a user who needs a code but has no working email address can then no longer sign in.
* Two-Step Verification is not meant for roles with many users, such as the WooCommerce customer role: the save-time checks read every user of the chosen roles.
* Do not combine it with another two-factor plugin for the same user. Plugins that recreate the session during sign-in (such as Two Factor) create a session without this plugin's mark, which is then signed out.

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

Deleting the plugin removes the denial log of Access Restriction, the saved result of the BASIC authentication receive diagnosis (rebuilt the next time it is run), the sign-in attempts, send records, confirmations and trusted devices of Two-Step Verification, a few internal records and cached counts, and, if present, the update check data left behind by an earlier version distributed outside WordPress.org. All settings, including the per-role and per-user Access Restriction modes, IP lists, BASIC authentication IDs and password hashes, and the per-role and per-user Two-Step Verification settings (including the number of days a device is trusted), are kept so that they come back if you install the plugin again.

= Does Two-Step Verification change anything right after updating? =

No. It is off for every role and user until you turn it on. Existing settings are not changed.

= The verification code does not arrive. What can I do? =

Wait a few minutes, check the spam folder, and send a new code from the code screen. If codes never arrive, check the email sending of your site. As a last resort, add `define( 'ACGD_DISABLE_TWO_STEP', true );` to `wp-config.php` to stop Two-Step Verification until the problem is fixed.

= Can users turn Two-Step Verification on for themselves? =

No. Only users who can manage options can change it, on the settings tab or on the user edit screen.

= Does it work on multisite? =

Two-Step Verification is not available on multisite: the login cookie can be shared across the network, so a site without it would let people in. The other features work as before.

== Screenshots ==

1. The Login Name Protection tab of Settings > ETBS Account Guard, where each place that can reveal login names (REST API, oEmbed, sitemap, class names, author ID links, login errors, author pages) can be turned on or off.
2. The list of users whose display name or nickname is the same as their login name, with a link to each user's profile.
3. The Access Restriction tab, where each role other than administrator can be restricted to allowed IP addresses or asked for a second ID and password (BASIC authentication).
4. The Access Restriction section of the user edit screen, where an administrator can set a user to follow the role setting or give them their own mode, extra IP addresses, and a BASIC authentication ID and password.
5. The Denial Log tab, listing denied sign-ins and requests with the date and time, user, IP address and where it happened.

== Changelog ==

= 1.2.2 =
* [ Bug Fix ] Fixed the dates and times in the Denial Log tab and the "Last run" time of the receive diagnosis being shown in UTC instead of the site's time zone.
* [ Other ] Updated the bundled Japanese translation to follow the WordPress.org Japanese translation style guide.

= 1.2.1 =
* [ Bug Fix ] Fixed the BASIC authentication ID being compared as submitted on servers that do not expand the Authorization header into PHP_AUTH_USER, so an ID containing repeated spaces could be saved but never accepted at the sign-in prompt.
* [ Bug Fix ] Fixed a submitted IP address list being discarded in full, without an error, when any part of it was not valid UTF-8; the offending line is now reported on its own.
* [ Spec Change ] The inline style of the BASIC authentication screen is now printed through wp_add_inline_style(), and submitted credentials, IP lists and redirect targets are sanitized on the way in.
* [ Other ] Declared a minimum PHP version of 7.3.

= 1.2.0 =
* [ Spec Change ] Removed the bundled update checker; updates are now delivered through WordPress.org.
* [ Spec Change ] Removed the dashboard widget; the list of users whose display name or nickname is their login name stays on the settings screen, and a stopped Access Restriction is now shown as an admin notice.
* [ Other ] Removed the scheduled update check left behind by an earlier version distributed outside WordPress.org.

= 1.1.1 =
* [ Bug Fix ] Fixed the "Verify" button for BASIC authentication credentials requiring the mode to already be switched to "BASIC authentication" before it would run, so an admin could not check new credentials while still on "No restriction" or "IP restriction".
* [ Bug Fix ] Fixed the "Verify" button for BASIC authentication credentials leaving no indication that the ID and password just entered are kept even when WordPress's own "changes you made will be lost" warning appears; added a note next to the button making this clear.
* [ Other ] Skipped an unnecessary database read on every admin request while checking for a pending BASIC authentication confirmation, on sites where server-side BASIC authentication is already in place.

= 1.1.0 =
* [ New Feature ] Added Access Restriction, letting a role or a specific user be required to connect from an allowed IP address, with a denial log and an emergency switch to turn it off.
* [ New Feature ] Added BASIC authentication as a third Access Restriction mode, letting a role or a specific user be required to answer a browser sign-in prompt with a separate username and password, with its own receive diagnosis, a confirmation screen for setting up one's own credentials, and a denial log entry for it.

= 1.0.0 =
* Initial release.
