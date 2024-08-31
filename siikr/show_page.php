<?php
$scriptVer=19;
try {
    require_once 'internal/disk_stats.php';
    $used_percent = get_disk_stats();
} catch(Exception $e){

}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="stylesheet" type="text/css" href="css/sikr-css.css?<?php echo $scriptVer;?>">
<!--<style>
        :root {
            --rain-1: 5s;
            --rain-2: 6s;
            --rain-3: 7s;
            --rain-4: 5.5s;
            --rain-5: 6.5s;
            --rain-6: 7.5s;
            --rain-7: 5.8s;
            --rain-8: 6.8s;
            --rain-9: 7.8s;

            --delay-1: 2s;
            --delay-2: 3s;
            --delay-3: 4s;
            --delay-4: 5s;
            --delay-5: 6s;
            --delay-6: 2.5s;
            --delay-7: 3.5s;
            --delay-8: 6.5s;
            --delay-9: 7s;

            --rotation-1: -130deg;
            --rotation-2: -145deg;
            --rotation-3: -160deg;
            --rotation-4: -175deg;
            --rotation-5: 190deg;
            --rotation-6: 105deg;
            --rotation-7: 120deg;
            --rotation-8: 135deg;
            --rotation-9: 150deg;
        }

        @keyframes rain {
            0% {
                opacity: 0;
                transform: translateY(-5%);
            }

            5% {
                opacity: 1;
            }

            75% {
                opacity: 1;
            }

            100% {
                opacity: 0;
                transform: translateY(100vh) rotate(var(--rotate));
            }
        }

        .rain {
            position: fixed;
            display: block;
            width: 100%;
            height: 100%;
            pointer-events: none;
            overflow: hidden;
            z-index: 5;
        }

        .rain span {
            position: absolute;
            font-size: 30px;
            animation: rain linear infinite;
            animation-fill-mode: forwards;
            opacity: 0;
        }

        .rain span:nth-child(9n+1) {
            left: calc(10% * 0);
            animation-duration: var(--rain-1);
            animation-delay: var(--delay-1);
            --rotate: var(--rotation-1);
        }

        .rain span:nth-child(9n+2) {
            left: calc(10% * 1);
            animation-duration: var(--rain-2);
            animation-delay: var(--delay-2);
            --rotate: var(--rotation-2);
        }

        .rain span:nth-child(9n+3) {
            left: calc(10% * 2);
            animation-duration: var(--rain-3);
            animation-delay: var(--delay-3);
            --rotate: var(--rotation-3);
        }

        .rain span:nth-child(9n+4) {
            left: calc(10% * 3);
            animation-duration: var(--rain-4);
            animation-delay: var(--delay-4);
            --rotate: var(--rotation-4);
        }

        .rain span:nth-child(9n+5) {
            left: calc(10% * 4);
            animation-duration: var(--rain-5);
            animation-delay: var(--delay-5);
            --rotate: var(--rotation-5);
        }

        .rain span:nth-child(9n+6) {
            left: calc(10% * 5);
            animation-duration: var(--rain-6);
            animation-delay: var(--delay-6);
            --rotate: var(--rotation-6);
        }

        .rain span:nth-child(9n+7) {
            left: calc(10% * 6);
            animation-duration: var(--rain-7);
            animation-delay: var(--delay-7);
            --rotate: var(--rotation-7);
        }

        .rain span:nth-child(9n+8) {
            left: calc(10% * 7);
            animation-duration: var(--rain-8);
            animation-delay: var(--delay-8);
            --rotate: var(--rotation-8);
        }

        .rain span:nth-child(9n+9) {
            left: calc(10% * 8);
            animation-duration: var(--rain-9);
            animation-delay: var(--delay-9);
            --rotate: var(--rotation-9);
        }
        .rain span:nth-child(3n+1)::before {
            content: "😢";
        }

        .rain span:nth-child(3n+2)::before {
            content: "😭";
        }

        .rain span:nth-child(3n+3)::before {
            content: "🥺";
        }

    </style>-->
    </head>
<body>
<?php //if($squid_game == true) {require_once 'deletion_roulette.php';}?>
    <!--<div class="rain">
        <span></span>
        <span></span>
        <span></span>
        <span></span>
        <span></span>
        <span></span>
        <span></span>
    </div>-->
