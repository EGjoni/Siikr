<?php
$scriptVer = 80;
require_once 'internal/disk_stats.php';
try {
	$diskpath = $db_disk;   
    
    $total_diskspace = get_allocated_space()/(1024*1024); 
    $free_space = disk_free_space($db_disk)/(1024*1024);
    $used_percent = get_used_percent();
	$used_percent = round($used_percent, 2);

} catch (Exception $e) {

}
?>
<!DOCTYPE html>
<html lang="en">
<head>
        <title>Siikr - A tumblr search engine that really exists!</title>
    <link rel="stylesheet" type="text/css" href="css/sikr-css.css?<?php echo $scriptVer; ?>">
</head>
<body <?php if(isset($node_maintenance_mode_reminder) && $node_maintenance_mode_reminder) { echo "class = 'maintenance-mode'";}?>>
<?php //if($squid_game == true) {require_once 'deletion_roulette.php';}?>
<div id="all-stuff">
    <?php require 'management/notice.php'?>
    <div id="support">
        <details style="color: lightgray" id="help">
            <summary style="
            background-color: 1c3144;
            width: 110px;">Pro Tips:</summary>
            <ul style="font-size: 14">
                <li> If you type in multiple words, siikr will return posts that contain as many of those words as it can find. </li>
                <li> If you surround words with quotation marks, siikr will return only posts that contain the exact words in those quotation marks in that exact order.</li>
                <li> You can make searching your own blog more convenient by visiting or linking to </br>
                <span style="font-size: 12"><i><a style="color:azure" href="https://siikr.tumblr.com/?u=YourUserName">siikr.tumblr.com/?u=<b>YourUserName</a></b></i></span> </br> This will tell siikr to prepopulate the username following <i><b>?u=</b></i> into the username box </li>
                <ul>
                    <li> If you want to share a search, you can add <br><span><i><b>&amp;s=stuff to search for</b></i></span> to the end of the url</br></li>
                    <li> If you want the search to show post previews by default, you can add  <i><b>&amp;p=t</b></i> to the url</li>
                    <li id="example-li"> If you're confused, try a search, and I'll give you a sample URL here.</li>
                    <li id="search-link-li" style="display: none"> For example, you might try <br> <span style="font-size: 12"><i><a id="search-link-text" style="color:azure" href="https://siikr.tumblr.com/?u=YourUserName">siikr.tumblr.com/<b>?u=</b>siikr<b>&amp;q=</b>computer blog<b>&amp;p=</b>t</a></i>
                </ul>
            </ul>
        </details>
    </div>
    <?php require_once 'toys/wordcloud.php'?>
    <!--<div id="beta-notice" st>This version of siikr is <b>SUPER SAD</b>. Please be nice to it! <span style="font-size:0.8em"> Report any bugs to <a href="http://antinegationism.tumblr.com/ask" style="color: white">antinegationism.tumblr.com/ask</a></span></div>-->

    <div id="container">
        <div id="search-container">
            <?php require_once 'advanced.php'?>
            <div id="search-fields-container">
                <button id="show-advanced" title="Advanced Filter" onclick="showAdvanced()"><img class="gear-icon" src="images/gear.svg"></img></button>
                <input id="username" type="text" placeholder="Username" onkeyup="seekIfSubmit(event)">
                <input id="query" type="text" placeholder='these words OR "this phrase" -"but not this one"' onkeyup="seekIfSubmit(event)">
                <button id="search" value="Seek" onclick="preSeek()">Seek</button>
            </div>
        </div>
        <div id="progress-container">
            <div id="status"><span id="status-text"></span></div>
            <div id="progress"><div id="progress-text"></div></div>
        </div>
        <details id="notice-container">
            <summary></summary>
            <div id="notice-log" onclick="collapseExpandableParent(this)"></div>
        </details>      

        <div id="search-controls">
                <div id="previews" style=""><input type="checkbox" id="preview-toggle" style="
                z-index: 12;" onclick="manuallyTogglePreviews()"> previews</div>
                <div id="tag-info-cont">
                    <div id="selected-tags"></div>
                    <div id="tag-search-button">
                        <div id="tag-search-button-text" onclick="showTagSearcher(this)" tabindex="0"></div>
                        <div id="tag-filterer">
                            <input id="tag-filter-input" autocomplete="off" placeholder="type a tag name..." oninput="findTags(event)" onkeyup="checkUnfocus(event)">
                            <div id="tag-filter-results" class="nicebars">                               
                            </div>
                        </div>
                    </div>
                </div>

                <select id="sort-by" onchange="reSort(this.value, true, false)">
                    <option value="score">Relevance</option>
                    <option value="hits">Popularity</option>
                    <option value="new">Newest</option>
                    <option value="old">Oldest</option>
                </select>
        </div>

       
        <div id="results">

        </div>
        <div id="space">
            <div id="load_more" style = "display:none" onclick = "attachPending(20)">
                <h2>Load More <span id="pending_count"></span></h2>
            </div>
        </div>
    </div>
