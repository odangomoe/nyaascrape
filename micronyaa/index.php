<?php

error_reporting(E_ALL);
date_default_timezone_set('Etc/UTC');

define('DB_FILE',       '../nyaascrape.db');
define('ROWS_PER_PAGE', 100);

route(hasGet('page') ? $_GET['page'] : '');
die();

//

function db() {
	static $db = null;
	if (is_null($db)) {
		$db = new \PDO('sqlite:'.DB_FILE);
	}
	return $db;
}

function categories() {
	return [
		//"1_0" => "Anime",
		"1_32" => "Anime - Anime Music Video",
		"1_37" => "Anime - English-translated Anime",
		"1_38" => "Anime - Non-English-translated Anime",
		"1_11" => "Anime - Raw Anime",
		//"3_0" => "Audio",
		"3_14" => "Audio - Lossless Audio",
		"3_15" => "Audio - Lossy Audio",
		//"2_0" => "Literature",
		"2_12" => "Literature - English-translated Literature",
		"2_39" => "Literature - Non-English-translated Literature",
		"2_13" => "Literature - Raw Literature",
		//"5_0" => "Live Action",
		"5_19" => "Live Action - English-translated Live Action",
		"5_22" => "Live Action - Live Action Promotional Video",
		"5_21" => "Live Action - Non-English-translated Live Action",
		"5_20" => "Live Action - Raw Live Action",
		//"4_0" => "Pictures",
		"4_18" => "Pictures - Graphics",
		"4_17" => "Pictures - Photos",
		//"6_0" => "Software",
		"6_23" => "Software - Applications",
		"6_24" => "Software - Games",
	];
}

function hasGet($key) {
	return array_key_exists($key, $_GET) && !is_array($_GET[$key]);
}

function hesc($str) {
	return htmlentities($str, ENT_QUOTES, 'UTF-8');
}

function fmtBytes($bytes) {
	if ($bytes < 1024) {
		return $bytes.' B';
	
	} elseif ($bytes < 1024*1024) {
		return number_format($bytes / 1024, 1).' KiB';	
		
	} elseif ($bytes < 1024*1024*1024) {
		return number_format($bytes / (1024*1024), 1).' MiB';	
		
	} elseif ($bytes < 1024*1024*1024*1024) {
		return number_format($bytes / (1024*1024*1024), 1).' GiB';	
		
	} else {
		return number_format($bytes / (1024*1024*1024*1024), 1).' TiB';
		
	}
}

function route($page) {
	switch($page) {
		case 'view': {
			if (hasGet('tid')) {
				render(view($_GET['tid']));
			} else {
				header('Location: ?page=browse&offset=1');
			}
		} break;
		
		case 'download': {
			if (hasGet('tid')) {
				download($_GET['tid']);
			} else {
				header('Location: ?page=browse&offset=1');
			}
		} break;
		
		case 'browse': {
			render(browse( hasGet('offset') ? $_GET['offset'] : 0 ));
		} break;
		
		case 'search': {
			if (hasGet('q')) {
				render(search($_GET['q']));
			} else {
				header('Location: ?page=browse&offset=1');
			}
		} break;
		
		default: {
			header('Location: ?page=browse&offset=1');
		} break;
	}
}

function download($tid) {
	$query = db()->prepare('SELECT * FROM torrentfiles WHERE id = ?');
	
	$query->execute([$tid]);	
	$check = $query->fetchAll(\PDO::FETCH_ASSOC);
	
	if (count($check) == 1) {
		header('Content-Type: application/x-bittorrent');
		header('Content-Disposition: attachment; filename="nyaa_'.$tid.'.torrent"');
		echo $check[0]['torrentfile'];
		die();
	}
}

