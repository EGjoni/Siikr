var href = document.location.href; 
var currentResultsById = {};
var currentResults = [];
var searchParams = {sp_over_all : "3"};
var sortMode = null;
document.addEventListener("DOMContentLoaded", () => {
	window.addEventListener('message', function(event) {
		if (event.origin !== 'https://embed.tumblr.com') return; 
		const iframes = document.querySelectorAll('iframe[src*="tumblr.com"][data-hasheight="false"]'); //fast but risky
		var found = false;
		for (let iframe of iframes) {
			if (iframe.contentWindow === event.source) {
				var dat = JSON.parse(event.data);
				if(dat.method == "tumblr-post:sizeChange") {
					iframe.setAttribute("height", dat.args[0]+"px");
					iframe.setAttribute("data-hasheight", "true");
					found = true; 
				}
				break;
			}
		}
		if(!found) { 
			const iframes = document.querySelectorAll('iframe[src*="tumblr.com"]'); //safe but slow
			/**
				manually iterate through all iframes if we got a secondary sizechange request for som reason
			*/
			for (let iframe of iframes) { 
				if (iframe.contentWindow === event.source) {
					var dat = JSON.parse(event.data);
					if(dat.method == "tumblr-post:sizeChange") {
						iframe.setAttribute("height", dat.args[0]+"px");
						iframe.setAttribute("data-hasheight", "true");
					}
					break;
				}
			}
		}
	});
	selectedTagContainer = document.getElementById("selected-tags"); 
	progressContainer = document.getElementById("progress-container");
	statusBar = document.getElementById("progress");
	statusText = document.getElementById("status-text"); 
	statusText.addEventListener("transitionend", setPendingStatusText);
	progressBar = document.getElementById("progress");
	progressText = document.getElementById("progress-text");
	
	usernameField = document.getElementById("username");
	queryField = document.getElementById("query");
	tagInfoCont = document.getElementById("tag-info-cont");
	tagButton = tagInfoCont.querySelector("#tag-search-button");
	tagButtonText = tagInfoCont.querySelector("#tag-search-button-text");
	resultContainer = document.getElementById("results");
	templates = document.getElementById("templates");
	templateResult = templates.querySelector(".result");
   

	progressListener = getOrAddServerListenerFor(progressContainer);
	sortBy= document.getElementById("sort-by");

	splitURL();
	window.onpopstate = (e) => {
		if(e.state) {
			href = document.location.href; 
			splitURL();
		}
	}


	/*window.addEventListener('message', function (e) {
		//try {
			var parsed = JSON.parse(e.data);
			if(parsed.src == "tumblr-parent-iframe") {
				var iframeURL = parsed.url;
				href = iframeURL;
				splitURL();
			}
		//} catch(e) {}
	});*/
});

function isSubmit(event) {
	return (event.code == "Enter");
}

function seekIfSubmit(event) {
	if(isSubmit(event)) preSeek();
}

function checkBlogExistence(event) {
	if(isSubmit(event)) 
		preSeek(); 
	else {
		//fadeStatusTextTo("lol, that's not even a real name.")
	}
}

function preSeek() {
	if(queryField.value == null || queryField.value.trim()== "") {
			fadeStatusTextTo("You have requested nothing. And I have found it.")
	} else {
			seek();
	}
}

function manuallyTogglePreviews() {
	resultContainer.classList.toggle("active-preview");
}
function clearSelectedTags() {
	selectedTags = {};
}
function clearBlogTags() {
	blogTags = {};
	var alltagnodes = document.querySelectorAll("#tag-filter-results .tag-autocomplete");
	alltagnodes.forEach(tg => tg.remove());
}
var prevUsername = null;
var prevQuery = null;
var prevSortBy = null;

var background = document.querySelector("body");
background.addEventListener('animationend', () => {
	background.classList.remove('flashEffect');
});
  
const epilepsy = () => {
	background.classList.remove('flashEffect');
setTimeout(() => {
	background.classList.add('flashEffect');
}, 150);
};
/**
 * if doAugmentFilp is false, then the returned results will not be added to the dom upon animated
 */