<div id="all-stuff">
    <div id="support">       
        <details style="color: lightgray" id="help">
            <summary style="
            background-color: 1c3144;
            width: 110px;">Pro Tips:</summary>
            <ul style="font-size: 14">
                <li> If you type in multiple words, siikr will return posts that contain as many of those words as it can find. </li>
                <li> If you surround words with quotation marks, siikr will return only posts that contain ALL of the words in those quotation marks.</li>
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
        <div id="outer-donation" style="display: none"> 
            <details style="color: lightgray;padding-right: 10px;">
                <summary style="
                    background-color: 1c3144;
                    width: 165px;
                    margin-left: 9px;
                    margin-top: 5px;
                    "><div id="donate-title">Donate?</div><div id="sub-blurb" style="
                    max-width: 200px;
                ">Please help with server costs :3</div>
                </summary>
                <div id="donation-box">
                    <div class="donation-tabs">
                        <div class="tab">
                            <input type="radio" id="bitcoin" name="tab-group-1" checked="">
                            <label for="bitcoin" id="bitcoin-tab"></label>
                            
                            <div class="content">
                                <a href="funding/siikr/btc-address.html"><img class="qr" src="funding/siikr/siikr-donations-btc.png"></a>
                            </div> 
                        </div>                    
                        <div class="tab">
                            <input type="radio" id="ethereum" name="tab-group-1">
                            <label for="ethereum" id="ethereum-tab"></label>                    
                            <div class="content">
                                <a href="funding/siikr/ethereum-address.html"><img class="qr" src="funding/siikr/siikr-donations-eth.png"></a>
                            </div> 
                        </div>                
                        <div class="tab">
                                <input type="radio" id="patreon" name="tab-group-1">
                                <label for="patreon" id="patreon-tab"></label>
                        
                            <div class="content">
                                <span><a href="https://www.patreon.com/Eron_G?ty=a" target="_blank">Click here to donate through Patreon!</a></span> 
                            </div> 
                        </div>
                    </div>
                </div>
            </details>
            <!--<a href="tumblr_auth/blog_control_panel.php" target="_blank"><button id="control-panel-button">Privacy Options</button></a>-->
        </div>
    </div>

    
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
                
                <select id="sort-by" onchange="reSort(this)">
                    <option value="score">Relevance</option>
                    <option value="new">Newest</option>
                    <option value="old">Oldest</option>
                </select>
        </div>
        <div id="results">
            
        </div>
        <div id="space"></div>
    </div>
</div>
<div id="templates" style="display:none;">
     <div class = "row"></div>
    <div class="img-container"> 
        <!-- using onerror instead of onload because if I were a browser developer I would be less lazy with lazy loading for cached image-->
        <img src="https://nowhhherrre.invalidurl.placeholder.jpg" loading="lazy" onerror="loadActualImage(this)">
        <div class="img-caption"></div>
    </div>
    <span class="text-container">
    </span>
    <div class="link-container">
        <a>
            <h2></h2>
            <span> </span>
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
        <div class="user-header"></div>
        <div class="post-content"></div>
    </div>
    <div class="ask-box">
        <a class="name-container"></a>
        <div class="ask-content"></div>
    </div>
    <div class="result">
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
                    <a><img class="calendar-icon" src="images/calendar-icon.svg"></img></a>
                </div>        
            </div>
        </div>  
    </div>
</div>
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
<div id="disk-use">
    <div id="total-disk">
        <div id="used-disk">
            <div id="texted-light"> %disk used</div>
        </div>
        <div id="texted-dark">%disk used</div>
        <!--<div id="free-disk">

        </div>-->
    </div>
</div>
<script src="js/pseudosocket/PseudoSocket.js?v=<?php echo $scriptVer;?> "></script>
<script> 

    function updateDiskUseBar(usedPercent) {
        var diskhue = (125-(usedPercent*1.25));
        var x= usedPercent/100;
        var disklight = (((x-0.5)*(x-0.5)))+0.5;
        disklight = x*100;
        let diskred = x;
        let diskgreen = 1-x;
        let norm = Math.sqrt((diskred*diskred) + (diskgreen*diskgreen));
        diskred = parseInt(225*diskred/norm);
        diskgreen = parseInt(225*diskgreen/norm);

        lightText.textContent = parseInt(usedPercent)+"% of diskspace used";
        darkText.textContent = parseInt(usedPercent)+"% of diskspace used";

        document.documentElement.style.setProperty('--disk-percent', usedPercent+'%');
        document.documentElement.style.setProperty('--disk-light', disklight+'%');
        document.documentElement.style.setProperty('--disk-hue', diskhue);
        document.documentElement.style.setProperty('--disk-r', diskred);
        document.documentElement.style.setProperty('--disk-g', diskgreen);
    }
    var lightText = document.getElementById("texted-light");
    var darkText = document.getElementById("texted-dark");
    var usedPercent = <?php echo $used_percent;?>;
    updateDiskUseBar(usedPercent);
    /*window.setInterval(()=>{
        usedPercent += 0.1;
        usedPercent = usedPercent % 100;
        updateDiskUseBar(usedPercent);

    }, 25);*/
    var scriptVersion = <?php echo $scriptVer;?>;
    var baseServerUrlString = 'https://siikr.giftedapprentice.com';
    var pseudoSocket = new PseudoSocket();
    </script>
<script src="js/modified.js?v3"></script>
<script src="js/flippy.js?v=<?php echo $scriptVer;?> "></script>
<script src="js/siikr.js?v=<?php echo $scriptVer;?> "></script>
<script src="js/siikr-tags.js?v=<?php echo $scriptVer;?> "></script>
</body>
</html>
