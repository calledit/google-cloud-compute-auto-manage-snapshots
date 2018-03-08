#!/usr/bin/php
<?php


//Dependencys php clonezilla gcloud

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


if($argv[1] == 'offsite_backup'){
	//preferably run this once a week on saturday or sunday just after that days snapshot has been taken that way you get the wekend to to transfer and mimimize bandwith impact and get an extra backup that is keppt for more than 7 days

	echo "transfering backups to offsiste storage (functionality WORK IN PROGRESS)\n";

	$own_zone = get_own_zone();
	$own_name = get_own_name();
	echo "own zone: $own_zone\n";

	$time_per_GB = 60*10;//We allow 10 minutes per GB of disk if it takes longer than that we asume there has been an error

	$snapshots = list_snapshots();
	$snapshots_of_disks = group_auto_snappshots_per_disk($snapshots);
	foreach($snapshots_of_disks AS $source_disk_uri => $disk_snapshots){
		echo("disk: ".$source_disk_uri."\n");

		$last_snap = end($disk_snapshots);
		echo("	snapshot: ".$last_snap['name']."\n");


		//If this fails the offisite disk may exsit and need deletion due to a failed previus atempt
		echo("gcloud compute disks create offsite-disk --zone=".$own_zone.' --source-snapshot '.$last_snap['name']);
		echo "\n";
		//echo("gcloud compute instances attach-disk ".$own_name." --mode=ro --zone=".$own_zone.' --device-name=attached-offsite --disk=offsite-disk');//disk read only when using dd
		echo("gcloud compute instances attach-disk ".$own_name." --zone=".$own_zone.' --device-name=attached-offsite --disk=offsite-disk');//to use clonezilla we need the disk to be writable
		echo "\n";
		echo "file should now be attahed to this device and be named: /dev/disk/by-id/google-attached-offsite\n";

		$disk_device = explode('/', readlink('/dev/disk/by-id/google-attached-offsite'));
		$disk_device = array_pop($disk_device);


		echo "/home/partimag/ Needs to be mounted offsite with NFS or similar";

		$max_time_to_take_backup = $last_snap['diskSizeGb'] * $time_per_GB;


		//We should interupt the procces if it takes longer than it should
		$time_before_backup  = time();

		//This is the fastest way to take the image but it does require the disk to be writen to
		echo "sudo /usr/sbin/ocs-sr -batch -q2 -j2 -z1 -i 2000 -fsck-src-part-y -nogui -p true savedisk ".$last_snap['name']." ".$disk_device."\n";


		$time_taken = time() - $time_before_backup;

		//TODO this does nothing we need to run this check in parallel with the backup
		if($time_taken > $max_time_to_take_backup){
			echo("The backup was to slow it should have finished earlier\n");
		};


		echo "backup should now be located at /home/partimag/".$last_snap['name']."\n";

		//We rename the clonezilla folder to indicate that the backup is done
		echo "sudo mv /home/partimag/".$last_snap['name']." /home/partimag/done-".$last_snap['name']."\n";


		echo("gcloud compute instances detach-disk ".$own_name." --zone=".$own_zone.' --disk=offsite-disk');
		echo "\n";
		echo("gcloud compute disks delete offsite-disk --zone=".$own_zone.' --quiet');
		echo "\n";

		//at this point the files need to be locked so that this machine cant read or alter the files. It is an offsite backup for a reason...

		//This is super slow but it garanties a perferct image
		//echo "dd if=/dev/disk/by-id/google-attached-offsite | gzip -1 - | dd of=image.gz";
		echo "\n";

		exit;
	}
	exit;
}



if($argv[1] == 'free_old'){
	$snapshots = list_snapshots();
	$snapshots_of_disks = group_auto_snappshots_per_disk($snapshots);
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


function get_own_name(){
	$meta_context = stream_context_create([
	    "http" => [
		"method" => "GET",
		"header" => "Metadata-Flavor: Google"
	    ]
	]);

	return file_get_contents('http://metadata.google.internal/computeMetadata/v1/instance/name', false, $meta_context);
}

function get_own_zone(){
	$meta_context = stream_context_create([
	    "http" => [
		"method" => "GET",
		"header" => "Metadata-Flavor: Google"
	    ]
	]);

	$info = file_get_contents('http://metadata.google.internal/computeMetadata/v1/instance/zone', false, $meta_context);
	$info = explode('/', $info);
	return array_pop($info);
}

function group_auto_snappshots_per_disk($snapshots){
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
	return $snapshots_of_disks;
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
		$snapname = $snappdate.$disk['name'];
		if(strlen($snapname) > 63){
			//Disk names can not be longer than 39 charaters long as the date is 24 characters long
			exec_ret("gcloud logging write --severity=ERROR 'snapshot_error' ".escapeshellarg($snapname.' is longer than 63 charaters long'));
		}
		$names[] = substr($snapname, 0 , 63);
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
