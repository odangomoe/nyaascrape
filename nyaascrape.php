#!/usr/bin/php
<?php

ini_set("display_errors", true);
error_reporting(E_ALL);
date_default_timezone_set('Etc/UTC');

// Configuration

define('USER_AGENT', 'Mozilla/5.0 (Windows NT 6.3; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/37.0.2049.0 Safari/537.36');
define('MAX_PARALLEL_REQUESTS', 5);

//

function unhesc($sz) {
    return html_entity_decode($sz, ENT_QUOTES, "UTF-8");
}

function fix_filesize($str) {
    $base = floatval($str);

    if (strpos($str, 'KiB') !== false) {
        return round($base * 1024);
    }
    if (strpos($str, 'MiB') !== false) {
        return round($base * 1024 * 1024);
    }
    if (strpos($str, 'GiB') !== false) {
        return round($base * 1024 * 1024 * 1024);
    }
    if (strpos($str, 'TiB') !== false) {
        return round($base * 1024 * 1024 * 1024 * 1024);
    }

    return floor($base); // assume bytes
}

/**
 * curlslurp: modified version of rolling_curl
 * Retrieve URLs in parallel.
 *
 * @param array $urls
 * @param callable $callback Accepts body, index parameters
 * @param array $custom_options Associative array of extra cURL options
 * @return array An array of all URLs that failed to retrieve
 */
function curlslurp(array $urls, $callback, $custom_options = array()) {

    $MAX_WINDOW = MAX_PARALLEL_REQUESTS;
    $master        = curl_multi_init();
    $options    = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0, // treat redirection as failure
        CURLOPT_USERAGENT => USER_AGENT,
        CURLOPT_CONNECTTIMEOUT => 10, // seconds
        CURLOPT_TIMEOUT => 20, // seconds
    ) + $custom_options;

    // Start the first batch of requests
    for ($i = 0, $e = min($MAX_WINDOW, count($urls)); $i != $e; ++$i) {
        $ch = curl_init();
        $options[CURLOPT_URL] = $urls[$i];
        curl_setopt_array($ch, $options);
        curl_multi_add_handle($master, $ch);
    }

    $failed = array();
    $running = null;

    do {
        curl_multi_select( $master ); // wait for state change
        do {
            $rv = curl_multi_exec($master, $running);
        } while ($rv == CURLM_CALL_MULTI_PERFORM);

        if ($rv != CURLM_OK) {
            break;
        }

        // A request was just completed, find out which one
        while ($done = curl_multi_info_read($master)) {
            $info = curl_getinfo($done['handle']);

            if ($info['http_code'] == 200) {
                $output = curl_multi_getcontent($done['handle']);

                // Request successful
                $callback($output, $info['url']);

            } else {
                // request failed
                $failed[] = $info['url'];
                echo 'Request ' . $info['url'] . ' failed: ' . curl_error($done['handle']);
            }

            // Start a new request before removing the old one
            if ($i < count($urls)) {
                $ch = curl_init();
                $options[CURLOPT_URL] = $urls[$i++];
                curl_setopt_array($ch, $options);
                curl_multi_add_handle($master, $ch);
            }

            // remove the curl handle that just completed
            curl_multi_remove_handle($master, $done['handle']);
        }
    } while ($running);

    curl_multi_close($master);
    return $failed;
}

//

class Entry {
    public $id;
    public $title = '';
    public $categoryID = '';
    public $pageClass = '';
    public $submitterID = 0;
    public $submitterName = '';
    public $infoURL = '';
    public $date = 0;
    public $seeders = -1;
    public $leechers = -1;
    public $downloads = -1;
    public $filesize = -1;
    public $description = '';