async function seek(doAugmentFlip = true, updateURL = true) {
	augmentExisting = true;
	prevUsername = username; 
	prevQuery = query; 
	username = usernameField.value;
	query = queryField.value;
	sortMode = sortBy.value;
	
	if(prevQuery != query) {
		augmentExisting = false;
	}
	if(prevUsername != username) {
		clearSelectedTags();
		clearBlogTags(); 
		augmentExisting = false;
	}
		
	lastArchivedStatus = 0;
	lastTotalStatus = "[computing]";
	//progressBar.classList.remove("progress-trans");
	progressBar.style.width = "0px"
	statusText.justSearched = true; 
	//progressBar.classList.add("progress-trans");
	if (username.length == 0) {
		fadeStatusTextTo(`Whose blog am I supposed to search? How am I supposed to know? 
			"Oh man, I can't do anything right. I'm such a failure. Now Google 
			"will never ask me to prom.`);
	} else {
		if(!augmentExisting) {
			clearSearchResults();
		}
		var reqString = "";
		Object.keys(searchParams).forEach(key => reqString += "&"+key+"="+searchParams[key]);
		var selectedTags = getCurrentlySelectedTags();
		fadeStatusTextTo(`Searching...`);
		var sres = await fetch("search.php?username="+username+"&query="+query+"&sortMode="+sortBy.value+reqString+"&tags="+JSON.stringify(selectedTags));
		if(updateURL) {
			updateCurrentUrl(selectedTags);
		}
		var blogInfo = await sres.json();		
		if(blogInfo.valid) {
			blog_uuid = blogInfo.blog_uuid;
			search_id = blogInfo.search_id;
			setTags(blogInfo.tag_list);
			associatePostTags(blogInfo.results);
			augmentSearchResults(blogInfo.results, doAugmentFlip, getCurrentlySelectedTags());
			fadeStatusTextTo(currentResults.length +` posts found!`);
			progressListener.removeListener("indexconclude");
			progressListener.removeListener("indexpostupdate");
			progressListener.removeListener("indextagupdate");
			progressListener.setListener("indexconclude",
				"FINISHEDINDEXING!"+search_id, {},
				concludeIndexState
			);
			progressListener.setListener("indexpostupdate", 
				"INDEXEDPOST!"+search_id, {},
				updateIndexState
			);
			progressListener.setListener("indextagupdate", 
				"INDEXEDTAG!"+search_id, {},
				updateAvailableTags);
		} else {
			fadeStatusTextTo(blogInfo.display_error);
		}
	}
}



function associatePostTags(posts) {
	for(var i=0; i<posts.length; i++) {
		try{
			posts[i].tag_ids = JSON.parse(posts[i].tags);
			posts[i].tags = JSON.parse(posts[i].tags);
		} catch(e){}
		for(var k of Object.keys(posts[i].tag_ids)) {
			posts[i].tags[k] = blogTags[posts[i].tag_ids[k]];
		}
	}

}

async function __reSort(elem, updateURL=true, doSeek = true) {
	sortMode = elem.value; 
	
	var elems = []; 
	var addedElems = {};
	if(doSeek) 
		await seek(false, updateURL); 
	for(var i=0; i<currentResults.length; i++) {
		elems.push(currentResults[i].element);
		addedElems[currentResults[i].post_id] = currentResults[i];
	}
	for(var i=0; i<resultContainer.children.length; i++) {
		if(addedElems[resultContainer.children[i].result.post_id] == null)
			elems.push(resultContainer.children[i]);
	}
	histSort(currentResults, sortBy.value);
	var selectedTags = getCurrentlySelectedTags();
	await flip(elems, 
		()=>{
			for(var i=0; i<currentResults.length; i++) { 
				currentResults[i].element.remove();
			}
			for(var i=0; i<currentResults.length; i++) { 
				updateConstraints(currentResults[i].element, selectedTags);
				resultContainer.appendChild(currentResults[i].element);				
			}
		},
		{
			duration: 600,                    // the length of the animation in milliseconds
			ease: "ease",                     // the CSS timing function used for the animation
			animatingClass: "flip-animating", // a class added to elements when they are animated
			scalingClass: "flip-scaling",     // a class added to elements when they are scaled
			callback: null                    // a function to call when the animation is finished
		}		
	);
}

