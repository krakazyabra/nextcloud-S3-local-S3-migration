<?php
/* *********************************************************************************** */
/*        2023 code created by Eesger Toering / knoop.frl / geoarchive.eu              */
/*     Like the work? You'll be surprised how much time goes into things like this..   */
/*                            be my hero, support my work,                             */
/*                     https://paypal.me/eesgertoering                                 */
/*                     https://www.geef.nl/en/donate?action=15544                      */
/* *********************************************************************************** */

# best practice: run the script as the cloud-user!!
# sudo -u clouduser php74 -d memory_limit=1024M /var/www/vhost/nextcloud/localtos3.php

# runuser -u clouduser -- composer require aws/aws-sdk-php
use Aws\S3\S3Client;

echo "\n#########################################################################################";
echo "\n Migration tool for Nextcloud local to S3 version 0.31\n";
echo "\n Reading config...";

$PREVIEW_MAX_AGE = 0; // max age of preview images (EXPERIMENTAL! 0 = no del)
$PREVIEW_MAX_DEL = 0.005; // max amount of previews to delete at a time (when < 1 & > 0 => percentage! )..

// Note: Preferably use absolute path without trailing directory separators
$PATH_BASE      = '/var/www/vhost/nextcloud'; // Path to the base of the main Nextcloud directory

$PATH_NEXTCLOUD = $PATH_BASE.'/public_html'; // Path of the public Nextcloud directory

$PATH_BACKUP    = $PATH_BASE.'/bak'; // Path for backup of MySQL database (you must create it yourself..)

// don't forget this one -.
$OCC_BASE       = 'sudo -u clouduser php74 -d memory_limit=1024M '.$PATH_NEXTCLOUD.'/occ ';

$TEST = 2; //'admin';//'appdata_oczvcie123w4';
// set to 0 for LIVE!!
// set to 1 for all data : NO db modifications, with file modifications/uplaods/removal
// set to user name for single user (migration) test
// set to 2 for complete dry run

$SET_MAINTENANCE = 1; // only in $TEST=0 Nextcloud will be put into maintenance mode
// ONLY when migration is all done you can set this to 0 for the S3-consitancy checks

$SHOWINFO = 1; // set to 0 to force much less info (while testing)

$SQL_DUMP_USER = ''; // leave both empty if nextcloud user has enough rights..
$SQL_DUMP_PASS = '';

$CONFIG_OBJECTSTORE = dirname(__FILE__).'/storage.config.php';

$DO_FILES_CLEAN = 0; // perform occ files:cleanup    | can take a while on large accounts (should not be necessary but cannot hurt / not working while in maintenance.. )
$DO_FILES_SCAN  = 0; // perform occ files:scan --all | can take a while on large accounts (should not be necessary but cannot hurt / not working while in maintenance.. )

echo "\n\n#########################################################################################";
echo "\nSetting up local migration to S3 (sync)...\n";

// Autoload
require_once(dirname(__FILE__).'/vendor/autoload.php');

echo "\nfirst load the nextcloud config...";
include($PATH_NEXTCLOUD.'/config/config.php');
if (!empty($CONFIG['objectstore'])) {
  if ($CONFIG_OBJECTSTORE == $PATH_NEXTCLOUD.'/config/config.php') {
    echo "\nS3 config found in \$PATH_NEXTCLOUD system config.php => same as \$CONFIG_OBJECTSTORE !";
  } else {
    echo "\nS3 config found in \$PATH_NEXTCLOUD system config.php => \$CONFIG_OBJECTSTORE not used! ($CONFIG_OBJECTSTORE)";
  }
  $CONFIG_OBJECTSTORE = ''; //no copy!
} else {
  echo "\nS3 NOT configured in config.php, using \$CONFIG_OBJECTSTORE";
  if (is_string($CONFIG_OBJECTSTORE) && file_exists($CONFIG_OBJECTSTORE)) {
    $CONFIG_MERGE = $CONFIG;
    include($CONFIG_OBJECTSTORE);
    $CONFIG = array_merge($CONFIG_MERGE,$CONFIG);
  }
  else if (is_array($CONFIG_OBJECTSTORE)) {
    $CONFIG['objectstore'] = $CONFIG_OBJECTSTORE;
  } else {
    echo "\nERROR: var \$CONFIG_OBJECTSTORE is not configured (".gettype($CONFIG_OBJECTSTORE)." / $CONFIG_OBJECTSTORE)\n\n";
    die;
  }
}
$PATH_DATA = preg_replace('/\/*$/','',$CONFIG['datadirectory']);

