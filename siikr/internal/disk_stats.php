<?php
require_once 'disks.php';

function get_disk_stats() {
    global $db_disk;
    $diskpath = $db_disk;
    $total_diskspace = disk_total_space($diskpath);
    $free_space = disk_free_space($diskpath);
    $used_percent = (1 - $free_space/$total_diskspace)*100;
    return $used_percent;
  }

  function sizeToBytes($size) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
    $size = trim($size);
    $unit = preg_replace('/[^a-zA-Z]/', '', $size);
    $value = floatval(preg_replace('/[^0-9.]/', '', $size));

    $exponent = array_flip($units)[strtoupper($unit)];
    if ($exponent === null) {
        throw new InvalidArgumentException("Invalid size unit: $unit");
    }

    return $value * pow(1024, $exponent);
}