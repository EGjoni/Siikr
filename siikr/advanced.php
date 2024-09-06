<?php ?>

<div id="advanced-container">
    <dialog id="advanced-filter">
        <form id="searchForm">
        <label class="section" id="include-reblogs-lbl">
            <input type="checkbox" id="include-reblogs" name="fp_include_reblogs" onchange="updateState(this)" checked="">Include reblogs</label>
            <!-- Search Over Section -->
            <fieldset class="section" id="searchOver">
                <legend> 
                    <label><input type="checkbox" id="textual" class="grouping" checked="" onchange="toggleNested('textual', true)"><h3>Search Over</h3></label>
                </legend> 
                <fieldset class="indent">
                    <legend>
                        <label>
                            <input type="checkbox" id="self" class="grouping" checked="" onchange="toggleNested('self', true)">self
                        </label>
                    </legend>
                    <div class="indent">
                        <input type="checkbox" id="sp_self_text" name="sp_self_text" onchange="updateState(this)" checked=""> content
                        <input type="checkbox" id="sp_self_media" name="sp_self_media" onchange="updateState(this)" checked=""> media text
                        <input type="checkbox" id="sp_self_mentions" name="sp_self_mentions" onchange="updateState(this)" checked=""> mentions
                        <input type="checkbox" id="sp_tagtext" name="sp_tagtext" onchange="updateState(this)" checked=""> tags
                    </div>    
                </fieldset>
                <fieldset class="indent">
                    <legend>
                        <label>
                            <input type="checkbox" id="trail" class="grouping" checked="" onchange="toggleNested('trail', true)">trail
                        </label>
                    </legend>
                    <div class="indent">
                        <input type="checkbox" id="sp_trail_text" name="sp_trail_text" onchange="updateState(this)" checked=""> content
                        <input type="checkbox" id="sp_trail_media" name="sp_trail_media" onchange="updateState(this)" checked=""> media text
                        <input type="checkbox" id="sp_trail_mentions" name="sp_trail_mentions" onchange="updateState(this)" checked=""> mentions
                        <input type="checkbox" id="sp_trail_usernames" name="sp_trail_usernames" onchange="updateState(this)" checked=""> usernames
                    </div>
                </fieldset>
            </fieldset>

            <!-- Must Have Section -->
            <fieldset class="section">
                <legend><h3>Must Have:</h3></legend>
                <div class="grid">
                    <!-- Images -->
                    <div class="checkbox-container">
                        <fieldset class="radio-group disabled">
                            <legend><label><input type="checkbox" name="result_must_have" value="images" onchange="toggleRadioButtons(this)"/> Images</label></legend>
                            <label><input type="radio" name="images_location" value="in_post" disabled/> in post</label>
                            <label><input type="radio" name="images_location" value="in_ancestors" disabled/> in ancestors</label>
                            <label><input type="radio" name="images_location" value="either" checked="true"disabled/> either</label>
                        </fieldset>
                    </div>
                    <!-- Videos -->
                    <div class="checkbox-container">
                        <fieldset class="radio-group">
                            <legend><label><input type="checkbox" name="result_must_have" value="video" onchange="toggleRadioButtons(this)"/> Videos</label></legend>
                            <label><input type="radio" name="video_location" value="in_post" disabled/> in post</label>
                            <label><input type="radio" name="video_location" value="in_ancestors" disabled/> in ancestors</label>
                            <label><input type="radio" name="video_location" value="either" checked="true" disabled/> either</label>
                        </fieldset>
                    </div>
                    <!-- Audio -->
                    <div class="checkbox-container">
                        <fieldset class="radio-group">
                            <legend><label><input type="checkbox" name="result_must_have" value="audio" onchange="toggleRadioButtons(this)"/> Audio</label></legend>
                            <label><input type="radio" name="audio_location" value="in_post" disabled/> in post</label>
                            <label><input type="radio" name="audio_location" value="in_ancestors" disabled/> in ancestors</label>
                            <label><input type="radio" name="audio_location" value="either" checked="true" disabled> either</label>
                        </fieldset>
                    </div>
                    <!-- Link -->
                    <div class="checkbox-container">
                        <fieldset class="radio-group">
                            <legend><label><input type="checkbox" name="result_must_have" value="link" onchange="toggleRadioButtons(this)"/> Link</label></legend>
                            <label><input type="radio" name="link_location" value="in_post" disabled/> in post</label>
                            <label><input type="radio" name="link_location" value="in_ancestors" disabled/> in ancestors</label>
                            <label><input type="radio" name="link_location" value="either" checked="true" disabled/> either</label>
                        </fieldset>
                    </div>
                    <!-- Chat -->
                    <div class="checkbox-container">
                        <fieldset class="radio-group">
                            <legend><label><input type="checkbox" name="result_must_have" value="chat" onchange="toggleRadioButtons(this)"/> Chat</label></legend>
                            <label><input type="radio" name="chat_location" value="in_post" disabled/> in post</label>
                            <label><input type="radio" name="chat_location" value="in_ancestors" disabled/> in ancestors</label>
                            <label><input type="radio" name="chat_location" value="either" checked="true" disabled/> either</label>
                        </fieldset>
                    </div>
                    <!-- Ask -->
                    <div class="checkbox-container">
                        <fieldset class="radio-group">
                            <legend><label><input type="checkbox" name="result_must_have" value="ask" onchange="toggleRadioButtons(this)"/> Ask</label></legend>
                            <label><input type="radio" name="ask_location" value="in_post" disabled/> in post</label>
                            <label><input type="radio" name="ask_location" value="in_ancestors" disabled/> in ancestors</label>
                            <label><input type="radio" name="ask_location" value="either" checked="true" disabled/> either</label>
                        </fieldset>
                    </div>
                </div>
            </fieldset>
            <button type="reset" onclick="removeHint()">Reset</button>
            <button type="button" onclick="closeAdvanced()">Close</button>
        </form>
    </dialog>
    <div id="nosdoubleout-container">
    
        <!-- Pre-create hidden "NO" elements -->
        <div class="nos-container">
            <div class="weeping-nos">NO</div>
            <div class="weeping-nos">PLEASE NO</div>
            <div class="weeping-nos">No!</div>
            <div class="weeping-nos">nooooo</div>
            <div class="weeping-nos">Oh god</div>
            <div class="weeping-nos">NO</div>
            <div class="weeping-nos">NO</div>
            <div class="weeping-nos">THIS IS WRONG</div>
            <div class="weeping-nos">NO</div>
            <div class="weeping-nos">.tumblr.com.tumblr.com???</div>
            <div class="weeping-nos">NO</div>
            <div class="weeping-nos">NO</div>
            <div class="weeping-nos">NO</div>
            <div class="weeping-nos">Why would you do this</div>
            <div class="weeping-nos">NO</div>
            <div class="weeping-nos">WHYYYY</div>
            <div class="weeping-nos">NO</div>
            
        </div>
