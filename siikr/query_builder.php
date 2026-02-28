<span class="qb-anchor"><div id="qb-panel" class="qb-panel" style="display:none"></div></span>
<script>
function qbTokenize(str) {
    var tokens = [];
    var i = 0;
    while (i < str.length) {
        var c = str[i];
        // Skip whitespace
        if (c === ' ' || c === '\t' || c === '\n' || c === '\r') { i++; continue; }
        // Quoted phrase
        if (c === '"') {
            var j = i + 1;
            while (j < str.length && str[j] !== '"') j++;
            tokens.push(str.slice(i, j + 1));
            i = j + 1;
            continue;
        }
        // Parens
        if (c === '(' || c === ')') { tokens.push(c); i++; continue; }
        // Unary minus: only when immediately followed by non-whitespace
        if (c === '-' && i + 1 < str.length &&
            str[i+1] !== ' ' && str[i+1] !== '\t' && str[i+1] !== '\n' && str[i+1] !== '\r') {
            tokens.push('-');
            i++;
            continue;
        }
        // Word: run of chars that aren't whitespace, quote, paren
        var k = i;
        while (k < str.length &&
               str[k] !== ' ' && str[k] !== '\t' && str[k] !== '\n' && str[k] !== '\r' &&
               str[k] !== '"' && str[k] !== '(' && str[k] !== ')') {
            k++;
        }
        if (k > i) { tokens.push(str.slice(i, k)); i = k; continue; }
        i++; // skip unknown
    }
    return tokens;
}

function qbParseToTree(str) {
    var tokens = qbTokenize(str);
    var pos = 0;
    function peek() { return pos < tokens.length ? tokens[pos] : null; }
    function consume() { return tokens[pos++]; }

    function parseOrExpr() {
        var items = [];
        var first = parseAndExpr();
        if (first) items.push(first);
        while (peek() && peek().toUpperCase() === 'OR') {
            consume();
            var next = parseAndExpr();
            if (next) items.push(next);
        }
        if (items.length === 0) return null;
        if (items.length === 1) return items[0];
        return {type: 'group', combinator: 'OR', negate: false, items: items};
    }

    function parseAndExpr() {
        var items = [];
        while (peek() && peek().toUpperCase() !== 'OR' && peek() !== ')') {
            var f = parseFactor();
            if (f) items.push(f);
        }
        if (items.length === 0) return null;
        if (items.length === 1) return items[0];
        return {type: 'group', combinator: 'AND', negate: false, items: items};
    }

    function parseFactor() {
        var negate = false;
        if (peek() === '-') { consume(); negate = true; }
        var atom = parseAtom();
        if (!atom) return null;
        atom.negate = negate;
        return atom;
    }

    function parseAtom() {
        var t = peek();
        if (!t) return null;
        if (t === '(') {
            consume();
            var expr = parseOrExpr();
            if (peek() === ')') consume();
            return expr;
        }
        if (t.charAt(0) === '"') {
            consume();
            var inner = t.length >= 2 && t.charAt(t.length - 1) === '"'
                ? t.slice(1, t.length - 1) : t.slice(1);
            return {type: 'term', termType: 'phrase', text: inner, negate: false};
        }
        if (t.toUpperCase() !== 'OR' && t !== ')') {
            consume();
            return {type: 'term', termType: 'word', text: t, negate: false};
        }
        return null;
    }

    var tree = parseOrExpr();
    if (!tree) tree = {type: 'group', combinator: 'AND', negate: false, items: []};
    return tree;
}

