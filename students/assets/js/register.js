(function () {
  const statusSelect = document.getElementById('statusSelect');
  const centerBox = document.getElementById('centerBox');

  const gradeSelect = document.getElementById('gradeSelect');
  const centerSelect = document.getElementById('centerSelect');
  const groupSelect = document.getElementById('groupSelect');
  const barcodeInput = document.getElementById('barcodeInput');

  function setRequiredForCenterMode(on) {
    if (!centerSelect || !groupSelect || !barcodeInput) return;
    centerSelect.required = !!on;
    groupSelect.required = !!on;
    barcodeInput.required = !!on;
  }

  function toggleCenterUI() {
    const st = statusSelect ? statusSelect.value : 'اونلاين';
    const show = (st === 'سنتر');

    if (centerBox) centerBox.style.display = show ? '' : 'none';
    setRequiredForCenterMode(show);

    if (!show) {
      if (centerSelect) centerSelect.value = '0';
      if (groupSelect) groupSelect.innerHTML = '<option value="0">— اختر المجموعة —</option>';
      if (barcodeInput) barcodeInput.value = '';
    }
  }

  async function fetchGroups() {
    if (!groupSelect || !gradeSelect || !centerSelect) return;

    const gradeId = parseInt(gradeSelect.value || '0', 10);
    const centerId = parseInt(centerSelect.value || '0', 10);

    groupSelect.innerHTML = '<option value="0">— اختر المجموعة —</option>';

    if (!gradeId || !centerId) return;

    try {
      const url = 'register_groups_api.php?grade_id=' + encodeURIComponent(gradeId) + '&center_id=' + encodeURIComponent(centerId);
      const res = await fetch(url, { credentials: 'same-origin' });
      const data = await res.json();

      if (!data || !Array.isArray(data.groups)) return;

      data.groups.forEach((g) => {
        const opt = document.createElement('option');
        opt.value = String(g.id);
        opt.textContent = g.name;
        groupSelect.appendChild(opt);
      });
    } catch (e) {
      // ignore
    }
  }

  if (statusSelect) statusSelect.addEventListener('change', toggleCenterUI);
  if (gradeSelect) gradeSelect.addEventListener('change', fetchGroups);
  if (centerSelect) centerSelect.addEventListener('change', fetchGroups);

  toggleCenterUI();
  fetchGroups();
})();