/**
 * Keeps the currentResults but executes an additional query 
 * to augment them with, and then reSorts the results with a flip animation
 */
 async function reSort(elem, updateURL=true, doSeek=true) {
	prevSortBy = sortMode;
	await __reSort(elem, updateURL, doSeek);
}

/**sorts the result according to the specified sortMode.
 * Upon sorting, assigns an appearanceIndex to each result corresponding
 * to its current position in the array (and presumably in the document). 
 * prior to sorting, assigns the any current appearanceIndex of each result
 * to a "prevIndex" variable.
 */
function histSort(toSort, sortMode) {
	for(var i=0; i<toSort.length; i++) {
		toSort[i].prevIndex = toSort[i].appearanceIndex;
	}
	toSort.sort((a, b)=>{
		if(sortMode == "score")
			return b.score - a.score;
		if(sortMode == "new")
			return Date.parse(b.post_date) - Date.parse(a.post_date);
		else 
			return Date.parse(a.post_date) - Date.parse(b.post_date);
	});
	for(var i=0; i<toSort.length; i++) {
		toSort[i].appearanceIndex = i;
	}
}



function updateAvailableTags(serverEvent) {
	var eventMessage = serverEvent.eventMessage;
	addTag(eventMessage.newTag.tag_id, eventMessage.newTag.tagtext, eventMessage.newTag.user_usecount, true);
	//console.log("new tag found: " + eventMessage.newTag.tagtext);
}

function updateIndexState(serverEvent) {
	var eventMessage = serverEvent.eventMessage;
	statusText.justSearched = false; 
	var progressString = "I'm still indexing your blog: "
					+  eventMessage.indexed_post_count + " out of " + eventMessage.serverside_posts_reported + 
					` posts indexed so far. 
					</br> In the meantime, I'll show you any results I come across below. </br>`;
	if(statusText.justSearched) {
		statusText.justSearched = false;
		fadeStatusTextTo(progressString);
	} else {
		statusText.pendingText = progressString;
		statusText.innerHTML = progressString;
	}
	if(eventMessage.as_search_result != null) {
		augmentSearchResults(eventMessage.as_search_result);
	}
	progressText.innerHTML = progressString; 
	progressBar.style.width = (100*(eventMessage.indexed_post_count)/eventMessage.serverside_posts_reported) + " %";
	//console.log("indexed post : " + eventMessage.indexed +"/"+eventMessage.server_total + " ----- " + eventMessage.post_id);
}

function concludeIndexState(serverEvent) {
	var eventMessage = serverEvent.eventMessage;
	if(eventMessage.indexed_this_time > 0) {
		fadeStatusTextTo(`All done! `+currentResults.length+` posts found, and ` +eventMessage.indexed_this_time+` new posts indexed!`);
	}
 	//console.log("indexing finished!");
}


/**clears the existing search result list*/
function clearSearchResults() {
	currentResults = [];
	currentResultsById = {};
	resultContainer.innerHTML = '';
}


/**
 * adds the results in the resultList to the existing results
 */
async function augmentSearchResults(resultList, doAugmentFlip = true, currentlySelectedTags = getCurrentlySelectedTags()) {
	var toReSort = []; //contains only new results;
	var toReplace = []; //contains only new results;  
	for(var i=0; i<resultList.length; i++) {
		var result = resultList[i]; 
		if(currentResultsById[result.post_id] == null) {
			currentResultsById[result.post_id] = result; 
			var resultElement = templateResult.cloneNode(true);
			result.element = resultElement;
			hydrateResultElement(result, resultElement); 
			updateConstraints(resultElement, currentlySelectedTags);
			currentResults.push(result);
			toReSort.push(result); 
		} else if (currentResultsById[result.post_id].score != resultList[i].score) {
			currentResultsById[result.post_id].score = resultList[i].score;
			toReplace.push(currentResultsById[result.post_id]);
		}
	}
	if(toReplace.length>0) {
		epilepsy();
	}
	if((toReSort.length > 0 || toReplace.length) > 0 && doAugmentFlip) {
		await replaceAndResort(toReSort, toReplace);
	}
}


