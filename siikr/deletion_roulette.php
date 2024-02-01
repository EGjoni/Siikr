<style>
@keyframes hslAnimation {
0%, 100% {
    background-color: hsl(0, 02%, 10%);
}
25% {
    background-color: hsl(90, 02%, 12%);
}
50% {
    background-color: hsl(180, 02%, 10%);
}
75% {
    background-color: hsl(270, 02%, 12%);
}
}

@keyframes blip {
0%, 100% {
    transform: translate(0,0);
    filter: url(#noise-blur-filter) blur(2.8px);
    opacity: 0.1;
}
99% {
    transform: translate(2px, 5px) rotate(-2deg);
    filter: url(#noise-blur-filter) blur(4px);
    opacity: 0.12;
}

99.5% {
    transform: translate(-2px, 5px) rotate(2deg);
    filter: blur(1px);
    opacity: 0.2;
}
}

body {

    animation: hslAnimation 10s infinite cubic-bezier(0.42, 0, 0.52, 0.88) !important;

}

.roul h1 {
    /*filter: url(#noise-blur-filter) blur(2.8px);*/
    opacity: 0.1;
    animation:blip 4s infinite cubic-bezier(1, 2.18, 0, 0.15);
}

.roul h2 {
    opacity: 0.3;
    filter: url(#noise-blur-filter) blur(1.2px);
}


.roul h3 {
    opacity: 0.5;
    filter: blur(2px);
}

.roul h4 {
    opacity: 0.7;
    filter: blur(1.2px);
}

.roul h5 {
    opacity: 0.9;
    filter: blur(0.8px);
}

.roul div {
    font-size: 0.7em;
}

div.roul {
    font-size: 1.5em;
    color: white !important;
    font-weight: lighter !important;
    font-family: monospace;
    text-align: center;
    width: 98vw;
    position: absolute;
    margin-top: 5vh;
}

#countdown {
  font-size: 2rem;
  text-align: center;
}

#countdown div {
  display: inline-block;
  padding: 10px;
  background-color: #333;
  color: #fff;
  border-radius: 5px;
}

#condolences * {
    font-size: 1.1em;
    filter: none;
    color: gray;
}

#condolences h2 {
    font-size: 2em;
    opacity: 1;
}
li {
    text-align: left;
}

ul {
    text-align: left !important;
    display: flex;
    flex-direction: column;
    max-height: 30vh;
    width: 50vw !important;
    margin: auto;
    font-size: 1.3em !important;
    align-content: space-around;
    flex-wrap: wrap;
    justify-content: flex-start;
    align-items: flex-start;
    justify-items: start;
}


</style>

<div class="roul">
    <div>Siikr is out of diskspace.</div>
    <h5>Each blog is special and unique in its own way.</h5>
    <h4>None of them deserve this.</h4>
    <h3>Every day is a new trolley problem.</h3>
    <h2>And the only choice</h2>
    <h1>is murder.</h1>


    <br><br>
    <div>
    <span id="round-text">This round of Blog Elimination Roulette ends in</span>
    <div id="countdown">
        <div id="hours">00</div>:
        <div id="minutes">00</div>:
        <div id="seconds">00</div>:
        <div id="milliseconds">000</div>
    </div>
    <br><br>
    Our deepest condolonces to our late contestants.
    <div id="condolences">
        <h2>RIP:</h2>
        <ul>
            <li>nutsacktorture</li>
            <li>themadcapmathematician</li>
            <li>peoplescommissariat</li>
            <li>lokizen</li>
            <li>cyb3rjew</li>            
            <li>nocakeno</li>
            <li>thefuzzhead                 </li>
            <li>cats2019forthenintendoswitch</li>
            <li>princess-of-the-corner      </li>
            <li>fortunechaos                </li>
            <li>queernuck                   </li>
            <li>mostlysignssomeportents     </li>
            <li>lightkrets312               </li>
            <li>whimsycore                  </li>
            <li>charlesoberonn              </li>
            <li>nostalgebraist-autoresponder</li>
            <li>997                         </li>
            <li>demilypyro                  </li>
            <li>animate-mush                </li>
            <li>ultra-strawberry-lemonade   </li>
            <li>sablesablesablesable        </li>
            <!-- <li></li>
            <!-- <li></li>
            <!-- <li></li>
            <!-- <li></li>
            <!-- <li></li>
            <!-- <li></li>
            <!-- <li></li>
            <!-- <li></li>
            <!-- <li></li>
            <!-- <li></li>
            <!-- <li></li>
            <!-- <li></li>
            <!-- <li></li> -->
        </ul>

    </div>
    <!--Siikr should be back in a few hours. Sorry in advance if your blog is chosen.-->


    </div>
</div>
<script>

let stdistance = <?php echo (((1706590800+3600)-time())*1000);?>;

const started = new Date().getTime();
function updateCountdown() {
  let delta = new Date().getTime() - started;
  let distance = stdistance - delta;
  const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
  const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
  const seconds = Math.floor((distance % (1000 * 60)) / 1000);
  const milliseconds = Math.floor((distance % (1000)));

  document.getElementById('hours').innerText = hours.toString().padStart(2, '0');
  document.getElementById('minutes').innerText = minutes.toString().padStart(2, '0');
  document.getElementById('seconds').innerText = seconds.toString().padStart(2, '0');
  document.getElementById('milliseconds').innerText = milliseconds.toString().padStart(3, '0');

  if (distance <= 0) {
    clearInterval(interval);
    document.getElementById('countdown').innerHTML = '';
    document.getElementById('round-text').innerHTML = "This round of Blog Elimination is over. Please be patient as we clean up.";
  }
}
const interval = setInterval(updateCountdown, 1);
updateCountdown(); // Update the countdown once immediately

</script>
<svg xmlns="http://www.w3.org/2000/svg" version="1.1" width="0" height="0">
    <defs>
        <filter id="noise-blur-filter">
        <feTurbulence type="fractalNoise" baseFrequency="1.5" numOctaves="5" result="turbulence" seed="4"></feTurbulence>
            <feDisplacementMap in2="turbulence" in="SourceGraphic" scale="15.2" xChannelSelector="R" yChannelSelector="G" result="displaced"></feDisplacementMap>
            <feGaussianBlur in="displaced" stdDeviation="1" result="blurred"></feGaussianBlur>
            <feComposite operator="over" in="displaced" in2="blurred" result="penultimate"></feComposite>
            <feTurbulence type="fractalNoise" baseFrequency="0.005" numOctaves="5" result="turbulence2" seed="5"></feTurbulence>
            <feDisplacementMap in2="turbulence2" in="penultimate" scale="8" xChannelSelector="R" yChannelSelector="G"></feDisplacementMap>
        </filter>
    </defs>
</svg>