</div>
<output id="formOutput"></output>
<style>
    @keyframes rain {
        0% { transform: translateY(1.2em); opacity: 0; }
        20% { opacity: 1; }
        100% { transform: translateY(500px); opacity: 0; }
    }
    .nos-container {
        position: relative;
        z-index: 1;
        color: white;
        filter: drop-shadow(2px 2px 1px black);
    }

    .input-container {
        position: relative;
        display: inline-block;
    }

    .weeping-nos {
        position: absolute;
        color: #ff8b88;
        animation: rain 2s linear infinite;
        display: none; /* Initially hidden */
        top: 0;
        white-space: nowrap;
    }
</style>
<script>
    const all_possible_params = [
        "sp_self_text", // v3,4
        "sp_self_media", // v4
        "sp_tag_text",  // v3,4
        "sp_trail_text",  // v3,4
        "sp_trail_media", // v3,4
        "sp_trail_usernames", // v4
        "sp_image_text",// v3
        "fp_include_reblogs", //v4
        "fp_images",
        "fp_video",
        "fp_audio",
        "fp_chat",
        "fp_link",
        "fp_ask"
    ]
    const default_search_params = {
        sp_self_text : true,
        sp_self_media : true,
        sp_self_mentions : true,
        sp_tagtext : true,
        
        sp_trail_text : true,
        sp_trail_media : true,
        sp_trail_mentions : true,
        sp_trail_usernames : true,
        fp_include_reblogs : true
    };
    var searchParams = {...default_search_params};

    function toggleRadioButtons(checkbox) {
        const parentFieldset = checkbox.closest('.checkbox-container')?.querySelector('.radio-group');
        if(parentFieldset == null) return;
        const radioButtons = parentFieldset.querySelectorAll('input[type="radio"]');
        const radioLabels = parentFieldset.querySelectorAll('label');

        if (checkbox.checked) {
            radioButtons.forEach(radio => radio.disabled = false);
            radioLabels.forEach(label => label.classList.remove('disabled'));
        } else {
            radioButtons.forEach(radio => {
                radio.disabled = true;
                //radio.checked = false; // Uncheck the radio button when the checkbox is unchecked
            });
            radioLabels.forEach(label => label.classList.add('disabled'));
        }
        setChangeHintStatus();
    }

    function toggleNested(parentId) {
        const parentCheckbox = document.getElementById(parentId);
        const enabled = parentCheckbox.checked && !parentCheckbox.disabled;
        const childGroup = parentCheckbox.closest('fieldset');
        if (childGroup) {
        const childCheckboxes = childGroup.querySelectorAll('input[type="checkbox"]:not(#'+parentId+')');
        childCheckboxes.forEach(childCheckbox => {
            let parentElem = childCheckbox.parentElement.closest(".indent").parentElement.closest("fieldset").querySelector("legend input");
            childCheckbox.disabled = !enabled || !parentElem.checked;
            updateState(childCheckbox, childCheckbox.checked && !childCheckbox.disabled);
        });
        }
        updateState(parentCheckbox, enabled);
    }

    function updateState(box, state=true) {
        if(box.name !== undefined && !box.classList.contains("grouping")) {
            searchParams[box.name] = state && box.checked ? true : false;
        } 
        setChangeHintStatus();
    }

    /**
     * takes the filter dialog generated json and modifies the keys to their get_request search_parameter names
     */
    function toRequestJSON(json) {
        var result = {};
        Object.keys(json).forEach(key => result["sp_"+key] = json[key]);
        return result;
    }


    /**
     * takes get_request search_parameter name formatted json and returns filter dialog formatted json
     */
    function fromRequestJSON(json) {
        var result = {};
        Object.keys(json).forEach(key => result[key.substring(3)] = json[key]);
        return result;
    }

    function setFormFromJSON(data) {
        const setRadioButtonValue = (name, value) => {
            let val;
            switch (value) {
                case 1: val = 'in_post'; break;
                case 2: val = 'in_ancestors'; break;
                case 3: val = 'either'; break;
                default: val = 'either';
            }
            const radio = document.querySelector(`input[name="${name}"][value="${val}"]`);
            if (radio) radio.checked = true;
        };
        
        // Handle "Must Have" section
        const mustHaveFields = ['images', 'video', 'audio', 'link', 'chat', 'ask'];
        mustHaveFields.forEach(field => {
            if (data[field] !== undefined) {
                var checkbox = document.querySelector(`input[name="result_must_have"][value="${field}"]`);
                if (checkbox) checkbox.checked = true;
                setRadioButtonValue(field + '_location', data[field]);
                toggleRadioButtons(checkbox);
            }
        });
        if(data["include_reblogs"] != null) {
            include_reblogs_chk.checked = data["include_reblogs"];
        }
        setChangeHintStatus();
    }
    var include_reblogs_chk = document.getElementById("include-reblogs");
    function generateJSON() {
        var result = {};
        // Helper function to get the radio button value
        const getRadioButtonValue = (name) => {
            const radios = document.getElementsByName(name);
            for (let i = 0; i < radios.length; i++) {
                if (radios[i].checked) {
                    return locationToNumber(radios[i].value);
                }
            }
            return null;
        };
        
        var searchOver = {
            ...searchParams
        }
        //"Must Have" section
        const mustHaveFields = ['images', 'video', 'audio', 'link', 'chat', 'ask'];
        mustHaveFields.forEach(field => {
            if (document.querySelector(`input[name="result_must_have"][value="${field}"]`)?.checked) {
                searchOver["fp_"+field] = getRadioButtonValue(field + '_location');
            }
        });
        searchOver["fp_include_reblogs"] = include_reblogs_chk.checked;
        return searchOver;
    }

    function locationToNumber(location) {
        switch(location) {
            case 'in_post':
                return 1;
            case 'in_ancestors':
                return 2;
            case 'either':
                return 3;
            default:
                return null;
        }
    }

    function showAdvanced(){
        document.getElementById("advanced-filter").showModal();
    }
    function closeAdvanced() {
        searchParams = setChangeHintStatus();
        //document.getElementById("formOutput").textContent = JSON.stringify(searchParams);
        document.getElementById("advanced-filter").close();

    }

    function setChangeHintStatus() {
        var newParams = generateJSON();
        var jsp = JSON.stringify(default_search_params);
        var jdefaultsp = JSON.stringify(newParams);
        if(jsp != jdefaultsp) {
            optionsModified();
        } else {
            optionsReset();
        }
        return newParams;
    }

    function removeHint() {
        document.getElementById("formOutput").textContent = "";
        searchParams = JSON.parse(JSON.stringify(default_search_params));
        optionsReset();
    }

    let rateLimit = 200;
    let pendingCall = null;
    document.addEventListener('DOMContentLoaded', function () {
        const dialog = document.querySelector("#advanced-filter");
        const dialogContent = document.getElementById('searchForm');
        dialog.addEventListener('click', (event) => {
            if (event.target !== dialogContent && !dialogContent.contains(event.target)) {
                dialog.close();
            }
        });
        const checkboxes = document.querySelectorAll('.checkbox-container input[type="checkbox"]');

        // Initialize state based on the initial state of checkboxes
        //checkboxes.forEach(checkbox => {
        //    toggleRadioButtons(checkbox);
        //});

        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function () {
                toggleRadioButtons(this);
            });
        });

        

        let advancedButton = document.querySelector("button#show-advanced");
        let noscontparent = document.querySelector("#nosdoubleout-container");
        let weeper = noscontparent.querySelector(".nos-container");
        let searchCont = advancedButton.parentNode;
        let queryCont = searchCont.querySelector("#query");
        searchCont.insertBefore(noscontparent, queryCont);
        //advancedButton.parent.insertAfter(noscontparent, advancedButton);
        function startRainingNo() {
            const elements = weeper.querySelectorAll('.weeping-nos');
            let num = 0;
            elements.forEach(
                (el) => {
                    //el.style.display = 'block';
                    num++;
                    if(el.stayOn == true) return;
                    el.stayOff = false;
                    window.setTimeout(()=>{
                        if(!el.stayOff) {
                            el.stayOn = true;
                            el.style.display = 'block';
                            el.style.left = ((Math.random() * -15)-1) + 'em';
                            el.style.fontSize = (1+(0.2*Math.random()))+'em';
                            el.style.animation = 'rain '+(1.9+Math.random()*.03)+'s cubic-bezier(0.12, -0.01, 0.95, 0.36) infinite';
                        }
                    }, Math.random()*1500);
                }
            );
        }

        function stopRainingNo() {
            const elements = weeper.querySelectorAll('.weeping-nos');
            elements.forEach(el => {
                el.style.display = 'none';
                el.stayOff = true;
                el.stayOn = false;
            });
        }
        document.getElementById("username").addEventListener('keyup', (event)=>{
            let inputField = event.target; 
            if(inputField.value.split('.').length > 1) {
                startRainingNo();
                inputField.classList.add('input-shake');
                return;
            }
            else {
                inputField.classList.remove('input-shake');
                stopRainingNo();
            }
            let value = inputField.value;
            if(value.length <=3) {advancedButton.style.background = "none";}
            else {
                inputField.value = value.split('.tumblr.com')[0];
                newCall = ()=>{advancedButton.style.background = "url(https://api.tumblr.com/v2/blog/"+value+".tumblr.com/avatar/64)";}
                if(pendingCall == null) { 
                    pendingCall = newCall;
                    pendingCall();  
                    window.setTimeout(()=>{pendingCall = null}, rateLimit);
                } else {
                    pendingCall = newCall;
                    window.setTimeout(() => {
                        console.log("g");
                        if(pendingCall != null) {
                            pendingCall();
                            pendingCall = null;
                        } else {"slow down!"}
                    }, rateLimit);
                }
            }
        })

    });

    function optionsModified() {
        document.getElementById('show-advanced').style.setProperty('--notification-content', '""');
    }

    function optionsReset() {
        document.getElementById('show-advanced').style.setProperty('--notification-content', 'none');
    }
</script>
</div>
