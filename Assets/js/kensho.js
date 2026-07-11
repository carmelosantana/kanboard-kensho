/* Kensho — buildless dashboard behavior. No inline script (CSP default-src 'self').
 *
 * Wires: tab switching; a unified manual+AI area-card editor (works fully by
 * hand — AI only appends editable, unsaved suggestions); goals collection;
 * the three AI flows routed through a privacy-summary confirm modal. Every
 * mutating request reuses the single CSRF token embedded once on the root
 * element (Kanboard's checkCSRFForm() validates a stateless, non-consumed
 * HMAC token, so one token per page load is valid for every call).
 */
(function () {
    'use strict';

    function boot() {
        var root = document.querySelector('[data-kensho-root]');
        if (!root) {
            return;
        }

        var csrf = root.getAttribute('data-csrf') || '';
        var urls = {
            propose: root.getAttribute('data-url-propose'),
            clarify: root.getAttribute('data-url-clarify'),
            reflect: root.getAttribute('data-url-reflect'),
            areas: root.getAttribute('data-url-areas'),
            goals: root.getAttribute('data-url-goals')
        };

        setupTabs(root);
        setupImportanceOutputs(root);
        setupActions(root, csrf, urls);
    }

    // ── Tabs ─────────────────────────────────────────────────────────────────

    function setupTabs(root) {
        var tabs = root.querySelectorAll('[data-kensho-tab]');
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var name = tab.getAttribute('data-kensho-tab');
                root.querySelectorAll('[data-kensho-panel]').forEach(function (p) {
                    p.hidden = p.getAttribute('data-kensho-panel') !== name;
                });
                tabs.forEach(function (t) {
                    t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
                });
            });
        });
    }

    // Live-update the <output> next to each importance <input type=range>.
    function setupImportanceOutputs(root) {
        root.querySelectorAll('[data-area-importance]').forEach(function (input) {
            var output = input.parentElement ? input.parentElement.querySelector('output') : null;
            if (!output) {
                return;
            }
            input.addEventListener('input', function () {
                output.textContent = input.value;
            });
        });
    }

    // ── Networking ───────────────────────────────────────────────────────────

    function post(url, data, csrf) {
        var body = new URLSearchParams();
        body.set('csrf_token', csrf);
        Object.keys(data).forEach(function (k) { body.set(k, data[k]); });
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) {
            if (!r.ok) {
                throw new Error('Request failed: ' + r.status);
            }
            return r.json();
        });
    }

    // Build + submit a real form (full-page redirect keeps the CSRF/flash flow
    // simple and matches the server-rendered forms already on the page).
    function submitForm(url, fields, csrf) {
        var form = document.createElement('form');
        form.method = 'post';
        form.action = url;
        var all = Object.assign({ csrf_token: csrf }, fields);
        Object.keys(all).forEach(function (k) {
            var i = document.createElement('input');
            i.type = 'hidden';
            i.name = k;
            i.value = all[k];
            form.appendChild(i);
        });
        document.body.appendChild(form);
        form.submit();
    }

    // ── Privacy-summary confirm modal (gates every AI call) ─────────────────

    function openPrivacy(root, summaryText, onConfirm) {
        var modal = root.querySelector('[data-kensho-privacy]');
        if (!modal) {
            return;
        }
        var detail = modal.querySelector('[data-kensho-privacy-detail]');
        if (detail) {
            detail.textContent = summaryText;
        }
        modal.hidden = false;

        var confirmBtn = modal.querySelector('[data-kensho-privacy-confirm]');
        var cancelBtn = modal.querySelector('[data-kensho-privacy-cancel]');
        if (!confirmBtn || !cancelBtn) {
            modal.hidden = true;
            return;
        }

        function cleanup() {
            modal.hidden = true;
            confirmBtn.removeEventListener('click', ok);
            cancelBtn.removeEventListener('click', no);
        }
        function ok() {
            var sel = modal.querySelector('[data-kensho-provider]');
            var provider = sel ? sel.value : '';
            cleanup();
            onConfirm(provider);
        }
        function no() {
            cleanup();
        }
        confirmBtn.addEventListener('click', ok);
        cancelBtn.addEventListener('click', no);
    }

    // ── Area-card editor (manual by default; AI appends editable cards) ─────

    // Clone the blank-card <template> into the cards list; returns the new card.
    function addAreaCard(root) {
        var editor = root.querySelector('[data-kensho-areas-editor]');
        var template = editor ? editor.querySelector('[data-kensho-area-card-template]') : null;
        var list = editor ? editor.querySelector('[data-kensho-area-cards]') : null;
        if (!template || !list) {
            return null;
        }
        var fragment = template.content.cloneNode(true);
        list.appendChild(fragment);
        return list.lastElementChild;
    }

    // Render each AI-proposed area as one of these editable cards. Nothing is
    // saved until the user hits "Save areas" — this is a scaffold, not a write.
    function renderProposedAreas(root, res) {
        if (!res || !Array.isArray(res.areas)) {
            return;
        }
        res.areas.forEach(function (a) {
            var card = addAreaCard(root);
            if (!card) {
                return;
            }
            var labelInput = card.querySelector('[data-area-card-label]');
            var importanceInput = card.querySelector('[data-area-card-importance]');
            var rationale = card.querySelector('[data-area-card-rationale]');

            if (labelInput) {
                labelInput.value = a.label || '';
            }
            if (importanceInput) {
                importanceInput.value = a.importance_hint ? String(a.importance_hint) : '5';
            }
            if (rationale) {
                rationale.textContent = a.rationale || '';
            }

            var refs = Array.isArray(a.dimension_refs) ? a.dimension_refs : [];
            refs.forEach(function (ref) {
                var box = card.querySelector(
                    '[data-area-card-dim][data-dim-type="' + cssEscape(ref.type) + '"][data-dim-id="' + cssEscape(String(ref.id)) + '"]'
                );
                if (box) {
                    box.checked = true;
                }
            });
        });
    }

    // Collect every card (manual + AI-derived) into the shape areas() expects:
    // {label, importance, sources:{project_ids, category_ids, tag_ids}}.
    function collectAreas(root) {
        var list = root.querySelector('[data-kensho-area-cards]');
        var areas = [];
        if (!list) {
            return areas;
        }
        list.querySelectorAll('[data-kensho-area-card]').forEach(function (card) {
            var labelInput = card.querySelector('[data-area-card-label]');
            var label = labelInput ? labelInput.value.trim() : '';
            if (!label) {
                return; // Skip blank cards.
            }
            var importanceInput = card.querySelector('[data-area-card-importance]');
            var importance = importanceInput ? parseInt(importanceInput.value, 10) : 5;
            if (!importance || isNaN(importance)) {
                importance = 5;
            }
            importance = Math.max(1, Math.min(10, importance));

            var sources = { project_ids: [], category_ids: [], tag_ids: [] };
            card.querySelectorAll('[data-area-card-dim]:checked').forEach(function (box) {
                var type = box.getAttribute('data-dim-type');
                var id = parseInt(box.getAttribute('data-dim-id'), 10);
                if (isNaN(id)) {
                    return;
                }
                if (type === 'project') {
                    sources.project_ids.push(id);
                } else if (type === 'category') {
                    sources.category_ids.push(id);
                } else if (type === 'tag') {
                    sources.tag_ids.push(id);
                }
            });

            areas.push({ label: label, importance: importance, sources: sources });
        });
        return areas;
    }

    function saveAreas(root, csrf, urls) {
        if (!urls.areas) {
            return;
        }
        var areas = collectAreas(root);
        submitForm(urls.areas, { areas: JSON.stringify(areas) }, csrf);
    }

    // ── Goals ────────────────────────────────────────────────────────────────

    function saveGoals(root, csrf, urls) {
        if (!urls.goals) {
            return;
        }
        var updates = [];
        root.querySelectorAll('[data-area-id]').forEach(function (fs) {
            var importanceInput = fs.querySelector('[data-area-importance]');
            var identityInput = fs.querySelector('[data-area-identity]');
            var goalsInput = fs.querySelector('[data-area-goals]');
            updates.push({
                id: fs.getAttribute('data-area-id'),
                importance: importanceInput ? parseInt(importanceInput.value, 10) : 5,
                identity: identityInput ? identityInput.value : '',
                goals: goalsInput
                    ? goalsInput.value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean)
                    : []
            });
        });
        submitForm(urls.goals, { goals: JSON.stringify(updates) }, csrf);
    }

    function applyClarified(fieldset, res) {
        if (!fieldset || !res) {
            return;
        }
        var identityInput = fieldset.querySelector('[data-area-identity]');
        var goalsInput = fieldset.querySelector('[data-area-goals]');
        var questionEl = fieldset.querySelector('[data-area-question]');
        if (identityInput && res.identity) {
            identityInput.value = res.identity;
        }
        if (goalsInput && Array.isArray(res.goals) && res.goals.length) {
            goalsInput.value = res.goals.join('\n');
        }
        if (questionEl && res.reflection_question) {
            questionEl.textContent = res.reflection_question;
        }
    }

    // ── Reflection ───────────────────────────────────────────────────────────

    function applyReflection(root, res, provider) {
        if (!res) {
            return;
        }
        var parts = [];
        (res.observations || []).forEach(function (o) { parts.push('• ' + o); });
        (res.questions || []).forEach(function (q) { parts.push('? ' + q); });
        if (res.gentle_note) {
            parts.push('');
            parts.push(res.gentle_note);
        }
        var ta = root.querySelector('[data-kensho-narrative]');
        if (ta) {
            ta.value = parts.join('\n');
        }
        var providerField = root.querySelector('[data-kensho-provider-profile]');
        if (providerField) {
            providerField.value = provider || '';
        }
    }

    // ── Action dispatch ──────────────────────────────────────────────────────

    function setupActions(root, csrf, urls) {
        root.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-kensho-action]');
            if (!btn) {
                return;
            }
            var action = btn.getAttribute('data-kensho-action');

            if (action === 'add-area-card') {
                addAreaCard(root);
            } else if (action === 'remove-area-card') {
                var card = btn.closest('[data-kensho-area-card]');
                if (card) {
                    card.remove();
                }
            } else if (action === 'save-areas') {
                saveAreas(root, csrf, urls);
            } else if (action === 'save-goals') {
                saveGoals(root, csrf, urls);
            } else if (action === 'propose') {
                openPrivacy(
                    root,
                    'Aggregated dimensions (project/category/tag): task counts, time spent, up to 5 sample task titles each. No task descriptions or comments.',
                    function (provider) {
                        post(urls.propose, { provider_profile: provider }, csrf).then(function (res) {
                            renderProposedAreas(root, res);
                        });
                    }
                );
            } else if (action === 'clarify') {
                var label = btn.getAttribute('data-area-label') || '';
                var fieldset = btn.closest('[data-area-id]');
                var noteInput = fieldset ? fieldset.querySelector('[data-area-identity]') : null;
                var note = noteInput ? noteInput.value : '';
                openPrivacy(
                    root,
                    'Area: ' + label + '\nYour current note: ' + (note || '(none yet)'),
                    function (provider) {
                        post(urls.clarify, { area_label: label, note: note, provider_profile: provider }, csrf).then(function (res) {
                            applyClarified(fieldset, res);
                        });
                    }
                );
            } else if (action === 'reflect') {
                openPrivacy(
                    root,
                    'Your computed discrepancies (stated importance share vs. actual effort share per area) and the desired-self statements you have written so far.',
                    function (provider) {
                        post(urls.reflect, { provider_profile: provider }, csrf).then(function (res) {
                            applyReflection(root, res, provider);
                        });
                    }
                );
            }
        });
    }

    // Minimal CSS.escape shim (attribute-selector safe for our own numeric/word values).
    function cssEscape(value) {
        return String(value).replace(/["\\]/g, '\\$&');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