echo "\nconnect to sql-database...";
// Database setup
$mysqli = new mysqli($CONFIG['dbhost'], $CONFIG['dbuser'], $CONFIG['dbpassword'], $CONFIG['dbname']);
if ($CONFIG['mysql.utf8mb4']) {
  $mysqli->set_charset('utf8mb4');
}

################################################################################ checks #
$LOCAL_STORE_ID = 0;
if ($result = $mysqli->query("SELECT * FROM `oc_storages` WHERE `id` = 'local::$PATH_DATA/'")) {
  if ($result->num_rows>1) {
    echo "\nERROR: Multiple 'local::$PATH_DATA', it's an accident waiting to happen!!\n";
    die;
  }
  else if ($result->num_rows == 1) {
    echo "\nFOUND 'local::$PATH_DATA', good. ";
    $row = $result->fetch_assoc();
    $LOCAL_STORE_ID = $row['numeric_id']; // for creative rename command..
    echo "\nThe local store  id is:$LOCAL_STORE_ID";
  } else {
    echo "\nWARNING: no 'local::$PATH_DATA' found, therefor no sync local data > S3!\n";
  }
}
$OBJECT_STORE_ID = 0;
if ($result = $mysqli->query("SELECT * FROM `oc_storages` WHERE `id` LIKE 'object::store:amazon::".$CONFIG['objectstore']['arguments']['bucket']."'")) {
  if ($result->num_rows>1) {
    echo "\nMultiple 'object::store:amazon::".$CONFIG['objectstore']['arguments']['bucket']."' clean this up, it's an accident waiting to happen!!\n\n";
    die;
  }
  else if ($result->num_rows == 0) {
    if (empty($CONFIG['objectstore'])) {
      echo "\nERROR: No 'object::store:' & NO S3 storage defined\n\n";
      die;
    } else {
      echo "\nNOTE: No 'object::store:' > S3 storage  = defined\n\n";
      echo "\n Upon migration local will be renamed to object::store";
    }
  }
  else {
    echo "\nFOUND 'object::store:amazon::".$CONFIG['objectstore']['arguments']['bucket']."', OK";
    $row = $result->fetch_assoc();
    $OBJECT_STORE_ID = $row['numeric_id']; // for creative rename command..
    echo "\nThe object store id is:$OBJECT_STORE_ID";
    
    $result = $mysqli->query("SELECT `fileid` FROM `oc_filecache` WHERE `storage` = ".$OBJECT_STORE_ID);
    if ( $result->num_rows > 0 ) {
      echo "\n\nWARNING: if this is for a full migration remove all data with `storage` = $OBJECT_STORE_ID in your `oc_filecache` !!!!\n";
    }
    
  }
}
$result->free_result();

  
echo "\n\n######################################################################################### ".$TEST;
if (empty($TEST) ) {
  echo "\n\nNOTE: THIS IS THE REAL THING!!\n";
} else {
  echo empty($TEST)          ? '' : "\nWARNING: you are in test mode (".$TEST.")";
}

echo "\nBase init complete, continue?";
$getLine = '';
while ($getLine == ''): $getLine = fgets( fopen("php://stdin","r") ); endwhile;

if ($DO_FILES_CLEAN) {
  echo "\nRunning cleanup (should not be necessary but cannot hurt)";
  echo occ($OCC_BASE,'files:cleanup');
}
if ($DO_FILES_SCAN) {
  echo "\nRunning scan (should not be necessary but cannot hurt)";
  echo occ($OCC_BASE,'files:scan --all');
}

if (empty($TEST)) {
  if ($SET_MAINTENANCE) { // maintenance mode
    $process = occ($OCC_BASE,'maintenance:mode --on');
    echo $process;
    if (strpos($process, "\nMaintenance mode") == 0
     && strpos($process, 'Maintenance mode already enabled') == 0) {
      echo " could not set..  ouput command: ".$process."\n\n";
      die;
    }
  }
} else {
  echo "\n\nNOTE: In TEST-mode, will not enter maintenance mode";
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
    $copy = 1;
  }
}
if ($copy) {
  copy($PATH_NEXTCLOUD.'/config/config.php', $PATH_BACKUP.'/config.php');
}

echo "\nconnect to S3...";
$bucket = $CONFIG['objectstore']['arguments']['bucket'];
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

echo "\n";
echo "\n#########################################################################################";
echo "\nSetting everything up finished ##########################################################";

