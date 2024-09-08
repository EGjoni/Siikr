<?php ?>
<div id="word-cloud">
        <div id="wordcloud-head"><h2>Blog Fingerprint:</h2></div>
        <div id="positive-words" class="feature-container"></div>
        <div id="negative-words" class="feature-container"></div>
    </div>
	<!--<span style="color: white; opacity: 0.7; grid-column:help; font-size: 1.2em; padding: 15px; margin-top:60px; font-weight:thin;">Note: If this is your first time using siikr, please do not search a blog just to generate a word cloud. For now, <b>word clouds will only appear for blogs that were already indexed <u>at least two days prior</u> to announcement of the word cloud feature.</b></span><span style="color: white; font-size:0.95em; grid-column: help; padding: 10px;"> You are of course more than welcome to search your blog if you just want to find something, but do be mindful of the disk space indicator at the bottom right.</span>-->
<script>
        
function lerp(t, min, max) {
    return (t*(max-min))+min;
}
function ilerp(val, min, max) {
    return (val-min)/(max-min);
}

var minWordSize = 0.7;
var maxWordSize = 2;
async function resetWordCloud(blog_uuid) {
    let wcContainer = document.getElementById("word-cloud");
    let positives = document.getElementById("positive-words");
    let negatives = document.getElementById("negative-words");  
    let wordDefault = document.querySelector("#templates .word-feature");
    
    wcContainer.style.display = 'none';
    positives.innerHTML = '';
    negatives.innerHTML = '';
    
    try {
        let wordcloudwait = await fetch("get_fingerprint.php?blog_uuid="+blog_uuid);
        let wordcloudInfo = await wordcloudwait.json();
        if(wordcloudInfo.unavailable == true) {
            wcContainer.style.display = 'none';
        } else {
            wcContainer.style.display = 'block';
            let posMax = 0; let posMin = 9999999;
            wordcloudInfo.overused.forEach(word => { 
                posMax = Math.max(posMax, word.appearance_z_score); 
                posMin = Math.min(posMin, word.appearance_z_score); 
            });
            let zPosScale = Math.max(Math.min(4.0, posMax), 2);
            wordcloudInfo.overused.forEach(word => {
                let wordElem = wordDefault.cloneNode('deep');
                wordElem.querySelector('.lexeme').innerText = word.lexeme;
                let boundedZ = ilerp(word.appearance_z_score, posMin, posMax); 
                let rescaled = lerp(boundedZ, 0.5, zPosScale);
                //let percentile = rescaled;//normalCDF(rescaled);
                let hpercent = boundedZ * boundedZ;
                let fontSize = lerp(boundedZ, minWordSize, maxWordSize);
                let r = "128";
                let g = 255*rescaled+"";
                let b = "128"
                wordElem.style.color = 'rgb('+r+', '+g+', '+b+')';
                wordElem.style.fontSize = fontSize+'em';
                //word.percentile = normalCDF(word.appearance_z_score);
                wordElem.word = word;
                wordElem.querySelector(".percentile-val").innerText = (word.percentile*100).toFixed(6)+"th"; 
                wordElem.querySelector(".post-appearances-expected-val").innerText = (word.avg_post_freq * wordcloudInfo.total_posts_on_stat).toFixed(4);
                wordElem.querySelector(".post-appearances-actual-val").innerText =  word.ndoc;
                wordElem.querySelector(".post-nentry-expected-val").innerText =  (word.avg_blog_freq * wordcloudInfo.estimated_total_words).toFixed(4);
                wordElem.querySelector(".post-nentry-actual-val").innerText =  word.nentry;
                positives.appendChild(wordElem);  
                                   
            });
            let negMax = 0; let negMin = 99999999;
            wordcloudInfo.underused.forEach(word => { 
                negMax = Math.max(negMax, -word.appearance_z_score); 
                negMin = Math.min(negMin, -word.appearance_z_score); 
            });
            let zNegScale = Math.max(Math.min(4.0, negMax), 2);
            wordcloudInfo.underused.forEach(word => {
                let wordElem = wordDefault.cloneNode('deep');
                wordElem.querySelector('.lexeme').innerText = word.lexeme;
                let boundedZ = ilerp(-word.appearance_z_score, negMin, negMax);
                let rescaled = lerp(boundedZ, 0.5, zNegScale);
                //let negPercentile = rescaled;//normalCDF(rescaled);
                //let hpercent = 2*(negPercentile-0.5);
                //hpercent *= hpercent;
                let fontSize = lerp(boundedZ, minWordSize, maxWordSize);
                let r = (255*rescaled)+"";
                let g = "128";                    
                let b = "128"
                wordElem.style.color = 'rgb('+r+', '+g+', '+b+')';
                wordElem.style.fontSize = fontSize+'em';
                //word.percentile = normalCDF(word.appearance_z_score);
                wordElem.word = word;
                wordElem.querySelector(".percentile-val").innerText = (word.percentile*100).toFixed(6)+"%";
                wordElem.querySelector(".post-appearances-expected-val").innerText = (word.avg_post_freq * wordcloudInfo.total_posts_on_stat).toFixed(4);
                wordElem.querySelector(".post-appearances-actual-val").innerText =  word.ndoc;
                wordElem.querySelector(".post-nentry-expected-val").innerText =  (word.avg_blog_freq * wordcloudInfo.estimated_total_words).toFixed(4);
                wordElem.querySelector(".post-nentry-actual-val").innerText =  word.nentry;   
                negatives.appendChild(wordElem);
            });
        }
    } catch(e) {}
    
}
function erf(x) {
    const a1 =  0.254829592;
    const a2 = -0.284496736;
    const a3 =  1.421413741;
    const a4 = -1.453152027;
    const a5 =  1.061405429;
    const p  =  0.3275911;

    const sign = (x >= 0) ? 1 : -1;
    x = Math.abs(x);

    const t = 1.0 / (1.0 + p*x);
    const y = 1.0 - (((((a5*t + a4)*t) + a3)*t + a2)*t + a1)*t*Math.exp(-x*x);

    return sign * y;
}

function normalCDF(x) {
    return 0.5 * (1 + erf(x / Math.sqrt(2)));
}
</script>