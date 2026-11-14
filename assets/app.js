(function () {
  // Confirm buttons: <button data-confirm="Text">
  document.addEventListener('submit', function (e) {
    var b = e.submitter;
    var msg = (b && b.getAttribute('data-confirm')) || e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) e.preventDefault();
  });
  // Copy buttons: <button data-copy="text">
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var t = b.getAttribute('data-copy');
      (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () {
        var o = b.textContent; b.textContent = 'Copied'; setTimeout(function () { b.textContent = o; }, 1500);
      }).catch(function () { window.prompt('Copy this:', t); });
    });
  });
  // Auto receive amount = amount × rate
  var amt = document.getElementById('send_amount'), rate = document.getElementById('rate'), rec = document.getElementById('receive_amount');
  if (amt && rate && rec) {
    var manual = !!rec.value;
    rec.addEventListener('input', function () { manual = !!rec.value; });
    var calc = function () {
      var a = parseFloat(amt.value), r = parseFloat(rate.value);
      if (!manual && !isNaN(a) && !isNaN(r)) rec.value = (a * r).toFixed(2);
    };
    amt.addEventListener('input', calc); rate.addEventListener('input', calc);
  }
  // Fill the exchange rate with today's sell rate when the currencies change
  var rm = document.getElementById('rateMap'), sc = document.getElementById('send_currency'), rc = document.getElementById('receive_currency');
  if (rm && sc && rc && rate) {
    var map = {}; try { map = JSON.parse(rm.textContent || '{}'); } catch (e) {}
    var touched = false; rate.addEventListener('input', function () { touched = true; });
    var upd = function () { var k = sc.value + '>' + rc.value; if (!touched && map[k]) { rate.value = map[k]; rate.dispatchEvent(new Event('input')); touched = false; } };
    sc.addEventListener('change', upd); rc.addEventListener('change', upd);
  }
  // Native share sheet on phones (WhatsApp, SMS, etc.), copy as a fallback
  document.querySelectorAll('[data-share]').forEach(function (b) {
    b.addEventListener('click', function () {
      var url = b.getAttribute('data-share'), text = b.getAttribute('data-share-text') || url;
      if (navigator.share) { navigator.share({ title: 'DhanXfer', text: text }).catch(function () {}); return; }
      (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () {
        var o = b.textContent; b.textContent = 'Copied – paste it in WhatsApp'; setTimeout(function () { b.textContent = o; }, 2500);
      }).catch(function () { window.prompt('Copy this:', text); });
    });
  });
  // Tables become stacked cards on phones: give each cell its column name
  document.querySelectorAll('.tablewrap table').forEach(function (t) {
    var heads = Array.prototype.map.call(t.querySelectorAll('thead th'), function (th) { return th.textContent.trim(); });
    t.querySelectorAll('tbody tr').forEach(function (tr) {
      Array.prototype.forEach.call(tr.children, function (td, i) { if (heads[i] && !td.hasAttribute('colspan')) td.setAttribute('data-label', heads[i]); });
    });
  });
  // Conditional questions: <div data-show-if="name=value|value2&other=x">. Hidden questions are disabled,
  // so they are neither checked nor sent.
  var condEls = document.querySelectorAll('[data-show-if]');
  var fieldVals = function (form, name) {
    var out = [];
    form.querySelectorAll('[name="' + name + '"]').forEach(function (el) {
      if ((el.type === 'radio' || el.type === 'checkbox') ? el.checked : true) out.push(el.value);
    });
    return out;
  };
  var applyCond = function () {
    condEls.forEach(function (el) {
      var form = el.closest('form') || document;
      var show = el.getAttribute('data-show-if').split('&').every(function (part) {
        var kv = part.split('='), want = (kv[1] || '').split('|'), have = fieldVals(form, kv[0]);
        return have.some(function (h) { return want.indexOf(h) >= 0; });
      });
      el.hidden = !show;
      el.querySelectorAll('input,select,textarea').forEach(function (i) { i.disabled = !show; });
    });
  };
  if (condEls.length) { document.addEventListener('change', applyCond); applyCond(); }
  // Phone: show the country code for the chosen country; quick check for Canada/US (10 digits). The server checks fully.
  var ctry = document.getElementById('country'), ph = document.getElementById('phone'), dial = document.getElementById('dialShow'), dc = document.getElementById('dialCodes');
  if (ctry && ph && dial && dc) {
    var codes = {}; try { codes = JSON.parse(dc.textContent || '{}'); } catch (e) {}
    var hint = document.getElementById('phoneHint');
    var checkPhone = function () {
      var raw = ph.value.trim(), d = raw.replace(/\D/g, ''), code = codes[ctry.value] || '';
      dial.textContent = raw.charAt(0) === '+' ? '+' : (code || '+');
      var msg = '';
      if (raw && /[^0-9+\s().\-\/]/.test(raw)) msg = 'Use digits only.';
      else if (raw && raw.charAt(0) !== '+' && code === '+1') { var n = (d.length === 11 && d.charAt(0) === '1') ? d.slice(1) : d; if (n.length !== 10) msg = 'Canadian/US numbers have 10 digits (' + n.length + ' entered).'; }
      else if (raw && raw.charAt(0) !== '+' && !code) msg = 'Start with + and the country code.';
      else if (raw && (d.length < 7 || d.length > 15)) msg = 'That number looks too ' + (d.length < 7 ? 'short.' : 'long.');
      ph.setCustomValidity(msg);
      if (hint) { hint.textContent = msg || (raw ? 'Looks right.' : 'Spaces and dashes are fine. For another country, start with + and its code.'); hint.className = msg ? 'field-bad' : (raw ? 'field-ok' : 'tag'); }
    };
    ctry.addEventListener('change', checkPhone); ph.addEventListener('input', checkPhone); checkPhone();
  }
  // Address lookup (only when the provider is switched on): pick a suggestion to fill the boxes.
  var addr = document.querySelector('input[data-lookup="1"]'), list = document.getElementById('addrList'), src = document.getElementById('address_source');
  if (addr && list) {
    var tmr = null, tokEl = document.querySelector('input[name="csrf"]');
    var fill = function (a) {
      [['address', a.line1], ['city', a.city], ['province', a.province], ['postal', a.postal]].forEach(function (p) { var el = document.getElementById(p[0]); if (el) el.value = p[1] || ''; });
      if (src) src.value = 'lookup';
      list.hidden = true; list.innerHTML = '';
    };
    addr.addEventListener('input', function () {
      if (src) src.value = 'manual';
      clearTimeout(tmr);
      var q = addr.value.trim(); if (q.length < 4) { list.hidden = true; return; }
      tmr = setTimeout(function () {
        fetch('/addr.php?q=' + encodeURIComponent(q) + '&country=' + encodeURIComponent(ctry ? ctry.value : ''), { headers: { 'X-CSRF': tokEl ? tokEl.value : '' }, credentials: 'same-origin' })
          .then(function (r) { return r.ok ? r.json() : []; }).then(function (rows) {
            list.innerHTML = '';
            rows.forEach(function (a) { var b = document.createElement('button'); b.type = 'button'; b.textContent = a.label; b.addEventListener('click', function () {
              if (a.line1) { fill(a); return; }
              // two-step providers: fetch the full address for the picked suggestion
              fetch('/addr.php?id=' + encodeURIComponent(a.id || ''), { headers: { 'X-CSRF': tokEl ? tokEl.value : '' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; }).then(function (full) { if (full && full.line1) fill(full); else list.hidden = true; }).catch(function () { list.hidden = true; });
            }); list.appendChild(b); });
            list.hidden = !rows.length;
          }).catch(function () { list.hidden = true; });
      }, 300);
    });
    ['city', 'province', 'postal'].forEach(function (k) { var el = document.getElementById(k); if (el) el.addEventListener('input', function () { if (src) src.value = 'manual'; }); });
    document.addEventListener('click', function (e) { if (!list.contains(e.target) && e.target !== addr) list.hidden = true; });
  }
  // Step-by-step form
  var wf = document.querySelector('form.wizard');
  if (wf) {
    var steps = Array.prototype.slice.call(wf.querySelectorAll('.step'));
    var head = wf.querySelector('.wiz-head'), back = document.getElementById('wizBack'), next = document.getElementById('wizNext'),
        skip = document.getElementById('wizSkip'), submit = document.getElementById('wizSubmit');
    var cur = 0;
    wf.classList.add('js');
    head.hidden = false; back.hidden = false; next.hidden = false;
    var labelFor = function (el) {
      var l = el.id && wf.querySelector('label[for="' + el.id + '"]');
      if (!l) { var ff = el.closest('.f'); l = ff && ff.querySelector('label'); }
      if (l) return l.textContent.replace(/\*|\(.*?\)/g, '').trim();
      var f = el.closest('.f'); var s = f && f.querySelector('.lbl'); return s ? s.textContent.replace('*', '').trim() : el.name;
    };
    var review = function () {
      var box = document.getElementById('wizReview'); if (!box) return;
      var html = '';
      steps.forEach(function (st, i) {
        if (st.hasAttribute('data-review')) return;
        var rows = '';
        st.querySelectorAll('input,select,textarea').forEach(function (el) {
          if (el.disabled || ((el.type === 'radio' || el.type === 'checkbox') && !el.checked)) return;
          var val = el.type === 'file' ? (el.files && el.files[0] ? el.files[0].name : '') : (el.tagName === 'SELECT' ? (el.value ? el.options[el.selectedIndex].text : '') : el.value);
          if (!val || el.type === 'hidden') return;
          rows += '<dt>' + esc(labelFor(el)) + '</dt><dd>' + esc(val) + '</dd>';
        });
        html += '<div class="rv"><div class="row" style="justify-content:space-between"><strong>' + esc(st.getAttribute('data-title')) + '</strong><button type="button" class="linkbtn" data-goto="' + i + '">Edit</button></div>' +
          (rows ? '<dl class="kv">' + rows + '</dl>' : '<div class="tag">Nothing added</div>') + '</div>';
      });
      box.innerHTML = html;
      box.querySelectorAll('[data-goto]').forEach(function (b) { b.addEventListener('click', function () { show(+b.getAttribute('data-goto')); }); });
    };
    function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
    function show(i) {
      cur = Math.max(0, Math.min(i, steps.length - 1));
      steps.forEach(function (st, k) { st.classList.toggle('on', k === cur); });
      var st = steps[cur], last = cur === steps.length - 1;
      document.getElementById('wizTitle').textContent = st.getAttribute('data-title');
      document.getElementById('wizCount').textContent = 'Step ' + (cur + 1) + ' of ' + steps.length;
      document.getElementById('wizFill').style.width = ((cur + 1) / steps.length * 100) + '%';
      back.style.visibility = cur === 0 ? 'hidden' : 'visible';
      next.hidden = last; submit.hidden = !last;
      skip.hidden = !st.hasAttribute('data-optional');
      if (st.hasAttribute('data-review')) review();
      window.scrollTo({ top: wf.getBoundingClientRect().top + window.pageYOffset - 70, behavior: 'smooth' });
      var first = st.querySelector('input:not([type=hidden]):not([readonly]),select,textarea');
      if (first && cur > 0 && window.innerWidth > 700) first.focus({ preventScroll: true });
    }
    function valid(st) {
      var bad = Array.prototype.find.call(st.querySelectorAll('input,select,textarea'), function (el) { return !el.checkValidity(); });
      if (bad) { bad.reportValidity(); return false; }
      return true;
    }
    next.addEventListener('click', function () { if (valid(steps[cur])) show(cur + 1); });
    skip.addEventListener('click', function () { steps[cur].querySelectorAll('input').forEach(function (i) { i.value = ''; }); show(cur + 1); });
    back.addEventListener('click', function () { show(cur - 1); });
    wf.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && e.target.tagName === 'INPUT' && cur < steps.length - 1) { e.preventDefault(); next.click(); }
    });
    wf.addEventListener('submit', function (e) {
      if (e.submitter && e.submitter.value === 'draft') return; // saving for later checks nothing
      for (var k = 0; k < steps.length; k++) { if (!valid(steps[k])) { e.preventDefault(); show(k); valid(steps[k]); return; } }
      submit.disabled = true; submit.textContent = 'Sending…';
    });
    show(0);
  }
  // Add another beneficiary block
  var addB = document.getElementById('addBenef');
  if (addB) addB.addEventListener('click', function () {
    var wrap = document.getElementById('benefs'), first = wrap.querySelector('.benef');
    var max = parseInt(addB.getAttribute('data-max') || '10', 10);
    if (wrap.querySelectorAll('.benef').length >= max) return;
    var c = first.cloneNode(true);
    c.querySelectorAll('input').forEach(function (i) { i.value = ''; i.removeAttribute('id'); });
    c.querySelectorAll('label').forEach(function (l) { l.removeAttribute('for'); });
    c.querySelectorAll('.rm-benef').forEach(function (x) { x.remove(); });
    var rm = document.createElement('button'); rm.type = 'button'; rm.className = 'btn sm ghost rm-benef'; rm.textContent = 'Remove this person';
    rm.addEventListener('click', function () { c.remove(); addB.hidden = false; });
    c.appendChild(rm);
    wrap.appendChild(c);
    if (wrap.querySelectorAll('.benef').length >= max) addB.hidden = true;
    var f = c.querySelector('input'); if (f) f.focus();
  });
})();
