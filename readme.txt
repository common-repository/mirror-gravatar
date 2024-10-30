=== Mirror Gravatar ===

Contributors: jwz
Tags: Gravatar, Comments
Requires at least: 2.7
Tested up to: 6.6.1
Stable tag: 1.4
License: MIT

Locally mirror commenters' Gravatar or Mastodon profile images.

== Description ==

Locally mirrors commenters' Gravatar, Libravatar and Mastodon avatars and serves them from your site, rather than loading them from a third-party web site upon each page load.

This has several effects:

 * If most of the comments on a post have no avatar, those turn into _one_ load of a shared image, instead of one for each comment, that happens to return the same "mystery" image.

 * You will be serving more (small) images.

 * If a commenter's URL looks like a link to a Mastodon / ActivityPub profile, their Mastodon account's avatar will be displayed.

 * When commenting, a live preview of the Gravatar tracks the contents of the "Email" field.

 * [gravatar.com](https://www.gravatar.com/) and [libravatar.org](https://www.libravatar.org/) no longer have a web-bug on your blog that is loaded by each viewer.  Instead of being loaded at every page view, the avatar is loaded just once, on the server-side, at the time each new comment is posted.

 * If someone changes or deletes their Gravatar, your site continues displaying the image that was their avatar at the time that they last posted.

 * Likewise, the user's Gravatar profile is saved along with their comment, viewable by admins even if they later change or delete it.

== Security and Privacy ==

 * [Libravatar](https://www.libravatar.org/) is open source. Gravatar is [owned by WordPress](https://en.wikipedia.org/wiki/Gravatar), and their [privacy policy](https://automattic.com/privacy/) says that they don't monetize that info.  But hey, corporate policies change, subpoenas exist, and domain names get sold.

 * Should you trust Gravatar with user data? Well, in 2024, Gravatar announced that they are [pivoting to blockchain](https://jwz.org/b/ykXF), whatever that means, so that's fairly disqualifying. See also [WordPress "growth hacking"](https://jwz.org/b/ykPk) and [WordPress sells users' data to train AI tools](https://jwz.org/b/ykNg).

 * There used to be a potential issue due to [Gravatars using MD5 hashes](https://en.wikipedia.org/wiki/Gravatar#Security_concerns_and_data_breaches), but these days they use SHA256, so I assume that's no longer a problem.

== Screenshots ==

1. A copy of the user's gravatar.com profile is saved with the comment.
2. A live preview of the Gravatar when commenting.

== Installation ==

1. Upload the `mirror-gravatar` directory to your `/wp-content/plugins/` directory.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Make sure the directory `/wp-content/plugins/mirror-gravatar/` is writable by your web server.

== Changelog ==

= 1.0 =
* Created

= 1.1 =
* Also mirrors Mastodon avatar images, if the commenter's URL is of the form "https://example.com/@username"

= 1.2 =
* Minor Mastodon tweaks.

= 1.3 =
* Prefer SHA256 to MD5, since Gravatar accepts that now.
* Added support for Libravatar.
