#!/usr/bin/php
<?php

function on_exception($Ex){
	$errstr = $Ex->getMessage();
	echo "Exception: ".$errstr."\n";
	exec_ret("gcloud logging write --severity=ERROR 'snapshot_error' ".escapeshellarg($errstr));
}
set_exception_handler("on_exception");


if(!isset($argv[1])){
	echo("php manage_snapshots.php take\n");
	echo("php manage_snapshots.php free_old\n");
	exit;
}

if($argv[1] == 'take'){
	//Take snappshots of all machines
	$instances = list_instances();
	//var_dump($instances);
	//exit;
	foreach($instances AS $instance){
		echo "Taking snappshots of the instance ".$instance['name']."\n";
		take_snappshot($instance);

		echo "\n\n\n";

	}
	exit;
}

if($argv[1] == 'free_old'){
	$snapshots = list_snapshots();
	$snapshots_of_disks = array();
	foreach($snapshots AS $snapshot){
		$nameparts = explode('-', $snapshot['name']);
		$prefix = array_shift($nameparts);
		if($prefix == 'auto'){
			$snapshot['snapshot_date'] = array_shift($nameparts).'-'.array_shift($nameparts).'-'.array_shift($nameparts).' '.array_shift($nameparts).':'.array_shift($nameparts).':'.array_shift($nameparts);
			$snapshot['snapshot_unix_time'] = strtotime($snapshot['snapshot_date']);
			$source_disk = $snapshot['sourceDisk'];
			if(!isset($snapshots_of_disks[$source_disk])){
				$snapshots_of_disks[$source_disk] = array();
			}
			$snapshots_of_disks[$source_disk][] = $snapshot;
		}
	}
	$curent_time = time();
	foreach($snapshots_of_disks AS $source_disk_uri => $disk_snapshots){
		echo("source: ".$source_disk_uri."\n");
		$date_categories = array(
			'7days' => array(),
			'31days' => array(),
			'100days' => array(),
			'over100days' => array(),
		);
		foreach($disk_snapshots AS $snapshot){
			$age = $curent_time - $snapshot['snapshot_unix_time'];
			$age_in_days = $age/(3600*24);
			$age_in_full_days = intval($age_in_days);
			$snapshot['age_in_full_days'] = $age_in_full_days;
			$snapshot['day_of_week'] = date('w', $snapshot['snapshot_unix_time']);

			if($age_in_full_days < 7){
				$date_categories['7days'][] = $snapshot;
			}elseif($age_in_full_days < 31){
				$date_categories['31days'][] = $snapshot;
			}elseif($age_in_full_days < 100){
				$date_categories['100days'][] = $snapshot;
			}else{
				$date_categories['over100days'][] = $snapshot;
			}
			//echo("	age in days: ".$age_in_days."\n");
		}



		//sunday = day_of_week = 0
		//monday = day_of_week = 1
		//thusday = day_of_week = 2
		//wensday = day_of_week = 3
		//thursday = day_of_week = 4
		//friday = day_of_week = 5
		//saturday = day_of_week = 6

		//for snappshots of the last 7 days we keep them all
		//$date_categories['7days']

		//for snappshots of the last 31 days we keep the ones taken on tuesday and friday
		foreach($date_categories['31days'] AS $snapshot){
			if($snapshot['day_of_week'] == 2 || $snapshot['day_of_week'] == 5){
				//keep this snapshot
			}else{
				echo "31days remove snapshot: ".$snapshot['name']."\n";
				remove_snappshot($snapshot);
			}
		}

		//for snappshots of the last 100 days we keep the ones taken on tuesday
		foreach($date_categories['100days'] AS $snapshot){
			if($snapshot['day_of_week'] == 2){
				//keep this snapshot
			}else{
				echo "100days remove snapshot: ".$snapshot['name']."\n";
				remove_snappshot($snapshot);
			}
		}

		//we discard all snapshots older than 100 days
		foreach($date_categories['over100days'] AS $snapshot){
			echo "over100days remove snapshot: ".$snapshot['name']."\n";
			remove_snappshot($snapshot);
		}
	}

	exit;
}
function remove_snappshot($snapshot){
	exec_ret("gcloud compute snapshots delete ".$snapshot['name'].' --quiet');
}