async function replaceAndResort(toReSort, toReplace) {
	histSort(currentResults, sortBy.value);
	var onElems = [...new Set(resultContainer.children)];
	for(var i=0; i < toReSort.length; i++) {
		onElems.push(toReSort[i].element);
	}
	await flip(
		onElems,
		()=>{
			for(var i =0; i<toReplace.length; i++) {
				toReplace[i].element.remove();
				toReSort.push(toReplace[i]);
			}
			toReSort.sort((a,b) => a.appearanceIndex - b.appearanceIndex);
			for(var i =0; i < toReSort.length; i++) {
				var res = toReSort[i];
				//if(res.appearanceIndex == currentResults.length-1 || currentResults[res.appearanceIndex+1].element.parentElement == null) {
					resultContainer.appendChild(res.element);						
				//} else {
				//	var cur = currentResults[res.appearanceIndex+1];
				//	resultContainer.insertBefore(res.element, cur.element);
				//}
			}
		},
		{
			duration: 400,                    // the length of the animation in milliseconds
			ease: "ease",                     // the CSS timing function used for the animation
			animatingClass: "flip-animating", // a class added to elements when they are animated
			scalingClass: "flip-scaling",     // a class added to elements when they are scaled
			callback: null                    // a function to call when the animation is finished
		}
	);
}

/**
 * returns an html node containing text content, recursively account for any reblog codes.
 * @param {String} body 
 */
 function reblogSplit(body, insertContents = null) {
	var splitbody = body.split('[skrtgrblgnd]');
	var result = document.createElement("span"); 

	if(splitbody.length > 1) {
		result.classList.add("reblog-block");
		result.innerText = splitbody[0];
		var outerText = splitbody.slice(1).join("[skrtgrblgnd]");
		if(insertContents != null) {
			result.insertBefore(insertContents, result.firstChild);
		}
		var containedBy = reblogSplit(outerText, result);
		//containedBy.querySelector(".reblog-block").insertBefore(result, containedBy.firstChild); 
		result = containedBy; 
	} else {
		result.innerText = body; 
		if(insertContents != null) {
			result.insertBefore(insertContents, result.firstChild);
		}
	}
	
	return result;
}


function hydrateResultElement(data, element) {
	element.querySelector(".result-title").innerText = data.title;
	var rejoined_text = data.trail_text +"[skrtgrblgnd]"+data.self_text; //TODO: Fix this to take advantage of the the fact that stuff gets seperated now
	element.querySelector(".result-body").innerHTML = reblogSplit(rejoined_text).innerHTML;
	element.querySelector(".external-go > a").setAttribute("href", data.post_url);
	var date = new Date(typeof data.post_date == "number"? data.post_date*1000 : data.post_date);
	data.post_date = date.toLocaleString();
	element.querySelector(".from-date > a").setAttribute("href", "http://"+username+".tumblr.com/day/"+date.getFullYear()+"/"+(date.getMonth()+1)+"/"+date.getDate());
	var tagList = element.querySelector(".result-tags");
	for(var k of Object.keys(data.tags)) {
		if(data.tags[k] != undefined) {
			var taglink = document.createElement("a");
			taglink.classList.add("taglink");
			taglink.innerText = "#"+(typeof data.tags[k] == "string"? data.tags[k] : data.tags[k].full);
			tagList.appendChild(taglink); 
		}
	}
	element.result = data;
	var resPrev = element.querySelector(".result-preview");
	tumblrHydrate(resPrev);
	//intersectObserver.observe(resPrev);
	//resPrev.isObserved = true;
}

blogTags = {}; 
usecountSortedTags = [];
/**clears existing tag entries and populates new tags*/
function setTags(tagList) {
	tagButton.classList.remove("displayed");
	blogTags = {};
	usecountSortedTags=[];
	for(var i=0; i<tagList.length; i++) {
		addTag(tagList[i].tag_id, tagList[i].tagtext, tagList[i].user_usecount);
	}
	tagButton.classList.add("displayed");
	tagButtonText.innerText = "+" + Object.keys(blogTags).length + " tags available";
}

