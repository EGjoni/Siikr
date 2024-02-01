<?php
$stmtGetCurrentLeader = $db->prepare("SELECT leader_uuid FROM archiver_leases WHERE blog_uuid = :blog_uuid");
$stmtCreateLease = $db->prepare("INSERT INTO archiver_leases (blog_uuid, leader_uuid) VALUES (:blog_uuid, :archiver_uuid)");
$stmtRenewLease = $db->prepare("UPDATE archiver_leases SET lease_expires_on = now() + interval '5 seconds' WHERE blog_uuid = :blog_uuid AND leader_uuid = :archiver_uuid");
$stmtStealLease = $db->prepare("UPDATE archiver_leases SET leader_uuid = :archiver_uuid, lease_expires_on = now() + interval '5 seconds' WHERE blog_uuid = :blog_uuid AND lease_expires_on < now()");
$abandonLease = $db->prepare("DELETE FROM archiver_leases WHERE leader_uuid = :leader_uuid");

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

