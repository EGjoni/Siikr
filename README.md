# Siikr

IYKYK, YK?

## Installation

Best done on a clean Ubuntu or debian server.

0. Clone this repo.
1. Copy `siikr.template_conf` to a new file named `siikr.conf`. 
2. Fill out `siikr.conf` as per the comments inside or description below.
3. If not already in a terminal, switch to one and run `chmod +x setup_simple.sh`
4. Then run `sudo ./setup_simple.sh` and follow the prompts.
5. Pray.
6. Once you get it working, please perform a git pull every now and again and run `sudo ./upgrade.sh` so your node doesn't go obsolete.


## More Detailed Installation Guide

This walks you through setting up a Siikr hub/spoke node on an Ubuntu system.

## Prerequisites

* **Ubuntu Server:** This guide assumes you're using an Ubuntu server (tested on 20.04 and 22.04).
* **Root or Sudo Access:** You'll need root or sudo privileges to install packages and configure services.\
* **Domain Name:**  You'll need a domain name for your server.
* **SSL certificate:** The install script can optionally set one up for you, but basically Tumblr refuses to give you an API key if you don't have https:// domain name so, ssl.
* **Tumblr API Key:** Obtain a Tumblr API key by registering an application at [https://www.tumblr.com/oauth/apps](https://www.tumblr.com/oauth/apps).


## Installation Steps

1. **Clone the Repository:**

   ```bash
   git clone https://github.com/EGjoni/Siikr.git
   cd Siikr
   ```

2. **Configure siikr.conf:**

   Copy `siikr.template_conf` to `siikr.conf` and fill in the required values:

   ```bash
   cp siikr.conf.template_conf siikr.conf
   nano siikr.conf
   ```
 REQUIRED
   * *siikr_domain:* Your domain name (e.g., `coolestspoke.mysite.com`).
   * *tumblr_API_consumer_key:* Your Tumblr API key.
   * *pg_pass:* The password for the PostgreSQL user.

OPTIONAL
   * ***document_root:** The webroot directory (defaults to `/var/www`). The actual files will be stored in a subdirectory named `siik`. So the full default installation path would amount to `/var/www/siikr/`
   * **php_user:** The user that PHP-FPM runs as (often `www-data`).
   * **siikr_db:**  (Optional (default is fine))The name for your Siikr database (e.g., `siikrdb`).
   * **pg_user:**  (Optional (default is fine)) The PostgreSQL user for the Siikr database (e.g., `siikrweb`).   
   * **hub_url:**  (Optional (default is fine)) The URL of the central Siikr hub you want this node to interface with. (note for now you will need to reach out to the owner of a hub to register your node (by providing your domain name).
   * **db_dir:** (Optional) Specify a directory for PostgreSQL data files. If not set, the default PostgreSQL data directory will be used. Useful if you have a dedicated disk partition for the database.
   * **min_disk_headroom:** (Optional) Minimum free space (in bytes) to maintain on the database disk.  If not specified, defaults to 1GB
   * **rate_limited:** (Optional) Boolean. defaults to true. Set to false if you want to signal to the hub that you are special and don't have to worry as much about Tumblr rate limits.
   * **default_meta:** (Optional) Boolean. If true, index.php page will by default route to its "hub" interface, querying spokes registered with it instead of indexing or searching itself (unless it registers as a spoke with its self). Defaults to false because please let mine be the hub instead please okay thank you.
   * **content_text_config:** (Optional) Text search config to use. defaults to 'en_us_hunspell_simple', set it to something else if you're optimizing for a different language.
   * **language:** (Optional) Language of the text being indexed. Spoke nodes of the same language will be preferred. Defaults to 'en'. 

3. **Run the Setup Script:**

   ```bash
   sudo bash setup_simple.sh
   ```
    * The script will prompt you to confirm the installation of required packages (nginx, PHP, PostgreSQL, etc.) and the disabling of Apache if it's running on port 80.
	* The script will prompt you to confirm database creation and tumblr api access.
    * Respond "y" to both prompts unless you have specific reasons not to.
	* Answer the prompt about whether to generate an ssl certificate with "y" or "n" depending on if you already have a certificate. If you have a certificate that works for the given domain just answer "y" and manually edit the file nginx created.
 * If you don't have a certificate, then you probably don't have an API key, so you should go get one after this step is complete.

4. **Verify Installation:**
   Navivigate to `[yourdomain]/index_local.php` and perform a search on your blog. If it works, then omg I can't believe that actually worked. Amazing. You're done. Let me know about your spoke if you want to help ease the load for.

## Troubleshooting

   * **auth and config:** Navigate to your document root (default `/var/www/siikr/`) and take a look at `auth/credentials.php` and `config.php`. If something looks fishy, fix it.
   * **Nginx:** Check that Nginx is running and configured correctly: `sudo nginx -t` and `sudo systemctl status nginx`.
   * **PHP-FPM:**  Verify that PHP-FPM is running: `sudo systemctl status php*-fpm`.  Note: The `*` represents the PHP version (e.g., `php8.3-fpm`).
   * **PostgreSQL:**  Connect to your Siikr database:  `psql -U [username you specified] -d [db name you specified]` and verify you can log in with the password you specified. If you can't see the hba.conf bullshit below.
   * **Message Router:** Check that the message router is running: `sudo systemctl restart msgrouter`, `sudo systemctl status msgrouter`.


Still no luck? It's probably Postgres permissions.

### PostgreSQL Authentication (pg_hba.conf)

If you encounter issues with PostgreSQL authentication, check the `pg_hba.conf` file. The setup script attempts to configure `md5` authentication for the specified user. Ensure the entry added by the script is correct and *before* any more general `peer` or `trust` entries for the same user or database.

1. **Locate pg_hba.conf:**  The location of this file varies depending on your PostgreSQL version and installation. Common locations include `/etc/postgresql/*/main/pg_hba.conf` or `/var/lib/postgresql/*/main/pg_hba.conf`, where `*` represents the PostgreSQL version. 
2. **Backup pg_hba.conf:** Before making changes create a backup.
3. **Edit pg_hba.conf:** Add or modify the following line, replacing placeholders with your actual values.  **Important:** This line should appear *before* any `peer` or `trust` authentication methods for the same user and database.

   ```
   local   all             siikrweb                                md5
   ```
(replace siikrweb with whatever pg_user you specified at install time)
4. **Restart PostgreSQL:** After making changes, restart the PostgreSQL service:  `sudo systemctl restart postgresql`.

### Other Issues

* **Port Conflicts:** If another service is using port 80 or 443, you'll need to reconfigure or stop that service.
* **Firewall:** Ensure your firewall allows traffic on ports 80 and 443. 
* **File Permissions:** If you have issues with file access, especially when writing data, double check that the directories are owned by the `www-data` user and group (or the appropriate PHP user/group configured in your system).
* **Tumblr API Issues:** If you encounter errors related to the Tumblr API, double check your API key.


## Upgrading Siikr

1. Navigate to the directory where you cloned this repo
2. Run `git pull`
3. Run `sudo chmod +x upgrade.sh`
4. If you have specified a custom text search configuration / language, generate a schema to make sure it doesn't get dropped after the upgrade.  
5. Run `sudo ./upgrade.sh`.

The upgrade script will handle database schema migrations and file permissions.

## Additional Notes

* If this script didn't work for you, submit an ask to antinegationism.tumblr.com, or post an issue in this repo.

---

## Overview

Siikr is designed to be distributed across multiple nodes ("spokes"). Spokes do the hard work of indexing and searching, and the hub does the work of deciding which spokes to show mercy to. All spokes can also be hubs, but at the moment, each spoke only supports communication with a single hub (this may change soon). 
A spoke can also be its own hub.

Here's a breakdown of the files and their functionality:

**Core Functionality:**

* **`index.php`, `index_local.php`, `index_meta.php`:** These are entry points for the web interface. `index.php` determines whether to use a local or meta configuration based on `auth/config.php`. `index_local.php` serves the search interface for straight up directly indexing on your node without involving the hub. `index_meta.php` in contrast will search over any nodes registerd on your hub.
* **`show_page.php`:** This file renders the HTML for the search interface, including search fields, advanced filter options, results display, tag search/filter, sort options, and a disk usage indicator. This file imports other partial html components, like a wordcloud display, an advanced search popup, and a maintenance notice.
* **`streamed_search.php`:** Processes search queries submitted by the user. It communicates with the Tumblr API to get blog information, interacts with the database to perform the search, and returns results in JSON format.  Includes basic error handling for invalid Tumblr usernames or API issues. It also initiates the indexing process (`internal/archive.php`) in the background if the blog isn't already indexed. Streams search results back to the client in chunks. This helps improve responsiveness for large result sets. Calls to `streamed_search.php` will generally also trigger `archive.php`, which will attempt to get new posts from Tumblr or other spokes via the hub.
* **`repop.php`:** This file handles retrieving posts by their IDs and associating them with scores. This is an optimization for navigation (forward/back) to avoid re-executing expensive full-text searches.

**Supporting Files/Features/Junk:**

* **`advanced.php`:** Contains the HTML and JavaScript for the advanced search filter dialog, allowing users to refine their searches.
* **`get_fingerprint.php`:** Generates and returns "blog fingerprints" in JSON format. This feature analyzes word usage patterns to highlight overused and underused words, providing insights into a blog's vocabulary.
* **`get_tags.php`:** Retrieves and returns a list of tags associated with a specific blog. Used for the tag filtering feature.
* **`getIframe.php`:** Fetches the embed code for a Tumblr post using the Tumblr embed API. This is used for displaying post previews.
* **`resolve_image_url.php`:** Resolves the actual URL for an image based on its ID. Likely used for lazy loading or caching images.
* **`/toys/wordcloud.php`:** Contains the HTML and JavaScript for displaying the word cloud visualization of a blog's fingerprint.
* **`siikr_db_setup.sql`:** The SQL schema for the PostgreSQL database used by Siikr.  Includes tables for posts, tags, blog stats, active queries, and more.  Uses various PostgreSQL features like full-text search, JSONB fields, and materialized views.
* **`/internal/archive.php`:** A background process that indexes Tumblr blogs. It fetches posts from the Tumblr API, processes them, stores them in the database, and updates the indexing status.  Handles database transactions, error handling, and leases to prevent multiple nodes from indexing the same blog simultaneously.
* **`/internal/globals.php`:** Contains global variables, database credentials, helper functions, and configuration options used across the codebase.
* **`/internal/disk_stats.php`:** Helper functions for checking disk space usage and converting size units.
* **`/internal/post_processor.php`:** Functions for processing and transforming Tumblr posts into a format suitable for database storage and searching.  Handles text extraction, mention and link handling, and media metadata extraction.
* **`/internal/adopt_blog.php`:** Code to retrieve and "adopt" posts and related information from other Siikr nodes. This optimization reduces Tumblr API calls and distributes the indexing load.
* **`/internal/post_reprocessor.php`:** Functions for transforming adopted posts into the local database format, including handling media ID mappings.
* **`/internal/messageQ.php`:** Handles queuing and sending messages via ZeroMQ. This provides real-time updates to the client during indexing.
* **`/internal/lease.php`:** Database queries related to managing leases for blog indexing.
* **`/routing/messageSender.php`:** Handles sending messages to the ZeroMQ message queue.
* **`/routing/msgRouter.php`:** Implements a ZeroMQ message router to distribute messages to subscribed clients.
* **`/routing/serverEvents.php`:** Handles long-lived connections with clients and forwards relevant events from the message queue.
* **`/routing/sharedEventFuncs.php`:** Shared helper functions for managing event subscriptions and processing messages.
* **`/spoke_siikr/blog_check.php`:** Responds to check-in requests from the hub. Provides status information about a specific blog and the node's overall status.
* **`/spoke_siikr/broadcast.php`:** Helper functions for communicating with the hub, including requesting check-ins and updates.
* **`/spoke_siikr/get_post_ids.php`:** Returns a list of post IDs indexed by the spoke, based on specified criteria.
* **`/spoke_siikr/get_posts.php`:** Returns the full content of specified posts.
* **`/spoke_siikr/async/notify_blogstat_update.php`:** Notifies the hub that a blog's stats have been updated.
* **`/meta_siikr/*`:** Files related to the meta-search functionality, including handling search requests, managing node status, and distributing search and archiving tasks.
* **`/meta_siikr/meta_internal/node_management.php`:** Functions for managing the status and availability of Siikr nodes.
* **`/management/notice.php`:** This likely contains the HTML for display notices to the user, and manages error display.
* **`/management/notify_hub_all.php`:** Notifies the hub about all blogs that aren't currently assigned to a node.  Used for distributing the indexing load.
* **`squid_game.php`:**  Defines flags (`$squid_game`, `$squid_game_warn`) used to control maintenance mode.
* **`deletion_roulette.php`:**  HTML and JavaScript for a "Blog Elimination Roulette" display shown during maintenance mode when blogs might be deleted to free up disk space.
* **`maintenance.php`:** A simple HTML page probably displayed during maintenance mode.
* **`/js/*`:** JavaScript files for the client-side functionality of the search engine, including handling search requests, displaying results, managing previews, tag filtering, and the word cloud.  Uses a `PseudoSocket` Custom monstrosity for websocket-like communication with the server.


**Potential Issues/Areas for Improvement:**

* **Error Handling:** I should have some.
* **Code Comments:**  Are largely fictional. 
* **Security:** This isn't really the sort of application with much risk, but maybe some more thought should go into this.

