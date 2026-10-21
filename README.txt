=== Open Web Analytics for WordPress ===

Contributors: padams
Donate link: http://paypal.me/openwebanalytics
Tags: web analytics, user tracking, clickstream, traffic analysis, traffic reporting, open web analytics, owa
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl.html
Requires at least: 5.2
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 2.2.1

The official plugin for integrating Open Web Analytics with WordPress based web sites and applications.

== Description ==

Open Web Analytics is an open source alternative to commercial tools such as Google Analytics. Stay in control of the data you collect about the users of your website or app. This plugin does NOT install an instance of OWA server. It requires that you have admin access to a running instance of OWA, version 1.14 or later.

= FEATURES =

- Integrates your OWA instance with WordPress
- Track page views, visits actions etc. 
- Automatically inserts tracking code on all published web pages (posts, pages, attachments, taxonomies, archives, etc.)
- Track WordPress admin interface pages
- Automatically sets page title
- Automatically sets page type
- Automatically sets user name of logged in users

== Screenshots ==

1. Tracking Settings

== Changelog ==

= 2.2.1 =

* The plugin package no longer includes the OWA SDK's development files (its tests, test fixtures and CI configuration). 2.2.0 shipped them under vendor/open-web-analytics/owa-php-sdk/, including a test server script that echoes requests back.

= 2.2.0 =

* Requires PHP 8.2 and OWA 1.14 or later.
* Updated the OWA PHP SDK to 1.1.0, which no longer bundles Guzzle. Fixes conflicts with other plugins that ship their own copy, and the fatal error when OWA cannot be reached.
* The tracking tag loads the tracker from OWA 1.14's path (public/base/dist/owa.tracker.js) instead of relying on a redirect.
* The Website ID list reads the site list OWA has returned since 1.10.1. When the list cannot be loaded, the reason is shown under the field and the saved Website ID is kept.
* Removed tracking of WordPress admin actions (logins, posts, comments, etc.) and feed tracking (feed requests and tracking parameters on feed links), with their settings.
* Tracking commands are JSON encoded; page titles are sent as plain text, and an empty title no longer overwrites the page's own.
* User names are sent only for logged-in users.
* Fixed the endpoint URL scheme check, a fatal error on an invalid On/Off value, and PHP 8 deprecation notices.

== Development ==

This plugin is actively developed on [Github](https://github.com/open-web-analytics/owa-wordpress-plugin). Please file any bugs, feature, or support requests on Github!

== Donate! ==

Open Web analytics is free. However, we ask that you [make a donation](http://paypal.me/openwebanalytics) if you require support or find this plugin useful!