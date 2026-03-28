/* PDF Preview Modal using PDF.js
   الهدف: معاينة داخل نافذة منبثقة بدون أدوات المتصفح (تحميل/طباعة/فتح).
*/

(function () {
  const modal = document.getElementById('pdfPreviewModal');
  const closeBtn = document.getElementById('pdfPreviewClose');
  const titleEl = document.getElementById('pdfPreviewTitle');
  const canvas = document.getElementById('pdfPreviewCanvas');
  const pageInfo = document.getElementById('pdfPreviewPageInfo');

  const prevBtn = document.getElementById('pdfPrevPage');
  const nextBtn = document.getElementById('pdfNextPage');
  const zoomInBtn = document.getElementById('pdfZoomIn');
  const zoomOutBtn = document.getElementById('pdfZoomOut');

  if (!modal || !canvas || !closeBtn) return;

  let pdfDoc = null;
  let pageNum = 1;
  let scale = 1.2;
  let isRendering = false;
  let pendingPage = null;

  function openModal() {
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';

    pdfDoc = null;
    pageNum = 1;
    scale = 1.2;

    const ctx = canvas.getContext('2d');
    if (ctx) ctx.clearRect(0, 0, canvas.width, canvas.height);

    titleEl.textContent = 'معاينة PDF';
    pageInfo.textContent = '';
  }

  function queueRenderPage(num) {
    if (isRendering) {
      pendingPage = num;
      return;
    }
    renderPage(num);
  }

  async function renderPage(num) {
    if (!pdfDoc) return;
    isRendering = true;

    const page = await pdfDoc.getPage(num);
    const viewport = page.getViewport({ scale });

    const ctx = canvas.getContext('2d');
    canvas.height = viewport.height;
    canvas.width = viewport.width;

    await page.render({ canvasContext: ctx, viewport }).promise;

    pageInfo.textContent = `${num} / ${pdfDoc.numPages}`;
    isRendering = false;

    if (pendingPage !== null) {
      const p = pendingPage;
      pendingPage = null;
      renderPage(p);
    }
  }

  async function loadPdf(url, title) {
    titleEl.textContent = title || 'معاينة PDF';
    openModal();

    pdfDoc = await window.pdfjsLib.getDocument(url).promise;
    pageNum = 1;
    queueRenderPage(pageNum);
  }

  closeBtn.addEventListener('click', (e) => {
    e.preventDefault();
    closeModal();
  });

  modal.addEventListener('click', (e) => {
    if (e.target === modal) closeModal();
  });

  window.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
  });

  prevBtn && prevBtn.addEventListener('click', () => {
    if (!pdfDoc || pageNum <= 1) return;
    pageNum--;
    queueRenderPage(pageNum);
  });

  nextBtn && nextBtn.addEventListener('click', () => {
    if (!pdfDoc || pageNum >= pdfDoc.numPages) return;
    pageNum++;
    queueRenderPage(pageNum);
  });

  zoomInBtn && zoomInBtn.addEventListener('click', () => {
    if (!pdfDoc) return;
    scale = Math.min(scale + 0.2, 3);
    queueRenderPage(pageNum);
  });

  zoomOutBtn && zoomOutBtn.addEventListener('click', () => {
    if (!pdfDoc) return;
    scale = Math.max(scale - 0.2, 0.6);
    queueRenderPage(pageNum);
  });

  window.openPdfPreview = function (url, title) {
    loadPdf(url, title);
  };
})();