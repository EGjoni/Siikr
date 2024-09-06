var href = document.location.href; 
var currentResultsById = {};
var currentResults = [];
window.stateHistory = {};
blogInfo = null;
var blog_uuid = null;

var sortMode = null;
var pseudoSocket = null;//new PseudoSocket(server_events_override_url);

function reinitPseudoSocket(newUrl) {
	if(pseudoSocket?.isConnected) {
		pseudoSocket.disconnect();
	}
	pseudoSocket = new PseudoSocket(newUrl);
}


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
	
	statusBar = document.getElementById("progress");
	statusText = document.getElementById("status-text"); 
	statusText.addEventListener("transitionend", setPendingStatusText);
	progressBar = document.getElementById("progress");
	progressText = document.getElementById("progress-text");
	load_more = document.getElementById("load_more");
	
	usernameField = document.getElementById("username");
	queryField = document.getElementById("query");
	tagInfoCont = document.getElementById("tag-info-cont");
	tagButton = tagInfoCont.querySelector("#tag-search-button");
	tagButtonText = tagInfoCont.querySelector("#tag-search-button-text");
	resultContainer = document.getElementById("results");
	templates = document.getElementById("templates");
	templateResult = templates.querySelector(".result");
	subpostTemplate = templates.querySelector(".subpost"); 
	rowTemplate = templates.querySelector(".row");
	askTemplate = templates.querySelector(".ask-box");

	progressContainer = document.getElementById("progress-container");
	noticeContainer = document.getElementById("notice-container");
	noticeLog = noticeContainer.querySelector("#notice-log");

	progressListener = getOrAddServerListenerFor(progressContainer);
	noticeListener = getOrAddServerListenerFor(noticeContainer);

	sortBy= document.getElementById("sort-by");

	splitURL();
	window.onpopstate = (e) => {
		if(e.state) {
			href = document.location.href; 
			splitURL(e.state);
		}
	}

	

	window.addEventListener('message', function (e) {
		try {
			var parsed = JSON.parse(e.data);
			if(parsed.src == "tumblr-parent-iframe") {
				var iframeURL = parsed.url;
				href = document.location.href.split("?")[0]+"?"+decodeURI(iframeURL.split("?")[1]);
				parsed.url = href;
				splitURL(parsed);
			}
		} catch(error) {}
	});
});

function isSubmit(event) {
	return (event.code == "Enter");
}

function seekIfSubmit(event) {
	if(isSubmit(event)) {
		preSeek();
	}
}

function preSeek(doAugmentFlip = true, updateURL = true, isInsuranceCheck=false, limit = 15, offset = 0) {
	augmentExisting = doAugmentFlip;
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

	if(queryField.value == null || queryField.value.trim()== "") {
		clearSearchResults();
		fadeStatusTextTo("You have requested nothing. And I have found it.")
	} else {
		clearSearchResults();
		seek(doAugmentFlip, updateURL, isInsuranceCheck, limit, offset);
	}
}

function manuallyTogglePreviews() {
	resultContainer.classList.toggle("active-preview");
	if(resultContainer.classList.contains("active-preview")) {
		var results = resultContainer.querySelectorAll(".result");
		results.forEach(result=> {tumblrHydrate(result.querySelector(".result-preview"))});
	}
}
function clearSelectedTags() {
	selectedTags = {};
}
function clearBlogTags() {
	blogTags = {};
	var alltagnodes = document.querySelectorAll("#tag-filter-results .tag-autocomplete");
	alltagnodes.forEach(tg => tg.remove());
	setTags([]);
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

async function seek(doAugmentFlip = true, updateURL = true, isInsuranceCheck=false, limit = null, offset = 0) {
	username = usernameField.value;
        query = queryField.value;
        sortMode = sortBy.value;

	lastArchivedStatus = 0;
	previousResultCount = Math.max(currentResults.length, 15);
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
		/*if(!augmentExisting) {
			clearSearchResults();
		}*/
		var reqString = "";
		Object.keys(searchParams).forEach(key => reqString += "&"+key+"="+searchParams[key]);
		if(isInsuranceCheck) reqString += "&isInsuranceCheck=true";
		var selectedTags = Object.keys(getCurrentlySelectedTags());
		/*if(updateURL) {
			updateHistoryState(currentStateToUrl(selectedTags), "push");
		}*/
		fadeStatusTextTo(`Searching...`);
		var sres = await s_fetch(
			subdir+"streamed_search.php?username="+username+
			"&query="+query+
			"&sortMode="+sortBy.value+
			reqString+
			"&tags="+JSON.stringify(selectedTags), 
			resetProgressListeners,
			(obj)=>processSeekResult(obj, doAugmentFlip),
			processMoreResults
		);		
	}
}

async function processSeekResult(blogInfoIn, doAugmentFlip) {
	if(blogInfoIn.valid) {
		blogInfo = blogInfoIn;
		blog_uuid = blogInfo.blog_uuid;
		var tagres = fetch(subdir+"get_tags.php?blog_uuid="+blog_uuid);
		
		pending_attachment_count = 0;
		updatePendingCountHint();
		if(blogInfo.indexed_post_count == 0 || blogInfo.indexed_post_count == null) {
			fadeStatusTextTo("Haven't seen that blog before. Give me a sec to retrieve it. (You should see progress updates in a few seconds. Feel free to refresh the page if you get impatient)");
		} else {
			resetWordCloud(blog_uuid);
			if(blogInfo.has_more) {
				fadeStatusTextTo('More than '+ blogInfo.results.length +` posts found! (From `+blogInfo.indexed_post_count+` posts searched)`);
			} else {
				fadeStatusTextTo(blogInfo.results.length +` posts found! (From `+blogInfo.indexed_post_count+` posts searched)`);
			}
			pendingTags = asyncTagFetch(tagres, blogInfo.results);
			waitForTags = asyncSetTags();
			await augmentSearchResults(blogInfo.results, doAugmentFlip, waitForTags);
			updateHistoryState(currentStateToUrl(selectedTags), blogInfo?.search_id);
			
		}
	} else {
		fadeStatusTextTo(blogInfo.display_error);
	}
}
pending_attachment_count = 0;
async function processMoreResults(obj) {
	
	await augmentSearchResults(obj.more_results, false, pendingTags);
	let progressString = '';
	if(obj.has_more) {
		progressString = 'More than '+ currentResults.length +` posts found! (From `+obj.indexed_post_count+` posts searched)`;
	} else {
		progressString = currentResults.length +` posts found! (From `+obj.indexed_post_count+` posts searched)`;
	}
	statusText.pendingText = progressString;
	statusText.innerHTML = progressString;
	pending_attachment_count += obj.more_results.length;
	updatePendingCountHint();
}

