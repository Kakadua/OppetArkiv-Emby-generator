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
		$string = str_replace($old, $new, $string); //Replace åäö
		
		$string = str_replace(' ', '-', $string); // Replaces all spaces with hyphens.
		return preg_replace('/[^A-Za-z0-9\-]/', '', $string); // Removes special chars.
	}
	
	
	
	
	function c_print($str){ //Print the message and js that scrolls to bottom of page. Then update page even if not finnished loading
		echo $str . '<script>window.scrollTo(0,document.documentElement.clientHeight)</script>';
		ob_flush(); flush();
	}
	
	function c_println($str){
		c_print($str.'<br/>');
	}
	
	
	
	
	function win_encode($str){
		return mb_convert_encoding($str, 'ISO-8859-1','utf-8');
	}
	
	
	
	
	//Start variables
	$page = 1;
	$episodes = array();		
	
	
?>
<html><head><style>body{ color: #0F0; background: #000; }</style></head><body><?php

	if(isset($_POST['s'])){
		
		$url = $_POST['s'];
	
		//If input is url get the show id else it is id
		if(string_contain($url, "/")){
			if(string_contain($url, "/video/")){ //If input url is for episode and not show
				c_println('Converting from episode URL to series URL...');
				$url = 'http://www.oppetarkiv.se'.get_between(get_between(file_get_contents($url), '<dd class="svtoa-dd">', '</dd>'), '<a href="', '"'); //Convert url from episode to show
			}
			$temp = explode("/", $url);
			$show = $temp[count($temp)-2];
		}else{
			$show = $url;
		}
		
		
		
		
		c_print('Loading episodes...');
		$load = load_show($show, $page); //Load first page
		$loaded = count($load['episodes']); //Number loaded
		
		
		
		
		while(!$load['last']){ //If there are more pages to load after this one
		
			c_print('( ' . $loaded.' )<br/>Loading more episodes... ');			
			$page++;
			$episodes = array_merge($episodes, $load['episodes']); //Add to episodes list
			$load = load_show($show, $page); //Load next page
			$loaded = $loaded + count($load['episodes']); //Update loaded number
			
		}
		c_println('( ' . $loaded.' )');
		
		
		
		
		$episodes = array_merge($episodes, $load['episodes']);//Add to episodes list
		c_println('Loaded ' . count($episodes) . ' episodes in total. Starting generation...'); //Print number of episodes that has been loaded
		
		
		
		
		$show = no_special_char(urldecode($show)); //Here to avoid problems with encoding on windows server
		if($_POST['reset'] == '1'){  //Delete old versions before downloading
			delete_dir('files/' . $show);
			c_println('Deleted old files on server'); 
		}
		
		if(!file_exists('files/' . $show)){ mkdir('files/' . $show, 0777, true);	} //Create folder if it doesn't exist
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
			
			
			$j++; //Update number of loops (episodes)
			if($j>99){ $j = sprintf('%03d', $j); }else{ $j = sprintf('%02d', $j); } //Convert episodes so it has leading zeros. 2 or 3 depending on how big number
			
			
			
			
			$folder = 'files/'.$show;
			$filename = $folder.'/' . $j . ' - ' . $title;	
			
			
			
			
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
			
			
			
			
			if(!file_exists($filename.'.strm')){ //If the file has never been generated before (or has been reset)
				
				if(!file_exists($folder)){ mkdir($folder, 0777, true); } //Create folder if it doesn't exist
				file_write($filename.'.strm', $m3u8);
				file_write($filename.'.nfo', $xml);
				file_put_contents($filename.'-thumb.jpg', file_get_contents($thumb));
				
				$print .= 'Added: '; 

			}else{ $print .= 'Skipped: '; }			
			
			
			
			
			$print .=  $j . ' - ' . $title . '<br/>';
			c_print($print);
		}
		
		
		
		
		c_println('Zipping it up');
		zip_folder('files/'.$show, 'zip/'.$show.'.zip');
		c_println('Redirect to download...');
		c_print('<script>
					window.location.href="zip/'.utf8_encode($show).'.zip";
				</script>
			');
		
		
		
		
	}
?>
</body></html>