</div>
<dialog id="imageDialog">
    <img src="" id="fullSizeImage" alt="Full Size Display">
    <button onclick="document.getElementById('imageDialog').close();">Close</button>
</dialog>
<div id="templates" style="display:none;">
    <div class="disk-use-bar">
        <div class="total-disk">
            <div class="used-disk">
                <div class="texted-light"> %disk used</div>
            </div>
            <div class="texted-dark">%disk used</div>
            <!--<div id="free-disk">

            </div>-->
        </div>
    </div>
     <div class = "row"></div>
     <span class = "word-feature tooltip-container">
        <span class = "lexeme"></span>
        <div class="tooltip fingerprint-info">
            <div class="fingerprint-percentile"> Percentile: <span class="percentile-val"></span></div>
            <div class="fingerprint-global-ndocs"> Expected to appear in: <span class="post-appearances-expected-val"></span> posts</div>
            <div class="fingerprint-global-ndocs"> Actually appears in: <span class="post-appearances-actual-val"></span> posts</div>
            <div class="fingerprint-global-ndocs"> Expected total uses: <span class="post-nentry-expected-val"></span></div>
            <div class="fingerprint-global-ndocs"> Actual total uses: <span class="post-nentry-actual-val"></span></div>
        </div>
     </span>
    <div class="img-container">
        <img class = "post-image" loading="lazy">
        <!-- using onerror instead of onload because if I were a browser developer I would be less lazy with lazy loading for cached image-->
        <!--<img src="https://nowhhherrre.invalidurl.placeholder.jpg" loading="lazy" onerror="loadActualImage(this)">-->
        <div class="img-caption"></div>
    </div>
    <span class="text-container">
    </span>
    <div class="link-container">
        <a>
            <div class="poster basic-head"><h2></h2></div>
            <span class="link-description"> </span>
        </a>
    </div>
    <div class="tag-autocomplete">
        <div class="tag-text"></div>
        <!--<button class="tag-include" onclick="addInclude(this)">+</button>-->
        <button class="tag-disclude" onclick="addConstraint(this)">&#10983;</button>
        <div class="tag-usecount"></div>
    </div>
    <div class="tag-selected">
        <div class="tag-text"></div>
        <div class="tag-text-tip"></div>
        <button class="tag-remove" onclick="removeSelectedTag(this)"><div class="x-symbol">+</div></button>
    </div>
    <div class="subpost">
        <div class="user-header"><img class="blog-icon" loading="lazy"/></span><span class="blog-name"></span></div>
        <div class="post-content"></div>
    </div>
    <div class="ask-box">
       <img class="blog-icon" loading="lazy"/>
        <div class="ask-content">
            <a class="name-container"></a>
            <div class="ask-text-container"></div>
        </div>
    </div>
    <div class="result textured-background">
    
        <div class="result-siikr noise-blur nicebars">
            
            <div class="result-trail"></div>
            <div class="result-self"></div>
            <div class="result-tags"></div>
            <!--<div class="inline-go" onclick="showInlineFromDate(this)">
                <div class="frame-container">
                    <iframe></iframe>
                </div>
            </div>-->

        </div>
        <div class="result-preview nicebars"></div>
        <div class="nav-container">
            <div class="nav-container-content">
                <div class="toggle-this-preview" onclick="toggleThisPreview(this)">
                👁️‍🗨️
                </div>
                <div class="external-go">
                    <a>&#128279;</a>
                </div>
                <div class="from-date">
                    <a><img class="calendar-icon" src="images/calendar-icon.svg"></img>
                        <div class="result-date"></div>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