    public static function createFromHTML($html) {

        if (strpos($html, "The torrent you are looking for does not appear to be in the database.") !== false) {
            return false;
        }

        $e = new Entry();

        $matches = [];

        if (preg_match('~<div class="content\s?([^"]*)"~ms', $html, $matches)) {
            $e->pageClass = $matches[1];
        }

        if (preg_match('~tid=(\d+)~ms', $html, $matches)) {
            $e->id = $matches[1];
        }

        if (preg_match('~<td class="viewtorrentname">([^<]*)<~ms', $html, $matches)) {
            $e->title = unhesc($matches[1]);
        }

        if (preg_match_all('~href="[^"]+cats=(\d_\d+)"~ms', $html, $matches)) {
            $e->categoryID = $matches[1][ count($matches[1])-1 ];
        }

        if (preg_match('~Submitter:</td><td><a href="[^"]*user=(\d+)"><[^>]*>([^<]*)<~ms', $html, $matches)) {
            $e->submitterID = $matches[1];
            $e->submitterName = unhesc($matches[2]);
        }

        if (preg_match('~Information:</td><td>(.*?)</td>~ms', $html, $matches)) {
            $e->infoURL = unhesc(strip_tags($matches[1]));
        }

        if (preg_match('~Date:</td><td[^>]+>([^<]+)<~ms', $html, $matches)) {
            $e->date = strtotime($matches[1]);
        }

        if (preg_match('~viewsn">(\d+)<~ms', $html, $matches)) {
            $e->seeders = $matches[1];
        }

        if (preg_match('~viewln">(\d+)<~ms', $html, $matches)) {
            $e->leechers = $matches[1];
        }

        if (preg_match('~viewdn">(\d+)<~ms', $html, $matches)) {
            $e->downloads = $matches[1];
        }

        if (preg_match('~File size:</td><td[^>]*>([^<]*)<~ms', $html, $matches)) {
            $e->filesize = fix_filesize($matches[1]);
        }

        if (preg_match('~viewdescription">(.*?)</div>~ms', $html, $matches)) {
            $e->description = unhesc(strip_tags(str_replace('<br>', "\n", $matches[1])));
        }

        return $e;
    }
}

abstract class DB {
    protected $db = null;
    protected $insert = null;

    public function __construct($dsn, $username = null, $password = null) {
        $this->db = new \PDO($dsn, $username, $password);
        $this->createTables();
        $this->prepareStatements();
    }

    protected function createTables() {
    }

    protected function prepareStatements() {
    }

    public function beginBatch() {
        $this->db->query('BEGIN TRANSACTION;');
    }

    public function addEntry(\Entry $e) {
        $this->insert->execute([
            $e->id,
            $e->title,
            $e->categoryID,
            $e->pageClass,
            $e->infoURL,
            $e->submitterID,
            $e->submitterName,
            $e->date,
            $e->seeders,
            $e->leechers,
            $e->downloads,
            $e->filesize,
            $e->description,
            time()
        ]);
    }

    public function addTorrent($id, $torrentfile) {
        $this->torrent->execute([
            intval($id),
            $torrentfile
        ]);
    }

    public function getHighestEntryID() {
        $check = $this->db->query('SELECT id FROM nyaa ORDER BY id DESC LIMIT 1')->fetchColumn();

        if ($check === false) {
            return 0;
        }

        return $check;
    }

    public function endBatch() {
        $this->db->query('COMMIT;');
    }
}

class DB_Sqlite extends DB {

    protected function createTables() {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS nyaa (
                id INTEGER PRIMARY KEY,
                title VARCHAR,
                categoryID VARCHAR,
                pageClass VARCHAR,
                infoURL VARCHAR,
                submitterID INTEGER,
                submitterName VARCHAR,
                date INTEGER,
                seeders INTEGER,
                leechers INTEGER,
                downloads INTEGER,
                filesize INTEGER,
                description TEXT,
                record_updated INTEGER
            );
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS torrentfiles (
                id INTEGER PRIMARY KEY,
                torrentfile TEXT
            );
        ');
    }