echo "\n";
echo "\n#########################################################################################";
echo "\nappdata preview size...";
$PREVIEW_MAX_AGEU = 0;
$PREVIEW_1YR_AGEU = 0;
if ($PREVIEW_MAX_AGE > 0) {
  echo "\nremove older then ".$PREVIEW_MAX_AGE." day".($PREVIEW_MAX_AGE>1?'s':'');
  
  $PREVIEW_MAX_AGEU = new DateTime(); // For today/now, don't pass an arg.
  $PREVIEW_MAX_AGEU->modify("-".$PREVIEW_MAX_AGE." day".($PREVIEW_MAX_AGE>1?'s':''));
  echo " > clear before ".$PREVIEW_MAX_AGEU->format( 'd-m-Y' )." (U:".$PREVIEW_MAX_AGEU->format( 'U' ).")";
  $PREVIEW_MAX_AGEU = $PREVIEW_MAX_AGEU->format( 'U' );

  $PREVIEW_1YR_AGEU = new DateTime(); // For today/now, don't pass an arg.
  $PREVIEW_1YR_AGEU->modify("-1year");
  $PREVIEW_1YR_AGEU = $PREVIEW_1YR_AGEU->format( 'U' );
} else {
  echo " (\$PREVIEW_MAX_AGE = 0 days, stats only)";
}
$PREVIEW_NOW_COUNT= 0; $PREVIEW_NOW_SIZE = 0;
$PREVIEW_DEL_COUNT= 0; $PREVIEW_DEL_SIZE = 0;
$PREVIEW_REM_COUNT= 0; $PREVIEW_REM_SIZE = 0;
$PREVIEW_1YR_COUNT= 0; $PREVIEW_1YR_SIZE = 0;

if (!$result = $mysqli->query("SELECT `ST`.`id`, `FC`.`fileid`, `FC`.`path`, `FC`.`size`, `FC`.`storage_mtime` FROM".
                             " `oc_filecache` as `FC`,".
                             " `oc_storages`  as `ST`,".
                             " `oc_mimetypes` as `MT`".
                             " WHERE 1".
                              " AND `FC`.`path`    LIKE 'appdata_%'".
                              " AND `FC`.`path`    LIKE '%/preview/%'".
#                              " AND `ST`.`id` LIKE 'object::%'".
#                              " AND `FC`.`fileid` = '".substr($object['Key'],8)."'". # should be only one..

                              " AND `ST`.`numeric_id` = `FC`.`storage`".
                              " AND `FC`.`mimetype`   = `MT`.`id`".
                              " AND `MT`.`mimetype`  != 'httpd/unix-directory'".
                             " ORDER BY `FC`.`storage_mtime` ASC")) {
  echo "\nERROR: query pos 1";
  die;
} else {
  if ($PREVIEW_MAX_DEL > 0
   && $PREVIEW_MAX_DEL < 1) {
    $PREVIEW_MAX_DEL*= $result->num_rows;
  }
  while ($row = $result->fetch_assoc()) {
    // Determine correct path
    if (substr($row['id'], 0, 13) == 'object::user:') {
      $path = $PATH_DATA . DIRECTORY_SEPARATOR . substr($row['id'], 13) . DIRECTORY_SEPARATOR . $row['path'];
    }
    else if (substr($row['id'], 0, 6) == 'home::') {
      $path = $PATH_DATA . DIRECTORY_SEPARATOR . substr($row['id'], 6) . DIRECTORY_SEPARATOR . $row['path'];
    } else {
      $path = $PATH_DATA . DIRECTORY_SEPARATOR . $row['path'];
    }
    $user = substr($path, strlen($PATH_DATA. DIRECTORY_SEPARATOR));
    $user = substr($user,0,strpos($user,DIRECTORY_SEPARATOR));

    if ($PREVIEW_MAX_AGEU > $row['storage_mtime']
     && $PREVIEW_MAX_DEL > 1) {
      $PREVIEW_MAX_DEL--;
      if (empty($TEST)) {
        if(file_exists($path) && is_file($path)){
          unlink($path);
        }
        $mysqli->query("DELETE FROM `oc_filecache` WHERE `oc_filecache`.`fileid` = ".$row['fileid']);
      }
      $PREVIEW_DEL_SIZE += $row['size'];
      $PREVIEW_DEL_COUNT++;
    } else {
      if (preg_match('/\/preview\/([a-f0-9]\/[a-f0-9]\/[a-f0-9]\/[a-f0-9]\/[a-f0-9]\/[a-f0-9]\/[a-f0-9]\/)?([0-9]+)\/[^\/]+$/',$path,$matches)) {
        #echo "check fileID".$matches[2].' ';
        $result2 = $mysqli->query("SELECT `storage` FROM `oc_filecache` WHERE `oc_filecache`.`fileid` = ".$matches[2]);
        if ($result2->num_rows == 0 ) {
          if (empty($TEST)) {
            if(file_exists($path) && is_file($path)){
              unlink($path);
            }
            $mysqli->query("DELETE FROM `oc_filecache` WHERE `oc_filecache`.`fileid` = ".$row['fileid']);
          } else {
            echo "\nfileID ".$matches[2]." has a preview, but the source file does not exist, would delete the preview (fileID ".$row['fileid'].")";
          }
          $PREVIEW_REM_COUNT++;
          $PREVIEW_REM_SIZE += $row['size'];
        } else {
          if ($PREVIEW_1YR_AGEU > $row['storage_mtime'] ) {
            $PREVIEW_1YR_SIZE += $row['size'];
            $PREVIEW_1YR_COUNT++;
          }
          $PREVIEW_NOW_SIZE += $row['size'];
          $PREVIEW_NOW_COUNT++;
        }
        $result2->free_result();
      } else {
        echo "\n\nERROR:  path format not as expected (".$row['fileid']." : $path)\n";
        echo "\tremove the database entry..";
        if (empty($TEST)) {
          $mysqli->query("DELETE FROM `oc_filecache` WHERE `oc_filecache`.`fileid` = ".$row['fileid']);
        }
        else {
          echo " ONLY with \$TEST = 0 the DB entry will be removed!";
        }
        echo "\n";
      }
    }
    
  }
  $result->free_result();
}

