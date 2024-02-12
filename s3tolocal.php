<?php
/* *********************************************************************************** */
/*        2023 code created by Eesger Toering / knoop.frl / geoarchive.eu              */
/*     Like the work? You'll be surprised how much time goes into things like this..   */
/*                            be my hero, support my work,                             */
/*                     https://paypal.me/eesgertoering                                 */
/*                     https://www.geef.nl/en/donate?action=15544                      */
/* *********************************************************************************** */

# best practice: run the script as the cloud-user!!
# sudo -u clouduser php74 -d memory_limit=1024M /var/www/vhost/nextcloud/s3tolocal.php

# runuser -u clouduser -- composer require aws/aws-sdk-php
use Aws\S3\S3Client;

echo "\n#########################################################################################";
echo "\n Migration tool for Nextcloud S3 to local version 0.32\n";
echo "\n Reading config...";

// Note: Preferably use absolute path without trailing directory separators
$PATH_BASE      = '/var/www/vhost/nextcloud'; // Path to the base of the main Nextcloud directory

$PATH_NEXTCLOUD = $PATH_BASE.'/public_html'; // Path of the public Nextcloud directory
$PATH_DATA      = $PATH_BASE.'/data'; // Path of the new Nextcloud data directory
$PATH_DATA_BKP  = $PATH_BASE.'/data.bkp'; // Path of a previous migration.. to speed things up.. (manually move a previous migration here!!)
$PATH_BACKUP    = $PATH_BASE.'/bak'; // Path for backup of MySQL database

// don't forget this one -.
$OCC_BASE       = 'sudo -u clouduser php74 -d memory_limit=1024M '.$PATH_NEXTCLOUD.'/occ ';
// fill this variable ONLY when you are unable to run the 'occ' command above as the clouduser 
$CLOUDUSER      = ''; // example 'clouduser:group';

$TEST = 1; //'admin';//'appdata_oczvcie123w4';
// set to 0 for LIVE!!
// set to 1 just get all the data to local, NO database chainges
// set to user name for single user (migration) test

$NON_EMPTY_TARGET_OK = 1;

$PATH_DATA_LOCAL_EXISTS_OK = 1; //defaul 0 !! Only set to 1 if you're sure..

$NR_OF_COPY_ERRORS_OK = 8;

$SQL_DUMP_USER = ''; // leave both empty if nextcloud user has enough rights..
$SQL_DUMP_PASS = '';

if ($NON_EMPTY_TARGET_OK
 || !empty($TEST)) {
  echo "\n\n#########################################################################################";
  echo !$NON_EMPTY_TARGET_OK ? '' : "\nWARNING: deleted files since a previous copy will not get NOT removed!";
  echo empty($TEST)          ? '' : "\nWARNING: you are in test mode (".$TEST.")";
  echo "\nContinue?";
  $getLine = '';
  while ($getLine == ''): $getLine = fgets( fopen("php://stdin","r") ); endwhile;
}

echo "\n\n#########################################################################################";
echo "\nSetting up S3 migration to local...\n";

// Autoload
require_once(dirname(__FILE__).'/vendor/autoload.php');

if (empty($TEST)) {
  // Activate maintenance mode
  $process = occ($OCC_BASE,'maintenance:mode --on');
  echo $process;
  
  if (strpos($process, "\nMaintenance mode") == 0
   && strpos($process, 'Maintenance mode already enabled') == 0) {
    echo " could not set..  ouput command: ".$process."\n\n";
    die;
#  } else {
#    echo " OK? ".$OCC_COMMAND."\nouput command: ".$process."\n\n";
#   die;
  }
}

echo "\nfirst load the nextcloud config...";
include($PATH_NEXTCLOUD.'/config/config.php');

echo "\nconnect to sql-database...";
// Database setup
$mysqli = new mysqli($CONFIG['dbhost'], $CONFIG['dbuser'], $CONFIG['dbpassword'], $CONFIG['dbname']);
if ($CONFIG['mysql.utf8mb4']) {
  $mysqli->set_charset('utf8mb4');
}

