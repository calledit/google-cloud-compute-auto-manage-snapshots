#!/usr/bin/php
<?php


//Dependencys php gcloud

function on_exception($Ex){
	$errstr = $Ex->getMessage();
	echo "Exception: ".$errstr."\n";
	log_error($errstr);
}
set_exception_handler("on_exception");

$offsite_disk_name = 'offsite-disk-'.getmypid();
$children = array();

function log_error($errstr){
	exec_ret("gcloud logging write --severity=ERROR 'snapshot_error' ".escapeshellarg($errstr));
}
function log_prog($errstr){
	$errstr = date("[d/M/Y:H:i:s O]").' '.$errstr;
	echo $errstr."\n";
	file_put_contents("/tmp/backup_prog.log", $errstr."\n", FILE_APPEND | LOCK_EX);
}

$folder_destination = '/media/gcp_backups/';
$sql_backup_dest = $folder_destination.'sql_backups/';

function take_disk_backup($disk_device, $target_name, $meta_data, $use_file_name_hashing = false, $compress = true){
	global $folder_destination, $offsite_disk_name;
	log_prog("taking backup of $disk_device to $target_name");

	$meta_file = $target_name.'.json';

	$logical_blocks = intval(file_get_contents('/sys/block/'.$disk_device.'/size'));
	$device_block_size = intval(file_get_contents('/sys/block/'.$disk_device.'/queue/physical_block_size'));
	$logical_block_size = intval(file_get_contents('/sys/block/'.$disk_device.'/queue/logical_block_size'));

	$size = $logical_blocks * $logical_block_size;

	$backup_block_size = 67108864;//64 MB
	$backup_block_size = 536870912;//512 MB
	$backup_block_size = 134217728;//128 MB
	$backup_block_size = 268435456;//256 MB


	$nr_of_backup_blocks = ceil($size/$backup_block_size);

	$backup_metadata = array(
		'name' => $target_name,
		'start_backup_time' => date("Y-m-d H:i:s"),
		'disk_size' => $size,
		'nr_of_backup_blocks' => $nr_of_backup_blocks,
		'files' => array(),
		'user_meta' => $meta_data,
		'disk_transfers' => 0,
	);

	$stats = array(
		'changed_blocks' => 0,
		'unchnaged_blocks' => 0,
		'total_blocks' => $backup_metadata['nr_of_backup_blocks'],
	);


	$destination = $folder_destination.$target_name.'/';

	if(!file_exists($destination)){
		exec_ret('sudo mkdir '.$destination);
		exec_ret('sudo chmod 777 '.$destination);
	}

	exec_ret('sudo chmod 777 /dev/disk/by-id/google-'.$offsite_disk_name);

	$old_meta = array('files' => array());
	if(file_exists($destination.$meta_file)){
		$old_meta = json_decode(file_get_contents($destination.$meta_file), true);

		if(isset($old_meta['disk_transfers'])){
			$backup_metadata['disk_transfers'] = $old_meta['disk_transfers'];
		}
	}
	$backup_metadata['disk_transfers'] += 1;

	//Place wip file to indicate that the proccess is running
	file_put_contents($destination.$meta_file.'.wip', json_encode($backup_metadata, JSON_PRETTY_PRINT));

	$current_block = 0;
	while($current_block < $nr_of_backup_blocks){

		$blok_nr_str = str_pad(strval($current_block), 5, "0", STR_PAD_LEFT);

		$file_name = $blok_nr_str.'-'.$target_name.'.img';


		log_prog("$target_name Copying block $current_block from disk\n");
		exec_ret('dd bs='.$backup_block_size.' skip='.$current_block.' count=1 if=/dev/disk/by-id/google-'.$offsite_disk_name.' of=/tmp/'.$file_name);



		$hash_out = exec_ret('sha512sum -b /tmp/'.$file_name);
		$hash_out = explode(' ', $hash_out[0]);
		$file_hash = $hash_out[0];
		$nr_block_changes = 0;

		$transfer_file = true;
		if(isset($old_meta['files'][$current_block])){
			if(isset($old_meta['files'][$current_block]['nr_block_changes'])){
				$nr_block_changes = $old_meta['files'][$current_block]['nr_block_changes'];
			}
			if($old_meta['files'][$current_block]['hash'] == $file_hash){
				log_prog("$target_name hash matches. Block hash: $file_hash");
				$stats['unchnaged_blocks'] += 1;
				$transfer_file = false;
			}else{
				log_prog("hash is diffrent. $file_hash != ".$old_meta['files'][$current_block]['hash']);
				$nr_block_changes += 1;
				$stats['changed_blocks'] += 1;
			}
		}else{
			log_prog("$target_name new block no earlier hash. Block hash: $file_hash");
		}
		if($transfer_file){
			$target_file_name = $file_name;

			if($use_file_name_hashing){//add the hash to the filename
				$target_file_name .= '.'.$file_hash;
			}

			if($compress){
				log_prog("$target_name Compressing and sending file");
				$target_file_name .= '.gz';
				//exec_ret('pigz --fast /tmp/'.$file_name);
				//exec_ret('pigz --fast --stdout /tmp/'.$file_name.' > '.$destination.$target_file_name);
				//exec_pool('pigz --fast /tmp/'.$file_name.' && mv /tmp/'.$file_name.'.gz '.$destination.$target_file_name);
				//We use --rsyncable to get better dedup on the remote side we dont use --fast cause network is our main problem
				exec_pool('pigz --rsyncable /tmp/'.$file_name.' && mv /tmp/'.$file_name.'.gz '.$destination.$target_file_name);
				//exec_ret('pigz --fast /tmp/'.$file_name);
				//remove the uncompressed file
				//exec_ret('sudo rm /tmp/'.$file_name);

				$file_name .= '.gz';//add the gz extention that pigz does

			}else{
				log_prog("$target_name Sending file");
				exec_pool('mv /tmp/'.$file_name.' '.$destination.$target_file_name);
			}




			$backup_metadata['files'][$current_block] = array('name' => $target_file_name, 'org_file_name' => $file_name, 'hash' => $file_hash, 'nr' => $current_block, 'nr_block_changes' => $nr_block_changes, 'last_block_change' => date("Y-m-d H:i:s"));
		}else{
			$backup_metadata['files'][$current_block] = $old_meta['files'][$current_block];//Use old meta data
			exec_ret('rm /tmp/'.$file_name);//remove unmoved block
		}

		if($current_block % 10 == 0){//Save the meta file once in a while so that we are not lost if something fails
			log_prog("$target_name Save temp meta file");
			file_put_contents($destination.$meta_file.'.wip', json_encode($backup_metadata, JSON_PRETTY_PRINT));
		}

		//exit;

		$current_block++;
	}

	log_prog("$target_name Waiting for final blocks to transfer");
	wait_pool(0);//Wait for the procces pool to empty
	$backup_metadata['end_backup_time'] = date("Y-m-d H:i:s");
	$backup_metadata['stats'] = $stats;
	log_prog("$target_name blocks changed: ".$stats['changed_blocks'].'/'.$stats['total_blocks']." speedup: ".(($stats['total_blocks']/$stats['changed_blocks'])*100)."%");

	log_prog("Create meta data file");
	file_put_contents($destination.$meta_file, json_encode($backup_metadata, JSON_PRETTY_PRINT));
	exec_ret('rm '.$destination.$meta_file.'.wip');//remove wip meta to indicate that we are done


//sudo dd bs=67108864 count=1 if=/dev/disk/by-id/google-attached-offsite of=/tmp/sql-sw.img.0
}

