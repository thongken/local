/** Teacher course PDF-to-Quiz frontend controller with AI preview & refinement before saving to Moodle. */
define(['core/ajax', 'core/str'], function(Ajax, Str) {
    const KEY_STORAGE = 'local_pdfquiz_gemini_key';
    const QUICK_CHIPS = [
        'Viết toàn bộ tóm tắt và câu hỏi bằng tiếng Việt',
        'Làm câu hỏi chi tiết và khó hơn',
        'Viết tóm tắt dài và đầy đủ ý hơn',
        'Giải thích đáp án kỹ hơn',
        'Rewrite everything in English',
    ];

    function randomHex32() {
        const bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
    }

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
            const root = document.getElementById('pdfquiz-course-app');
            if (!root) {
                return;
            }
            const strKeys = [
                'quiztitle', 'questioncount', 'pdfsource', 'uploadnewpdf', 'choosecoursepdf',
                'nocoursepdfs', 'advancedoptions', 'pagefrom', 'pageto', 'geminimodel',
                'geminiapikey', 'apikeyplaceholder', 'apikeysystemconfigured', 'apikeysystemonly',
                'rememberkey', 'generatequiz', 'processingpdf', 'savingquiz', 'retrycreatequiz',
                'quizcreated', 'viewquiz', 'editquestions', 'quizsettings', 'backtocourse',
                'summary', 'mainpoints', 'segment', 'answer', 'explanation', 'missingapikey',
                'unsupportedsource', 'pdfsize',
                'savetomoodle', 'previewheading', 'refineheading', 'refineplaceholder',
                'refinebutton', 'refining', 'refinedsuccess', 'refineempty',
            ];
            const list = await Str.get_strings(strKeys.map(key => ({key, component: 'local_pdfquiz'})));
            const S = {};
            strKeys.forEach((k, i) => { S[k] = list[i]; });

            const sources = Array.isArray(cfg.sources) ? cfg.sources : [];
            const maxMB = formatMB(cfg.maxFileBytes || (50 * 1024 * 1024));
            const savedKey = cfg.allowUserKey ? (window.localStorage.getItem(KEY_STORAGE) || '') : '';

            let pendingResult = null;

            const sourceOptionsHtml = sources.length
                ? sources.map(s => `<option value="${s.id}">${escapeHtml(s.label)}</option>`).join('')
                : `<option value="">${escapeHtml(S.nocoursepdfs)}</option>`;

            const apiKeyBlock = cfg.allowUserKey
                ? `<div class="mb-3">
                        <label class="form-label fw-bold" for="pq-apikey">${escapeHtml(S.geminiapikey)}</label>
                        <input type="password" id="pq-apikey" class="form-control" value="${escapeHtml(savedKey)}"
                               placeholder="${escapeHtml(S.apikeyplaceholder)}" autocomplete="off">
                        ${cfg.hasSystemKey ? `<div class="form-text text-success">${escapeHtml(S.apikeysystemconfigured)}</div>` : ''}
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="pq-remember" ${savedKey ? 'checked' : ''}>
                            <label class="form-check-label" for="pq-remember">${escapeHtml(S.rememberkey)}</label>
                        </div>
                   </div>`
                : `<div class="alert alert-secondary py-2 mb-3">${escapeHtml(S.apikeysystemonly)}</div>`;

            root.innerHTML = `
                <div class="card shadow-sm mb-3">
                    <div class="card-body">
                        <form id="pq-form">
                            <div class="mb-3">
                                <label class="form-label fw-bold" for="pq-title">${escapeHtml(S.quiztitle)}</label>
                                <input type="text" id="pq-title" class="form-control" maxlength="255" required
                                       placeholder="VD: Kiểm tra chương 1 - Giáo trình PDF">
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold" for="pq-count">${escapeHtml(S.questioncount)}</label>
                                    <input type="number" id="pq-count" class="form-control" min="1" max="30" value="5" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold d-block">${escapeHtml(S.pdfsource)}</label>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="pq_mode" id="pq-mode-upload" value="upload" checked>
                                    <label class="form-check-label" for="pq-mode-upload">${escapeHtml(S.uploadnewpdf)}</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="pq_mode" id="pq-mode-course" value="course" ${sources.length ? '' : 'disabled'}>
                                    <label class="form-check-label" for="pq-mode-course">${escapeHtml(S.choosecoursepdf)}</label>
                                </div>
                            </div>

                            <div id="pq-upload-wrap" class="mb-3">
                                <input type="file" id="pq-file" class="form-control" accept=".pdf,application/pdf">
                                <div class="form-text">PDF (.pdf), tối đa ${maxMB} MB, tối đa ${cfg.maxPages || 100} trang.</div>
                            </div>

                            <div id="pq-course-wrap" class="mb-3 d-none">
                                <select id="pq-course-file" class="form-select">${sourceOptionsHtml}</select>
                            </div>

                            ${apiKeyBlock}

                            <details class="mb-3">
                                <summary class="fw-bold mb-2">${escapeHtml(S.advancedoptions)}</summary>
                                <div class="row g-3 mt-1">
                                    <div class="col-md-3">
                                        <label class="form-label" for="pq-pagefrom">${escapeHtml(S.pagefrom)}</label>
                                        <input type="number" id="pq-pagefrom" class="form-control" min="1" placeholder="1">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label" for="pq-pageto">${escapeHtml(S.pageto)}</label>
                                        <input type="number" id="pq-pageto" class="form-control" min="1" placeholder="${cfg.maxPages || 100}">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label" for="pq-model">${escapeHtml(S.geminimodel)}</label>
                                        <input type="text" id="pq-model" class="form-control" value="${escapeHtml(cfg.defaultModel || 'gemini/gemini-3.5-flash-lite')}">
                                    </div>
                                </div>
                            </details>

                            <button type="submit" id="pq-submit" class="btn btn-primary">${escapeHtml(S.generatequiz)}</button>
                        </form>
                    </div>
                </div>
                <div id="pq-status"></div>
                <div id="pq-result"></div>
            `;

            const form = root.querySelector('#pq-form');
            const titleEl = root.querySelector('#pq-title');
            const countEl = root.querySelector('#pq-count');
            const modeUpload = root.querySelector('#pq-mode-upload');
            const modeCourse = root.querySelector('#pq-mode-course');
            const uploadWrap = root.querySelector('#pq-upload-wrap');
            const courseWrap = root.querySelector('#pq-course-wrap');
            const fileEl = root.querySelector('#pq-file');
            const courseSelectEl = root.querySelector('#pq-course-file');
            const apiKeyEl = root.querySelector('#pq-apikey');
            const rememberEl = root.querySelector('#pq-remember');
            const pageFromEl = root.querySelector('#pq-pagefrom');
            const pageToEl = root.querySelector('#pq-pageto');
            const modelEl = root.querySelector('#pq-model');
            const submitBtn = root.querySelector('#pq-submit');
            const statusBox = root.querySelector('#pq-status');
            const resultBox = root.querySelector('#pq-result');

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

            function showLoading(msg) {
                statusBox.innerHTML = `<div class="alert alert-info pdfquiz-progress">
                    <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                    <span>${escapeHtml(msg)}</span>
                </div>`;
            }

            function showError(msg, allowRetry) {
                statusBox.innerHTML = `<div class="alert alert-danger">
                    <div>${escapeHtml(msg)}</div>
                    ${allowRetry ? `<button type="button" id="pq-retry-btn" class="btn btn-sm btn-warning mt-2">${escapeHtml(S.retrycreatequiz)}</button>` : ''}
                </div>`;
                const retryBtn = statusBox.querySelector('#pq-retry-btn');
                if (retryBtn) {
                    retryBtn.addEventListener('click', () => saveQuizToMoodle());
                }
            }

            function renderSuccess(quiz, meta) {
                statusBox.innerHTML = `<div class="alert alert-success">
                    <h5 class="alert-heading mb-2">${escapeHtml(S.quizcreated)}</h5>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <a href="${escapeHtml(quiz.viewurl)}" class="btn btn-primary btn-sm">${escapeHtml(S.viewquiz)}</a>
                        <a href="${escapeHtml(quiz.editurl)}" class="btn btn-outline-primary btn-sm">${escapeHtml(S.editquestions)}</a>
                        <a href="${escapeHtml(quiz.settingsurl)}" class="btn btn-outline-secondary btn-sm">${escapeHtml(S.quizsettings)}</a>
                        <a href="${escapeHtml(quiz.courseurl)}" class="btn btn-link btn-sm">${escapeHtml(S.backtocourse)}</a>
                    </div>
                </div>`;

                if (meta && meta.content) {
                    const rs = meta.routing_summary || {};
                    const sum = meta.content.summary || {};
                    const points = Array.isArray(sum.main_points) ? sum.main_points : [];
                    resultBox.innerHTML = `<div class="card mb-3">
                        <div class="card-body">
                            <p class="text-muted small mb-2">
                                Trang: <strong>${rs.total || meta.pagecount || 1}</strong>
                                (Text: ${rs.text_heavy || 0}, Mixed: ${rs.mixed || 0}, Visual: ${rs.visual_heavy || 0}, OCR: ${meta.ocr_pages_count || 0})
                                · Thời gian xử lý: ${meta.processing_time_seconds || 0}s
                                · Số câu hỏi đã lưu: <strong>${quiz.questioncount}</strong>
                            </p>
                            <h5>${escapeHtml(S.summary)}</h5>
                            <p>${escapeHtml(sum.short_summary || '')}</p>
                            ${points.length ? `<h6>${escapeHtml(S.mainpoints)}</h6><ul>${points.map(p => `<li>${escapeHtml(p)}</li>`).join('')}</ul>` : ''}
                        </div>
                    </div>`;
                }
            }

            function renderPreview(meta, refineNotice) {
                const content = meta.content || {};
                const rs = meta.routing_summary || {};
                const sum = content.summary || {};
                const points = Array.isArray(sum.main_points) ? sum.main_points : [];
                const segments = Array.isArray(content.segments) ? content.segments : [];

                let totalQ = 0;
                let segsHtml = '';
                segments.forEach((seg, i) => {
                    const pageInfo = (seg.page_start || seg.page_end)
                        ? ` (Trang ${seg.page_start || 1}–${seg.page_end || seg.page_start || 1})`
                        : '';
                    let qsHtml = '';
                    (seg.quiz || []).forEach((q, j) => {
                        totalQ++;
                        const optsHtml = (q.options || []).map(o => {
                            const isCorrect = String(o).trim() === String(q.correct_answer || '').trim();
                            return `<li class="${isCorrect ? 'pdfquiz-option-correct' : ''}">${escapeHtml(o)}${isCorrect ? ' ✓' : ''}</li>`;
                        }).join('');
                        qsHtml += `
                            <div class="border rounded p-3 mb-2">
                                <h6>${j + 1}. ${escapeHtml(q.question)}</h6>
                                <ol type="A">${optsHtml}</ol>
                                <p class="text-muted small mb-0"><strong>${escapeHtml(S.explanation)}:</strong> ${escapeHtml(q.explanation || '')}</p>
                            </div>
                        `;
                    });
                    segsHtml += `
                        <div class="card mb-3">
                            <div class="card-body">
                                <h5>${escapeHtml(S.segment)} ${i + 1}${escapeHtml(pageInfo)}</h5>
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
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                <h5 class="mb-0">${escapeHtml(S.previewheading)}</h5>
                                <button type="button" id="pq-save-moodle-btn" class="btn btn-success">${escapeHtml(S.savetomoodle)} (${totalQ})</button>
                            </div>
                            <p class="text-muted small mb-3">
                                Trang: <strong>${rs.total || meta.pagecount || 1}</strong>
                                (Text: ${rs.text_heavy || 0}, Mixed: ${rs.mixed || 0}, Visual: ${rs.visual_heavy || 0}, OCR: ${meta.ocr_pages_count || 0})
                                · Thời gian: ${meta.processing_time_seconds || 0}s
                            </p>

                            <label class="form-label fw-bold mb-2" for="pq-refine-input">${escapeHtml(S.refineheading)}</label>
                            <div class="d-flex flex-wrap gap-1 mb-2">${chipsHtml}</div>
                            <div class="input-group">
                                <input type="text" id="pq-refine-input" class="form-control" maxlength="1000"
                                       placeholder="${escapeHtml(S.refineplaceholder)}">
                                <button type="button" id="pq-refine-btn" class="btn btn-primary">${escapeHtml(S.refinebutton)}</button>
                            </div>
                            <div id="pq-refine-status" class="mt-2">${refineNotice ? `<div class="alert alert-success py-2 mb-0">${escapeHtml(refineNotice)}</div>` : ''}</div>
                        </div>
                    </div>

                    <div class="card mb-3">
                        <div class="card-body">
                            <h5>${escapeHtml(S.summary)}</h5>
                            <p>${escapeHtml(sum.short_summary || '')}</p>
                            ${points.length ? `<h6>${escapeHtml(S.mainpoints)}</h6><ul>${points.map(p => `<li>${escapeHtml(p)}</li>`).join('')}</ul>` : ''}
                        </div>
                    </div>
                    ${segsHtml}
                `;

                const saveMoodleBtn = resultBox.querySelector('#pq-save-moodle-btn');
                saveMoodleBtn.addEventListener('click', () => saveQuizToMoodle());

                const refineInput = resultBox.querySelector('#pq-refine-input');
                const refineBtn = resultBox.querySelector('#pq-refine-btn');
                const refineStatus = resultBox.querySelector('#pq-refine-status');
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
                    if (!pendingResult) {
                        return;
                    }
                    refineBtn.disabled = true;
                    saveMoodleBtn.disabled = true;
                    refineStatus.innerHTML = `<div class="alert alert-info py-2 mb-0 pdfquiz-progress">
                        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                        <span>${escapeHtml(S.refining)}</span>
                    </div>`;
                    try {
                        const fd = new FormData();
                        fd.append('sesskey', cfg.sesskey);
                        fd.append('courseid', String(cfg.courseid));
                        fd.append('feedback', feedback);
                        fd.append('extractedtext', pendingResult.extractedtext);
                        fd.append('content', JSON.stringify(pendingResult.content));
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
                        pendingResult.content = refined.content;
                        const notice = S.refinedsuccess.replace('{$a}', refined.processing_time_seconds || 0);
                        renderPreview(pendingResult, notice);
                    } catch (err) {
                        refineBtn.disabled = false;
                        saveMoodleBtn.disabled = false;
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
            }

            async function saveQuizToMoodle() {
                if (!pendingResult) {
                    return;
                }
                showLoading(S.savingquiz);
                try {
                    const quiz = await Ajax.call([{
                        methodname: 'local_pdfquiz_create_quiz',
                        args: {
                            requestid: pendingResult.requestid,
                            extractedtext: pendingResult.extractedtext,
                            content: JSON.stringify(pendingResult.content),
                            pagecount: pendingResult.pagecount || 1,
                        },
                    }])[0];
                    renderSuccess(quiz, pendingResult);
                    pendingResult = null;
                } catch (err) {
                    const msg = (err && (err.message || err.error)) || 'Failed to save Quiz in Moodle.';
                    showError(msg, true);
                }
            }

            form.addEventListener('submit', async(e) => {
                e.preventDefault();
                resultBox.innerHTML = '';
                pendingResult = null;

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
                        showError(S.unsupportedsource, false);
                        return;
                    }
                    if (file.size > cfg.maxFileBytes) {
                        showError(S.pdfsize.replace('{$a}', maxMB), false);
                        return;
                    }
                } else if (!courseSelectEl.value) {
                    showError(S.nocoursepdfs, false);
                    return;
                }

                submitBtn.disabled = true;
                showLoading(S.processingpdf);

                try {
                    const fd = new FormData();
                    fd.append('sesskey', cfg.sesskey);
                    fd.append('courseid', String(cfg.courseid));
                    fd.append('section', String(cfg.section));
                    fd.append('beforemod', String(cfg.beforemod || 0));
                    fd.append('title', title);
                    fd.append('questioncount', String(count));
                    fd.append('requestkey', randomHex32());
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

                    const resp = await fetch(cfg.prepareUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: fd,
                    });
                    const data = await resp.json();
                    if (!resp.ok || data.error || data.exception) {
                        throw new Error(data.error || data.message || ('HTTP ' + resp.status));
                    }
                    statusBox.innerHTML = '';
                    if (data.quiz) {
                        renderSuccess(data.quiz, data);
                        return;
                    }
                    pendingResult = data;
                    renderPreview(pendingResult, '');
                } catch (err) {
                    const msg = (err && (err.message || err.error)) || 'PDF processing failed.';
                    showError(msg, !!pendingResult);
                } finally {
                    submitBtn.disabled = false;
                }
            });
        }
    };
});
