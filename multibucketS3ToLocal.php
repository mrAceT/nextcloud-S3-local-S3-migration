// This script works only with `objectstore_multibucket` config parameter.
// Use it only if you have postgresql database
// Before running:
//
// put into separate folder
// run `composer require aws/aws-sdk-php`
//
// setup variables in the script:
// $NC_PATH => path to NextCloud root directory
// $NC_CONF => path from NextCloud root to config
// $DEST_FOLDER => path where to save backed up data
// $LOG_FOLDER => path where to save logs
//
//On second run the logs will be rewriten by string === USER FOLDER EXISTS SKIPPING! ===

<?php

require('./vendor/autoload.php');
use Aws\S3\S3Client;

$NC_PATH = '/var/www/html';
$NC_CONF = $NC_PATH.'/config/config.php';
$DEST_FOLDER = './bck';
$LOG_FOLDER = './bck-log';

$CONFIG = [];

require($NC_CONF);

function pgSelectAssoc(string $query)
{
  $result = pg_query($query) or die('ERROR - QUERY: ' . pg_last_error());
  $return = [];
  while($line = pg_fetch_array($result, null, PGSQL_ASSOC))
  {
    $return[] = $line;
  }
  pg_free_result($result);
  return $return;
}

function dataLog($logFile , $data, $type = "default", $noTime = false)
{
  $types = [
    "default" => "\e[39m",
    "red" => "\e[91m",
    "magenta" => "\e[95m",
    "cyan" => "\e[96m",
    "green" => "\e[32m",
    "yellow" => "\e[33m",
  ];

  echo $types[$type].$data.$types[$type]."\n";
  if ($noTime)
  {
    fwrite($logFile, $data."\n");
  }
  else
  {
    $date = new DateTime();
    fwrite($logFile, "[".$date->format("y:m:d h:i:s")."] ".$data."\n");
  }
}

if(!is_dir($DEST_FOLDER))
{
  mkdir($DEST_FOLDER, 0777, true);
}

if(!is_dir($LOG_FOLDER))
{
  mkdir($LOG_FOLDER, 0777, true);
}

$s3client = new S3Client([
  'version' => 'latest',
  'endpoint' => 'http://'.$CONFIG['objectstore_multibucket']['arguments']['hostname'].':'.$CONFIG['objectstore_multibucket']['arguments']['port'],
  'bucket_endpoint' => false,
  'region'  => 'us-east-1',
  'credentials' => [
    'key' => $CONFIG['objectstore_multibucket']['arguments']['key'],
    'secret' => $CONFIG['objectstore_multibucket']['arguments']['secret'],
  ]
]);

$pg = pg_connect(sprintf("host=%s port=%s dbname=%s user=%s password=%s", $CONFIG['dbhost'], $CONFIG['dbport'], $CONFIG['dbname'], $CONFIG['dbuser'], $CONFIG['dbpassword']))
or die('ERROR - CONNECT: '. pg_last_error());

$buckets = pgSelectAssoc("SELECT userid, configvalue FROM oc_preferences WHERE configkey = 'bucket'");
$bucketsCount = pgSelectAssoc("SELECT count(userid) as cnt FROM oc_preferences WHERE configkey = 'bucket'");
$userNum = 0;
foreach($buckets as $bucket)
{
  $userNum++;
  $storages = pgSelectAssoc("SELECT numeric_id FROM oc_storages WHERE id = 'object::user:".$bucket['userid']."'");
  $logFile = fopen($LOG_FOLDER.DIRECTORY_SEPARATOR.$bucket['userid'].'.log', "w");
  if(!is_dir($DEST_FOLDER.DIRECTORY_SEPARATOR.$bucket['userid']))
  {
    mkdir($DEST_FOLDER.DIRECTORY_SEPARATOR.$bucket['userid']);
  }
  else
  {
    dataLog($logFile, "=== USER FOLDER EXISTS SKIPPING! ===", 'cyan', true);
    fclose($logFile);
    continue;
  }

  dataLog($logFile, "=== GETTING USER ".$bucket['userid']." (".$userNum."/".$bucketsCount[0]['cnt'].") ===", 'cyan', true);
  foreach($storages as $storage)
  {
    $data = pgSelectAssoc("SELECT * FROM oc_filecache WHERE storage = '".$storage['numeric_id']."' AND mimetype != 5");
    $folders = pgSelectAssoc("SELECT * FROM oc_filecache WHERE storage = '".$storage['numeric_id']."' AND mimetype = 5");

    dataLog($logFile, "=== CREATING FOLDERS ===", 'magenta', true);
    foreach($folders as $folder)
    {
      $saveFile = $DEST_FOLDER.DIRECTORY_SEPARATOR.$bucket['userid'].DIRECTORY_SEPARATOR.$folder['path'];
      if(is_dir($saveFile))
      {
        dataLog($logFile, "Existing folder (skip): ".$saveFile, 'yellow');
      }
      else
      {
        dataLog($logFile, "Creating folder: ".$saveFile, 'yellow');
        mkdir($saveFile, 0777, true);
      }
    }
    dataLog($logFile, "=== CREATING FILES ===", 'magenta', true);
    foreach($data as $file)
    {
      try
      {
        $saveFile = $DEST_FOLDER.DIRECTORY_SEPARATOR.$bucket['userid'].DIRECTORY_SEPARATOR.$file['path'];
        $s3client->getObject([
          'Bucket' => $bucket['configvalue'],
          'Key'    => 'urn:oid:'.$file['fileid'],
          'SaveAs' => $saveFile,
        ]);
        dataLog($logFile, "Creating file: ".$saveFile, 'green');
      }
      catch(\Aws\S3\Exception\S3Exception $e)
      {
        if ($e->getAwsErrorCode())
        {
          dataLog($logFile, "404 ERROR - Creating file: ".$saveFile, 'red');
          unlink($saveFile);
        }
        else
        {
          dataLog($logFile, "ERROR - Creating file: ".$saveFile, 'red');
          dataLog($logFile, var_export($file, true)."\n", 'red', true);
          dataLog($logFile, "=== TRACE ==="."\n", 'red', true);
          dataLog($logFile, $e->getMessage()."\n", 'red', true);
          dataLog($logFile, "=== === ==="."\n", 'red', true);
        }
      }
    }
  }
  fclose($logFile);
}
