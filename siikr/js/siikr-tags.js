document.addEventListener("DOMContentLoaded", ()=> {resultTagTemplate = templates.querySelector(".tag-autocomplete");
    selectedTagTemplate = templates.querySelector(".tag-selected");
    selectedTagContainer = document.getElementById("selected-tags");    
    tagFilterer = document.getElementById("tag-filterer");
    tagFilterInput = document.getElementById("tag-filter-input");
});

function showTagSearcher(elem) {
		var tagDisplayCont = document.getElementById("tag-filter-results");
    if((tagDisplayCont.children == 0 
        || tagFilterInput.value.trim() == "" 
        || tagFilterInput.value == null
        ) 
        && usecountSortedTags.length > 0) {
				
        for(var i=usecountSortedTags.length-1; i>=usecountSortedTags.length-50; i--) {
					  if(usecountSortedTags[i].user_usecount == 1) break;
            var elem = createResultTag(usecountSortedTags[i]);
            tagDisplayCont.appendChild(elem);
						if(i==0) break;
        }
    }
    tagFilterer.classList.add("visible");
    tagFilterInput.focus();
}

function hideTagSearcher() {
    tagFilterer.classList.remove("visible");
    tagFilterInput.blur();
}

document.addEventListener("click", (event) => {
    if(event.target.closest("#tag-search-button") == null) {
        hideTagSearcher();
    }
})

function checkUnfocus(event) {
    if(event.code == "Esc"){
        hideTagSearcher();
        return; 
    }
}

function findTags(event) {
    var elem = event.target;
    if(event.code == "Esc"){
        hideTagSearcher();
        return; 
    }
    var infoCont = document.getElementById("tag-info-cont");
    var selectedTags = getCurrentlySelectedTags();
    var tagDisplayCont = document.getElementById("tag-filter-results");
    tagDisplayCont.innerHTML='';
    var query = elem.value.split(/[ ,]+/);
    for(var i=0; i<query.length; i++) {
        query[i] = query[i].toLowerCase();
    }
    var foundTags = {};
    for (var k of Object.keys(blogTags)) {
        var tagCandidate = blogTags[k];
        if (selectedTags[k] == null) {
            var tf = tagCandidate.full;
            for(var tw = 0; tw<tagCandidate.words.length; tw++) {
                for(var qw = 0; qw<query.length; qw++) {
                    var q = query[qw];
                    var t = tagCandidate.words[tw];
                    if(tagCandidate.words[tw].indexOf(query[qw]) != -1) {
                        if(foundTags[k] == null) {
                            foundTags[k] = tagCandidate;
                            tagCandidate.score = 0;
                            tagCandidate.tag_id = k;
                        }
                        tagCandidate.score += query[qw].length/tagCandidate.words[tw].length;
                    }
                }
            }
        }
    }
    var foundTagsArr = Object.values(foundTags);
    foundTagsArr.sort((a, b) => {
        return b.score - a.score; 
    });
    for(var i=0; i<foundTagsArr.length; i++) {
        var elem = createResultTag(foundTagsArr[i]);
        tagDisplayCont.appendChild(elem);
    }
}

function createResultTag(tagObj) {
    var tagElem = resultTagTemplate.cloneNode("deep");
    tagElem.tagObj = tagObj;
    var textField = tagElem.querySelector(".tag-text");
    textField.innerText = tagObj.full;
		var tagUC = tagElem.querySelector(".tag-usecount");
		tagUC.innerText = tagObj.user_usecount; 
    return tagElem;
}

function createSelectedTag(tagObj, tagtype) {
    var tagElem = selectedTagTemplate.cloneNode("deep");
    tagElem.tagObj = tagObj;
		tagObj.tagtype = tagtype;
    tagElem.tagtype = tagtype;
    tagElem.querySelector(".tag-text").innerText = tagObj.full;
    tagElem.querySelector(".tag-text-tip").innerText = tagObj.full;
    tagElem.classList.add(tagtype);
    return tagElem;
}

function getCurrentlySelectedTags() {    
    var selectedTags = {};
    var selectedTagElems = selectedTagContainer.querySelectorAll(".tag-selected");
    for (var i = 0; i < selectedTagElems.length; i++) {
        var tagElem = selectedTagElems[i];
        tagElem.tagObj.tagtype = tagElem.tagtype;
        selectedTags[tagElem.tagObj.tag_id] = tagElem.tagObj;
    }
    return selectedTags;
}

function addInclude(button) {
    var tagElem = button.closest(".tag-autocomplete");
    var selected = createSelectedTag(tagElem.tagObj, "include");
    selectedTagContainer.appendChild(selected);
    seek();
    tagElem.remove();
}

function updateConstraints(resElm, selectedTags = null) {
	var currentlySelectedTags = selectedTags;
	if(selectedTags == null) 
		currentlySelectedTags = getCurrentlySelectedTags();
	
	for(var k of Object.keys(currentlySelectedTags)) {
		var tag = currentlySelectedTags[k];
		//for(var i=0; i<currentResults.length; i++) { 
			var res = resElm.result;
			if(tag.tagtype == "disclude") {
				if(resElm.disculdedBy == null) 
						resElm.discludedBy = {}; 
				if(res.tags[tag.tag_id] == null) {
					resElm.discludedBy[tag.tag_id] = tag;
					resElm.classList.add("discluded");
				}
			}
		//}
	}
}

//var discludedElems = [];

function addConstraint(button) {
    var tagElem = button.closest(".tag-autocomplete");
    var selected = createSelectedTag(tagElem.tagObj, "disclude");
    selectedTagContainer.appendChild(selected);
    tagElem.remove();
		var elems = [...new Set(resultContainer.children)];
		flip(elems, 
		()=>{
			for(var i=0; i<currentResults.length; i++) { 
				var res = currentResults[i];
				var resElm = res.element;
				if(res.tags[tagElem.tagObj.tag_id] == null){
					if(resElm.disculdedBy == null) 
						resElm.discludedBy = {}; 
					resElm.discludedBy[tagElem.tagObj.tag_id] = tagElem.tagObj;
					resElm.classList.add("discluded");
				}
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

function removeSelectedTag(button) {
    var tagElem = button.closest(".tag-selected");
    var tag_id = tagElem.tagObj.tag_id;
    tagElem.remove();
    if(tagElem.tagObj.tagtype == "disclude") {
        for(var i=0; i<currentResults.length; i++) { 
            var res = currentResults[i];
            var resElm = res.element;
	    if(resElm.discludedBy == null) resElm.discludedBy = {};
            delete resElm.discludedBy[tagElem.tagObj.tag_id];
	    if(Object.keys(resElm.discludedBy).length == 0) resElm.classList.remove("discluded");
        }
        reSort(sortBy, true, false);
    } else {
        for(var i=0; i<currentResults.length; i++) {
                if(currentResults[i].tags[tag_id] != null) {
                        currentResults[i].score--;
                }
                if(currentResults[i].score == 0) {
                        currentResults.splice(i, 1);
                        delete currentResultsById[currentResults[i].post_id];
                }
        }
        reSort(sortBy, true, true);
    }
}
