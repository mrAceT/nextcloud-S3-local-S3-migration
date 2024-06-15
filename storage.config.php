<?php
$CONFIG = array(
  'objectstore' => array(
          'class' => 'OC\\Files\\ObjectStore\\S3',
          'arguments' => array(
                  'bucket' => '**bucket**', // your bucket name
                  'key' => '**key', // your key
                  'secret' => '**secret**', // your secret
                  'hostname' => '**host**', // your host
                  'port' => 443, // default
                  'use_ssl' => true, // default
                  'use_path_style' => false, //required to be true by some s3 providers
                  'sse_c_key' => '***sse-c key***', // only provide, if you want to use customer provided encryption key see, otherwise delete: https://docs.nextcloud.com/server/latest/admin_manual/configuration_files/primary_storage.html#s3-sse-c-encryption-support
                  'region' => '***region***' // must be present, even if non-S3 storage is selected. Otherwise the script localtoS3 will fail
          ),
  ),
);
