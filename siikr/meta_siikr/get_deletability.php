<?php
/**
 * takes as an argument the spoke_url, returns an ordered list of blog_uuids arranged with the most deletable blog first.
 * deletability is determined by the availability of a blog on other nodes.
 * 
 * an empty list indicates it isn't safe to delete any blogs this hub is aware of the spoke having.
 * if an empty list is returned but deletion is absolutely necessary, a call should first be made to request_offload.php
 * with the blog_uuid of the blog to delete.
 * 
 * Every few minutes, follow-up calls should be made to this endpoint until the blog_uuid to delete appears in the list.
 * This indicates that it is safe to delete the blog.
 */
require_once __DIR__."/../internal/globals.php";
$db = getDb();//new SPDO("pgsql:dbname=$db_name", $db_user, $db_pass);

$requested_by = 'https://'.normalizeURL($_GET["requested_by"]);

if($requested_by == null) throw new Error("Error: requested_by is a required parameter");
if($blog_uuid == null) throw new Error("Error: blog_uuid is a required parameter");

$redundant_blogs = $db->prepare(
    "SELECT * FROM 
         blog_node_map blnm, siikr_nodes sn 
    WHERE 
        blnm.node_id = sn.node_id 
    AND
        sn.node_url != :requested_by
    GROUP BY blnm.blog_uuid 
    ORDER BY weighted_appearance_count desc");