if ($PREVIEW_DEL_COUNT > 0
 || $PREVIEW_REM_COUNT > 0) {
  echo "\nappdata preview size before :".sprintf('% 8.2f',($PREVIEW_NOW_SIZE+$PREVIEW_DEL_SIZE)/1024/1024)." Mb\t(".($PREVIEW_NOW_COUNT+$PREVIEW_DEL_COUNT)." files)";
  echo "\nappdata preview > 1 year old:".sprintf('% 8.2f',($PREVIEW_1YR_SIZE)/1024/1024)." Mb\t(".$PREVIEW_1YR_COUNT." files)";
  echo "\nappdata preview size cleared:".sprintf('% 8.2f',($PREVIEW_DEL_SIZE)/1024/1024)." Mb\t(".$PREVIEW_DEL_COUNT." files".($PREVIEW_MAX_DEL<1?' MAX DEL ':'').")";
  echo "\nappdata preview size cleared:".sprintf('% 8.2f',($PREVIEW_DEL_SIZE)/1024/1024)." Mb\t(".$PREVIEW_DEL_COUNT." files".($PREVIEW_MAX_DEL<1?' MAX DEL ':'').")";
  echo "\nappdata preview size now    :".sprintf('% 8.2f',($PREVIEW_NOW_SIZE)/1024/1024)." Mb\t(".$PREVIEW_NOW_COUNT." files";
  if ($PREVIEW_NOW_SIZE+$PREVIEW_DEL_SIZE > 0 ) {
    echo "/ -".floor(($PREVIEW_DEL_SIZE+$PREVIEW_REM_SIZE)/($PREVIEW_NOW_SIZE+$PREVIEW_DEL_SIZE)+.5)."%";
  }
  echo ")";
  if (!empty($TEST)) {
    echo "\n\nNOTE: in TEST-mode, no preview-data has been cleared!";
  }
} else {
  echo "\nappdata preview size        :".sprintf('% 8.2f',($PREVIEW_NOW_SIZE)/1024/1024)." Mb\t(".$PREVIEW_NOW_COUNT." files)";
  echo "\nappdata preview > 1 year old:".sprintf('% 8.2f',($PREVIEW_1YR_SIZE)/1024/1024)." Mb\t(".$PREVIEW_1YR_COUNT." files)";
}

echo "\n";
echo "\n#########################################################################################";
echo "\ncheck files in S3... ";
$objects = S3list($s3, $bucket);

$objectIDs = array();
$users     = array();