################################################################################ checks #
$LOCAL_STORE_ID = 0;
if ($result = $mysqli->query("SELECT * FROM `oc_storages` WHERE `id` = 'local::$PATH_DATA/'")) {
  while ($row = $result->fetch_assoc()) {
    echo "\nERROR: there already is a oc_storages record with 'local::$PATH_DATA/' (id:".$row['numeric_id'].")";
  }
  if ($result->num_rows>0) {
    echo "\nClean this up (check oc_filecache, oc_filecache_extended, oc_filecache_locks and more?)";
    echo "\n(keep one, or none.. check this source for some tips..)";
    # those tips.... :
    # SELECT `oc_filecache_extended`.`fileid`, `oc_filecache`.`storage` FROM `oc_filecache_extended` LEFT JOIN `oc_filecache` ON `oc_filecache`.`fileid` = `oc_filecache_extended`.`fileid`
    # SELECT `oc_file_metadata`.`id`, `oc_filecache`.`storage` FROM `oc_file_metadata` LEFT JOIN `oc_filecache` ON `oc_filecache`.`fileid` = `oc_file_metadata`.`id`
  }
  if ($result->num_rows>1) {
    echo "\nERROR: Multiple 'local::$PATH_DATA', it's an accident waiting to happen!!\n";
    die;
  }
  else if ($result->num_rows == 1) {
    echo "\nWARNING/ERROR: Clean up `oc_filecache`";
    if (!$PATH_DATA_LOCAL_EXISTS_OK) {
      echo " and then set \$PATH_DATA_LOCAL_EXISTS_OK to 1 (be carefull!!!)\n";
    }
    if (!$PATH_DATA_LOCAL_EXISTS_OK) {
      if (empty($TEST)) {
        die;
      } else {
        echo "We're in 'test mode', so we will continue.. but upon 'live' it'll fail!!\n";
      }
    }
    $row = $result->fetch_assoc();
    $LOCAL_STORE_ID = $row['numeric_id']; // for creative rename command..
    echo "\nThe local store  id $LOCAL_STORE_ID";
  }
}
$OBJECT_STORE_ID = 0;
if ($result = $mysqli->query("SELECT * FROM `oc_storages` WHERE `id` LIKE 'object::store:%'")) {
  if ($result->num_rows>1) {
    echo "\nMultiple 'object::store:' clean this up, it's an accident waiting to happen!!\n";
    die;
  }
  else if ($result->num_rows == 0) {
    echo "\nNo 'object::store:' No S3 storage defined!?\n";
    die;
  }
  else {
    $row = $result->fetch_assoc();
    $OBJECT_STORE_ID = $row['numeric_id']; // for creative rename command..
  }
}

echo "\ndatabase backup...";
if (!is_dir($PATH_BACKUP)) { echo "\$PATH_BACKUP folder does not exist\n"; die; }

$process = shell_exec('mysqldump --host='.$CONFIG['dbhost'].
                               ' --user='.(empty($SQL_DUMP_USER)?$CONFIG['dbuser']:$SQL_DUMP_USER).
                               ' --password='.escapeshellcmd( empty($SQL_DUMP_PASS)?$CONFIG['dbpassword']:$SQL_DUMP_PASS ).' '.$CONFIG['dbname'].
                               ' > '.$PATH_BACKUP . DIRECTORY_SEPARATOR . 'backup.sql');
if (strpos(' '.strtolower($process), 'error:') > 0) {
  echo "sql dump error\n";
  die;
} else {
  echo "\n(to restore: mysql -u ".(empty($SQL_DUMP_USER)?$CONFIG['dbuser']:$SQL_DUMP_USER)." -p ".$CONFIG['dbname']." < backup.sql)\n";
}

echo "\nbackup config.php...";
$copy = 1;
if(file_exists($PATH_BACKUP.'/config.php')){
  if (filemtime($PATH_NEXTCLOUD.'/config/config.php') > filemtime($PATH_BACKUP.'/config.php') ) {
    unlink($PATH_BACKUP.'/config.php');
  }
  else {
    echo 'not needed';
    $copy = 0;
  }
}
if ($copy) {
  copy($PATH_NEXTCLOUD.'/config/config.php', $PATH_BACKUP.'/config.php');
}

echo "\nconnect to S3...";
$bucket = $CONFIG['objectstore']['arguments']['bucket'];
if($CONFIG['objectstore']['arguments']['use_path_style']){
  $s3 = new S3Client([
    'version' => 'latest',
    'endpoint' => 'https://'.$CONFIG['objectstore']['arguments']['hostname'].'/'.$bucket,
    'bucket_endpoint' => true,
    'use_path_style_endpoint' => true,
    'region'  => $CONFIG['objectstore']['arguments']['region'],
    'credentials' => [
      'key' => $CONFIG['objectstore']['arguments']['key'],
      'secret' => $CONFIG['objectstore']['arguments']['secret'],
    ],
  ]);
}else{
  $s3 = new S3Client([
    'version' => 'latest',
    'endpoint' => 'https://'.$bucket.'.'.$CONFIG['objectstore']['arguments']['hostname'],
    'bucket_endpoint' => true,
    'region'  => $CONFIG['objectstore']['arguments']['region'],
    'credentials' => [
      'key' => $CONFIG['objectstore']['arguments']['key'],
      'secret' => $CONFIG['objectstore']['arguments']['secret'],
    ],
  ]);
}