function view($tid) {
	$query = db()->prepare('SELECT * FROM nyaa WHERE id = ?');
	$query->execute([$tid]);
	$check = $query->fetchAll(\PDO::FETCH_ASSOC);
	if (count($check) !== 1) {
		header('Location: ?');
		die();
	}

	ob_start();
?> 
<style type="text/css">
	th {
		text-align:left;
	}
</style>
	
<strong>
	<?=hesc(categories()[$check[0]['categoryID']])?> 
</strong>

<div class="content-area <?=$check[0]['pageClass']?>">

	<table>
		<tr>
			<td style="width:50%;vertical-align:top;">
			
				<table>
					<tr>
						<th>Name:</th>
						<td><?=hesc($check[0]['title'])?></td>
					</tr>
					<tr>
						<th>Submitter:</th>
						<td>
							<?php if ($check[0]['submitterID']) { ?> 
							<a href="http://www.nyaa.se/?user=<?=$check[0]['submitterID']?>"><?=hesc($check[0]['submitterName'])?></a>
							<?php } else { ?> 
							<?=hesc($check[0]['submitterName'])?>
							<?php } ?> 
						</td>
					</tr>
					<tr>
						<th>Information:</th>
						<td><?=hesc($check[0]['infoURL'])?></td>
					</tr>
				</table>
				
			</td>
			<td style="width:50%;vertical-align:top;">
				
				<table style="margin:0 0 0 auto;">
					<tr>
						<th>Date:</th>
						<td><?=hesc(date(DATE_RSS, $check[0]['date']))?></td>
					</tr>
					<tr>
						<th>Seeders:</th>
						<td class="list-s"><?=$check[0]['seeders']?></td>
					</tr>
					<tr>
						<th>Leechers:</th>
						<td class="list-l"><?=$check[0]['leechers']?></td>
					</tr>
					<tr>
						<th>Downloads:</th>
						<td class="list-d"><?=$check[0]['downloads']?></td>
					</tr>
					<tr>
						<th>File size:</th>
						<td><?=fmtBytes($check[0]['filesize'])?></td>
					</tr>
					<tr>
						<th>&nbsp;</th>
						<td>
							<a href="?page=download&tid=<?=$tid?>"><img src="images/www-download.png"></a>
						</td>
					</tr>
				</table>
				
			</td>
		</tr>
	</table>
	
	<div style="padding:4px;">
		
		<strong>Torrent Description:</strong>
		
		<div style="background:white;padding:3px;">
			<?=nl2br(hesc($check[0]['description']))?> 
		</div>

	</div>
		
</div>

	
<?php
	return ob_get_clean();
}

function browse($page=1) {

	$check = db()->query('SELECT COUNT(*) c FROM nyaa')->fetchAll(\PDO::FETCH_ASSOC);
	$total_entries = $check[0]['c'];

	$total_pages = ceil($total_entries / ROWS_PER_PAGE);
	
	$offset = max(0, $page - 1);
	$list = db()->query('SELECT * FROM nyaa ORDER BY id DESC LIMIT '.intval(ROWS_PER_PAGE).' OFFSET '.intval($offset * ROWS_PER_PAGE))->fetchAll(\PDO::FETCH_ASSOC);
	

	ob_start();
?>	
<style type="text/css">
	.page-links {
		margin: 4px 0;
	}
	.page-links a {
		padding:1px;
		border:1px solid #CCC;
		background:#EEE;
		text-decoration:none;
	}
</style>

<div class="page-links">
	<strong>Page:</strong>
	<?php for ($i = 0; $i < $total_pages; ++$i) { ?> 
		<?php if ($i == $offset) { ?>
	<strong><?=$i + 1?></strong>
		<?php } else { ?> 
	<a href="?page=browse&offset=<?=$i+1?>"><?=$i+1?></a>
		<?php } ?> 
	<?php } ?> 
</div>
<?=renderTable($list)?> 
<?php	
	return ob_get_clean();
}

