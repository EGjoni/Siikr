<?php
require_once './../internal/globals.php';
require_once 'meta_internal/node_management.php';
/**
 * Use to avoid hitting the tumblr api when indexing a new blog,
 * Requests that this siikr hub query all of the nodes it is aware of to return acquire all psots meeting the
 * the specified constraints for the requested blog. This endpoint will consolidate the results before returning them 
 * 
 * Takes as arguments  
 *  a  `blog_uuid` (required),
 *  a  `requested_by` (required, node url),  
 *  a  'version` parameter (optional, single character 0-9,a-Z), 
 *  a  `before` parameter (timestamp, optional), and
 *  an `after` parameter (timestamp, optional)
 */



 $spoke_posts = []; //keyed by spokes, value is a list of posts they contain
 $post_spokes = []; //keyed by post_ids, value is a list of spokes on which they reside
 $timestamp_posts = []; //timestamp sorted list of [post_ids => timestamp]

/**
* basic_gist is we just query each spoke in $spoke_posts. On the first pass we do so using the largest before and after timestamps provided by the client (which ideally were specified such that before is less than after)
* We eliminate the posts that spoke returned from the $post_spokes list and $timestamp_posts lists. 
* We then set our $before value to that of the largest remain timestamp in $timestamp_posts, and our $after value to that of the smallest timestamp remaining in the list, and query the next spoke. Repeating this porcess until there are no more posts in $post_spokes
**/