// Check that new Nextcloud data directory is empty
if (count(scandir($PATH_DATA)) != 2) {
  echo "\nThe new Nextcloud data directory is not empty..";
  if (!$NON_EMPTY_TARGET_OK) {
    echo " nAborting script\n";
    die;
  } else {
    echo "WARNING: deleted files since previous copy are NOT removed! (take a look at the option '\$PATH_DATA_BKP')\n";
  }
}

if (!is_dir($PATH_DATA_BKP)) { echo "\$PATH_DATA_BKP folder does not exist\n"; die; }

echo "\n#########################################################################################";
echo "\nSetting everything up finished ##########################################################\n";

echo "\nCreating folder structure started... ";

if ($result = $mysqli->query("SELECT st.id, fc.fileid, fc.path, fc.storage_mtime FROM oc_filecache as fc, oc_storages as st, oc_mimetypes as mt WHERE st.numeric_id = fc.storage AND st.id LIKE 'object::%' AND fc.mimetype = mt.id AND mt.mimetype = 'httpd/unix-directory'")) {
  
  // Init progress
  $complete = $result->num_rows;
  $prev     = '';
  $current  = 0;
  
  while ($row = $result->fetch_assoc()) {
    $current++;
    try {
      // Determine correct path
      if (substr($row['id'], 0, 13) != 'object::user:') {
        $path = $PATH_DATA . DIRECTORY_SEPARATOR . $row['path'];
      } else {
        $path = $PATH_DATA . DIRECTORY_SEPARATOR . substr($row['id'], 13) . DIRECTORY_SEPARATOR . $row['path'];
      }
      // Create folder (if it doesn't already exist)
      if (!file_exists($path)) {
        mkdir($path, 0777, true);
      }
      #echo "\n".$path."\t";
      touch($path, $row['storage_mtime']);
    } catch (Exception $e) {
      echo "    Failed to create: ".$row['path']." (".$e->getMessage().")\n";
      $flag = false;
    }
    // Update progress
    $new = floor($current/$complete*100).'%';
    if ($prev != $new ) {
      echo str_repeat(chr(8) , strlen($prev) );
      $prev = $current+1 >= $complete ? ' DONE ' : $new;
      echo $prev;
    }
  }
  $result->free_result();
}

echo "\nCreating folder structure finished\n";

echo "Copying files started... ";
$error_copy = '';

$users      = array();

if ($result = $mysqli->query("SELECT st.id, fc.fileid, fc.path, fc.storage_mtime FROM oc_filecache as fc,".
                             " oc_storages as st,".
                             " oc_mimetypes as mt".
                             " WHERE st.numeric_id = fc.storage".
                              " AND st.id LIKE 'object::%'".
                              " AND fc.mimetype = mt.id".
                              " AND mt.mimetype != 'httpd/unix-directory'".
                             " ORDER BY st.id ASC")) {

  // Init progress
  $complete = $result->num_rows;
  $current  = 0;
  $prev     = '';

  while ($row = $result->fetch_assoc()) {
    $current++;
    try {
      // Determine correct path
      if (substr($row['id'], 0, 13) != 'object::user:') {
        $path = $PATH_DATA . DIRECTORY_SEPARATOR . $row['path'];
      } else {
        $path = $PATH_DATA . DIRECTORY_SEPARATOR . substr($row['id'], 13) . DIRECTORY_SEPARATOR . $row['path'];
      }
      $user = substr($path, strlen($PATH_DATA. DIRECTORY_SEPARATOR));
      $user = substr($user,0,strpos($user,DIRECTORY_SEPARATOR));
      $users[ $user ] = $row['storage'];

      # just for one user? set test = appdata_oczvcie795w3 (system wil not go to maintenance nor change database, just test and copy data!!)
      if (is_numeric($TEST) || $TEST == $user ) {
        #echo "\n".$path."\t".$row['storage_mtime'];
        $copy = 1;
        if(file_exists($path) && is_file($path)){
          if ($row['storage_mtime'] > filemtime($path) ) {
            unlink($path);
          }
          else { $copy = 0;}#echo '.'; }
        }
        if ($copy) {
          $path_bkp = str_replace($PATH_DATA,
                                  $PATH_DATA_BKP,
                                  $path);
          if (file_exists($path_bkp) && is_file($path_bkp)
           && $row['storage_mtime'] == filemtime($path_bkp) ) {
            if (rename($path_bkp,
                       $path) ) {
              $copy = 0;
            } else {
              echo "\nmove failed!?\n";
              exit;
            }
            #echo ':';
          }
        }
        if ($copy) {
          // Download file from S3
          $s3->getObject(array(
            'Bucket' => $bucket,
            'Key'    => 'urn:oid:'.$row['fileid'],
            'SaveAs' => $path,
          ));
          // Also set modification time
          touch($path, $row['storage_mtime']);
          #echo '!';
        }
        #echo ''.$copy."\n";if ($copy) { exit;} 
      }
    } catch (Exception $e) {
      if(file_exists($path) && is_file($path) ){
        unlink($path);
      }
      echo "\n#########################################################################################";
      echo "\nFailed to transfer: $row[fileid] (".$e->getMessage().")\n";
      echo "\ntarget: ".$path."\n";
      echo "datadump of database record:\n";
      print_r($row);
      $error_copy.= $path."\n";
      $prev = '';
      #exit;
    }
    // Update progress
    $new = sprintf('%.2f',$current/$complete*100).'% (now at user '.$user.')';
    if ($prev != $new ) {
      echo str_repeat(chr(8) , strlen($prev) );
      $prev = $current+1 >= $complete ? ' DONE ' : $new;
      echo $prev;
    }
  }
  $result->free_result();
}
echo "\n";
#exit; ###################################################################################


