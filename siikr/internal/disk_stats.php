<?php
require_once 'disks.php';

function get_disk_stats() {
    global $db_disk;
    $diskpath = "/mnt/volume_sfo3_01";
    $total_diskspace = disk_total_space($diskpath);
    $free_space = disk_free_space($diskpath);
    $used_percent = (1 - $free_space/$total_diskspace)*100;
    return $used_percent;
  }