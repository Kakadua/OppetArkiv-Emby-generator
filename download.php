<?php
	set_time_limit(0);
	error_reporting(0);

	include('PHP-Snippets/include_functions.php');
	
	//Zip folder
	function zip_folder($folder, $zipFile){
		$root = realpath($folder);
		$zip = new ZipArchive();
		$zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root), RecursiveIteratorIterator::LEAVES_ONLY);
		foreach($files as $name => $file){
			if(!$file->isDir()){
				$filePath = $file->getRealPath();
				$relativePath = substr($filePath, strlen($root) + 1 );
				$zip->addFile($filePath, $relativePath);
			}
		}
		$zip->close();
	}
	
	//Delete directory with files recursivly
	function delete_dir($dirPath) {
		if (substr($dirPath, strlen($dirPath) - 1, 1) != '/') {
			$dirPath .= '/';
		}
		$files = glob($dirPath . '*', GLOB_MARK);
		foreach ($files as $file) {
			if (is_dir($file)) {
				delete_dir($file);
			} else {
				unlink($file);
			}
		}
		rmdir($dirPath);
	}
	
	function load_show($title, $page){
		$raw = file_get_contents("http://www.oppetarkiv.se/etikett/titel/".urlencode($title)."/?sida={$page}&sort=tid_stigande&embed=true");
		$episodeRaw = get_between_all($raw, '<article class="svtUnit', 'article>');
		$return = array(
			'last' => !string_contain($raw, 'Visa fler'),
			'episodes' => array()
		);
		foreach($episodeRaw as $episode){
			$temp = array();
			$temp['title'] = get_between($episode, 'alt="', '"');
			$temp['cover'] = 'http:'.get_between($episode, 'oaImg" src="', '"');
			$temp['year'] = get_between($episode, 'datetime="', '-');
			$temp['aired'] = get_between($episode, 'datetime="', 'T');
			$temp['url'] = 'http://www.oppetarkiv.se' . get_between($episode, ' href="', '"');
			array_push($return['episodes'], $temp);
		}
		return $return;
	}
	
	function no_special_char($string) {
		$old = array('å', 'ä', 'ö', 'Å', 'Ä', 'Ö');
		$new = array('a', 'a', 'o', 'A', 'A', 'O');
		$string = str_replace($old, $new, $string);
		
		$string = str_replace(' ', '-', $string); // Replaces all spaces with hyphens.
		return preg_replace('/[^A-Za-z0-9\-]/', '', $string); // Removes special chars.
	}
	
	$arrContextOptions=array(
		"ssl"=>array(
			"verify_peer"=>false,
			"verify_peer_name"=>false,
		),
	); 
	
	function sanitize($str){
		return mb_convert_encoding($str, 'ISO-8859-1','utf-8');
	}

	$badCharacters = array_merge(
        array_map('chr', range(0,31)),
        array("<", ">", ":", '"', "/", "\\", "|", "?", "*"));	
		
	$scroll = '<script>window.scrollTo(0,document.documentElement.clientHeight)</script>';
?>
<html><head><style>body{ color: #0F0; background: #000; }</style></head><body><?php

	if(isset($_POST['s'])){
	
		if(string_contain($_POST['s'], "/")){
			$temp = explode("/", $_POST['s']);
			$show = $temp[count($temp)-2];
		}else{
			$show = $_POST['s'];
		}
		
		$page = 1;

		$episodes = array();

		echo 'Loading episodes...'.$scroll;
		ob_flush(); flush();
		
		$load = load_show($show, $page);
		$loaded = count($load['episodes']);
		
		while(!$load['last']){
			echo '( ' . $loaded.' )<br/>' . $scroll;
			echo 'Loading more episodes... ' . $scroll;
			ob_flush(); flush();
			
			$page++;
			$episodes = array_merge($episodes, $load['episodes']);
			$load = load_show($show, $page);
			$loaded = $loaded + count($load['episodes']);
		}
		echo '( ' . $loaded.' )<br/>' . $scroll; //Printed here as well since loop is not run for last batch of episodes
		ob_flush(); flush();
		
		$episodes = array_merge($episodes, $load['episodes']);
		echo 'Loaded '.count($episodes).' episodes in total. Starting generation...<br/>'.$scroll;
		ob_flush(); flush();
		
		$show = sanitize($show);
		if($_POST['reset'] == '1'){ delete_dir('files/'.$show); echo 'Deleted old files on server<br/>'.$scroll; ob_flush(); flush(); } //Delete old versions before downloading
		if(!file_exists('files/'.$show)){ mkdir('files/'.$show, 0777, true);	} //Create folder if it doesn't exist
		if(!file_exists('zip')){ mkdir('zip', 0777, true); } //Create folder if it doesn't exist
		
		$j = 0;
		foreach($episodes as $episode){
			$print = '';
			
			$data = json_decode(file_get_contents($episode['url'].'?output=json'), true);
			$title = str_replace('-', ' ', no_special_char($data['context']['title'])); //TODO, fix encoding bug so there is no need to remove special char
			$thumb = $episode['cover'];
			$year = $episode['year'];
			$date = $episode['aired'];
			$m3u8 = file($data['video']['videoReferences'][1]['url']);
			$m3u8 = $m3u8[count($m3u8)-1];
			$plot = '';
			$j++;
			if($j>99){ $j = sprintf('%03d', $j); }else{ $j = sprintf('%02d', $j); }		
			
			echo $print.$scroll;
			ob_flush(); flush();
			
			
			//The nfo file
			$xml = '
				<?xml version="1.0" encoding="utf-8" standalone="yes"?>
					<episodedetails>
						<plot>'.$plot.'</plot>
						<title>'.$title.'</title>
						<originaltitle>'.$title.'</originaltitle>
						<year>'.$year.'</year>
						<aired>'.$date.'</aired>
					</episodedetails>
				';
					
					
			$folder = 'files/'.$show;
			$filename = $folder.'/' . $j . ' - ' . $title;	
			
			if(!file_exists($filename.'.strm')){
				
				if(!file_exists($folder)){ mkdir($folder, 0777, true); } //Create folder if it doesn't exist
				file_write($filename.'.strm', $m3u8);
					file_write($filename.'.nfo', $xml);
				file_put_contents($filename.'-thumb.jpg', file_get_contents($thumb));
				$print .= 'Added: ';

			}else{ $print .= 'Skipped: '; }			
			
			$print .= $j . ' - ' . $title . '<br/>';
			$print .= $scroll;
			
			echo $print;
			
			ob_flush(); flush();
		}
		
		echo 'Zipping it up<br/>'.$scroll;
		zip_folder('files/'.$show, 'zip/'.$show.'.zip');
		echo 'Redirect to download...<br/>'.$scroll.'
				<script>
					window.location.href="zip/'.utf8_encode($show).'.zip";
				</script>
			';
			
	}
?>
</body></html>