if (!empty($error_copy)) {
  echo "\n#########################################################################################";
  $error_count = substr_count($error_copy,"\n");
  echo "\nCopying of ".$error_count." files failed:\n".$error_copy."\n\n";
  if ($error_count > $NR_OF_COPY_ERRORS_OK ) {
    echo "Aborting script\n";
    die;
  } else {
    echo "\nContinue?";
    $getLine = '';
    while ($getLine == ''): $getLine = fgets( fopen("php://stdin","r") ); endwhile;
  }
}

echo "\nCopying files finished";

if (!empty($CLOUDUSER)) {
  echo "\n\nSet the correct owner of the data folder..";
  echo occ('','chown -R '.$CLOUDUSER.' '.$PATH_DATA);
  echo "\n";
}

if (empty($TEST)) {
  echo "\n#########################################################################################";
  echo "\nModifying database started...\n";
  
  $mysqli->query("UPDATE `oc_storages` SET id=CONCAT('home::', SUBSTRING_INDEX(oc_storages.id,':',-1)) WHERE `oc_storages`.`id` LIKE 'object::user:%'");
  
  //rename command
  if ($LOCAL_STORE_ID == 0
   || $OBJECT_STORE_ID== 0) { // standard rename
    $mysqli->query("UPDATE `oc_storages` SET `id`='local::$PATH_DATA/' WHERE `oc_storages`.`id` LIKE 'object::store:%'");
  } else {
    $mysqli->query("UPDATE `oc_filecache` SET `storage` = '".$LOCAL_STORE_ID."' WHERE `storage` = '".$OBJECT_STORE_ID."'");
    $mysqli->query("DELETE FROM `oc_storages` WHERE `oc_storages`.`numeric_id` = ".$OBJECT_STORE_ID);
  }

  foreach ($users as $key => $value) {
    $mysqli->query("UPDATE `oc_mounts` SET `mount_provider_class` = REPLACE(`mount_provider_class`, 'ObjectHomeMountProvider', 'LocalHomeMountProvider') WHERE `user_id` = '".$key."'");
    if ($mysqli->affected_rows == 1) {
      echo $dashLine."\n-Changed mount provider class off ".$key." from home to object";
      $dashLine = '';
    }
  }
  
  echo "\nModifying database finished";
  
  echo "\nDoing final adjustments started...";

  echo "\nDeactivate maintenance mode...";
  echo occ($OCC_BASE,'maintenance:mode --off');

  echo "\nUpdate config file...";
  echo occ($OCC_BASE,'config:system:set datadirectory --value="'.$PATH_DATA.'"');

  echo "\nRemove S3 stuff from config file...";
  echo occ($OCC_BASE,'config:system:delete objectstore');
  if (file_exists($PATH_NEXTCLOUD.'/config/storage.config.php')) {
    echo "\nrename /config/storage.config.php...";
    rename($PATH_NEXTCLOUD.'/config/storage.config.php',
           $PATH_NEXTCLOUD.'/config/storage.config.bak');
  }

  
  echo "\nRunning cleanup (should not be necessary but cannot hurt)...";
  echo occ($OCC_BASE,'files:cleanup');

  echo "\nRunning scan (should not be necessary but cannot hurt)...";
  echo occ($OCC_BASE,'files:scan --all');
  
  echo "\nDoing final adjustments finished";
  
  echo "\n\nYou are good to go!\n";
} else {
  echo "\n\ndone testing..\n";
}

#########################################################################################
function occ($OCC_BASE,$OCC_COMMAND) {
  $result = "\nset  ".$OCC_COMMAND.":\n";

  ob_start();
  passthru($OCC_BASE . $OCC_COMMAND);
  $process = ob_get_contents();
  ob_end_clean(); //Use this instead of ob_flush()
  
  return $result.$process."\n";
}