function search($string) {
	
	$filtered = str_replace(['_', '%', '\\'], ['\_', '\%', '\\'], $string); // sanitise LIKE characters
	
	$query = db()->prepare('SELECT * FROM nyaa WHERE title LIKE ? ORDER BY id DESC');
	
	$query->execute(['%'.$filtered.'%']);
	$list = $query->fetchAll(\PDO::FETCH_ASSOC);
	
	ob_start();
?>
<strong><?=count($list)?> result(s) for:</strong> <em><?=hesc($string)?></em>

<?=renderTable($list) ?> 
<?php
	return ob_get_clean();
}

function renderTable($list) {
	
	$categories = categories();
	
	ob_start();	
?>
<style type="text/css">
	.list-title {
		color:darkgreen;
		font-weight:bold;
		
		text-decoration:none;
	}
	.list-title:hover {
		text-decoration:underline;
	}
	.tlisttbl {
		border-collapse: collapse;
		width:100%;
	}
	.tlisttbl td {
		padding:2px;
	}
	
	.shunt {
		position:relative;
		top:2px;
		z-index:1;
	}
	
</style>
<table class="tlisttbl">
	<tr>
		<td style="width:80px;">Category</td>
		<td>&nbsp;</td>
		<td>DL</td>
		<td style="width:80px;">Size</td>
		<td>SE</td>
		<td>LE</td>
		<td>DLs</td>
		<td>Msg</td>
	</tr>
<?php foreach($list as $row) { $cat = explode('_', $row['categoryID']); ?> 
	<tr class="tlistrow <?=$row['pageClass']?>">
		<td><img src="images/www-<?=$cat[1]?>.png" class="shunt" title="<?=hesc($categories[$row['categoryID']])?>"></td>
		<td>
			<a class="list-title" href="?page=view&tid=<?=$row['id']?>"><?=hesc($row['title'])?></a>
		</td>
		<td>
			<a href="?page=download&tid=<?=$row['id']?>" class="shunt"><img src="images/www-dl.png"></a>
		</td>
		<td>
			<?=hesc(fmtBytes($row['filesize']))?> 
		</td>
		<td class="list-s">
			<?=$row['seeders']?>
		</td>
		<td class="list-l">
			<?=$row['leechers']?>
		</td>
		<td class="list-d">
			<?=$row['downloads']?>
		</td>
		<td>
			-
		</td>
	</tr>
<?php } ?> 
</table>
<?php	
	return ob_get_clean();
}

function render($content) {
?>
<!DOCTYPE html>
<html>
	<head>
		<title>micronyaa</title>
		<style type="text/css">
* {
	font-family:sans-serif;
	font-size:11px;
}
a {
	border:0;
}
body {
	position:relative;
}
.page-header {
	position:fixed;
	top:0;
	left:0;
	right:0;
	height:30px;
	background:linear-gradient(#666, #000);
	
	z-index: 100;
}
.page-header a {
	position:relative;
	top:6px;
	
	text-decoration:none;
	color:white;
}
.page-container {
	margin-top:34px;
}
.center-area {
	width:960px;
	margin:0 auto;
}
/* */
.trusted {
	background-color:#98D9A8;
}	
.remake {
	background-color:#F0B080;
}	
.aplus {
	background-color:#60B0F0;
}	
.hidden {
	background-color:#C0C0C0;
}
.list-s {
	color:darkgreen;
	font-weight:bold;
}
.list-l {
	color:red;
	font-weight:bold;
}
.list-d {
	color:black;
	font-weight:bold;
}
		</style>
	</head>
	<body>
		<div class="page-header">
			<div class="center-area">

				<a href="?">micronyaa</a>
			
				<div style="float:right;margin:3px;">
					<form method="GET" action="?">
						<input type="hidden" name="page" value="search">
						<input type="text" name="q" placeholder="Search..." autofocus>
						<input type="submit" value="Search">
					</form>
				</div>
			</div>
		</div>
		<div class="page-container">
			<div class="center-area">
<?=$content?>
			</div>
		</div>
	</body>
</html>
<?php
}