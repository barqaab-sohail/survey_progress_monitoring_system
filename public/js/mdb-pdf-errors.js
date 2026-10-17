export function pdfLoadErrorMessage(error, phase = 'document') {
    if (phase === 'library' || /worker/i.test(error?.message || '')) {
        return 'The PDF viewer could not start. Use Open PDF to view the file. Ask the administrator to check the PDF.js module and worker files on this server.';
    }
    const status = Number(error?.status);
    if (status === 401 || status === 403) return 'PDF access was denied. Sign in again or ask the administrator to check your survey access.';
    if (status === 404 || error?.name === 'MissingPDFException') return 'The PDF file could not be found. Ask the administrator to check the retained original and storage reference.';
    if (status === 503) return 'PDF storage is temporarily unavailable. Retry shortly; if this continues, ask the administrator to check the original file and storage connection.';
    if (error?.name === 'InvalidPDFException') return 'The server did not return a valid PDF. Use Open PDF to check the file or your sign-in.';
    return 'Could not open PDF. Use Open PDF to check the file. Ask the administrator to check the PDF request in the browser Network tab.';
}

export function clearPdfFallback(container) {
    container.querySelector('[data-pdf-fallback]')?.remove();
}

export function showPdfFallback(container, url, page = 1) {
    clearPdfFallback(container);
    const frame = container.ownerDocument.createElement('iframe');
    frame.dataset.pdfFallback = 'true';
    frame.title = 'Survey PDF in browser viewer';
    frame.src = url.split('#')[0] + '#page=' + Math.max(1, Math.trunc(Number(page)) || 1);
    frame.style.cssText = 'display:block;width:100%;height:100%;min-height:180px;border:0;background:white';
    container.prepend(frame);
}