    protected function prepareStatements() {
        $this->insert = $this->db->prepare('
            INSERT OR REPLACE INTO nyaa (
                id, title, categoryID, pageClass, infoURL, submitterID, submitterName, date,
                seeders, leechers, downloads, filesize,
                description,
                record_updated
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?,
                ?
            )
        ');

        $this->torrent = $this->db->prepare('
            INSERT OR REPLACE INTO torrentfiles ( id, torrentfile ) VALUES ( ?, ? )
        ');
    }
}

class DB_Mysql extends DB_Sqlite {

    public function beginBatch() {
        $this->db->query('START TRANSACTION;');
    }

    protected function createTables() {
        $return = $this->db->exec('
            CREATE TABLE IF NOT EXISTS nyaa (
                id BIGINT,
                title TEXT,
                categoryID VARCHAR(5),
                pageClass VARCHAR(255),
                infoURL VARCHAR(511),
                submitterID INT,
                submitterName VARCHAR(255),
                date INT,
                seeders INT,
                leechers INT,
                filesize INT,
                description TEXT,
                record_updated INT,
                PRIMARY KEY (`id`),
                KEY ( `categoryID` ),
                KEY ( `submitterID` ),
                FULLTEXT (`title`)
            ) ENGINE=InnoDB;
        ');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS torrentfiles (
                id BIGINT,
                torrentfile MEDIUMBLOB,
                PRIMARY KEY ( `id` )
            );
        ');
    }

    protected function prepareStatements() {
        $this->insert = $this->db->prepare('
            INSERT INTO nyaa (
                id, title, categoryID, pageClass, infoURL, submitterID, submitterName, date,
                seeders, leechers, downloads, filesize,
                description,
                record_updated
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?,
                ?
            )
        ');

        $this->torrent = $this->db->prepare('
            INSERT INTO torrentfiles ( id, torrentfile ) VALUES ( ?, ? )
        ');
    }
}

function processRange(\DB &$db, $nyaaURL, $start, $count, $getTorrents=true) {
    $db->beginBatch();

    $urls = [];
    for($i = $start, $end = $start + $count; $i < $end; ++$i) {
        $urls[] = $nyaaURL.'?page=view&tid='.$i;
    }

    $successE = [];

    $failures = curlslurp(
        $urls,
        function($body, $url) use (&$db, &$successE) {

            $tid = substr($url, strpos($url, 'tid=') + 4);

            $entry = Entry::createFromHTML($body);
            if ($entry !== false && ($entry->id > 0)) {
                $db->addEntry($entry);
                $successE[] = $tid;
            }

        }
    );

    $successT = 0;
    if ($getTorrents) {
        $urls = [];
        foreach($successE as $tid) {
            $urls[] = $nyaaURL.'?page=download&tid='.$tid;
        }

        $failures = array_merge($failures, curlslurp(
            $urls,
            function($body, $url) use (&$db, &$successT) {

                $tid = substr($url, strpos($url, 'tid=') + 4);

                $db->addTorrent($tid, $body);
                $successT++;
            }
        ));
    }

    $db->endBatch();

    echo sprintf("%10d %10d %10d %10d %10d\n", $start, $count, count($failures), count($successE), $successT);
}

function test_file($filename) {
    $file = file_get_contents($filename);
    if ($file === false) {
        echo "FATAL: Couldn't open file '".$filename."'\n";
        die(1); // EXIT_FAILURE
    }

    $e = Entry::createFromHTML($file);
    if ($e === false) {
        echo "Invalid torrent ID specified for file.\n";
        die(0); // EXIT_SUCCESS
    }

    echo json_encode($e, JSON_PRETTY_PRINT)."\n";
    die(0); // EXIT_SUCCESS
}

function get_latest_tid($nyaaURL) {
    $ret = false;
    $failed = curlslurp(
        [$nyaaURL],
        function($body /*, $url*/) use (&$ret) {
            $matches = [];
            if (preg_match_all('~tid=(\d+)~ms', $body, $matches)) {
                $ret = max($matches[1]); // pick largest
            }
        }
    );
    return $ret;
}

function usage() {
    echo <<<EOF
Usage: nyaascrape [options]
Options:
  -b, --batches {number}         Number of batches to download
  -c, --count {number}           Number of torrents to download per batch
  --database {filename}          Select output file used in sqlite
  --mysql {dsn}                  Selects mysql as storage instead of sqlite
  --username {username}          Username used for mysql connection
  --password {password}          Password used for mysql connection
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
EOF;
    die(1); // EXIT_FAILURE
}

function main($argv) {

    $dbfile = "nyaascrape.db";
    $nyaaURL = 'http://www.nyaa.se/';
    $getTorrents = true;
    $quiet = false;
    $database = 'sqlite';
    $dsn = null;
    $username = null;
    $password = null;
    $update = false;

    $start = 0;
    $count = 0;
    $batches = 1;

    for($i = 0, $e = count($argv); $i < $e; ++$i) {

        if ($argv[$i] == "--database") {
            $dbfile = $argv[++$i];

        } elseif ($argv[$i] == "--sukebei") {
            $nyaaURL = 'http://sukebei.nyaa.se/';

        } elseif ($argv[$i] == "--url") {
            $nyaaURL = $argv[++$i];

        } elseif ($argv[$i] == "--no-torrents") {
            $getTorrents = false;

        } elseif ($argv[$i] == "--start" || $argv[$i] == '-s') {
            $start = intval($argv[++$i]);

        } elseif ($argv[$i] == "--count" || $argv[$i] == '-c') {
            $count = intval($argv[++$i]);

        } elseif ($argv[$i] == "--batches" || $argv[$i] == '-b') {
            $batches = intval($argv[++$i]);

        } elseif ($argv[$i] == "--help") {
            usage();

        } elseif ($argv[$i] == "--test-file") {
            test_file($argv[++$i]);
            die(0);
        } elseif ($argv[$i] == '--mysql') {
            $database = 'mysql';
            $dsn = $argv[++$i];
        } elseif ($argv[$i] == '--username') {
            $username = $argv[++$i];

        } elseif ($argv[$i] == '--password') {
            $password = $argv[++$i];

        } elseif ($argv[$i] == "-q" && !$quiet) {
            $quiet = true;
            ob_start();

        } elseif ($argv[$i] == "--get-latest-id") {
            if (($latest = get_latest_tid($nyaaURL)) !== false) {
                echo $latest."\n";
                die(0);
            } else {
                echo "FATAL: Couldn't retrieve latest ID\n";
                die(1);
            }

        } elseif ($argv[$i] == "--update") {
            $update = true;
        } else {
            echo "FATAL: Unknown argument '".$argv[$i]."'\n";
            usage();
        }
    }

    if ($database == 'sqlite') {
        $db = new DB_Sqlite('sqlite:' . $dbfile);
    } elseif ($database == 'mysql') {
        $db = new DB_Mysql('mysql:' . $dsn, $username, $password);
    }

    if ($update) {
        if (($latest = get_latest_tid($nyaaURL)) !== false) {
            $current = $db->getHighestEntryID();

            $start = $current + 1;
            if ($latest - $start > 100) {
                $count = 100;
                $batches = ceil( ($latest - $start)/100 );
            } else {
                $count = $latest - $current;
                $batches = 1;
            }

            echo "INFO: Got ${current}/${latest}, setting -s ${start} -c ${count} -b ${batches}\n";

            if ($count == 0) {
                echo "INFO: Nothing to do, closing.\n";
                die(0);
            }
        } else {
            echo "FATAL: Couldn't retrieve latest ID\n";
            die(1);
        }
    }

    if ($count == 0) {
        echo "FATAL: No count specified.\n\n";
        usage();
    }


    echo sprintf("%10s %10s %10s %10s %10s\n", "Start", "Count", "Failures", "Entries", "Torrents");

    for($i = 0; $i < $batches; ++$i) {
        processRange($db, $nyaaURL, $start, $count, $getTorrents);
        $start += $count;
    }

    if ($quiet) {
        ob_end_clean();
    }

    return 0; // EXIT_SUCCESS
}

return main(array_slice($_SERVER['argv'], 1));
