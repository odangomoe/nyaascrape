This is fork from the original nyaascrape hosted here: https://code.ivysaur.me/nyaascrape.html

This fork adds support for MySQL.

---

a scraper and ui for maintaining a mirror of NyaaTorrents.

# Scraper

The command-line tool can be used to build up an sqlite3 database of most information hosted on Nyaa. It updates an existing sqlite3 database or creates a new one. To bring your database up-to-date, the --update option will determine the most recent torrents available and set the download-chunk options appropriately. It would be suitable to run this via cron.

```
Usage: nyaascrape [options]
Options:
  -b, --batches {number}         Number of batches to download
  -c, --count {number}           Number of torrents to download per batch
  --database {filename}          Select output file
  --get-latest-id                Find the most recent Torrent ID on site
  --help                         Display this message
  --no-torrents                  Download entries only, no torrent files
  -s, --start {number}           Torrent ID to start processing from
  --sukebei                      Download from sukebei instead of www
  --test-file {file}             Test parse local file containing nyaa html
  -q                             Quiet, suppress output
  --update                       Automatically set -s/-c/-b options to get all
                                   the latest torrent entries
  --url {http://.../}            Set custom nyaa URL
```

# Web interface

The web interface allows browse, search, and torrent downloading using an interface modelled on the live NyaaTorrents site. However, the following issues make it a little unsuitable for public / high-traffic access:

- It shamelessly uses nyaa's own images and layout
- It works directly from the unindexed sqlite DB, so searches may be very slow against a full dump

