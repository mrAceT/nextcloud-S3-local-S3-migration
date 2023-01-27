<?php
$CONFIG = array (
  'objectstore' => array(
          'class' => 'OC\\Files\\ObjectStore\\S3',
          'arguments' => array(
                  'bucket' => '**bucket**', // your bucket name
                  'autocreate' => true,
                  'key' => '**key', // your key
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