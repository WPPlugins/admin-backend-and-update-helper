=== Admin Backend and Update Helper ===
Contributors: yismedia
Tags: updates, backup, activity log, maintenance report, system info
Requires at least: 6.2
Tested up to: 7.2
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Updates, backups, activity log and maintenance reports for WordPress – everything you need for website maintenance in one place.

== Description ==

**Admin Backend and Update Helper** brings together what agencies and freelancers need for website maintenance: an overview of all updates, backups of database and files, a log of every change and a periodic service report for your clients.

= Main features =

* **Update overview**: All available updates for core, plugins, themes and translations at a glance; install single updates or all at once.
* **Email notifications**: Get notified about available updates and after updates were installed.
* **Activity log**: Updates (including automatic updates), plugin and theme changes, published and edited content, user changes and backups are recorded automatically.
* **Manual services**: Add work that WordPress cannot record – e.g. support tasks or checks of hosting backups – with an optional duration.
* **Service report**: An HTML email at the end of each period with a summary and details of all work. Periods are flexible: monthly on a fixed day (e.g. 16th to 15th), on the first or last day of the month, every few days, or a fixed number of days such as four weeks.
* **Backups**: Database and wp-content backups, manually or on a schedule, processed in small steps so they also work on shared hosting. Backups are stored in a protected directory and can be downloaded from the admin area.
* **UpdraftPlus**: Completed UpdraftPlus backups are counted in the log and the report.
* **System information**: Server, PHP, database, WordPress, themes, plugins, PHP extensions and disk space.
* **Role-based access**: Choose which user roles may use the plugin (in addition to the "manage_options" capability).

= Privacy =

No data leaves your server. The plugin makes no external requests and uses no tracking. The activity log stores the user names of the users who made changes; backups contain a complete copy of your database. Both stay on your server.

= Accessibility =

The admin pages follow the WordPress admin components and the WCAG 2.2 AA guidelines: labelled form fields, keyboard operation and status messages announced to screen readers.

= Support =

For questions or problems, contact us at kontakt@yis-media.com or visit https://yis-media.com.

== Installation ==

1. In your WordPress admin, go to "Plugins" > "Add New", search for "Admin Backend and Update Helper", install and activate it.
   Or upload the folder `admin-backend-and-update-helper` to `/wp-content/plugins/` and activate the plugin in the "Plugins" menu.
2. Open "Backend Helper" in the admin menu.
3. Under "Overview" > "Notifications", configure the update notifications and send a test email.
4. Under "Service Report", enter the recipients and the period, and enable the automatic report.
5. Under "Backups", create a first backup and set up a schedule.

== Frequently Asked Questions ==

= Does the plugin send data to external servers? =

No. All data stays on your server.

= How is the report period defined? =

You choose the last day of each period, e.g. "on day 15 of each month". The report then covers the 16th of the previous month to the 15th and is sent on the 16th at the time you set. Alternatively, the period can always cover a fixed number of days.

= Can I send the report for any period? =

Yes. On the "Service Report" page you can preview the report for any date range and send it immediately.

= Which changes are recorded? =

Core, plugin, theme and translation updates (including automatic updates); installed, activated, deactivated and deleted plugins and themes; theme switches; published, edited and trashed content (several edits of the same content on one day are combined); created and deleted users and role changes; backups. Logins are not recorded.

= How long are log entries kept? =

24 months by default; you can change this on the "Service Report" page.

= How do I restore a backup? =

The plugin does not restore backups automatically, because a failed restore can break a website. Download the backup files. Import the database file (`.sql.gz`) with a tool such as phpMyAdmin, and upload the contents of the ZIP files to your `wp-content` directory.

= Where are backups stored? Are they safe? =

In a directory with a random name inside `wp-content/uploads`, protected against direct access. Downloads are only possible for logged-in users with access to the plugin. Keep copies of your backups in a second location.

= Why do my scheduled backups or reports run late? =

WP-Cron only runs when your website is visited. For reliable schedules, set up a real cron job at your host that calls `wp-cron.php`.

= What happens when I delete the plugin? =

All settings, the activity log and all backups created by the plugin are deleted. Download the backups you want to keep first.

= Which PHP version is recommended? =

The system page shows the latest stable PHP version and recommends an update if your version no longer receives active support. Developers can change both values with the filters `backhe_yis_latest_stable_php` and `backhe_yis_recommended_php`.

= Is the plugin compatible with Multisite? =

The plugin works per site. Super admins always have access.

== Screenshots ==

1. Update overview with one-click installation
2. System information
3. Email notification settings
4. Activity log with manual services
5. Service report settings
6. Service report email
7. Backups

== Changelog ==

= 1.1.0 =
* New: Activity log for updates, plugin and theme changes, content, users and backups, with manual services and durations.
* New: Periodic service report by email with flexible periods, preview and "send now".
* New: Backups of the database and wp-content, manual or scheduled, with download, retention and protected storage.
* New: Completed UpdraftPlus backups are counted in the log and the report.
* New: The role setting now restricts access to the plugin.
* New: Removal of all plugin data on uninstall.
* New: English source strings with a German translation.
* Security: Capability checks for all AJAX actions; updates check the matching capability per type and only accept items with an available update.
* Fix: Styles and scripts were not loaded on the plugin page.
* Fix: Saving the notification settings and the role settings overwrote each other.
* Fix: Bi-weekly, monthly and custom check intervals were not scheduled correctly; changing the frequency now reschedules the check.
* Fix: The "updates installed" email was not sent for single updates.
* Improved: Accessibility of the admin pages (labels, keyboard use, screen reader messages instead of alert boxes).
* Improved: Notification emails use a cleaner, accessible layout and the WordPress default sender address.
* Improved: Code follows the WordPress Coding Standards.
* Changed: Requires WordPress 6.2 or later.

= 1.0.0 =
* First public version.

== Upgrade Notice ==

= 1.1.0 =
Adds an activity log, service reports and backups, and fixes several issues with settings, scheduling and security. Requires WordPress 6.2.