if (is_string($objects)) {
  echo $objects; # error..
  die;
}
else {
  echo "\nObjects to process in S3: ".count($objects).' ';
  $S3_removed = 0;
  $S3_updated = 0;
  $S3_skipped = 0;

  // Init progress
  $complete = count($objects);
  $prev     = '';
  $current  = 0;
  
  $showinfo = !empty($TEST);
  $showinfo = $SHOWINFO ? $showinfo : 0;
  
  foreach ($objects as $object) {
    $current++;
    $infoLine = "\n".$current."  /  ".substr($object['Key'],8)."\t".$object['Key'] . "\t" . $object['Size'] . "\t" . $object['LastModified'] . "\t";

    if (!$result = $mysqli->query("SELECT `ST`.`id`, `FC`.`fileid`, `FC`.`path`, `FC`.`storage_mtime` FROM".
                                 " `oc_filecache` AS `FC`,".
                                 " `oc_storages`  AS `ST`,".
                                 " `oc_mimetypes` AS `MT`".
                                 " WHERE 1".
   #                              " AND st.id LIKE 'object::%'".
                                  " AND `FC`.`fileid` = '".substr($object['Key'],8)."'". # should be only one..

                                  " AND `ST`.`numeric_id` = `FC`.`storage`".
                                  " AND `FC`.`mimetype`   = `MT`.`id`".
                                  " AND `MT`.`mimetype`  != 'httpd/unix-directory'".
                                 " ORDER BY `FC`.`path` ASC")) {#
      echo "\nERROR: query pos 2";
      die;
    } else {
      if ($result->num_rows>1) {
        echo "\ndouble file found in oc_filecache, this can not be!?\n";
        die;
      }
      else if ($result->num_rows == 0) { # in s3, not in db, remove from s3
        if ($showinfo) { echo $infoLine."\nID:".$object['Key']."\ton S3, but not in oc_filecache, remove..."; }
        if (!empty($TEST) && $TEST == 2) {
          echo ' not removed ($TEST = 2)';
        } else {
          $result_s3 =  S3del($s3, $bucket, $object['Key']);
          if ($showinfo) { echo 'S3del:'.$result_s3; }
        }
        $S3_removed++;
      }
      else { # one match, up to date?
        $row = $result->fetch_assoc();

        // Determine correct path
        if (substr($row['id'], 0, 13) == 'object::user:') {
        $path = $PATH_DATA . DIRECTORY_SEPARATOR . substr($row['id'], 13) . DIRECTORY_SEPARATOR . $row['path'];
        }
        else if (substr($row['id'], 0, 6) == 'home::') {
          $path = $PATH_DATA . DIRECTORY_SEPARATOR . substr($row['id'], 6) . DIRECTORY_SEPARATOR . $row['path'];
        } else {
          $path = $PATH_DATA . DIRECTORY_SEPARATOR . $row['path'];
        }
        $user = substr($path, strlen($PATH_DATA. DIRECTORY_SEPARATOR));
        $user = substr($user,0,strpos($user,DIRECTORY_SEPARATOR));
        $users[ $user ] = 1;

        $infoLine.= $user. "\t";

        # just for one user? set test = appdata_oczvcie795w3 (system wil not go to maintenance nor change database, just test and copy data!!)
        if (is_numeric($TEST) || $TEST == $user ) {
          #echo "\n".$path."\t".$row['storage_mtime'];
          if(file_exists($path) && is_file($path)){
            if ($row['storage_mtime'] < filemtime($path) ) {
              if ($showinfo) { echo $infoLine."\nID:".$object['Key']."\ton S3, but is older then local, upload..."; }
              if (!empty($TEST) && $TEST == 2) {
                echo ' not uploaded ($TEST = 2)';
              } else {
                $result_s3 =  S3put($s3, $bucket,[
                                          'Key' => 'urn:oid:'.$row['fileid'],
                                          #'Body'=> "Hello World!!",
                                          'SourceFile' => $path,
                                          'ACL' => 'private'//public-read'
                                        ]);
                if ($showinfo) { echo 'S3put:'.$result_s3; }
              }
              $S3_updated++;
            } else {
              $objectIDs[ $row['fileid'] ] = 1;
#              if ($showinfo) { echo $infoLine."OK (".$row['fileid']." / ".(count($objectIDs)).")"; }
            }
          } else {
            $objectIDs[ $row['fileid'] ] = 1;
#            if ($showinfo) { echo $infoLine."OK-S3 (".$row['fileid']." / ".(count($objectIDs)).")"; }
          }
        } else {
          $S3_skipped++;
#          if ($showinfo) { echo "SKIP (TEST=$TEST)"; }
        }
      }
      // Update progress
      $new = sprintf('%.2f',$current/$complete*100).'% (now at user '.$user.')';
      if ($prev != $new && !$showinfo) {
        echo str_repeat(chr(8) , strlen($prev) );
        $new.= (strlen($prev)<=strlen($new))? '' : str_repeat(' ' , strlen($prev)-strlen($new) );
        $prev = $new;
        echo $prev;
      }
    }
    $result->free_result();
  }
  if (!$showinfo) {
    echo str_repeat(chr(8) , strlen($prev) );
    $new = ' DONE ';
    $new.= (strlen($prev)<=strlen($new))? '' : str_repeat(' ' , strlen($prev)-strlen($new) );
    $prev = $new;
    echo $prev;
  }
  if ($showinfo) { echo "\nNumber of objects in  S3: ".count($objects); }
  echo "\nobjects removed from  S3: ".$S3_removed;
  echo "\nobjects updated to    S3: ".$S3_updated;
  echo "\nobjects skipped on    S3: ".$S3_skipped;
  echo "\nobjects in sync on    S3: ".count($objectIDs);
  if ($S3_removed+$S3_updated+$S3_skipped+count($objectIDs) - count($objects) != 0 ) {
    echo "\n\nERROR: The numbers do not add up!?\n\n";
    die;
  }
}