//take_disk_backup('sdb', 'schweiz-sql-u2', 'backup info: machine: schweiz-sql-u2, type: n1-standard-1, project: gant-ab, zone: europe-west1-b disks: (schweiz-sql-u2) nets: (nic0 = 10.132.0.19) tags: ()');
//exit;

if(!isset($argv[1])){
	echo("php manage_snapshots.php take\n");
	echo("php manage_snapshots.php free_old\n");
	echo("php manage_snapshots.php offsite_backup\n");
	echo("php manage_snapshots.php sql_backup\n");
	echo("php manage_snapshots.php list_users\n");
	exit;
}

if($argv[1] == 'take'){
	//Take snappshots of all machines
	$instances = list_instances();
	//var_dump($instances);
	//exit;
	foreach($instances AS $instance){
		if(isset($argv[2])){
			if($argv[2] != $instance['name']){
				continue;
			}
		}
		echo "Taking snappshots of the instance ".$instance['name']."\n";
		take_snappshot($instance);

		echo "\n\n\n";

	}
	exit;
}

if($argv[1] == 'sql_backup'){
	$sql_instances = list_sql_instances();
	//exit;
	foreach($sql_instances AS $sql_instance){
		if(isset($argv[2])){
			if($argv[2] != $sql_instance['name']){
				continue;
			}
		}
		echo "Taking sql backup of the sql instance ".$sql_instance['name']."\n";
		//take_snappshot($instance);
		take_sql_backup($sql_instance);

		echo "\n\n\n";

	}
	exit;
}

