<?php
require_once 'disks.php';

function get_used_percent() {
    global $db_disk, $db_min_disk_headroom;
    $total_diskspace = disk_total_space($db_disk) - $db_min_disk_headroom;
    $free_space = disk_free_space($db_disk);
    $used_percent = (1 -($free_space/$total_diskspace))*100;
    return $used_percent;
  }

  function get_allocated_space() {
    global $db_disk, $db_min_disk_headroom;
    return disk_total_space($db_disk) - $db_min_disk_headroom;
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

    return $value * pow(1000, $exponent);
}