function qbRenderNode(nodeData, parentEl, isRoot) {
    if (!nodeData) return;
    if (nodeData.type === 'group') {
        var group = document.createElement('div');
        group.className = 'qb-group';
        group.setAttribute('data-combinator', nodeData.combinator || 'AND');

        var header = document.createElement('div');
        header.className = 'qb-group-header';

        var combBtn = document.createElement('button');
        combBtn.className = 'qb-combinator-btn';
        combBtn.type = 'button';
        combBtn.textContent = nodeData.combinator || 'AND';
        combBtn.setAttribute('onclick', 'qbToggleCombinator(this)');
        header.appendChild(combBtn);

        var negLabel = document.createElement('label');
        negLabel.className = 'qb-negate-label';
        var negChk = document.createElement('input');
        negChk.type = 'checkbox';
        negChk.className = 'qb-negate';
        negChk.setAttribute('onchange', 'qbUpdate()');
        if (nodeData.negate) negChk.checked = true;
        negLabel.appendChild(negChk);
        negLabel.appendChild(document.createTextNode(' NOT'));
        header.appendChild(negLabel);

        var addTermBtn = document.createElement('button');
        addTermBtn.className = 'qb-add-term-btn';
        addTermBtn.type = 'button';
        addTermBtn.textContent = '+ term';
        addTermBtn.setAttribute('onclick', 'qbAddTermToGroup(this)');
        header.appendChild(addTermBtn);

        var addGroupBtn = document.createElement('button');
        addGroupBtn.className = 'qb-add-group-btn';
        addGroupBtn.type = 'button';
        addGroupBtn.textContent = '+ group';
        addGroupBtn.setAttribute('onclick', 'qbAddGroupToGroup(this)');
        header.appendChild(addGroupBtn);

        if (!isRoot) {
            var removeBtn = document.createElement('button');
            removeBtn.className = 'qb-remove-btn';
            removeBtn.type = 'button';
            removeBtn.title = 'Remove';
            removeBtn.innerHTML = '&#x2715;';
            removeBtn.setAttribute('onclick', 'qbRemoveNode(this)');
            header.appendChild(removeBtn);
        }
        group.appendChild(header);

        var itemsEl = document.createElement('div');
        itemsEl.className = 'qb-group-items';
        if (nodeData.items) {
            for (var i = 0; i < nodeData.items.length; i++) {
                if (nodeData.items[i]) qbRenderNode(nodeData.items[i], itemsEl, false);
            }
        }
        group.appendChild(itemsEl);
        parentEl.appendChild(group);

    } else if (nodeData.type === 'term') {
        var term = document.createElement('div');
        term.className = 'qb-term';

        var negLabel2 = document.createElement('label');
        negLabel2.className = 'qb-negate-label';
        var negChk2 = document.createElement('input');
        negChk2.type = 'checkbox';
        negChk2.className = 'qb-negate';
        negChk2.setAttribute('onchange', 'qbUpdate()');
        if (nodeData.negate) negChk2.checked = true;
        negLabel2.appendChild(negChk2);
        negLabel2.appendChild(document.createTextNode(' not'));
        term.appendChild(negLabel2);

        var sel = document.createElement('select');
        sel.className = 'qb-term-type';
        sel.setAttribute('onchange', 'qbUpdate()');
        var optWord = document.createElement('option');
        optWord.value = 'word';
        optWord.textContent = 'words';
        var optPhrase = document.createElement('option');
        optPhrase.value = 'phrase';
        optPhrase.textContent = 'phrase';
        sel.appendChild(optWord);
        sel.appendChild(optPhrase);
        if (nodeData.termType === 'phrase') sel.value = 'phrase';
        term.appendChild(sel);

        var inp = document.createElement('input');
        inp.type = 'text';
        inp.className = 'qb-text';
        inp.value = nodeData.text || '';
        inp.setAttribute('oninput', 'qbUpdate()');
        inp.setAttribute('onkeyup', 'seekIfSubmit(event)');
        term.appendChild(inp);

        var removeBtn2 = document.createElement('button');
        removeBtn2.className = 'qb-remove-btn';
        removeBtn2.type = 'button';
        removeBtn2.title = 'Remove';
        removeBtn2.innerHTML = '&#x2715;';
        removeBtn2.setAttribute('onclick', 'qbRemoveNode(this)');
        term.appendChild(removeBtn2);

        parentEl.appendChild(term);
    }
}