echo "\n";
echo "\n#########################################################################################";
echo "\ncheck files in oc_filecache... ";

if (!$result = $mysqli->query("SELECT `ST`.`id`, `FC`.`fileid`, `FC`.`path`, `FC`.`storage_mtime` FROM".
                             " `oc_filecache` AS `FC`,".
                             " `oc_storages`  AS `ST`,".
                             " `oc_mimetypes` AS `MT`".
                             " WHERE 1".
#                              " AND fc.size      != 0".
#                              " AND st.id LIKE 'object::%'".
#                              " AND fc.fileid = '".substr($object['Key'],8)."'". # should be only one..

                              " AND `ST`.`numeric_id` = `FC`.`storage`".
                              " AND `FC`.`mimetype`   = `MT`.`id`".
                              " AND `MT`.`mimetype`  != 'httpd/unix-directory'".
                             " ORDER BY `ST`.`id`, `FC`.`fileid` ASC")) {
  echo "\nERROR: query pos 3\n\n";
  die;
} else {
  // Init progress
  $complete = $result->num_rows;
  $prev     = '';
  $current  = 0;

  echo "\nNumber of objects in oc_filecache: ".$result->num_rows.' ';
  
  $showinfo = !empty($TEST);
  $showinfo = 0;
  
  $LOCAL_ADDED = 0;
  while ($row = $result->fetch_assoc()) {
    $current++;

    if (empty($objectIDs[ $row['fileid'] ]) ) {
      // Determine correct path
      if (substr($row['id'], 0, 13) == 'object::user:') {
        $path = $PATH_DATA . DIRECTORY_SEPARATOR . substr($row['id'], 13) . DIRECTORY_SEPARATOR . $row['path'];
      }
      else if (substr($row['id'], 0, 6) == 'home::') {
        $path = $PATH_DATA . DIRECTORY_SEPARATOR . substr($row['id'], 6) . DIRECTORY_SEPARATOR . $row['path'];
      } else {
        $path = $PATH_DATA . DIRECTORY_SEPARATOR . $row['path'];
      }
      $user = substr($path, strlen($PATH_DATA. DIRECTORY_SEPARATOR));
      $user = substr($user,0,strpos($user,DIRECTORY_SEPARATOR));
      $users[ $user ] = 1;

      if ($showinfo) { echo "\n".$user."\t".$row['fileid']."\t".$path."\t"; }
      
      # just for one user? set test = appdata_oczvcie795w3 (system wil not go to maintenance nor change database, just test and copy data!!)
      if (is_numeric($TEST) || $TEST == $user ) {
        if(file_exists($path) && is_file($path)){
          if (!empty($TEST) && $TEST == 2) {
            echo ' not uploaded ($TEST = 2)';
          } else {
            $result_s3 = S3put($s3, $bucket,[
                                      'Key' => 'urn:oid:'.$row['fileid'],
                                      #'Body'=> "Hello World!!",
                                      'SourceFile' => $path,
                                      'ACL' => 'private'//public-read'
                                    ]);
            if (strpos(' '.$result_s3,'ERROR:') == 1) {
              echo "\n".$result_s3."\n\n";
              die;
            }
            if ($showinfo) { echo "OK"; }
          }
          $LOCAL_ADDED++;
        } else {
          echo "\n".$path." (id:".$row['fileid'].") DOES NOT EXIST?!\n";
          if (empty($TEST)) {
            $mysqli->query("DELETE FROM `oc_filecache` WHERE `oc_filecache`.`fileid` = ".$row['fileid']);
            echo "\t".'removed ($TEST = 0)'."\n";
          } else {
            echo "\t".'not removed ($TEST != 0)'."\n";
          }
        }
      } else if ($showinfo) {
        echo "SKIP (\$TEST = $TEST)";
      }
    } else {
      if ($showinfo) { echo "\n"."\t".$row['fileid']."\t".$row['path']."\t"."SKIP";}
    }
    // Update progress
    $new = sprintf('%.2f',$current/$complete*100).'% (now at user '.$user.')';

    if ($prev != $new && !$showinfo) {
      echo str_repeat(chr(8) , strlen($prev) );
      $new.= (strlen($prev)<=strlen($new))? '' : str_repeat(' ' , strlen($prev)-strlen($new) );
      $prev = $new;
      echo $prev;
    }
  }
  $result->free_result();
  if (!$showinfo) {
    echo str_repeat(chr(8) , strlen($prev) );
    $new = ' DONE ';
    $new.= (strlen($prev)<=strlen($new))? '' : str_repeat(' ' , strlen($prev)-strlen($new) );
    $prev = $new;
    echo $prev;
  }  
  if (!empty($TEST) && 0) { echo "\nNumber of objects in oc_filecache: ".$result->num_rows; }
  echo "\nFiles in oc_filecache added to S3: ".$LOCAL_ADDED;
}

