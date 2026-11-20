QUIETBOARD V4 — PHP textboard for InfinityFree
===========================================

FEATURES
- Single public board page (index.php)
- New thread + replies; numeric post IDs
- Anonymous display name in gray; admin posts use a custom red name
- Sage replies do not bump a thread
- Keeps the newest 30 non-sticky threads; older regular threads are pruned
- Sticky threads are protected and may make the total visible thread count exceed 30
- Admin login, edit/delete posts, sticky/unsticky, thread locking, IP bans/unbans, custom admin display name
- Admin dashboard (admin.php): create/delete admin accounts, change your password, manage/unban IPs
- Global board lock: visitors can read but cannot post; admins retain posting/moderation access; status persists in MySQL
- Installer creates tables and an initial admin account

INSTALL ON INFINITYFREE
1. In the InfinityFree Control Panel, create a MySQL database.
2. Upload index.php and installer.php to your website's htdocs directory.
3. Visit https://YOUR-DOMAIN/installer.php.
4. Enter the exact MySQL hostname, full database name, username, and password shown in the panel. Do not guess these values.
5. Choose the board title and a strong admin password (12+ characters).
6. When installation completes, DELETE installer.php using the File Manager or FTP.
7. Visit index.php and log in using the admin credentials you created.

IMPORTANT NOTES
- The installer creates config.php in the same directory. Keep it private; never post its contents publicly.
- If PHP cannot write config.php, create it manually based on config.sample.php, using the database details from your hosting panel.
- If you get a MySQL connection error, verify the host, database name, username, password, and that the database was created in the same InfinityFree account. The installer cannot discover or repair credentials that only the hosting provider knows.
- IP bans use REMOTE_ADDR. Shared Wi-Fi, mobile carriers, VPNs, and proxies can share/change IPs, so bans can affect other users or be bypassed.
- The board intentionally does not trust X-Forwarded-For because visitors can spoof it.
- Sticky threads are excluded from the 30-thread pruning count, so sticky threads can make the board show more than 30 total threads.
- Regular thread count is pruned after a successful post. Only the newest 30 non-sticky threads remain.
- For stronger production security, use HTTPS, keep PHP updated, use a unique database password, and remove the installer after setup.

REQUIREMENTS
- PHP 8.1+ recommended, PDO MySQL extension, MySQL/MariaDB, mbstring extension.
- InfinityFree's exact PHP/MySQL availability can vary by hosting configuration.

QUIETBOARD V2 UPDATE (existing installation)
--------------------------------------------
1. Back up the current htdocs files and export the MySQL database in phpMyAdmin.
2. Upload the new index.php and the new admin.php and upgrade.php into htdocs. Keep your existing config.php.
3. Log in as an administrator on the board, then visit https://YOUR-DOMAIN/upgrade.php. This adds the `locked` column required for thread locking.
4. When the upgrade page reports success, DELETE upgrade.php from htdocs.
5. Visit https://YOUR-DOMAIN/admin.php to manage administrator accounts, change your password, and manage IP bans.
6. Existing posts, threads, bans, and admin accounts are preserved. Do not run installer.php again.

For a fresh installation, the installer creates the `locked` column automatically, so upgrade.php is not needed.


QUIETBOARD V3 UPDATE (from V2)
-----------------------------
1. Back up all files in htdocs and export the MySQL database using phpMyAdmin.
2. Upload the new index.php, admin.php, and upgrade.php into htdocs. Keep your existing config.php unchanged. Do not upload or rerun installer.php on an existing board.
3. Sign in as an administrator on index.php.
4. Visit https://YOUR-DOMAIN/upgrade.php once. This safely adds the board_settings table and ensures the thread locked column exists. Existing posts, threads, accounts, and bans are retained.
5. When the page reports success, DELETE upgrade.php from htdocs.
6. Visit https://YOUR-DOMAIN/admin.php. The Global board lock section has a Lock entire board / Unlock entire board button.
7. While locked, public visitors can read but cannot create threads or reply. The server rejects direct POST requests too. Logged-in admins can still post and moderate.

FRESH V3 INSTALL
----------------
Use installer.php as usual. It automatically creates board_settings with the board unlocked. Delete installer.php immediately after installation; upgrade.php is not needed for a fresh install.

GLOBAL LOCK DETAILS
-------------------
- The setting is stored in board_settings in MySQL, not in a browser session.
- A red notice appears at the top of the board while global lock is enabled.
- Public compose and reply forms are hidden while locked.
- Direct POST attempts from non-admin users are rejected server-side.
- Admin accounts retain posting access while global lock is enabled.


QUIETBOARD V4 FIXES (from V3)
-----------------------------
1. Back up your htdocs files and export the MySQL database in phpMyAdmin.
2. Replace only index.php with the V4 index.php. Keep config.php and the database unchanged.
3. No database migration is required for this update.
4. Test the thread Lock / Unlock button while logged in as admin. Unlock now correctly saves the posted value `0`; the previous version treated any present hidden field as `1`, so it could never unlock.
5. Admins can now reply inside individually locked threads. Non-admin visitors remain unable to reply.
6. Global board lock behavior is unchanged: visitors are blocked from creating threads and replying; admins retain posting access.

V4 CHANGE NOTES
---------------
- Fixed thread unlock: the action now reads the hidden field's actual value rather than only checking whether it exists.
- Admin bypass for individual thread locks: logged-in administrators can reply in a locked thread, while regular users remain blocked.
- The displayed reply form now follows the same permission rules as the server-side posting handler.
