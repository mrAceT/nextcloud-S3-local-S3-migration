# nextcloud S3 local S3 migration
 Script for migrating Nextcloud primary storage from S3 to local to S3 storage

# Nextcloud S3 to local to S3 storage migration script :cloud: to :floppy_disk: to :cloud: 

## S3 Best practice: start clean
It is always best to start with the way you want to go. [Nextcloud](https://nextcloud.com/) default for the primary storage is 'local'.
To start out with 'S3' from the start these are the steps I took:
1. download [setup-nextcloud.php](https://github.com/nextcloud/web-installer/blob/master/setup-nextcloud.php)
2. upload the file and execute it (for current folder use . )
3. **before** step 2: go to folder'config' and add file storage.config.php with
```<?php
$CONFIG = array (
  'objectstore' => array(
          'class' => 'OC\\Files\\ObjectStore\\S3',
          'arguments' => array(
                  'bucket' => '**bucket**', // your bucket name
                  'autocreate' => true,
                  'key' => '**key**', // your key
                  'secret' => '**secret**', // your secret
                  'hostname' => '**host**', // your host
                  'port' => 443,
                  'use_ssl' => true,
                  'region' => '**region**', // your region
                  'use_path_style' => false
// required for some non Amazon S3 implementations
// 'use_path_style' => true
          ),
  ),
);
```
4. click 'next'
5. follow the instructions..

# A friendly note before you start migrating..
Officially it is not supported to change the primary storage in Nextcloud.
However, it's very well possible and these unofficial scripts will help you in doing so.

**TIP**: When you can, install a “test nextcloud”, configured just like your “real one” and go through the steps.. I have tried to make it al as generic as possible, but you never know.. and I wouldn’t want to be the cause of your data loss…

In theory nothing much could go wrong, as the script does not remove your local/S3 data and only uploads/downloads it all to your s3 bucket/local drive and does database changes (which are backed up)..but there might just be that one thing I didn’t think of.. or did that little alteration that I haven’t tested..

:warning: These scripts are written with the best of intentions and have both been tested thoroughly.
**But** it may fail and lead to data loss. **Use at your own risk!** :warning:

## S3 to local
It will transfer files from **S3** based primary storage to a **local** primary storage.

The basics were inspired upon the work of [lukasmu](https://github.com/lukasmu/nextcloud-s3-to-disk-migration/).

1. the only 'external thing' you need is 'aws/aws-sdk-php' (runuser -u clouduser -- composer require aws/aws-sdk-php)
2. set & check all the config variables in the beginning of the script!
3. start with the highest $TEST => choose a 'small test user"
4. set $TEST to 1 and run the script again
5. when 4. was completely successful move the data in folder $PATH_DATA to $PATH_DATA_BKP !
6. set $TEST to 0 and run the script again (this is LIVE, nextcloud will be set into maintenance:mode --on while working !)

**DO NOT** skip ahead and go live ($TEST=0) as the first step.. then your downtime will be very long!

With performing 'the move' at step 5 you will decrease the downtime (with maintenance mode:on) immensely!
This because the script will first check if it already has the latest file, then it only needs to move the file and does not need to (slowly) download it form your S3 bucket!
With a litte luck the final run (with $TEST=0) can be done within a minute!

**NOTE** step 4 will take a very long time when you have a lot of data to download!

If everything worked you might want to delete the backup folder and S3 instance manually.
Also you probably want to delete this script after running it.

### S3 to local version history
v0.30 first github release

## local to S3
It will transfer files from **local** based primary storage to a **S3** primary storage.

The basics were inspired upon the script s3tolocal.php (mentioned above), but there are **a lot** of differences..

1. the only 'external thing' you need is 'aws/aws-sdk-php' (runuser -u clouduser -- composer require aws/aws-sdk-php)
2. place 'storage.config.php' in the same folder as localtos3.php (and set your S3 credentials!)
3. set & check all the config variables in the beginning of the script!
4. start with the highest $TEST => 2 (complete dry run, just checks en dummy uploads etc. NO database changes what so ever!)
5. set $TEST to a 'small test user", upload the data to S3 for only that user (NO database changes what so ever!)
6. set $TEST to 1 and run the script yet again, upload (**and check**) all the data to S3 (NO database changes what so ever!)
7. set $TEST to 0 and run the script again (this is LIVE, nextcloud will be set into maintenance:mode --on while working ! **database changes!**)

**DO NOT** skip ahead and go live ($TEST=0) as the first step.. then your downtime will be very long!

With performing 'the move' at step 6 you will decrease the downtime (with maintenance mode:on) immensely!
This because the script will first check if it already has uploaded the latest file, then it can skip to the next and does not need to (slowly) upload it to your S3 bucket!
With a litte luck the final run (with $TEST=0) can be done within a minute!

**NOTE** step 6 will take a very long time when you have a lot of data to upload!

If everything worked you might want to delete the data in data folder.
Also you probably want to delete this script (and the 'storage.config.php') after running it.
If all went as it should the config data in 'storage.config.php' is included in the 'config/config.php'. Then the 'storage.config.php' can also be removed from your config folder (no sense in having a double config)

## S3 sanity check!
When you
1. have S3 as your primary storage
2. set $TEST to 0
3. **optionally** set $SET_MAINTENANCE to 0
4. (have set/checked all the other variables..)

Then the script 'localtos3.php' will:
- look for entries in S3 and not in the database and vice versa **and remove them**.
This can happen sometimes upon removing an account, preview files might not get removed.. stuff like that..

- check for canceled uploads.
Inspired upon [otherguy/nextcloud-cleanup](https://github.com/otherguy/nextcloud-cleanup/blob/main/clean.php). I have not had this problem, so can not test.. => check only!

- preview cleanup.
Removes previews of files that no longer exist.
There is some initial work for clearing previews.. that is a work in progress, use at your own risc!

The script will do the "sanity check" when migrating also (we want a good and clean migrition, won't we? ;)

### local to S3 version history
v0.34 added support for 'MultipartUploader'\
v0.33 some improvements on 'preview management'\
v0.32 more (size) info + added check for canceled uploads\
v0.31 first github release

# I give to you, you..

I built this to be able to migrate if the one or the other is needed for what ever reason I could have in the future.
You might have that same reason, so here it is!
**Like the work?** You'll be surprised how much time goes into things like this.. 

Be my hero, think about the time this script saved you, and (offcourse) how happy you are now that you migrated this smoothly.
Support my work, buy me a cup of coffee, give what its worth to you, or give me half the time this script saved you ;)
- [paypal](https://paypal.me/eesgertoering)
- [geef.nl](https://www.geef.nl/en/donate?action=15544) (iDeal, Dutch)

## Contributing

If you find this script useful and you make some modifications please make a pull request so that others can benefit from it. This would be highly appreciated!

## License

This script is open-sourced software licensed under the GNU GENERAL PUBLIC LICENSE. Please see [LICENSE](LICENSE.md) for details.