<dialog id="imageDialog">
    <img src="" id="fullSizeImage" alt="Full Size Display">
    <button onclick="document.getElementById('imageDialog').close();">Close</button>
</dialog>
<span id="nodelist-container"></span>
<!--<svg xmlns="http://www.w3.org/2000/svg" version="1.1" width="0" height="0">
    <defs>
        <filter id="noise-blur-filter">
        <feTurbulence type="fractalNoise" baseFrequency="0.5" numOctaves="1" result="turbulence" seed="4"></feTurbulence>
            <feDisplacementMap in2="turbulence" in="SourceGraphic" scale="2.2" xChannelSelector="R" yChannelSelector="G" result="displaced"></feDisplacementMap>
            <feGaussianBlur in="displaced" stdDeviation="0.5" result="blurred"></feGaussianBlur>
            <feComposite operator="over" in="displaced" in2="blurred" result="penultimate"></feComposite>
            <feTurbulence type="fractal" baseFrequency="0.0005" numOctaves="1" result="turbulence2" seed="5"></feTurbulence>
            <feDisplacementMap in2="turbulence2" in="penultimate" scale="8" xChannelSelector="R" yChannelSelector="G"></feDisplacementMap>
        </filter>
    </defs>
</svg>-->


<script src="js/pseudosocket/PseudoSocket.js?v=<?php echo $scriptVer; ?> "></script>
<script>
    function showImage(img) {
        const fullImageUrl = img.getAttribute('data-image-id');
        const dialog = document.getElementById('imageDialog');
        const fullSizeImage = document.getElementById('fullSizeImage');
        fullSizeImage.src = fullImageUrl; // Set the source for the dialog image
        dialog.showModal(); // Show the dialog
    }
    let diskUseBase = document.querySelector(".disk-use-bar");
    let diskUseContainer = document.querySelector("#nodelist-container");
    var nodesByURL = {};

    function initDiskUseBars(node_info) {
        let nodeListCont = document.getElementById("nodelist-container");
        
        node_info.forEach(n => {
            n.free_space_mb = parseInt(n.free_space_mb);
            nodesByURL[n.node_url] = n;
            if(n.diskUseBar == null) {
                n.diskUseBar = diskUseBase.cloneNode(true);
                diskUseContainer.appendChild(n.diskUseBar);
                n.diskUseBar.forNode = n;
                if(n.down_for_maintenance) 
                    n.diskUseBar.classList.add("down_for_maintenance");                
                
            }
            let node_ratio = (1-(n.free_space_mb / myTotal)) * 100;
            updateDiskUseBar(null, n.diskUseBar); 
        });
    }

    function setNodeHints() {
        let archivingNode = nodesByURL['https://'+document.archiving_server_url];
        let searchingNode = nodesByURL['https://'+document.searched_server_url];
        archivingNode?.diskUseBar.classList.add("is_archiving");
        searchingNode?.diskUseBar.classList.add("was_search_provider");
    }

    function updateDiskUseBar(usedPercent, bar) {
        let freeTotal = 0;
        let node = bar.forNode;
        node_list.forEach(n => {
            n.free_space_mb = parseFloat(n.free_space_mb);       
            freeTotal += n.free_space_mb;           
        });

        usedPercent = parseInt(100*(1-(node.free_space_mb/freeTotal)));
        let diskuseelem = bar;//document.getElementById("disk-use");
        let diskString = node.node_name ?? "Node: "+node.node_id;//parseInt(usedPercent)+"% of diskspace used";
        let lightText = bar.querySelector(".texted-light");
        let darkText = bar.querySelector(".texted-dark");
        if(usedPercent > 97) {
            diskuseelem.style.height = '2.5em';
            diskuseelem.style.width = 'auto';
            lightText.style.width = 'auto';
            darkText.style.width = 'auto';
            diskString = node.node_name ?? "Node: "+node.node_id;//"<b>Disk Status:</b> Everything's fucked. How could <a href='https://tumblr.com/antinegationism'>antinegationism</a> let this happen?";
        }
        else if(usedPercent > 95) {
            diskuseelem.style.height = '2.5em';
            diskuseelem.style.width = 'auto';
            lightText.style.width = 'auto';
            darkText.style.width = 'auto';
            diskString = node.node_name ?? "Node: "+node.node_id;//"<b>Disk Status:</b> Freakishly low, call <a href='https://tumblr.com/antinegationism'>antinegationism</a>.";
        }
        else if(usedPercent > 90) {
            diskuseelem.style.width = 'auto';
            diskString = node.node_name ??"<b>Disk "+node.node_id+" Status:</b> Getting kinda low.";
        }
        if(usedPercent <= 80) {
            diskuseelem.style.width = 'auto';
            diskString = node.node_name ??"<b>Node "+node.node_id+" Status:</b> Everything's fine."
        }

        lightText.innerHTML = diskString;
        darkText.innerHTML = diskString;//parseInt(usedPercent)+"% of diskspace used";

        var diskhue = (125-(usedPercent*1.25));
        var x= (usedPercent-70)/30;
        var disklight = (((x-0.5)*(x-0.5)))+0.5;
        disklight = x*100;
        let diskred = x;
        let diskgreen = 1-x;
        let norm = Math.sqrt((diskred*diskred) + (diskgreen*diskgreen));
        diskred = parseInt(225*diskred/norm);
        diskgreen = parseInt(225*diskgreen/norm);

        //let freeElem = bar.querySelector(".free-disk");
        let usedDisk = bar.querySelector(".used-disk");
        usedDisk.style.width = usedPercent +'%';
        usedDisk.style.backgroundColor = 'rgb('+diskred+','+diskgreen+','+0+','+0.6+')';

        /*lightText.textContent = parseInt(usedPercent)+"% of diskspace used";
        darkText.textContent = parseInt(usedPercent)+"% of diskspace used";*/


        document.documentElement.style.setProperty('--disk-percent', usedPercent+'%');
        document.documentElement.style.setProperty('--disk-light', disklight+'%');
        document.documentElement.style.setProperty('--disk-hue', diskhue);
        document.documentElement.style.setProperty('--disk-r', diskred);
        document.documentElement.style.setProperty('--disk-g', diskgreen);
    }
    var lightText = document.getElementById("texted-light");
    var darkText = document.getElementById("texted-dark");
    var myTotal = <?php echo $total_diskspace; ?>;
    var myFree = <?php echo $free_space; ?>;
    var usedPercent = <?php echo $used_percent; ?>;
    
    //updateDiskUseBar(usedPercent);
</script>
<script>
    /*window.setInterval(()=>{
        usedPercent += 0.1;
        usedPercent = usedPercent % 100;
        updateDiskUseBar(usedPercent);

    }, 25);*/
    var scriptVersion = <?php echo $scriptVer; ?>;
    <?php echo $injectable?>
    var alwaysPrepend = "";
    if(node_list == null || node_list.length == 0) {
        var node_list = [{free_space_mb: myFree}];
    }
    initDiskUseBars(node_list);
    
</script>
<script src="js/wordcloud.js?v=<?php echo $scriptVer; ?> "></script>
<script src="js/modified.js?v3"></script>
<script src="js/flippy.js?v=<?php echo $scriptVer; ?> "></script>
<script src="js/siikr.js?v=<?php echo $scriptVer; ?> "></script>
<?php if(isset($_GET['dev'])) { ?>
    <script>
        alwaysPrepend = "dev=<?php echo $_GET['dev']?>";
        subdir = "<?php echo $_GET['dev']?>/";
    </script>
<?php } ?>

<script src="js/siikr-tags.js?v=<?php echo $scriptVer; ?> "></script>
</body>
</html>
