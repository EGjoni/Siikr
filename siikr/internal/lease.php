<?php
$stmtGetCurrentLeader = $db->prepare("SELECT leader_uuid FROM archiver_leases WHERE blog_uuid = :blog_uuid");
$abandonLease = $db->prepare("DELETE FROM archiver_leases WHERE leader_uuid = :leader_uuid");
$establishLease = $db->prepare("INSERT INTO archiver_leases (blog_uuid, leader_uuid) VALUES (?, ?) ON CONFLICT (blog_uuid) DO UPDATE SET leader_uuid = EXCLUDED.leader_uuid");

/**returns true if the provided archiver_uuid is allowed to persist, false if it should terminate*/
function amLeader($db, $blog_uuid, $archiver_uuid) {
    global $stmtGetCurrentLeader;
    $currentLeader = $stmtGetCurrentLeader->exec(['blog_uuid' => $blog_uuid])->fetchColumn();
    return $currentLeader == $archiver_uuid;
}

function renewOrStealLease($db, $blog_uuid, $archiver_uuid) {
    global $stmtGetCurrentLeader, $stmtCreateLease, $stmtRenewLease, $stmtStealLease;

    $stmtGetCurrentLeader->execute(['blog_uuid' => $blog_uuid]);
    $currentLeader = $stmtGetCurrentLeader->fetchColumn();

    if($currentLeader == null) {
        // Call dibs
        $stmtCreateLease->execute(['blog_uuid' => $blog_uuid, 'archiver_uuid' => $archiver_uuid]);
        return true;
    } else if ($currentLeader === $archiver_uuid) {
        // Renew the lease
        $stmtRenewLease->execute(['blog_uuid' => $blog_uuid, 'archiver_uuid' => $archiver_uuid]);
        return true;
    } else {
        // Try to steal the lease
        for ($i = 0; $i < 3; $i++) {
            $stmtStealLease->execute(['blog_uuid' => $blog_uuid, 'archiver_uuid' => $archiver_uuid]);
            if ($stmtStealLease->rowCount() > 0) {
                // yoink
                return true;
            }
            // Wait for 3 seconds before trying again
            sleep(3);
        }
        return false;
    }
}