function take_snappshot($instance){

	$snappdate = 'auto-'.date("Y-m-d-H-i-s").'-';
	$machine_disks = list_disks($instance['selfLink']);
	$disks = array();
	$names = array();
	foreach($machine_disks AS $disk){
		$disks[] = $disk['name'];
		$names[] = $snappdate.$disk['name'];
	}
	$disks = implode(" ", $disks);
	$names = implode(",", $names);

	if(empty($disks) OR empty($names)){
		return;
	}

	$machineType = explode('/', $instance['machineType']);

	$instance_info = 'machine: '.$instance['name'].', '.
		'type: '.array_pop($machineType).', '.
		'project: '.$instance['project'].', '.
		'zone: '.$instance['zone'];

	$instance_disks = array();
	$instance_nets = array();

	foreach($instance['networkInterfaces'] AS $net){
		$instance_nets[] = $net['name'].' = '.$net['networkIP'];
	}

	foreach($instance['disks'] AS $disk){
		$instance_disks[] = $disk['deviceName'];
	}

	$tags = array();
	if(isset($instance['tags']['items'])){
		$tags = $instance['tags']['items'];
	}


	//snapshot.description cant be larger than 2048
	$instance_info = $instance_info.' disks: ('.implode(', ', $instance_disks).') nets: ('.implode(', ', $instance_nets).') tags: ('.implode(', ', $tags).')';

	//Dent send enters to the command line
	$instance_info = substr(str_replace("\n", " ", $instance_info), 0 ,2048);

	exec_ret("gcloud compute disks snapshot ".$disks." --snapshot-names=".$names." --zone=".$instance['zone'].' --description=\''.$instance_info.'\' --async');
}

function list_snapshots($diskuri = NULL){
	$filter = '';
	if(isset($diskuri)){
		$filter = ' --filter sourceDisk='.$diskuri;
	}
	$list_data = exec_ret("gcloud compute snapshots list --format=json".$filter);

	$list = json_decode(implode("\n", $list_data), true);
	if(!is_array($list)){
		throw new Exception("list was not proper json");
	}

	$snapshots = array();
	foreach($list AS $line){
		$uri_parts = parse_url($line['selfLink']);
		if(!empty($uri_parts['path'])){
			$path = explode('/', $uri_parts['path']);
			if(count($path) != 8){
				throw new Exception("compute snapshot uri is malformated ".$line['selfLink']);
			}
			if($path[2] != 'v1'){
				throw new Exception("compute snapshot uri is not version v1 ".$line['selfLink']);
			}
			$line['project'] = $path[4];
			$snapshots[] = $line;
		}
	}
	return $snapshots;
}

function list_disks($instance = NULL){
	$filter = '';
	if(isset($instance)){
		$filter = ' --filter users='.$instance;
	}
	$list_data = exec_ret("gcloud compute disks list --format=json".$filter);

        $list = json_decode(implode("\n", $list_data), true);
        if(!is_array($list)){
                throw new Exception("list was not proper json");
        }

	$disks = array();
	foreach($list AS $line){
		$uri_parts = parse_url($line['selfLink']);
		if(!empty($uri_parts['path'])){
			$path = explode('/', $uri_parts['path']);
			if(count($path) != 9){
				throw new Exception("compute disk uri is malformated ".$line['selfLink']);
			}
			if($path[2] != 'v1'){
				throw new Exception("compute disk uri is not version v1 ".$line['selfLink']);
			}
			$line['project'] = $path[4];
			$line['zone'] = $path[6];

			$disks[] = $line;
		}
	}
	return $disks;
}


function list_instances(){
	$list_data = exec_ret("gcloud compute instances list --format=json");

        $list = json_decode(implode("\n", $list_data), true);
        if(!is_array($list)){
                throw new Exception("list was not proper json");
        }

	$instances = array();

	foreach($list AS $line){
		$uri_parts = parse_url($line['selfLink']);
		if(!empty($uri_parts['path'])){
			$path = explode('/', $uri_parts['path']);
			if(count($path) != 9){
				throw new Exception("compute instance uri is malformated ".$line['selfLink']);
			}
			if($path[2] != 'v1'){
				throw new Exception("compute instance uri is not version v1 ".$line['selfLink']);
			}
			$line['project'] = $path[4];
			$line['zone'] = $path[6];
			$instances[] = $line;
		}
	}
	return $instances;
}


function exec_ret($cmd){
	exec($cmd, $out, $fail);
	if($fail != 0){
		throw new Exception("Failed to run command: $cmd");
	}
	return $out;
}