async function resetProgressListeners(obj) {
	search_id = obj.search_id; 
	blogInfo = {search_id : search_id};

	progressListener.removeListener("indexbegin");
	progressListener.removeListener("indexconclude");
	progressListener.removeListener("indexpostupdate");
	progressListener.removeListener("indextagupdate");
	noticeListener.removeListener("noticelistener");
	noticeListener.removeListener("errorlistener");
	reinitPseudoSocket('https://'+obj.server+"/routing/serverEvents.php");		
	
	progressListener.setListener("indexbegin",
		"INDEXNG!"+search_id, {
			'queryFunction' : 'findBestKnownNode',
			'params' : {"search_id" : search_id, "blog_uuid" : obj.blog_uuid}
		},
		()=>{
			console.log("ARCHIVER STARTED");
		}
	);
	progressListener.setListener("indexconclude",
		"FINISHEDINDEXING!"+search_id,{
			'queryFunction' : 'findBestKnownNode',
			'params' : {"search_id" : search_id, "blog_uuid" : obj.blog_uuid}
		},
		concludeIndexState
	);
	progressListener.setListener("indexpostupdate", 
		"INDEXEDPOST!"+search_id, {
			'queryFunction' : 'findBestKnownNode',
			'params' : {"search_id" : search_id, "blog_uuid" : obj.blog_uuid}
		},
		updateIndexState
	);
	progressListener.setListener("indextagupdate", 
		"INDEXEDTAG!"+search_id, {
			'queryFunction' : 'findBestKnownNode',
			'params' : {"search_id" : search_id, "blog_uuid" : obj.blog_uuid}
		},
		updateAvailableTags
	);
	noticeListener.setListener("noticelistener", "NOTICE!"+search_id, {
		'queryFunction' : 'findBestKnownNode',
		'params' : {"search_id" : search_id, "blog_uuid" : obj.blog_uuid}
	}, updateNoticeText);
	noticeListener.setListener("errorlistener", "ERROR!"+search_id, {
		'queryFunction' : 'findBestKnownNode',
		'params' : {"search_id" : search_id, "blog_uuid" : obj.blog_uuid}
	}, updateErrorText);
}

/**maintains a leader stream fetcher object. leader gets replaced by any new calls to the s_fetch(), and an old streaming fetchers halt their progress if the aren't the leader. 
* this is so that multiple stream results don't interfere with one another.
*/
class SFetch {
	static activeFetcher = null;
	constructor(endpoint, on_search_id, on_initial_results, on_more_results) {
		return this.do_streamed_fetch(endpoint, on_search_id, on_initial_results, on_more_results);
	}

	async do_streamed_fetch(endpoint, on_search_id, on_initial_results, on_more_results) {
		SFetch.activeFetcher = this;
		const response = await fetch(endpoint);
	    const reader = response.body.getReader();
	    const decoder = new TextDecoder('utf-8');
		const delimiter = '#end_of_object#';
		let accumulated_string = '';
		let lastValid = Date.now();
		let lastDelim = Date.now();
		let debug_res = [];
	    while (true) {
	        const { done, value } = await reader.read();
	        if(SFetch.activeFetcher != this) {
				//console.log('STREAM TERMINATED');
				break;
			}
	
	        const chunk = decoder.decode(value, { stream: true });
			accumulated_string += chunk;
			//console.log('chunk');
			if(accumulated_string.lastIndexOf(delimiter) != -1) {
				let objects = accumulated_string.split(delimiter);			
				let hadValid = false;
				for(let objstr of objects) {	
					try {
						let obj = null;
						try{
							obj = JSON.parse(objstr); 
						} catch(e) {
							e.jparseError = true;
							throw e;
						}
						//console.log("object received, query_exec_time: "+obj.execution_time+"s");					
						if(obj.valid) {
							if(obj.blog_uuid != null && obj.results != null) {
								//console.log("+ "+obj.results.length + ",    last_id = "+obj.results[obj.results.length-1].post_id);
								//debug_res.push(...obj.results);
								await on_initial_results(obj);
							}
							else if(obj.search_id !=null && obj.is_init == true) 
								await on_search_id(obj);
							else if (obj.more_results != null && obj.more_results.length > 0) {
								//console.log("+ "+obj.more_results.length + ",    last_id = "+obj.more_results[obj.more_results.length-1].post_id);
								//debug_res.push(...obj.more_results);
								await on_more_results(obj);
							}
						} else {
							fadeStatusTextTo(obj.display_error);
						}
					} catch (e) {
						if(!e.jparseError) {
							throw e;
						}
						//console.log("reading: "+chunk.length);//(Date.now()-lastDelim)/1000.0+"s");					
					}
				}
				accumulated_string = objects[objects.length-1];
				if(hadValid) lastValid = Date.now();
				lastDelim = Date.now();
			}
			if (done) break;
		}
		//console.log("GOT "+ debug_res.length + " POSTS");
	}
	
}

async function s_fetch(endpoint, on_search_id, on_initial_results, on_more_results) {
	return new SFetch(endpoint, on_search_id, on_initial_results, on_more_results);
}


async function asyncTagFetch(tagFetch) {
	var tres = await tagFetch;
	var tblog_info = await tres.json();
	return tblog_info;
	//associatePostTags(posts, getCurrentlySelectedTags());
}

async function asyncSetTags() {
	let ptags = await pendingTags;
	setTags(ptags.tag_list);
}

async function associatePostTags(posts) {
	for(var i=0; i<posts.length; i++) {
		keyifyTagObj(posts[i]);
	}
	for(var i=0; i< posts.length; i++) {
		if(posts[i].element != null)
			hydratePostTags(posts[i].element);
	}
}