if($argv[1] == 'list_users'){
	$instances = list_instances();
	foreach($instances AS $instance){
		echo "name: ".$instance['name']."\n";
		if(isset($instance['metadata']['items'])){
			foreach($instance['metadata']['items'] AS $meta_data_item){
				if($meta_data_item['key'] == 'ssh-keys'){
					echo "\tssh-keys: ".$meta_data_item['value']."\n";
				}
				if($meta_data_item['key'] == 'windows-keys'){
					$users = json_decode($meta_data_item['value'], true);
					echo "\twindows-user: ".$users['userName']."\n";
				}
			}
		}
	}
	exit;
}

function check_progress($data, $time){
	global $max_time_to_take_backup;
	if($data == ''){
		//echo "no data\n";
	}else{
		echo($data);
	}
	if($max_time_to_take_backup < $time){
		log_error("backup has taken to long to finnish");
		echo("backup has taken to long to finnish should take max: $max_time_to_take_backup has taken: $time \n");
	}
}

if($argv[1] == 'offsite_backup'){
	//preferably run this once a week on saturday or sunday just after that days snapshot has been taken that way you get the wekend to to transfer and mimimize bandwith impact and get an extra backup that is keppt for more than 7 days

	//exec_ret_progress('/opt/manage_snapshots/time_out.sh', 'check_progress');
	//exit;

	echo "transfering backups to offsiste storage (functionality WORK IN PROGRESS)\n";

	$own_zone = get_own_zone();
	$own_name = get_own_name();
	echo "own zone: $own_zone\n";

	$time_per_GB = 60*10;//We allow 10 minutes per GB of disk if it takes longer than that we asume there has been an error

	//Do we have and old ofsite disk we need to remove XXXX Needs to be rone after paraleliztion
	$offsite_disk_exists = false;
	$machine_disks = list_disks();
	foreach($machine_disks AS $disk){
		if(strpos($disk['name'], 'offsite-disk-') !== FALSE){
			echo("An offsite disk allredy existed removing it first\n");
			detatch_and_delete_offsite_disk($own_name, $own_zone, $disk['name']);
		}
	}

	$snapshots = list_snapshots();
	$snapshots_of_disks = group_auto_snappshots_per_disk($snapshots);
	foreach($snapshots_of_disks AS $source_disk_uri => $disk_snapshots){
		//var_dump($disk_snapshots);
		echo("disk: ".$source_disk_uri."\n");

		$last_snap = end($disk_snapshots);
		echo("	snapshot: ".$last_snap['name']."\n");
//
//		if($last_snap['name'] != 'auto-2018-10-30-14-55-14-one-time-password-server'){
		//if($last_snap['name'] != 'auto-2018-10-29-01-16-34-one-time-password-server'){
//			continue;
//		}else{
//			$last_snap = array_pop($disk_snapshots);
//			$last_snap = array_pop($disk_snapshots);
//		}


		//get The name of the disk in gcp
		$google_source_disk_name = explode('/', $source_disk_uri);
		$google_source_disk_name = array_pop($google_source_disk_name);


		$take_backup = true;
		$meta_file = $folder_destination.$google_source_disk_name.'/'.$google_source_disk_name.'.json';
		if(file_exists($meta_file)){
			$last_meta = json_decode(file_get_contents($meta_file), true);
			if($last_meta['user_meta']['name'] == $last_snap['name']){//We already have a backup of this snapshot
				$take_backup = false;
			}
		}
		if($take_backup){

			//if there is more than on hild runing wait for a child to finish
			if(count($children) > 1){
				$pid = pcntl_waitpid(-1, $status, WUNTRACED);
				unset($children[$pid]);
			}

			$pid = pcntl_fork();
			if($pid){
			//if(false){
				echo "initiated child\n";
				$children[$pid] = $pid;
			}else{

				//This is a child lets take a backup
				$offsite_disk_name = 'offsite-disk-'.getmypid();

				//If there is a disk attached detach and remove it first
				if(file_exists('/dev/disk/by-id/google-'.$offsite_disk_name)){
					echo("Detactching old disk before initiating\n");
					detatch_and_delete_offsite_disk($own_name, $own_zone);
				}

				try{

					echo("Creating and attaching snapshot\n");
					create_and_attach_offsite_disk($own_name, $own_zone, $last_snap['name'], true);

					if(!file_exists('/dev/disk/by-id/google-'.$offsite_disk_name)){
						throw new Exception("failed to attach snapdisk");
					}

					//get the 3 letter disk dev name i.e sdb
					$disk_device = explode('/', readlink('/dev/disk/by-id/google-'.$offsite_disk_name));
					$disk_device = array_pop($disk_device);


					echo "/home/partimag/ Needs to be mounted offsite with NFS or similar\n";


					$max_time_to_take_backup = $last_snap['diskSizeGb'] * $time_per_GB;

					echo "Starting backup, it should not take longer than $max_time_to_take_backup seconds to complete\n";

					$meta_data = $last_snap;

					take_disk_backup($disk_device, $google_source_disk_name, $meta_data);

					//exit;

					//This is the fastest way to take the image but it does require the disk to be writen to
					//exec_ret_progress("sudo /usr/sbin/ocs-sr -batch -q2 -j2 -z1 -i 2000 --skip-check-restorable -fsck-src-part-y -nogui -p true savedisk ".$last_snap['name']." ".$disk_device, 'check_progress');

					//We rename the clonezilla folder to indicate that the backup is done
					//exec_ret("sudo mv /home/partimag/".$last_snap['name']." /home/partimag/done/".$last_snap['name']);

					//echo "backup done, should now be located at /home/partimag/done/".$last_snap['name']."\n";




					//at this point the files need to be locked so that this machine cant read or alter the files. It is an offsite backup for a reason...

					//This is super slow but it garanties a perferct image
					//echo "dd if=/dev/disk/by-id/google-attached-offsite | gzip -1 - | dd of=image.gz";
					//echo "\n";

				}catch(Exception $err){
					log_error("Somthing did no go as planed when copying disk");
				}

				//We are done with the disk
				echo("Detactching disk as we are done\n");
				detatch_and_delete_offsite_disk($own_name, $own_zone);
				exit;//exit child
			}
		}

		//exit;
	}
	exit;
}

