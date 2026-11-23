/** Student / Personal "PDF to quiz" frontend controller with AI refinement bar. */
define(['core/ajax', 'core/str'], function(Ajax, Str) {
    const KEY_STORAGE = 'local_pdfquiz_gemini_key';
    const QUICK_CHIPS = [
        'Viết toàn bộ tóm tắt và câu hỏi bằng tiếng Việt',
        'Làm câu hỏi chi tiết và khó hơn',
        'Viết tóm tắt dài và đầy đủ ý hơn',
        'Giải thích đáp án kỹ hơn',
        'Rewrite everything in English',
    ];

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    function formatMB(bytes) {
        return Math.max(1, Math.round(bytes / (1024 * 1024)));
    }

    return {
        init: async function(cfg) {
            const root = document.getElementById('pdfquiz-personal-app');
            if (!root) {
                return;
            }
            const strKeys = [
                'titlefield', 'questioncount', 'pdfsource', 'uploadnewpdf', 'choosecoursepdf',
                'nocoursepdfs', 'advancedoptions', 'pagefrom', 'pageto', 'geminimodel',
                'geminiapikey', 'apikeyplaceholder', 'apikeysystemconfigured', 'apikeysystemonly',
                'rememberkey', 'generatepractice', 'processingpdf', 'savehistory', 'savedtohistory',
                'summary', 'mainpoints', 'segment', 'showanswer', 'answer', 'extractedtext',
                'unsupportedsource', 'pdfsize', 'open',
                'refineheading', 'refineplaceholder', 'refinebutton', 'refining', 'refinedsuccess', 'refineempty',
            ];
            const list = await Str.get_strings(strKeys.map(key => ({key, component: 'local_pdfquiz'})));
            const S = {};
            strKeys.forEach((k, i) => { S[k] = list[i]; });

            const sources = Array.isArray(cfg.sources) ? cfg.sources : [];
            const maxMB = formatMB(cfg.maxFileBytes || (50 * 1024 * 1024));
            const savedKey = cfg.allowUserKey ? (window.localStorage.getItem(KEY_STORAGE) || '') : '';
            let lastOutput = null;

            const sourceOptionsHtml = sources.length
                ? sources.map(s => `<option value="${s.id}">${escapeHtml(s.label)}</option>`).join('')
                : `<option value="">${escapeHtml(S.nocoursepdfs)}</option>`;

            const apiKeyBlock = cfg.allowUserKey
                ? `<div class="mb-3">
                        <label class="form-label fw-bold" for="pp-apikey">${escapeHtml(S.geminiapikey)}</label>
                        <input type="password" id="pp-apikey" class="form-control" value="${escapeHtml(savedKey)}"
                               placeholder="${escapeHtml(S.apikeyplaceholder)}" autocomplete="off">
                        ${cfg.hasSystemKey ? `<div class="form-text text-success">${escapeHtml(S.apikeysystemconfigured)}</div>` : ''}
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="pp-remember" ${savedKey ? 'checked' : ''}>
                            <label class="form-check-label" for="pp-remember">${escapeHtml(S.rememberkey)}</label>
                        </div>
                   </div>`
                : `<div class="alert alert-secondary py-2 mb-3">${escapeHtml(S.apikeysystemonly)}</div>`;

            root.innerHTML = `
                <div class="card shadow-sm mb-3">
                    <div class="card-body">
                        <form id="pp-form">
                            <div class="mb-3">
                                <label class="form-label fw-bold" for="pp-title">${escapeHtml(S.titlefield)}</label>
                                <input type="text" id="pp-title" class="form-control" maxlength="255" required
                                       placeholder="VD: Ôn tập chương 1">
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold" for="pp-count">${escapeHtml(S.questioncount)}</label>
                                    <input type="number" id="pp-count" class="form-control" min="1" max="30" value="5" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold d-block">${escapeHtml(S.pdfsource)}</label>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="pp_mode" id="pp-mode-upload" value="upload" checked>
                                    <label class="form-check-label" for="pp-mode-upload">${escapeHtml(S.uploadnewpdf)}</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="pp_mode" id="pp-mode-course" value="course" ${sources.length ? '' : 'disabled'}>
                                    <label class="form-check-label" for="pp-mode-course">${escapeHtml(S.choosecoursepdf)}</label>
                                </div>
                            </div>

                            <div id="pp-upload-wrap" class="mb-3">
                                <input type="file" id="pp-file" class="form-control" accept=".pdf,application/pdf">
                                <div class="form-text">PDF (.pdf), tối đa ${maxMB} MB, tối đa ${cfg.maxPages || 100} trang.</div>
                            </div>

                            <div id="pp-course-wrap" class="mb-3 d-none">
                                <select id="pp-course-file" class="form-select">${sourceOptionsHtml}</select>
                            </div>

                            ${apiKeyBlock}

                            <details class="mb-3">
                                <summary class="fw-bold mb-2">${escapeHtml(S.advancedoptions)}</summary>
                                <div class="row g-3 mt-1">
                                    <div class="col-md-3">
                                        <label class="form-label" for="pp-pagefrom">${escapeHtml(S.pagefrom)}</label>
                                        <input type="number" id="pp-pagefrom" class="form-control" min="1" placeholder="1">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label" for="pp-pageto">${escapeHtml(S.pageto)}</label>
                                        <input type="number" id="pp-pageto" class="form-control" min="1" placeholder="${cfg.maxPages || 100}">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label" for="pp-model">${escapeHtml(S.geminimodel)}</label>
                                        <input type="text" id="pp-model" class="form-control" value="${escapeHtml(cfg.defaultModel || 'gemini/gemini-3.5-flash-lite')}">
                                    </div>
                                </div>
                            </details>

                            <button type="submit" id="pp-submit" class="btn btn-primary">${escapeHtml(S.generatepractice)}</button>
                        </form>
                    </div>
                </div>
                <div id="pp-status"></div>
                <div id="pp-result"></div>
            `;

            const form = root.querySelector('#pp-form');
            const titleEl = root.querySelector('#pp-title');
            const countEl = root.querySelector('#pp-count');
            const modeUpload = root.querySelector('#pp-mode-upload');
            const modeCourse = root.querySelector('#pp-mode-course');
            const uploadWrap = root.querySelector('#pp-upload-wrap');
            const courseWrap = root.querySelector('#pp-course-wrap');
            const fileEl = root.querySelector('#pp-file');
            const courseSelectEl = root.querySelector('#pp-course-file');
            const apiKeyEl = root.querySelector('#pp-apikey');
            const rememberEl = root.querySelector('#pp-remember');
            const pageFromEl = root.querySelector('#pp-pagefrom');
            const pageToEl = root.querySelector('#pp-pageto');
            const modelEl = root.querySelector('#pp-model');
            const submitBtn = root.querySelector('#pp-submit');
            const statusBox = root.querySelector('#pp-status');
            const resultBox = root.querySelector('#pp-result');

            function syncMode() {
                const isCourse = modeCourse.checked;
                uploadWrap.classList.toggle('d-none', isCourse);
                courseWrap.classList.toggle('d-none', !isCourse);
            }
            modeUpload.addEventListener('change', syncMode);
            modeCourse.addEventListener('change', syncMode);

            fileEl.addEventListener('change', () => {
                if (!titleEl.value.trim() && fileEl.files && fileEl.files[0]) {
                    titleEl.value = fileEl.files[0].name.replace(/\.pdf$/i, '');
                }
            });
            courseSelectEl.addEventListener('change', () => {
                if (!titleEl.value.trim() && courseSelectEl.value) {
                    const found = sources.find(s => String(s.id) === String(courseSelectEl.value));
                    if (found) {
                        titleEl.value = found.filename.replace(/\.pdf$/i, '');
                    }
                }
            });

            function renderResult(data, title, refineNotice) {
                const content = data.content || {};
                const sum = content.summary || {};
                const points = Array.isArray(sum.main_points) ? sum.main_points : [];
                const segments = Array.isArray(content.segments) ? content.segments : [];
                const rs = data.routing_summary || {};

                let segsHtml = '';
                segments.forEach((seg, i) => {
                    const pageInfo = (seg.page_start || seg.page_end)
                        ? ` (Trang ${seg.page_start || 1}–${seg.page_end || seg.page_start || 1})`
                        : '';
                    let qsHtml = '';
                    (seg.quiz || []).forEach((q, j) => {
                        const optsHtml = (q.options || []).map(o => `<li>${escapeHtml(o)}</li>`).join('');
                        qsHtml += `
                            <div class="border rounded p-3 mb-2">
                                <h6>${j + 1}. ${escapeHtml(q.question)}</h6>
                                <ol type="A">${optsHtml}</ol>
                                <details>
                                    <summary>${escapeHtml(S.showanswer)}</summary>
                                    <p class="mt-2 mb-1"><strong>${escapeHtml(S.answer)}:</strong> <span class="pdfquiz-option-correct">${escapeHtml(q.correct_answer)}</span></p>
                                    <p class="mb-0 text-muted">${escapeHtml(q.explanation || '')}</p>
                                </details>
                            </div>
                        `;
                    });
                    segsHtml += `
                        <div class="card mb-3">
                            <div class="card-body">
                                <h4>${escapeHtml(S.segment)} ${i + 1}${escapeHtml(pageInfo)}</h4>
                                <p>${escapeHtml(seg.segment_summary || '')}</p>
                                ${qsHtml}
                            </div>
                        </div>
                    `;
                });

                const chipsHtml = QUICK_CHIPS.map(chip =>
                    `<button type="button" class="btn btn-outline-secondary btn-sm pdfquiz-chip" data-chip="${escapeHtml(chip)}">${escapeHtml(chip)}</button>`
                ).join('');

                resultBox.innerHTML = `
                    <div class="card mb-3 border-primary pdfquiz-refine-bar">
                        <div class="card-body">
                            <label class="form-label fw-bold mb-2" for="pp-refine-input">${escapeHtml(S.refineheading)}</label>
                            <div class="d-flex flex-wrap gap-1 mb-2">${chipsHtml}</div>
                            <div class="input-group">
                                <input type="text" id="pp-refine-input" class="form-control" maxlength="1000"
                                       placeholder="${escapeHtml(S.refineplaceholder)}">
                                <button type="button" id="pp-refine-btn" class="btn btn-primary">${escapeHtml(S.refinebutton)}</button>
                            </div>
                            <div id="pp-refine-status" class="mt-2">${refineNotice ? `<div class="alert alert-success py-2 mb-0">${escapeHtml(refineNotice)}</div>` : ''}</div>
                        </div>
                    </div>

                    <div class="card mb-3 border-success">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                <h4 class="mb-0">${escapeHtml(title)}</h4>
                                <button type="button" id="pp-save-btn" class="btn btn-success btn-sm">${escapeHtml(S.savehistory)}</button>
                            </div>
                            <p class="text-muted small mb-2">
                                Trang: <strong>${rs.total || data.pagecount || 1}</strong>
                                (Text: ${rs.text_heavy || 0}, Mixed: ${rs.mixed || 0}, Visual: ${rs.visual_heavy || 0}, OCR: ${data.ocr_pages_count || 0})
                                · Thời gian: ${data.processing_time_seconds || 0}s
                            </p>
                            <div id="pp-save-msg"></div>
                            <h5>${escapeHtml(S.summary)}</h5>
                            <p>${escapeHtml(sum.short_summary || '')}</p>
                            ${points.length ? `<h6>${escapeHtml(S.mainpoints)}</h6><ul>${points.map(p => `<li>${escapeHtml(p)}</li>`).join('')}</ul>` : ''}
                        </div>
                    </div>
                    ${segsHtml}
                    <details class="mb-3">
                        <summary>${escapeHtml(S.extractedtext)}</summary>
                        <pre class="border rounded p-3 mt-2 bg-light" style="white-space:pre-wrap;max-height:360px;overflow:auto;">${escapeHtml(data.extractedtext || '')}</pre>
                    </details>
                `;

                const refineInput = resultBox.querySelector('#pp-refine-input');
                const refineBtn = resultBox.querySelector('#pp-refine-btn');
                const refineStatus = resultBox.querySelector('#pp-refine-status');
                resultBox.querySelectorAll('.pdfquiz-chip').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const text = btn.getAttribute('data-chip') || '';
                        const cur = refineInput.value.trim();
                        refineInput.value = cur ? (cur + '; ' + text) : text;
                        refineInput.focus();
                    });
                });

                async function handleRefine() {
                    const feedback = refineInput.value.trim();
                    if (!feedback) {
                        refineStatus.innerHTML = `<div class="alert alert-warning py-2 mb-0">${escapeHtml(S.refineempty)}</div>`;
                        refineInput.focus();
                        return;
                    }
                    if (!lastOutput) {
                        return;
                    }
                    refineBtn.disabled = true;
                    refineStatus.innerHTML = `<div class="alert alert-info py-2 mb-0 pdfquiz-progress">
                        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                        <span>${escapeHtml(S.refining)}</span>
                    </div>`;
                    try {
                        const fd = new FormData();
                        fd.append('sesskey', cfg.sesskey);
                        fd.append('feedback', feedback);
                        fd.append('extractedtext', lastOutput.extractedtext);
                        fd.append('content', JSON.stringify(lastOutput.content));
                        fd.append('questioncount', String(parseInt(countEl.value, 10) || 5));
                        if (modelEl.value.trim()) {
                            fd.append('model', modelEl.value.trim());
                        }
                        const apiKey = apiKeyEl ? apiKeyEl.value.trim() : '';
                        if (apiKey) {
                            fd.append('apikey', apiKey);
                        }
                        const resp = await fetch(cfg.refineUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            body: fd,
                        });
                        const refined = await resp.json();
                        if (!resp.ok || refined.error || refined.exception) {
                            throw new Error(refined.error || refined.message || ('HTTP ' + resp.status));
                        }
                        lastOutput.content = refined.content;
                        data.content = refined.content;
                        const notice = S.refinedsuccess.replace('{$a}', refined.processing_time_seconds || 0);
                        renderResult(data, title, notice);
                    } catch (err) {
                        refineBtn.disabled = false;
                        const msg = (err && (err.message || err.error)) || 'Refinement failed.';
                        refineStatus.innerHTML = `<div class="alert alert-danger py-2 mb-0">${escapeHtml(msg)}</div>`;
                    }
                }

                refineBtn.addEventListener('click', handleRefine);
                refineInput.addEventListener('keydown', (ev) => {
                    if (ev.key === 'Enter') {
                        ev.preventDefault();
                        handleRefine();
                    }
                });

                const saveBtn = resultBox.querySelector('#pp-save-btn');
                const saveMsg = resultBox.querySelector('#pp-save-msg');
                saveBtn.addEventListener('click', async() => {
                    if (!lastOutput) {
                        return;
                    }
                    saveBtn.disabled = true;
                    try {
                        const res = await Ajax.call([{
                            methodname: 'local_pdfquiz_save_item',
                            args: {
                                title: lastOutput.title,
                                extractedtext: lastOutput.extractedtext,
                                content: JSON.stringify(lastOutput.content),
                                pagecount: lastOutput.pagecount || 1,
                            },
                        }])[0];
                        const viewUrl = cfg.viewBaseUrl + '?id=' + encodeURIComponent(res.id);
                        saveMsg.innerHTML = `<div class="alert alert-success py-2">
                            ${escapeHtml(S.savedtohistory)}
                            <a href="${escapeHtml(viewUrl)}" class="alert-link ms-2">${escapeHtml(S.open)}</a>
                        </div>`;
                    } catch (err) {
                        saveBtn.disabled = false;
                        const msg = (err && (err.message || err.error)) || 'Could not save to history.';
                        saveMsg.innerHTML = `<div class="alert alert-danger py-2">${escapeHtml(msg)}</div>`;
                    }
                });
            }

            form.addEventListener('submit', async(e) => {
                e.preventDefault();
                resultBox.innerHTML = '';
                lastOutput = null;

                const title = titleEl.value.trim();
                const count = parseInt(countEl.value, 10) || 5;
                const isCourse = modeCourse.checked;
                const apiKey = apiKeyEl ? apiKeyEl.value.trim() : '';

                if (cfg.allowUserKey && rememberEl) {
                    if (rememberEl.checked && apiKey) {
                        window.localStorage.setItem(KEY_STORAGE, apiKey);
                    } else {
                        window.localStorage.removeItem(KEY_STORAGE);
                    }
                }

                if (!title) {
                    titleEl.focus();
                    return;
                }
                if (!isCourse) {
                    const file = fileEl.files && fileEl.files[0];
                    if (!file || !/\.pdf$/i.test(file.name)) {
                        statusBox.innerHTML = `<div class="alert alert-danger">${escapeHtml(S.unsupportedsource)}</div>`;
                        return;
                    }
                    if (file.size > cfg.maxFileBytes) {
                        statusBox.innerHTML = `<div class="alert alert-danger">${escapeHtml(S.pdfsize.replace('{$a}', maxMB))}</div>`;
                        return;
                    }
                } else if (!courseSelectEl.value) {
                    statusBox.innerHTML = `<div class="alert alert-danger">${escapeHtml(S.nocoursepdfs)}</div>`;
                    return;
                }

                submitBtn.disabled = true;
                statusBox.innerHTML = `<div class="alert alert-info pdfquiz-progress">
                    <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                    <span>${escapeHtml(S.processingpdf)}</span>
                </div>`;

                try {
                    const fd = new FormData();
                    fd.append('sesskey', cfg.sesskey);
                    fd.append('questioncount', String(count));
                    if (isCourse) {
                        fd.append('sourcefileid', String(courseSelectEl.value));
                    } else {
                        fd.append('source', fileEl.files[0]);
                    }
                    if (pageFromEl.value) {
                        fd.append('pagefrom', pageFromEl.value);
                    }
                    if (pageToEl.value) {
                        fd.append('pageto', pageToEl.value);
                    }
                    if (modelEl.value.trim()) {
                        fd.append('model', modelEl.value.trim());
                    }
                    if (apiKey) {
                        fd.append('apikey', apiKey);
                    }

                    const resp = await fetch(cfg.processUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: fd,
                    });
                    const data = await resp.json();
                    if (!resp.ok || data.error || data.exception) {
                        throw new Error(data.error || data.message || ('HTTP ' + resp.status));
                    }
                    statusBox.innerHTML = '';
                    lastOutput = {
                        title,
                        extractedtext: data.extractedtext || '',
                        content: data.content || {},
                        pagecount: data.pagecount || 1,
                    };
                    renderResult(data, title, '');
                } catch (err) {
                    const msg = (err && (err.message || err.error)) || 'PDF processing failed.';
                    statusBox.innerHTML = `<div class="alert alert-danger">${escapeHtml(msg)}</div>`;
                } finally {
                    submitBtn.disabled = false;
                }
            });
        }
    };
});