echo "\nCopying files finished";

if (empty($TEST)) {
  $dashLine = "\n".
              "\n#########################################################################################";
              
  $mysqli->query("UPDATE `oc_storages` SET `id`=CONCAT('object::user:', SUBSTRING_INDEX(`oc_storages`.`id`,':',-1)) WHERE `oc_storages`.`id` LIKE 'home::%'");
  $UpdatesDone = $mysqli->affected_rows;
  
  //rename command
  if ($LOCAL_STORE_ID == 0
   || $OBJECT_STORE_ID== 0) { // standard rename
    $mysqli->query("UPDATE `oc_storages` SET `id`='object::store:amazon::".$bucket."' WHERE `oc_storages`.`id` LIKE 'local::".$PATH_DATA."/'");
    $UpdatesDone.= '/'.$mysqli->affected_rows;
  } else {
    $mysqli->query("UPDATE `oc_filecache` SET `storage` = '".$OBJECT_STORE_ID."' WHERE `storage` = '".$LOCAL_STORE_ID."'");
    $UpdatesDone.= '/'.$mysqli->affected_rows;
    #$mysqli->query("DELETE FROM `oc_storages` WHERE `oc_storages`.`numeric_id` = ".$OBJECT_STORE_ID);
  }
  if ($UpdatesDone == '0/0' ) {
#    echo $dashLine." no modefications needed";
  } else {
    echo $dashLine."\noc_storages altered (".$UpdatesDone.")";
  }

  foreach ($users as $key => $value) {
    $mysqli->query("UPDATE `oc_mounts` SET `mount_provider_class` = REPLACE(`mount_provider_class`, 'LocalHomeMountProvider', 'ObjectHomeMountProvider') WHERE `user_id` = '".$key."'");
    if ($mysqli->affected_rows == 1) {
      echo $dashLine."\n-Changed mount provider class off ".$key." from home to object";
      $dashLine = '';
    }
  }  
  
  echo "\n\n#########################################################################################";

  if ($PREVIEW_DEL_SIZE > 0 ) {
    echo "\nThere were preview images removed";
    echo "\nNOTE: you can optionally run occ preview:generate-all => pre generate previews, do install preview generator)\n";
  }
  
  foreach ($users as $key => $value) {
    if (is_dir($PATH_DATA . DIRECTORY_SEPARATOR . $key)) {
      echo "\nNOTE: you can remove the user folder of $key\tby: rm -rf ".$PATH_DATA . DIRECTORY_SEPARATOR . $key;
    }
  }
  echo "\n";

  if (is_string($CONFIG_OBJECTSTORE) && file_exists($CONFIG_OBJECTSTORE) ) {
    echo "\nCopy storage.config.php to the config folder...".
    copy($CONFIG_OBJECTSTORE,$PATH_NEXTCLOUD.'/config/storage.config.php');

    if ($SET_MAINTENANCE) { // maintenance mode
      $process = occ($OCC_BASE,'maintenance:mode --off');
      echo $process;
    }
  }
  else if ($OBJECT_STORE_ID > 0 ) {
    if ($SET_MAINTENANCE) { // maintenance mode
      $process = occ($OCC_BASE,'maintenance:mode --off');
      echo $process;
    }
  } else {
    echo "\n#########################################################################################";
    echo "\nNOTE: THIS MUST BE DONE MANUALY!!";
    echo "\n 1: add \$CONFIG_OBJECTSTORE to your config.php";
    echo "\n 2: turn maintenance mode off";
    echo "\n\nthe importance of the order to do things is EXTREME, other order can brick your Nextcloud!!\n\n";
    echo "\n\n#########################################################################################";
  }  
  echo "\n\n";
  
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

#########################################################################################
function recursive_copy($src,$dst) {
  $dir = opendir($src);
  @mkdir($dst);
  while( $file = readdir($dir) ) {
    if ( $file != '.'
     &&  $file != '..' ) {
      if ( is_dir($src . DIRECTORY_SEPARATOR . $file) ) {
        recursive_copy($src . DIRECTORY_SEPARATOR . $file,
                       $dst . DIRECTORY_SEPARATOR . $file);
      } else {

        $copy = 1;
        if(file_exists($dst . DIRECTORY_SEPARATOR . $file)){
          if (filemtime($src . DIRECTORY_SEPARATOR . $file) > filemtime($dst . DIRECTORY_SEPARATOR . $file) ) {
            unlink($dst . DIRECTORY_SEPARATOR . $file);
          }
          else { $copy = 0; }
        }
        if ($copy) {
          copy($src . DIRECTORY_SEPARATOR . $file,
               $dst . DIRECTORY_SEPARATOR . $file);
        }

      }
    }
  }
  closedir($dir);
}

#########################################################################################
function S3list($s3, $bucket, $maxIteration = 10000000) {
  $objects = [];
  try {
    $iteration = 0;
    $marker = '';
    do {
      $result = $s3->listObjects(['Bucket' => $bucket, 'Marker' => $marker]);
      if ($result->get('Contents')) {
        $objects = array_merge($objects, $result->get('Contents'));
      }
      if (count($objects)) {
        $marker = $objects[count($objects) - 1]['Key'];
      }
    } while ($result->get('IsTruncated') && ++$iteration < $maxIteration);
    if ($result->get('IsTruncated')) {
      echo "\n".'WARNING: The number of keys greater than '.count($objects).' (the first part is loaded)';
    }
    return $objects;
  } catch (S3Exception $e) {
    return 'ERROR: Cannot retrieve objects: '.$e->getMessage();
  }
}
#########################################################################################
function S3put($s3, $bucket, $vars = array() ) {
  #return 'dummy';
  if (is_string($vars)      ) {
    if (file_exists($vars)) {
      $vars = array('SourceFile' => $vars);
    }
    else {
      return 'ERROR: S3put($cms, $bucket, $vars)';      
    }
  }
  if (empty($vars['Bucket'])) { $vars['Bucket'] = $bucket; }
  if (empty($vars['Key'])
   && !empty($vars['SourceFile'])) { $vars['Key'] = $vars['SourceFile']; }

  if (empty($vars['Bucket'])) { return 'ERROR: no Bucket'; }
  if (empty($vars['Key'])   ) { return 'ERROR: no Key';    }

  if (empty($vars['ACL'])   ) {  $vars['Key'] = 'private';    }

  try {
    $result = $s3->putObject($vars);
    if (!empty($result['ObjectURL'])) {
      return 'OK: '.'ObjectURL:'.$result['ObjectURL'];
    } else {
      return 'ERROR: '.$vars['key'].' was not uploaded';
    }
  } catch (S3Exception $e) { return 'ERROR: ' . $e->getMessage(); }
}
#########################################################################################
function S3del($s3, $bucket, $vars = array() ) {
  #return 'dummy';
  if (is_string($vars)      ) { $vars = array('Key' => $vars); }
  if (empty($vars['Bucket'])) { $vars['Bucket'] = $bucket; }

  if (empty($vars['Bucket'])) { return 'ERROR: no Bucket'; }
  if (empty($vars['Key'])   ) { return 'ERROR: no Key';    }

  try {
    $result = $s3->deleteObject($vars);
    return 'OK: '.$vars['Key'].' was deleted (or didn\'t not exist)';
  } catch (S3Exception $e) { return 'ERROR: ' . $e->getMessage(); }
}
#########################################################################################
function S3get($s3, $bucket, $vars = array() ) {
  #return 'dummy';
  if (is_string($vars)      ) {
    $vars = array('Key' => $vars);
  }
  if (empty($vars['Bucket']) ) { $vars['Bucket'] = $bucket; } // Bucket = the bucket
  if (empty($vars['Key'])
   && !empty($vars['SaveAs'])) { $vars['Key']    = $vars['SaveAs']; } // Key = the file-id/location in s3
  if (empty($vars['SaveAs'])
   && !empty($vars['Key'])   ) { $vars['SaveAs'] = $vars['Key']; } // SaveAs = local location+name

  if (empty($vars['Bucket'])) { return 'ERROR: no Bucket'; }
  if (empty($vars['Key'])   ) { return 'ERROR: no Key';    }

  try {
    if (1 || $cms['aws']['client']->doesObjectExist($vars['Bucket']
                                              ,$vars['Key']) ) {
      return $cms['aws']['client']->getObject($vars);
    } else {
      return 'ERROR: '.$vars['Key'].' does not exist';
    }
  } catch (S3Exception $e) { return 'ERROR: ' . $e->getMessage(); }
}