<?php
	$path = dirname(realpath($argv[0]));
	if(chdir($path))
	{
		$_SERVER['REMOTE_USER'] = $argv[1];
		require_once( '../../php/xmlrpc.php' );
		require_once( './stat.php' );
		eval(getPluginConf('trafic'));
		
		$req = new rXMLRPCRequest( array(
			new rXMLRPCCommand("get_up_total"),
			new rXMLRPCCommand("get_down_total"), 
			new rXMLRPCCommand("d.multicall", array("main","d.get_hash=","d.get_up_total=","d.get_down_total="))));
		$req->setParseByTypes();
		if($req->run() && !$req->fault)
		{
		        $dir = getSettingsPath().'/trafic/';
			$was = array(0,0,0);
			$wasTorrents = array();
			if($file=@fopen($dir.'last.csv',"r"))
			{
				$was = fgetcsv($file,100);
				while(($data = fgetcsv($file, 1000)) !== false)
					$wasTorrents[$data[0]] = array_slice($data,1);
				fclose($file);
			}
			$tm = time();
			$needUpdate = ($was[2]+3600>=$tm);
			$now = array_slice($req->i8s,0,2);
			$now[2] = $tm;
			$nowTorrents = array();
			for($i = 0; $i<count($req->strings); $i++)
				$nowTorrents[$req->strings[$i]] = array_slice($req->i8s,($i+1)*2,2);
			ksort($nowTorrents);
			if($file=@fopen($dir.'last.csv',"w"))
			{
				fputcsv($file,$now);
				foreach($nowTorrents as $key=>$data)
				{
					$tmp = $data;
					array_unshift($tmp, $key);
					fputcsv($file,$tmp);
				}
				fclose($file);
				@chmod($dir.'last.csv',0777);
			}
			if($needUpdate)
			{
				$needTorrents = array();
                                foreach($nowTorrents as $key=>$data)
				{
					if(array_key_exists($key,$wasTorrents))
					{
						$delta_up = floatval($data[0])-floatval($wasTorrents[$key][0]);
						$delta_down = floatval($data[1])-floatval($wasTorrents[$key][1]);
						if(($delta_up<0) || ($delta_down<0))
						{
							$delta_up = 0;
							$delta_down = 0;
						}
						if($delta_down!=0 || $delta_up!=0)
							$needTorrents[$key] = array($delta_up,$delta_down);
					}
					else
						$needTorrents[$key] = $data;
				}
				$trackers = array();
				foreach($needTorrents as $key=>$data)
				{
				        $req = new rXMLRPCRequest( array(
						new rXMLRPCCommand("t.multicall", 
							array($key,"","t.is_enabled=","t.get_type=","t.get_group=","t.get_url="))));
					$req->setParseByTypes();
					if($req->run() && !$req->fault)
					{
						$lastGroup = 65535;
						for($i = 0; $i<count($req->strings); $i++)
						{
							if($req->i8s[$i*3+2]>$lastGroup)
								break;
							if(($req->i8s[$i*3]!=0) && ($req->i8s[$i*3+1]<3))
							{
								$lastGroup = $req->i8s[$i*3+2];
								$domain = parse_url($req->strings[$i],PHP_URL_HOST);
								if(preg_match("/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/",$domain)!=1)
								{
									$pos = strpos($domain,'.');
									if($pos!==false)
									{
										$tmp = substr($domain,$pos+1);
										if(strpos($tmp,'.')!==false)
											$domain = $tmp;
									}
								}
                                                                if(array_key_exists($domain,$trackers))
								{
									$trackers[$domain][0]+=$needTorrents[$key][0];
									$trackers[$domain][1]+=$needTorrents[$key][1];
								}
								else
									$trackers[$domain] = $needTorrents[$key];
							}
						}
					}
				}
				$delta_up = floatval($now[0])-floatval($was[0]);
				$delta_down = floatval($now[1])-floatval($was[1]);
				if(($delta_up<0) || ($delta_down<0))
				{
					$delta_up = 0;
					$delta_down = 0;
				}
				$st = new rStat('global.csv');
				$st->correct($delta_up,$delta_down);
				$st->flush();

				$dh = @opendir($dir."trackers");
				if($dh)
				{
					while(false !== ($file = readdir($dh)))
					{
						if(is_file($dir."trackers/".$file))
						{
							$file = basename($file, ".csv");
							if(!array_key_exists($file,$trackers))
								$trackers[$file] = array(0,0);
						}
					}
					closedir($dh);
				}

                                foreach($trackers as $key=>$data)
				{
				        if(!empty($key))
				        {
						$st = new rStat("trackers/".$key.".csv");
						$st->correct($data[0],$data[1]);
						$st->flush();
					}
				}
			}
		}
	}
?>