/**adds a single tag to the pool of available tags*/
function addTag(tag_id, tagtext, userUsecount, doAnim = false) {
	var tag = {full: tagtext, words: tagtext.split(/[ ,]+/), user_usecount: userUsecount, tag_id: tag_id};
	if(blogTags[tag_id] == null) {
		var inserted = false;
		for(var i=0; i<usecountSortedTags.length; i++) {
			if(parseInt(tag.user_usecount) < parseInt(usecountSortedTags[i].user_usecount)) {
				usecountSortedTags.splice(i, 0, tag);
				inserted = true;
				break;
			}
		}
		if(inserted == false) 
			usecountSortedTags.push(tag);
	}
	blogTags[tag_id] = tag;
	
	for(var i=0; i<blogTags[tag_id]["words"].length; i++) {
		blogTags[tag_id]["words"][i] = blogTags[tag_id]["words"][i].toLowerCase();
	}
	if(doAnim) {
		tagButtonText.innerText = "+" + Object.keys(blogTags).length + " tags available";
	}
}

function splitURL() {
    var urlQuery = decodeURI(href.split("?")[1]);
    var urlSplit = urlQuery.split(/(&?u=|&?s=|&?p=|&?q=|&?tags=)/);
    for(var i=0; i<urlSplit.length; i++){ 
        if(urlSplit[i].search(/(&u|^u)=/) != -1) {
            usernameField.value = urlSplit[i+1];
        } else if(urlSplit[i].search(/(&q|^q)=/) != -1) {
            queryField.value = urlSplit[i+1];
        } 
        else if(urlSplit[i].search(/(&p|^p)=/) != -1) {
            var previewState = urlSplit[i+1];
            if(previewState === "t") { 
                previewToggleState = true;                
            }
        }
		else if(urlSplit[i].search(/(&s|^s)=/) != -1) { 
			prevSortBy = sortMode;
			sortBy.value = urlSplit[i+1];
		} else if(urlSplit[i].indexOf("tags=") !=-1) {
			preSpecifiedTags = JSON.parse(urlSplit[i+1]);
		}
    }
	if(usernameField.value != "" && queryField.value != "") {
		if(prevUsername != null && prevUsername != "" 
		&& prevQuery != null && prevQuery != ""
		&& prevSortBy != null && prevSortBy != sortBy.value) {
			__reSort(sortBy.value, false);
		} else {
			seek(true, false);
		}
	}
}

function updateCurrentUrl(selectedTags = null) {
	var glue = "?";
	var urlPath = "";
	if(usernameField.value != null) {
		urlPath += glue+"u="+usernameField.value;
		glue = "&";
	}
	if(queryField.value != null) {
		urlPath += glue+"q="+queryField.value;
		glue = "&";
	}

	if(sortBy.value != null) {
		urlPath += glue+"s="+sortBy.value;
		glue = "&";
	}

	
	/*if(selectedTags != null) {
		urlPath += glue+"tags="+JSON.stringify(selectedTags);
		glue = "&";
	}*/
	
	window.history.pushState({},"", urlPath);
	window.parent.postMessage(JSON.stringify({src: "siikr", url: urlPath}), '*');
}



function fadeStatusTextTo(text) {
	statusText.pendingText = text;
	statusText.classList.add("fadeout");
}

/**
 * updates the status text after the fadeout animation has ended,
 * then sets the fadein animation
 */
function setPendingStatusText() {
	if(statusText.classList.contains("fadeout")) {
		statusText.innerHTML = statusText.pendingText;
	}
	statusText.classList.remove("fadeout");
}


function toggleThisPreview(elem) {
	elem.closest(".result").classList.toggle("active-preview");
}


/**hydrates the post with fancy tumblr preview*/
async function tumblrHydrate(elem) {
	
	if(elem.post_hydrated != true) { 

		var resultElem = elem.parentNode;
		var data = resultElem.result;
		
		//TODO: this is the correct way to do it but need to reindex everyone's blog to use post_id_string
		var iframeurl = "https://embed.tumblr.com/embed/post/"+blog_uuid+"/"+data.post_id+""; 
		
		//This is a workaround. MUST CHANGE.
		//var url_usrn_postid = data.post_url.split(".tumblr.com/post/");
		//var iframeurl = "https://embed.tumblr.com/embed/post/"+username+"/"+url_usrn_postid[1].split("/")[0]+""; 
		
		var iframe = document.createElement("iframe"); 
		iframe.setAttribute("src", iframeurl);
		iframe.setAttribute("allow", "fullscreen");
		iframe.setAttribute("credentialless", "true");
		iframe.setAttribute("data-hasheight", "false");
		iframe.setAttribute("loading", "lazy");
		elem.appendChild(iframe);
		//addToHydrationQueue(elem);
		//var pin = elem.querySelector(".tumblr-post");
		//pin.classList.add("pendingLoad");
		//doTheThing(getFuncArray()); 
		//elem.querySelector(".tumblr-post").classList.remove("pendingLoad");
		//var
		elem.post_hydrated = true; 
	}
}

