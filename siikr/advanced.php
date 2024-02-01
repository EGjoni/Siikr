<?php ?>

<div id="advanced-container">
    <dialog id="advanced-filter">
    <form id="searchForm">
   <!-- Search Over Section -->
    <fieldset class="section">
        <legend><h3>Search Over:</h3></legend>
        <div class="grid">
            <!-- plaintext -->
            <div class="checkbox-pt-container">                               
                <div id="over-container">
                    <label class="field-type" id="nothingtop"></label>
                    <label class="field-type" id="imgcheck"><input type="checkbox" name="search_over" value="over_img_text" checked="true" onchange="setChangeHintStatus()"> image alt/captions</label>
                    <label class="field-type" id="nothing"></label>
                    <label class="field-type" id="tagcheck"><input type="checkbox" name="search_over" value="over_tag_text" checked="true" onchange="setChangeHintStatus()"> tagtext</label>
                    
                    <fieldset class="checkbox-container" id="plaintext">
                        <span class="radio-group"> 
                        <legend><label><input type="checkbox" name="search_over" value="over_plaintext" checked="true" onchange="setChangeHintStatus()"> plaintext</label></legend>
                        <label class="radio"><input type="radio" name="over_plaintext_location" value="in_post" onchange="setChangeHintStatus()"> in post</label>
                        <label class="radio"><input type="radio" name="over_plaintext_location" value="in_ancestors" onchange="setChangeHintStatus()"> in ancestors</label>
                        <label class="radio"><input type="radio" name="over_plaintext_location" value="either" checked="true" onchange="setChangeHintStatus()"> either</label>
                        </span>
                    </fieldset>        
                </div>                
            </div>
        </div>
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
<output id="formOutput"></output>

<script>
    const all_possible_params = [
        "sp_self_text", 
	    "sp_trail_text",
	    "sp_image_text",
	    "sp_tag_text",
        "fp_images",
        "fp_video",
        "fp_audio",
        "fp_chat",
        "fp_link",
        "fp_ask"
    ]
    const default_search_params = {
	sp_self_text : true, 
	sp_trail_text : true,
	sp_image_text : true,
	sp_tag_text	: true
    };
    var searchParams = {
	sp_self_text : true, 
	sp_trail_text : true,
	sp_image_text : true,
	sp_tag_text	: true
    };

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
        // Helper function to set the radio button value
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

        // Clear current form selections first
        const allCheckboxes = document.querySelectorAll('input[type="checkbox"]');
        const allRadios = document.querySelectorAll('input[type="radio"]');
        allCheckboxes.forEach(checkbox => checkbox.checked = false);
        //allRadios.forEach(radio => radio.checked = false);

        var overcont = document.querySelector("#over-container");        
        var textcheck = overcont.querySelector('input[name="search_over"][value="over_plaintext"]');
        var searchSelf = data.self_text;
        var searchAncestors = data.trail_text;
        if(!searchSelf && !searchAncestors) {
            textcheck.checked = false;
        } else{
            textcheck.checked = true;
            if(searchSelf)
                setRadioButtonValue("over_plaintext_location", 1);
            if(searchAncestors)
                setRadioButtonValue("over_plaintext_location", 2);
            if(searchSelf && searchAncestors) 
                setRadioButtonValue("over_plaintext_location", 3);
        }
        toggleRadioButtons(textcheck);

        var tagcheck = overcont.querySelector('input[name="search_over"][value="over_tag_text"]');
        var imgcheck = overcont.querySelector('input[name="search_over"][value="over_img_text"]');
        tagcheck.checked = data.tag_text;
        toggleRadioButtons(tagcheck);
        imgcheck.checked = data.image_text;
        toggleRadioButtons(imgcheck);

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
        setChangeHintStatus();
    }
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
        var overcont = document.querySelector("#over-container");
        
        var textcheck = overcont.querySelector('input[name="search_over"][value="over_plaintext"]').checked;
        var tagcheck = overcont.querySelector('input[name="search_over"][value="over_tag_text"]').checked;
        var imgcheck = overcont.querySelector('input[name="search_over"][value="over_img_text"]').checked;

        var searchSelf = overcont.querySelector('input[name="over_plaintext_location"][value="in_post"]').checked;
        var searchAncestors = overcont.querySelector('input[name="over_plaintext_location"][value="in_ancestors"]').checked;
        var searchEither = overcont.querySelector('input[name="over_plaintext_location"][value="either"]').checked;
    
        var searchOver = {
            sp_self_text : textcheck && (searchSelf || searchEither),
            sp_trail_text : textcheck && (searchAncestors || searchEither),
            sp_image_text : imgcheck,
            sp_tag_text : tagcheck
        }
        //"Must Have" section
        const mustHaveFields = ['images', 'video', 'audio', 'link', 'chat', 'ask'];
        mustHaveFields.forEach(field => {
            if (document.querySelector(`input[name="result_must_have"][value="${field}"]`)?.checked) {
                searchOver["fp_"+field] = getRadioButtonValue(field + '_location');
            }
        });

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
        
});

function optionsModified() {
    document.getElementById('show-advanced').style.setProperty('--notification-content', '""');
}

function optionsReset() {
    document.getElementById('show-advanced').style.setProperty('--notification-content', 'none');
}
</script>
</div>
