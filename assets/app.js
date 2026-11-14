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
          if (el.type === 'radio' && !el.checked) return;
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
      for (var k = 0; k < steps.length; k++) { if (!valid(steps[k])) { e.preventDefault(); show(k); valid(steps[k]); return; } }
      submit.disabled = true; submit.textContent = 'Sending…';
    });
    show(0);
  }
  // Add another beneficiary block
  var addB = document.getElementById('addBenef');
  if (addB) addB.addEventListener('click', function () {
    var wrap = document.getElementById('benefs'), first = wrap.querySelector('.benef');
    var c = first.cloneNode(true);
    c.querySelectorAll('input').forEach(function (i) { i.value = ''; });
    wrap.appendChild(c);
  });
})();