const wait = ms => new Promise(resolve => setTimeout(resolve, ms));

var loadingSet = {};
var awaitingQueue = [];

/**
 * limit concurrent preview loading to a maximum. Make any other elements that are attempting 
 * to load their preview wait until there is room in the queue or they are visible. 
 */
function addToHydrationQueue(elem) {

	var doHydrate = async (elem) => {
		var resultElem = elem.parentNode;
		var data = resultElem.result;
		var post_url = data.post_url;	
		var sres = await fetch("getIframe.php?post_id="+data.post_id+"&blog_uuid="+blog_uuid);//$post_url"
		var tumeblrData = await sres.json();
		var tumblhtml = tumeblrData.html;
		var tempelem = document.createElement("div");
		var iframeurl = "https://embed.tumblr.com/embed/post/"+blog_uuid+"/"+data.post_id+"";
		var iframe = document.createElement("iframe"); 
		iframe.setAttribute("src", iframeurl);
		iframe.setAttribute("allow", "fullscreen");
		iframe.setAttribute("credentialless", "true");
		iframe.setAttribute("data-hasheight", "false");
		iframe.setAttribute("loading", "lazy");
		elem.appendChild(iframe);

		/*
		observer = new MutationObserver((mutationsList, observer) => {
			for(const mutation of mutationsList) {
				if (mutation.type === 'childList') { 
					for(const added of mutation.addedNodes) {
						if(added.tagName == "IFRAME") {
							previewLoadedObserver.observe(added, {attributes: true, childList: true, subtree: true});
							//break;
						}
					}
				}
			}
		});
		var previewLoadedObserver = new MutationObserver((mutationsList, observer) => {
			for(const mutation of mutationsList) {
				if(mutation.type == "attributes") {
					var width = mutation.target.getAttribute("width");
					var height = mutation.target.getAttribute("height");
					if((width != null && width != "" && parseInt(width.split("px")[0]) > 0) 
					|| (height != null && height != "" && parseInt(height.split("px")[0]) > 0)) {
						deferrer.resolve();
					}
				}
			}
		});
		iframeAddedObserver.observe(elem, {attributes: true, childList: true, subtree: true});
		await deferrer;
		*/
		return true;
	};

	/*returns true if hydration was initated, false otherwise*/
	var hydrateIfAcceptable = async (elem) => {		

		var enqueued = Object.keys(loadingSet).length;
		if( enqueued < 3 /*|| isVisible(elem, window)*/)  {
			loadingSet[elem.parentElement.result.post_id] = true;
			var result = await doHydrate(elem);
			console.log(result);
			//window.clearInterval(elem.hydrateIntervalId);
			delete loadingSet[elem.parentElement.result.post_id];
			var loadNext = awaitingQueue.pop(); 
			if(loadNext != null) {
				hydrateIfAcceptable(loadNext);
			}
		} else {
			awaitingQueue.push(elem);
		}
	}
	hydrateIfAcceptable(elem);
}


function isVisible(ele, container) {
    const { bottom, height, top } = ele.getBoundingClientRect();
    const containerRect = container.getBoundingClientRect();

    return top <= containerRect.top ? containerRect.top - top <= height : bottom - containerRect.bottom <= height;
};

  
/*var intersectObserver = new IntersectionObserver((entries, observer) => {
	entries.forEach(entry => {
		if(entry.intersectionRatio > 0.0) {
			tumblrHydrate(entry.target);
		}
	});
}, 
{
root: null,
rootMargin: '50%',
threshold: [0.01]
});*/

 


/**
 * defers a promise so it can be resolved externally
 * @returns 
 */
 function defer() {
	var res, rej;

	var promise = new Promise((resolve, reject) => {
		res = resolve;
		rej = reject;
	});

	promise.resolve = res;
	promise.reject = rej;

	return promise;
}