function keyifyTagObj(post) {
	
	if(typeof post?.tags == "string") {
		post.tag_ids = JSON.parse(post.tags);
		post.tags = {};
	} else {
		if(Array.isArray(post.tags)) {
			post.tag_ids = post.tags;
		} else if(!Array.isArray(post.tag_ids)) {
			post.tag_ids = [];
		}
		post.tags = {};
	}
	
	for(var k of Object.keys(post.tag_ids)) {
		if(post.tags == null) post.tags = {};
		post.tags[post.tag_ids[k]] = blogTags[post.tag_ids[k]];
	}
}

async function __reSort(sortMode, updateURL=true, doSeek = true, isInsuranceCheck=false) {
	var elems = new Set(); 
	var addedElems = new Set();
	var removedElems = new Set();
	var existingElemsArr =  [...resultContainer.children];
	var existingElems = new Set(existingElemsArr);
	if(doSeek) 
		await seek(false, updateURL, isInsuranceCheck);
	else if(updateURL) {
		if(!isNaN(parseInt(blogInfo?.search_id))) {
			blogInfo.search_id = parseInt(blogInfo?.search_id);
			blogInfo.search_id = blogInfo.search_id+"-"+sortBy.value; //invalidate the search_id without forcing a new search
		}
		updateHistoryState(currentStateToUrl(selectedTags), blogInfo?.search_id);
	}
	histSort(currentResults, sortBy.value);
	let max = Math.max(resultContainer.children.length, 15);
	let startElemCount = resultContainer.children.length;

	let i=0;
	while(i<Math.min(startElemCount, max)) {
		elems.add(currentResults[i].element);
		//if(!existingElems.has(currentResults[i].element)) {
			addedElems.add(currentResults[i].element);		
			if(addedElems.size + existingElemsArr.length >= max) {
				let remelem = existingElemsArr.pop();
				if(remelem != null) {
					elems.add(remelem);
					removedElems.add(remelem);
				}
			}
		//}
		if(addedElems.size >= max) break;
		i++;
	}
	pending_attachment_count = currentResults.length - (addedElems.size + existingElemsArr.length);
	/*while(i<resultContainer.children.length) {
		//if(addedElems[resultContainer.children[i].result.post_id] == null)
		elems.add(resultContainer.children[i]);
		if(i>max) {
			pending_attachment_count++;
			removedElems.add(resultContainer.children[i]);
		}
		i++;
	}*/
	updatePendingCountHint(false);
	addedElems = [...addedElems]; 
	removedElems = [...removedElems];
	var selectedTags = getCurrentlySelectedTags();
	await flip([...elems], 
		()=>{
			for(var i=0; i<removedElems.length; i++) { 
				removedElems[i].remove();
			}
			for(var i=0; i<addedElems.length; i++) { 
				updateConstraints(addedElems[i], selectedTags);
				resultContainer.appendChild(addedElems[i]);				
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
 async function reSort(sortMode, updateURL=true, doSeek=true, isInsuranceCheck=false) {
	prevSortBy = sortMode;
	await __reSort(sortMode, updateURL, doSeek, isInsuranceCheck);
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
		if(sortMode == "score" || sortMode == null) {
			if(typeof a.score == "string") a.score = parseFloat(a.score);
			if(typeof b.score == "string") b.score = parseFloat(b.score);
			return b.score - a.score;
		}
		if(sortMode == "hits") {
			if(typeof a.hit_rate == "string") a.hit_rate = parseFloat(a.hit_rate);
			if(typeof b.hit_rate == "string") b.hit_rate = parseFloat(b.hit_rate);
			return b.hit_rate - a.hit_rate;
		}
		if(sortMode == "new")
			return Date.parse(b.post_date) - Date.parse(a.post_date);
		else if(sortMode == "old")
			return Date.parse(a.post_date) - Date.parse(b.post_date);
	});
	for(var i=0; i<toSort.length; i++) {
		toSort[i].appearanceIndex = i;
	}
}

function updateNoticeText(serverEvent) {
	var eventMessage = serverEvent.eventMessage;
	console.warn('SERVER NOTICE: '+eventMessage.notice);
	if(eventMessage.resolved) {
		noticeContainer.classList.remove("notice");
	} else {
		let logElem = document.createElement("div");
		logElem.classList.add("log-entry", "notice");
		logElem.innerText = eventMessage.notice;
		noticeContainer.classList.add("notice");
		noticeLog.appendChild(logElem);
        noticeContainer.hasError = true;
	}
}

function updateErrorText(serverEvent) {
	var eventMessage = serverEvent.eventMessage;
	console.error("ERROR NOTICE: "+eventMessage.notice);
	noticeContainer.classList.add("error");
	noticeContainer.classList.remove("notice");
	let logElem = document.createElement("div");
        logElem.classList.add("log-entry", "error");
        logElem.innerText = eventMessage.notice;
	noticeLog.appendChild(logElem);
	noticeContainer.hasError = true;
}

function updateAvailableTags(serverEvent) {
	var eventMessage = serverEvent.eventMessage;
	addTag(eventMessage.newTag.tag_id, eventMessage.newTag.tagtext, eventMessage.newTag.user_usecount, true);
	associatePostTags(currentResults)
	//console.log("new tag found: " + eventMessage.newTag.tagtext);
}

function updateIndexState(serverEvent) {
	var eventMessage = serverEvent.eventMessage;
	statusText.justSearched = false; 
	let upgradedText = eventMessage?.upgraded? ''+eventMessage.upgraded+' posts upgraded, and ' : '';
	var progressString = "I'm still indexing your blog: "
					+upgradedText+""+eventMessage.indexed_post_count + " out of " + eventMessage.serverside_posts_reported + 
					` posts indexed so far.
					</br> In the meantime, I'll show you any results I come across below. </br>`;
	if(statusText.justSearched) {
		statusText.justSearched = false;
		fadeStatusTextTo(progressString);
	} else {
		statusText.pendingText = progressString;
		statusText.innerHTML = progressString;
	}
	if(eventMessage.as_search_result != null && eventMessage.as_search_result.length > 0) {
		let resolved = async function(){};
		augmentSearchResults(eventMessage.as_search_result, false, resolved());
		reSort(sortBy, false, false);
	}
	progressText.innerHTML = progressString; 
	progressBar.style.width = (100*(eventMessage.indexed_post_count)/eventMessage.serverside_posts_reported) + " %";
	updateDiskUseBar(eventMessage.disk_used);
	//console.log("indexed post : " + eventMessage.indexed +"/"+eventMessage.server_total + " ----- " + eventMessage.post_id);
}

async function concludeIndexState(serverEvent) {
	var eventMessage = serverEvent.eventMessage;
	if(eventMessage.indexed_this_time > 0) {
		fadeStatusTextTo(eventMessage.content);
		reSort(sortBy.value, false, true, true);
	}
	updateDiskUseBar(eventMessage.disk_used);
	if(eventMessage.deleted) clearSearchResults();
	pseudoSocket.disconnect();
	//console.log("indexing finished!");
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



function collapseExpandableParent(elem) {
	elem.closest("details").querySelector("summary").click();
}


/**clears the existing search result list*/
function clearSearchResults() {
	currentResults = [];
	currentResultsById = {};
	search_id = null;
	if(blogInfo) blogInfo.search_id = null;
	resultContainer.innerHTML = '';
	noticeContainer.hasError = false;
	noticeContainer.classList.remove("error");
	noticeContainer.classList.remove("notice");
	noticeLog.innerText = '';
	pending_attachment_count = 0;
	updatePendingCountHint(false);
}


function collapseExpandableParent(elem) {
	elem.closest("details").querySelector("summary").click();
}

/**
 * adds the results in the resultList to the existing results
 */
async function augmentSearchResults(resultList, doAugmentFlip = true, tagAwaiter) {
	
	var toReSort = []; //contains only new results;
	var toReplace = []; //contains only new results;  
	let asyncConstraint = async function(resultElem, waitForTags) {
		let tagsDone = await waitForTags;
		let currentlySelectedTags = getCurrentlySelectedTags();
		updateConstraints(resultElem, currentlySelectedTags);
	}
	for(var i=0; i<resultList.length; i++) {
		var result = resultList[i]; 
		if(typeof result.media == "string") {
			result.media = JSON.parse(result.media);
			result.media_by_id = makeMediaById(result.media);
		}
		if(typeof result?.score == "string") result.score = parseFloat(result.score);
		if(typeof result?.hit_rate == "string") result.hit_rate = parseFloat(result.hit_rate);
		if(currentResultsById[result.post_id] == null) {
			currentResultsById[result.post_id] = result; 
			var resultElement = templateResult.cloneNode(true);
			result.element = resultElement;
			keyifyTagObj(result);
			hydrateResultElement(result, resultElement); 
			hydratePostTags(result.element, tagAwaiter);
			asyncConstraint(resultElement, tagAwaiter);
			currentResults.push(result);
			toReSort.push(result); 
		} else if (currentResultsById[result.post_id].score != resultList[i].score) {
			currentResultsById[result.post_id].score = resultList[i].score;
			var resultElement = currentResultsById[result.post_id].element; 
			if(resultElement == null) resultElement = templateResult.cloneNode(true);
			keyifyTagObj(result);
			hydrateResultElement(result, resultElement);
			hydratePostTags(resultElement, tagAwaiter);
			asyncConstraint(resultElement, tagAwaiter);
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

function updatePendingCountHint(updateState = true) {
	let pending_count_span = load_more.querySelector("#pending_count");
	if(pending_attachment_count > 0) {
		load_more.style.display = 'block';
		pending_count_span.innerText = " ("+pending_attachment_count+")";
	} else {
		load_more.style.display = 'none';
	}
	if(updateState)
		updateHistoryState(currentStateToUrl(selectedTags), blogInfo?.search_id);
}

async function attachPending(attachCount) {
	let alreadyAttached = [];
	let toAttach = [];
	try {
	currentResults.forEach(o => {
		if(o.element.parentNode != null)
			alreadyAttached.push(o.element);
		else if(toAttach.length < attachCount)
			toAttach.push(o.element);
		else 
			throw new Error();
	});}catch(e) {}
	await flip([...alreadyAttached, ...toAttach], 
		()=>{
			for(var i=0; i<toAttach.length; i++) { 
				//updateConstraints(currentResults[i].element, selectedTags);
				resultContainer.appendChild(toAttach[i]);				
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
	pending_attachment_count -= toAttach.length;
	updatePendingCountHint();
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
	var post = JSON.parse(data.blocks);
	data.blocks = post;
	var trailContainerElem = element.querySelector(".result-trail");
	trailContainerElem.innerHTML = '';
	var selfContainerElem = element.querySelector(".result-self");
	selfContainerElem.innerHTML = '';
	
	post?.trail.forEach(trailItem => {

		trailContainerElem.appendChild(hydrateSubpost(trailItem, data.media_by_id));
	});
	selfContainerElem.appendChild(hydrateSubpost(post?.self, data.media_by_id));

	//var username_url = data.post_url.split("://")[1].split("tumblr.com"); //annoying hack but have to handle potentially changed blog names.
	//if(username_url.length != 0 && username_url !== "tumblr.com") {
	data.post_url = "https://"+blogInfo.name+".tumblr.com/post/"+(data.post_id);//url.split(".tumblr.com")[1]);
	//}

	element.querySelector(".external-go > a").setAttribute("href", data.post_url);
	var date = new Date(typeof data.post_date == "number"? data.post_date*1000 : data.post_date);
	var dateDisplayElem = element.querySelector(".result-date");
	dateDisplayElem.innerText = date.toLocaleString('default', { month: 'short'}) + " "+ date.getDate()+" "+date.getFullYear();
	data.post_date = date.toLocaleString(); 
	let calendarlink = element.querySelector(".from-date > a");
	calendarlink.setAttribute("href", "https://"+username+".tumblr.com/day/"+date.getFullYear()+"/"+(date.getMonth()+1)+"/"+date.getDate());
	calendarlink.setAttribute("target", "blank");
	element.result = data;
	//hydratePostTags(element);	
	var resPrev = element.querySelector(".result-preview");
	prepareIframe(resPrev);
	//tumblrHydrate(resPrev);
	//intersectObserver.observe(resPrev);
	//resPrev.isObserved = true;
}

function makeMediaById(medialist) {
	let media_by_id = {};
	medialist.forEach((m)=> {
		m.media_meta.mtype = m.mtype;
		m.media_meta.media_id = m.media_id;
		m.media_meta.date_encountered = m.date_encountered; 
		media_by_id[m.media_id] = m.media_meta;
	});
	return media_by_id;
}

function makeFormattedBlock(npfbloc_hard, blocknum_start, media_by_id){
	let stmap = {
		"h1" : ["h1 class='text-container'", "/h1"],
		"h2" : ["h2 class='text-container'", "/h2"],
		"quirky" : ["span style='font-style: 'cursive'", "/span"],
		"chat" : ["div class='text-container chat'", "/div"],
		"ind" : ["blockquote class= 'text-container indent-block'", "/blockquote"],
		"lnk" : ["blockquote class='text-container link-block'", "/blockquote"],
		"q" : ["blockquote class='text-container quote'", "/blockquote"],
		"ol" : ["ol", "/ol"],
		"ul" : ["ul", "/ul"],
		"li" : ["li class= 'text-container'", "/li"],
		"poll" : ["div class='poll class='text-block'", "/div"],
		"default" :  ["div class='text-container'", "/div"]
	}
	let formatMap = {
		"b" : ["b", "/b"],
		"s" : ["small", "/small"],
		"i" : ["i", "/i"],
		"str" : ["strike", "/strike"],
		"col" : ["span style='color: ", "/span"], 
		"href" : ["a href='", "/a"],
		"ment" : ["a class='mention' href='", "/a"]
	}
	function wrapFormat(block, bstringArr) {
		let a = 1;
		if(block.frmt == null) 
			return; 
		for(f of block?.frmt) {
			if(f.t=="mention") f.t = "ment";
			let preAdd = formatMap[f.t][0];        
			if(f.t == "ment" || f.t == 'href') preAdd += 'https://'+f.url+"'";
			if(f.t == "col") preAdd += f.hex+"'";
			bstringArr[f.s].pre.push(preAdd);
			//if(bstringArr.length <= f.e) f.e--;
			bstringArr[f.e-1].post.push(formatMap[f.t][1]);
		}
	}
	function wrapSubtype(block, bstringArr, listStack, blockNum) {
		let st = block?.s ?? "default";

		if(st=="ol" || st == "ul") {
			if(block.il == null) block.il=1;
			inList = false; 
			lastListType = null;
			if(listStack.length > 0) {
				inList = true;
				lastListType = listStack[listStack.length-1];
			}
			if(inList//if the previous entry was a list
			&& block.s != lastListType.s //and the list type changed 
			&& block.il == lastListType.il // but the indent_level did not.
			) {
				bstringArr[0].pre.push(stmap[lastListType.s][1]); //then close the old list block
				listStack.pop(); 
				bstringArr[0].pre.push(stmap[st][0]); //and open a new one
				listStack.push(block);
			} else if (
				!inList || //if we're not in a list
				( 	inList //or we are but
					&& block.il > lastListType.il //the indent level increases
				)
			) { 
				bstringArr[0].pre.push(stmap[block.s][0]); //then open a new list block
				listStack.push(block);
			} else if(
				inList //if we're in a list
				&& block.il < lastListType.il //and the indent level decreases
			) {
				let dropCount = lastListType.il - block.il;
				for(let i =0; i<dropCount; i++) {
					bstringArr[0].pre.push(stmap[lastListType.s][1]); //close out as many list items as we dropped indent levels
					lastListType = listStack.pop(); 
				}
				bstringArr[0].pre.push(stmap[st][0]); //and open a new list block
				listStack.push(block);
			}
			st = "li";
		}
		
		if(st != "li") {
			//close out the remaining lists if we're not in one;
			let listItem = listStack.pop();
			while(listItem != null) {
				bstringArr[0].pre.push(stmap[listItem.s][1]);
				listItem = listStack.pop();
			}
		}
		if(st != "none") {
			bstringArr[0].pre.push(stmap[st][0]+" data-block-num='"+blockNum+"'");
			bstringArr[bstringArr.length-1].post.push(stmap[st][1]);
		} 
		
	}
	let contentString = "";
	for(let i = blocknum_start; i<npfbloc_hard.length; i++) {
		let b = npfbloc_hard[i];
		if(b.t != "txt") break; 
		contentString += b.c;
	}
	
	
	function mergeBlocks(decomposed) {
		let result = "";
		for(d of decomposed) {
			result += "\n"+mergeSingleItem(d);
		}
		return result;
	}
	function mergeSingleItem(item){
		let result = ""; 
		for(c of item) {
			for(st of c.pre) result += "<"+st+">"; 
			result += c.inner;
			for(et of c.post) result += "<"+et+">";
		}
		return result;
	}
	
	
	console.log('start');
	let blocknum = blocknum_start;
	let listStack = [];
	function derecompose(npfbloc_hard) {
		let blockCursor = 0;
		
		let formattedBlocks = [];	
		for(let i=blocknum_start; i<npfbloc_hard.length; i++) {
			let b = npfbloc_hard[i];
			if(b.t != 'txt') break;
			let bstringArr = [];
			if(b.t == 'txt') {
				if(b.c=="") bstringArr.push( {pre: [], inner: "", post: []});
				for(c of b.c) 
					bstringArr.push( {pre: [], inner: c, post: []});
				
				wrapSubtype(b, bstringArr, listStack, blocknum);
				wrapFormat(b, bstringArr);
				blockCursor+= b.c.length;
			}
			blocknum++;			
			formattedBlocks.push(bstringArr);
		}
		let closer =  [{pre:[], inner: "", post:[]}]; 
		wrapSubtype({s:"none"}, closer, listStack);//close trailing lists
		formattedBlocks.push(closer);
		return mergeBlocks(formattedBlocks)
	}

	let blocks_presult = derecompose(npfbloc_hard, media_by_id);
	let blocksCont = document.createElement('span');
	blocksCont.innerHTML = blocks_presult;
	let allblocks = blocksCont.querySelectorAll(".text-container");
	for(block of allblocks) {
		npfbloc_hard[block.getAttribute("data-block-num")].elem = block;
	}
	return {elem : blocksCont, block_num_end: blocknum};
}



function hydrateSubpost(contentContainer, mediaItems) {
	var implicit = {};
	var contentItems = contentContainer?.content;
	var layout = contentContainer?.layout;
	var subpost = subpostTemplate.cloneNode(true);
	var subpostContent = subpost.querySelector(".post-content");
	if(contentContainer == undefined) {
		subpostContent.innerHTML = "<h2>Something Went Wrong With This Post</h2>";
		return subpost;
	}
	var subpostHeader =  subpost.querySelector(".user-header");
	var blogIcon =  subpostHeader.querySelector(".blog-icon");
	var blogName =  subpostHeader.querySelector(".blog-name");
	blogIcon.setAttribute("src", "https://api.tumblr.com/v2/blog/"+contentContainer.by+".tumblr.com/avatar/32");
	blogName.innerText = contentContainer.by;
	let blocknum = 0;
	let subPostItems = [];	
	var makeblock = (content) => {
		var tagtype = "span";
		var tagclasses = ["text-container"];
		var attributes = {};
		var innercontent = null;
		var outertagclass = "span";
		var newnode = null;
		var newnodeInner = null;	
		
		switch(content.t) {
			case "img":
				tagtype = "img";
				newnode = document.querySelector("#templates .img-container").cloneNode(true);
				let media_item = mediaItems[content.db_id];
				if(media_item != null) {
					newnode.querySelector(".img-caption").innerText = media_item.title;
					//newnode.querySelector("img").setAttribute("data-image-id", content.db_id);
					let img = newnode.querySelector("img");
					img.setAttribute("alt", media_item.description);				
					img.setAttribute("src", 'https://'+(media_item.preview_url != null ? media_item.preview_url : media_item.media_url));
					img.setAttribute("data-image-id",  'https://'+media_item.media_url);
				} else {
					newnode.innerText = "MEDIA ITEM UNAVAILABLE. Your blog's index might be in the middle of an upgrade. Should come through eventually,";
					newnode.style.color = 'red';
				}

				newnode.setAttribute("data-block-num", blocknum);
				
				content.elem = newnode; 
				break;
			case "txt": 
				switch(content.s) {
					case "h1" : tagtype = "h1"; break;
					case "h2" :  tagtype = "h2"; break;
					case "q" : tagtype = "blockquote"; break;
					case "quirky": tagclasses.push("quirky"); break;
					case "ul" : 
					case "ol" : //tagtype = content.subtype; break;					
					case "ind" : tagclasses.push("indented");
				};
				newnode = document.createElement(tagtype);
				newnode.innerText = content.c;
				//if(subdir !="") {
					let result = makeFormattedBlock(contentItems, blocknum, mediaItems);
					newnode = result.elem;
					blocknum = result.block_num_end-1;
				/*} else
					newnode.classList.add(...tagclasses);*/
				break;
			case "vid":
			case "lnk": 
				newnode =  document.querySelector("#templates .link-container").cloneNode(true);
				//if(subdir !="" && content?.db_id != null) {
					media = mediaItems[content.db_id];
					if(media != null) {
						newnode.querySelector("a").setAttribute("href", 'https://'+media.media_url);
						newnode.querySelector("a h2").innerText = media.title;
						if(media.description != null) {
							newnode.querySelector("a span").innerText = media.description;
						}
					
						if(media.preview_url != null) {
							let imgelem = document.createElement("img");
							imgelem.setAttribute("loading", "lazy"); 
							imgelem.setAttribute("src", 'https://'+media.preview_url);
							newnode.querySelector("a span").appendChild(imgelem);
						}
					} else {
						newnode.innerText = "LINK INFO UNAVAILABLE. Your blog's index might be in the middle of an upgrade. Should come through eventually,";
						newnode.style.color = 'red';
					}
				/*} else {
					newnode.querySelector("a").setAttribute("href", content.u);
					newnode.querySelector("a h2").innerText = content.ttl;
					newnode.querySelector("a span").innerText = content.d;
				}*/
				newnode.setAttribute("data-block-num", blocknum); 
				content.elem = newnode;
				break;
			case "aud":
				newnode = document.querySelector("#templates .text-container").cloneNode(true);
				newnode.innerText = "###############\
				Video and Audio previews not yet supported :(\
				(click the eye button on the right for a quick full preview)\
					########################"; 
				newnode.setAttribute("data-block-num", blocknum);
				content.elem = newnode;
				break;
	
		}

		if(newnode == null) {
			newnode = document.querySelector("#templates .text-container").cloneNode(true);
			newnode.innerText = "----- THERE WAS A POLL HERE (click the eye button on the right for better previews) ----";
		}
		
		blocknum++;	
		subPostItems.push(newnode);
	};
	while(blocknum <contentItems.length) {
		makeblock(contentItems[blocknum]);
	}

	var rowblocks = (row, newPar) => {
		
		row.blocks.forEach(blockIdx => {
			try {
				var content = contentItems[blockIdx];
				newPar.appendChild(content.elem);
				content.elem = newPar;
			} catch (e) {}
		});
	
	};
	
	subPostItems.forEach(elem => {
		subpostContent.appendChild(elem);
	});

	/**
	 * for non-asks the format seems to be
	 * an array of unique layout types (which should really be an object with key vlaue pairs, but okay),
	 * layout: 
	 * 	[
	 * 		{
	 * 			type: "rows/whatever", 
	 * 	 		display: 
	 * 				[ //this seems to contain a list of elements each corresponding to a row
	 * 					{blocks: [//whichever blocks are in this row]},
	 * 					{blocks: [//whichever blocks are in this row]},
	 * 					{blocks: [//whichever blocks are in this row]},
	 * 				]
	 * 		},
	 * 		{ 
	 * 			type: "condensed/whatever",
	 * 			display:
	 * 				[ //this seems to contain a list of elements each corresponding to a row
	 * 					{blocks: [//whichever blocks are in this row]},
	 * 					{blocks: [//whichever blocks are in this row]},
	 * 					{blocks: [//whichever blocks are in this row]},
	 * 				]
	 * 		},
	 * 		{ 
	 * 			type: "ask/whatever",  //asks are special somehow, they skip the display thingy
	 * 			blocks:
	 * 				[ //this seems to contain a list of elements each corresponding to a row
	 * 					{blocks: [//whichever blocks are in this ask]},
	 * 				]
	 * 		}
	 * ]
	 */
	layout.reverse().forEach(layoutInst => {
		if(layoutInst.type == "ask") {
			askElem = askTemplate.cloneNode(true);
			var name = askElem.querySelector(".name-container");
			var icon = askElem.querySelector(".blog-icon");
			var asktextcontainer = askElem.querySelector(".ask-text-container");
			let blogAttr =layoutInst?.attribution?.blog;
			if(blogAttr?.url != null) 
				name.setAttribute("href", blogAttr?.url);
			if(blogAttr?.avatar != null) {
				icon.setAttribute("src", blogAttr?.avatar[Math.min(blogAttr.avatar.length-1, 3)].url);
			}
			name.innerText = blogAttr?.name ?? "Anonymous";
			var askContent = asktextcontainer;
			rowblocks(layoutInst, askContent);
			subpostContent.insertBefore(askElem, subpostContent.firstChild);
		} else if(layoutInst.type == "rows") {
			layoutInst.display.forEach(row => {
				rowElem = rowTemplate.cloneNode(true);
				rowblocks(row, rowElem);
				subpostContent.appendChild(rowElem);
			});
		}
	});
	return subpost;
	
}
async function loadActualImage(imgElement) {
    var image_id = imgElement.getAttribute('data-image-id'); 
	if(!imgElement.loadAttempted) {
		imgElement.loadAttempted = true; 
		var response = await fetch('resolve_image_url.php?image_id='+image_id);
		let result = await response.text();
		imgElement.src = 'https://'+result; 
	}
}


let pending = [];
let finished = [];

async function hydratePostTags(postElem, tagAwaiter) {
	var data = postElem.result;
	pending.push(tagAwaiter);
	let gotTags = await tagAwaiter;
	var tagList = postElem.querySelector(".result-tags");
	//if(Object.keys(data.tags).length == 0) console.log("skipping " + postElem.result.post_id + " due to 0 tags");
	//else console.log("augmenting " + postElem.result.post_id+" : with " + Object.keys(data.tags).length + " tags ");
	for(var k of Object.keys(data.tags)) {
		if(data.tags[k] == undefined && blogTags[k] != undefined) {
			data.tags[k] = blogTags[k];
			var taglink = document.createElement("a");
			taglink.classList.add("taglink");
			taglink.innerText = "#"+(typeof data.tags[k] == "string"? data.tags[k] : data.tags[k].full);
			tagList.appendChild(taglink); 
		}
	}
	finished.push(pending.pop()); 
}

blogTags = {}; 
usecountSortedTags = [];
/**clears existing tag entries and populates new tags*/
async function setTags(tagList) {
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

function splitURL(outerState) {
	var currentURL = new URL(outerState?.url ? outerState.url : href);
    var urlQuery = new URLSearchParams(currentURL.search);//decodeURI(href.split("?")[1]);

	usernameField.value = urlQuery.get("u") ?? null;
	queryField.value = urlQuery.get("q") ?? null;
	prevSortBy = sortMode;
	prevSearchParams = searchParams == null ? null : JSON.parse(JSON.stringify(searchParams));
	if(urlQuery.get("s") != null)
		sortBy.value = urlQuery.get("s");
	if(urlQuery.get("tags"))
		preSpecifiedTags = urlQuery.get("tags");
	
	all_possible_params.forEach(param => {
		if(urlQuery.get(param)) {
			if(param.substring(0,3) == "sp_")
				searchParams[param] = urlQuery.get(param) == "true" ? true : false;
			else if(param.substring(0,3) == "fp_") {
				if(param.indexOf("include_reblogs") > -1)
					searchParams[param] = urlQuery.get(param) == "true" ? true : false;
				else
					searchParams[param] = parseInt(urlQuery.get(param));
			}
		}
	});

	//if(window.parent == window) {
	//	window.history.replaceState({}, "Siik "+usernameField.value+"'s blog for `"+queryField.value+"`", "?"+href.split("?")[1]);
	//	document.title = "Siik "+usernameField.value+"'s blog for `"+queryField.value+"`";
	//}
	setFormFromJSON(fromRequestJSON(searchParams));
    //var urlSplit = urlQuery.split(/(&?u=|&?s=|&?p=|&?q=|&?tags=)/);
    /*for(var i=0; i<urlSplit.length; i++){ 
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
		} 
		else if(urlSplit[i].search(/(&s|^s)=/) != -1) { 
			prevSortBy = sortMode;
			sortBy.value = urlSplit[i+1];
		}
		else if(urlSplit[i].indexOf("tags=") !=-1) {
			preSpecifiedTags = JSON.parse(urlSplit[i+1]);
		}
    }*/
   	let state = window.stateHistory[outerState?.search_id];
   	if(state == null) state = outerState?.state;
	if(state?.search_id && state?.blog_uuid && state?.display && Object.keys(state?.display).length > 0) {
		if(Object.keys(state?.display).length > 0) {
			clearSearchResults();
			restoreResultsFromState(state);
			//updateHistoryState(currentStateToUrl(selectedTags), blogInfo?.search_id);
		}
	} else if(usernameField.value != "" && queryField.value != "") {
		if(usernameField.value == prevUsername && 
		queryField.value == prevQuery && 
		JSON.stringify(prevSearchParams) == JSON.stringify(searchParams) &&
		prevUsername != null && prevUsername != "" 
		&& prevQuery != null && prevQuery != ""
		&& prevSortBy != null && prevSortBy != sortBy.value) {
			reSort(sortBy.value, false);
			updateHistoryState(currentStateToUrl(selectedTags), blogInfo?.search_id);
		} else {
			preSeek(true, false, false, 50);
		}
	}
}

function currentStateToUrl(selectedTags = null) {
	var glue = "?";
	var urlPath = "";
	if(alwaysPrepend != "") {
		urlPath += alwaysPrepend;
		glue = "&";
	}
	var newTitle = "Siik ";
	if(usernameField.value != null) {
		urlPath += glue+"u="+usernameField.value;
		glue = "&";
		newTitle += usernameField.value + "'s blog ";
	}
	if(queryField.value != null) {
		urlPath += glue+"q="+queryField.value;
		glue = "&";
		newTitle += "for `"+queryField.value+"`"
	}

	if(sortBy.value != null) {
		urlPath += glue+"s="+sortBy.value;
		glue = "&";
	}

	Object.keys(searchParams).forEach(param => {
		urlPath += glue+param+"="+searchParams[param];
		glue = "&";
	});

	
	if(selectedTags != null) {
		urlPath += glue+"tags="+JSON.stringify(selectedTags);
		glue = "&";
	}

	
	let patharged = "?"+decodeURI(urlPath.split("?")[1]);
	let currUrl = "?"+decodeURI(document.location.href.split("?")[1]);

	return urlPath;
	
	//if(currUrl != patharged && window.parent == window) { //no back buttons in an iframe
	//	window.history.pushState({},newTitle, urlPath);
	//	document.title = newTitle;
	//}
	//tumblr outer page can take care of deciding whether to update its url
	//window.parent.postMessage(JSON.stringify({src: "siikr", url: urlPath, title: newTitle}), '*');
	//updateHistoryState();
}

var messagePostTimer = -1;
var messageQ = {};

function updateHistoryState(urlPath = null, updates_search_id = null) {

	let attached = {};
	let pendingAttach = {};
	let title = usernameField.value?.length > 0 ? "Siikr "+usernameField.value+"'s blog for '"+queryField.value+"'" : document.title;
	document.title = title;
	currentResults.forEach(r => {
		if(r.element.parentNode != null) {
			attached[r.post_id] = r.score;
		} else {
			pendingAttach[r.post_id] = r.score;
		}
	});
	let currentState = {
		display: attached,
		pending: pendingAttach,
		blog_uuid: blog_uuid == undefined ? null : blog_uuid, 
		blogInfo: {"blog_uuid": blogInfo?.blog_uuid, "search_id": blogInfo?.search_id, "blog_name": blogInfo?.name, 
			"avatar": blogInfo?.avatar
		}
	}

	let args = urlPath?.length > 0 ? urlPath : document.location.href;
	let postq = args.split("?");
	if(postq.length>1) args = postq[1];

	let stateObj = {
		state: currentState,
        url:  args?.length > 0 ? "?"+args : "",
		title: document.title
	}
	stateObj.state.search_id = updates_search_id;

	if(updates_search_id == null || window.stateHistory[updates_search_id] == null) {
		if(window.parent != window) {
			let prevTimerId = messagePostTimer;
			messageQ = {};
			window.parent.postMessage(JSON.stringify({
					src: "siikr",
					search_id: updates_search_id,
					state: stateObj.state,
					url: stateObj.url,
					title: stateObj.title
			}), '*');
		} else {
			window.history.pushState(stateObj.state, '', stateObj.url); 
		}
	} 
	if(updates_search_id != null) {
		let existing = window.stateHistory[updates_search_id];
		//only ever add values
		window.stateHistory[updates_search_id] = deepMerge(existing, stateObj.state);
	}
}


async function restoreResultsFromState(state) {
	if(state?.blog_uuid != null && state?.display && Object.keys(state?.display)?.length > 0) {
		let initialResults = fetch(subdir+'repop.php', 
			{
    			method: 'POST', headers: {'Content-Type': 'application/json'},
    			body: JSON.stringify({
					blogInfo : state.blogInfo,
					posts : state.display
				})
			}
		);
		let moreResults = async ()=>{{}};
		if(Object.keys(state?.pending)?.length > 0) {
			moreResults = fetch(subdir+'repop.php',
				{
						method: 'POST', headers: {'Content-Type': 'application/json'},
						body: JSON.stringify({
							blogInfo : state.blogInfo,
							posts : state.display
						})
				}
			);
		}
		initialResults = await initialResults;
		await processSeekResult(await initialResults.json());
		if(Object.keys(state?.pending)?.length > 0) {
			let otherResults = await moreResults;
			otherResults = await otherResults.json();
			if(otherResults?.results?.length>0) {
				otherResults.more_results = otherResults.results;
				processMoreResults(otherResults);
			}
		}
	}
}

//updates statustext without fading
/*function setStatusTextTo(text) {
	
}
function fadeStatusTextTo(text) {
	statusText.pendingText = text;
	statusText.classList.add("fadeout");
}*/

/**
 * updates the status text after the fadeout animation has ended,
 * then sets the fadein animation
 */
/*function setPendingStatusText() {
	if(statusText.classList.contains("fadeout")) {
		statusText.innerHTML = statusText.pendingText;
	}
	statusText.classList.remove("fadeout");
}*/


function toggleThisPreview(elem) {
	var result = elem.closest(".result");
	result.classList.toggle("active-preview");
	if(result.classList.contains("active-preview"))
		tumblrHydrate(result.querySelector(".result-preview"));
}

/**attach the iframe as a variable to the element it will be placed withn
 * so as to avoid any DOM shenanigans.
 */
function prepareIframe(elem) {
	if(elem.post_hydrated != true) {
		var resultElem = elem.parentNode;
		var data = resultElem.result;
	
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
		elem.pending_iframe = iframe;

		if(elem.closest(".active-preview") != null) {
			tumblrHydrate(elem);
		}
	}
}

/**hydrates the post with fancy tumblr preview*/
async function tumblrHydrate(elem) {
	
	if(elem.post_hydrated != true) { 
		elem.appendChild(elem.pending_iframe);
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
		//var post_url = data.post_url;	
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

		
		return true;
	};

	/*returns true if hydration was initated, false otherwise*/
	var hydrateIfAcceptable = async (elem) => {		

		var enqueued = Object.keys(loadingSet).length;
		if( enqueued < 3 /*|| isVisible(elem, window)*/)  {
			loadingSet[elem.parentElement.result.post_id] = true;
			var result = await doHydrate(elem);
			//console.log(result);
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


function deepMerge(a, b) {
    const result = { ...a };
    for (const key in b) {
        if (b.hasOwnProperty(key)) {
            if (typeof b[key] === 'object' && b[key] !== null && !Array.isArray(b[key])) {
                if (result.hasOwnProperty(key)) {
                    result[key] = deepMerge(result[key], b[key]);
                } else {
                    result[key] = deepMerge({}, b[key]);
                }
            } else {
                result[key] = b[key];
            }
        }
    }
    return result;
}