function qbSerializeNode(el, isRoot) {
    if (el.classList.contains('qb-term')) {
        var negate = el.querySelector('.qb-negate').checked;
        var type = el.querySelector('.qb-term-type').value;
        var text = el.querySelector('.qb-text').value.trim();
        if (!text) return '';
        if (type === 'phrase') {
            return (negate ? '-' : '') + '"' + text + '"';
        }
        var words = text.split(/\s+/).filter(Boolean);
        return words.map(function(w) { return (negate ? '-' : '') + w; }).join(' ');
    }
    if (el.classList.contains('qb-group')) {
        var combinator = el.getAttribute('data-combinator') || 'AND';
        var negate = el.querySelector(':scope > .qb-group-header > .qb-negate-label > .qb-negate').checked;
        var itemsEl = el.querySelector(':scope > .qb-group-items');
        var children = itemsEl ? itemsEl.children : [];
        var parts = [];
        for (var i = 0; i < children.length; i++) {
            var s = qbSerializeNode(children[i], false);
            if (s) parts.push(s);
        }
        if (parts.length === 0) return '';
        var joined = combinator === 'OR' ? parts.join(' OR ') : parts.join(' ');
        // Add parens if non-root, or if root but negated (to keep semantics unambiguous)
        if (!isRoot || negate) joined = '(' + joined + ')';
        if (negate) joined = '-' + joined;
        return joined;
    }
    return '';
}

function qbUpdate() {
    var root = document.querySelector('#qb-panel > .qb-group');
    var qf = document.getElementById('query');
    if (root && qf) qf.value = qbSerializeNode(root, true);
}

function qbSyncFromQuery() {
    var panel = document.getElementById('qb-panel');
    if (!panel || panel.style.display === 'none' || panel.style.display === '') return;
    var q = document.getElementById('query').value;
    qbPopulatePanel(q);
}

function qbPopulatePanel(q) {
    var panel = document.getElementById('qb-panel');
    var existing = panel.querySelector('.qb-group');
    if (existing) existing.remove();
    var tree = q.trim() ? qbParseToTree(q) : {type: 'group', combinator: 'AND', negate: false, items: []};
    if (tree.type === 'term') tree = {type: 'group', combinator: 'AND', negate: false, items: [tree]};
    qbRenderNode(tree, panel, true);
}

function qbToggleCombinator(btn) {
    var group = btn.closest('.qb-group');
    var current = group.getAttribute('data-combinator') || 'AND';
    var next = current === 'AND' ? 'OR' : 'AND';
    group.setAttribute('data-combinator', next);
    btn.textContent = next;
    qbUpdate();
}

function qbAddTermToGroup(btn) {
    var group = btn.closest('.qb-group');
    var itemsEl = group.querySelector(':scope > .qb-group-items');
    qbRenderNode({type: 'term', termType: 'word', text: '', negate: false}, itemsEl, false);
    qbUpdate();
}

function qbAddGroupToGroup(btn) {
    var group = btn.closest('.qb-group');
    var itemsEl = group.querySelector(':scope > .qb-group-items');
    qbRenderNode({
        type: 'group', combinator: 'AND', negate: false,
        items: [{type: 'term', termType: 'word', text: '', negate: false}]
    }, itemsEl, false);
    qbUpdate();
}

function qbRemoveNode(btn) {
    var node = btn.closest('.qb-term') || btn.closest('.qb-group');
    if (node) node.remove();
    qbUpdate();
}

function qbToggle() {
    var panel = document.getElementById('qb-panel');
    var toggleBtn = document.getElementById('qb-toggle');
    if (panel.style.display === 'none' || panel.style.display === '') {
        var qf = document.getElementById('query');
        var q = qf ? qf.value.trim() : '';
        qbPopulatePanel(q);
        panel.style.display = 'block';
        if (toggleBtn) toggleBtn.classList.add('qb-toggle-active');
    } else {
        panel.style.display = 'none';
        if (toggleBtn) toggleBtn.classList.remove('qb-toggle-active');
    }
}
</script>