function create_and_attach_offsite_disk($own_name, $own_zone, $snapshot, $readonly){//$last_snap['name']
	global $offsite_disk_name;
	$ro = '';
	if($readonly){
		$ro = ' --mode=ro ';
	}
	//If this fails the offisite disk may exsit and need deletion due to a failed previus atempt
	//disk read only when using dd
	//to use clonezilla we need the disk to be writable

	exec_ret("gcloud compute disks create ".$offsite_disk_name." --type=pd-ssd --zone=".$own_zone.' --source-snapshot '.$snapshot);
	exec_ret("gcloud compute instances attach-disk ".$own_name." ".$ro." --zone=".$own_zone.' --device-name='.$offsite_disk_name.' --disk='.$offsite_disk_name);
}

function detatch_and_delete_offsite_disk($own_name, $own_zone, $name_override = false){
	global $offsite_disk_name;

	$ofdisk = $offsite_disk_name;
	if($name_override !== false){
		$ofdisk = $name_override;
	}
	try{
		exec_ret("gcloud compute instances detach-disk ".$own_name." --zone=".$own_zone.' --disk='.$ofdisk);
	}catch(Exception $err){
		log_error('could not detach '.$ofdisk);
	}
	//Caution fix when a disk has a snapshot it cant be removed make sure the offsite-disk does not have snapshots
	try{
		exec_ret("gcloud compute disks delete ".$ofdisk." --zone=".$own_zone.' --quiet');
	}catch(Exception $err){
		log_error('could not delete '.$ofdisk);
	}
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
		if(strpos($disk['name'], 'offsite-disk-') === FALSE){//we dont want to take a backup of the offsite disk.
			$disks[] = $disk['name'];
			$snapname = $snappdate.$disk['name'];
			if(strlen($snapname) > 63){
				//Disk names can not be longer than 39 charaters long as the date is 24 characters long
				log_error($snapname.' is longer than 63 charaters long');
			}
			$names[] = substr($snapname, 0 , 63);
		}
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

function take_sql_backup($sql_instance){
	global $sql_backup_dest;
	$bucket_name = 'gs://'.$sql_instance['project'].'-'.$sql_instance['region'].'-'.$sql_instance['name'].'-backup';

	//create bucket
	$list_data = exec_ret("gsutil mb -p ".$sql_instance['project']." -l ".$sql_instance['region']." ".$bucket_name);

	//Add instance service account to bucket
	$list_data = exec_ret("gsutil acl ch -u ".$sql_instance['serviceAccountEmailAddress'].":W ".$bucket_name);

	//export database to bucket
	$list_data = exec_ret("gcloud sql export sql ".$sql_instance['name']." ".$bucket_name."/".$sql_instance['name'].".sql.gz");

	//Move backup from bucket
	$list_data = exec_ret("gsutil mv ".$bucket_name."/".$sql_instance['name'].".sql.gz ".$sql_backup_dest.$sql_instance['name'].".sql.gz");

	//remove bucket
	$list_data = exec_ret("gsutil rb -f ".$bucket_name);

}

function list_sql_instances(){
	$list_data = exec_ret("gcloud sql instances list --format=json");

        $list = json_decode(implode("\n", $list_data), true);
        if(!is_array($list)){
                throw new Exception("list was not proper json");
        }

	$instances = array();

	foreach($list AS $line){
		$uri_parts = parse_url($line['selfLink']);
		if(!empty($uri_parts['path'])){
			$instances[] = $line;
		}
	}
	return $instances;
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

$processes = array();
function wait_pool($how_many){
	global $processes;
	while(count($processes) > $how_many){
		foreach($processes AS $id => $handle){
			$contin_loop = true;
			while($contin_loop){
				if(feof($handle)){
					pclose($handle);
					unset($processes[$id]);
					$contin_loop = false;
				}else{
					$read = fgets($handle);
					if($read == ''){
						$contin_loop = false;
					}else{
						//echo "from handle: $id $read\n";
					}
				}
			}
		}
		usleep(300000);//0.3 s
	}
}

function exec_pool($cmd){
	global $processes;
	wait_pool(4);
	$new_handle = popen($cmd, 'r');
	stream_set_blocking($new_handle, false);
	$processes[] = $new_handle;
}


function exec_ret_progress($cmd, $progress_func){
	$start = time();
	$handle = popen($cmd.' 2>&1', 'r');
	stream_set_blocking($handle, false);

	while(!feof($handle)){
		$read = fgets($handle);
		$time_active = time() - $start;
		$progress_func($read, $time_active);
		if($read == ''){
			sleep(1);
		}
	}
	return pclose($handle);
}

function exec_ret($cmd){
	exec($cmd, $out, $fail);
	if($fail != 0){
		throw new Exception("Failed to run command: $cmd");
	}
	return $out;
}
