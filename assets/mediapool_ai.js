(function() {
    if (typeof window.jQuery === 'undefined') {
        return;
    }

    var $ = window.jQuery;

    var assetsBase = (document.currentScript && document.currentScript.src)
        ? document.currentScript.src.replace(/[^/?#]*(\?.*)?$/, '')
        : '/assets/addons/filepond_uploader/';
    var magicIconUrl = assetsBase + 'icons/magic.svg';
    var csrfToken = function () {
        return (window.rex && window.rex.filepond_csrf) || '';
    };
    var getMagicIcon = function(isSpinning) {
        var spinClass = isSpinning ? ' filepond-magic-icon--spin' : '';
        return '<img src="' + magicIconUrl + '" class="filepond-magic-icon' + spinClass + '" alt="" aria-hidden="true">';
    };

    var escapeAttr = function(value) {
        return String(value).replace(/[&<>"']/g, function(c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };
    var t = function(key, fallback) {
        var texts = (window.rex && window.rex.filepond_ai && window.rex.filepond_ai.i18n) || {};
        return texts[key] || fallback;
    };

    var getButtonContent = function(label, isSpinning) {
        return getMagicIcon(isSpinning) + '<span class="filepond-ai-btn-label">' + label + '</span>';
    };

    function normalizeLanguageCode(code) {
        if (typeof code !== 'string') {
            return '';
        }

        var normalized = code.trim().toLowerCase().slice(0, 2);
        return /^[a-z]{2}$/.test(normalized) ? normalized : '';
    }

    function resolveLanguageFromName(fieldName) {
        var match = fieldName.match(/_([a-z]{2})(?:_[a-z]{2})?\]$/i);
        if (match && match[1]) {
            return match[1].toLowerCase();
        }
        return 'de';
    }

    // regenerate: Feld(er) bereits befuellt -> bewusst neu erzeugen, Ergebnis-Cache umgehen.
    function generateForLanguage(fileName, langCode, regenerate) {
        return $.ajax({
            url: 'index.php',
            method: 'POST',
            cache: false,
            data: {
                'rex-api-call': 'filepond_ai_generate',
                '_csrf_token': csrfToken(),
                'media_name': fileName,
                'language': langCode,
                'regenerate': regenerate ? 1 : 0
            },
            dataType: 'json'
        });
    }

    function generateForLanguages(fileName, langCodes, regenerate) {
        return $.ajax({
            url: 'index.php',
            method: 'POST',
            traditional: true,
            data: {
                'rex-api-call': 'filepond_ai_generate',
                '_csrf_token': csrfToken(),
                'media_name': fileName,
                'languages[]': langCodes,
                'regenerate': regenerate ? 1 : 0
            },
            dataType: 'json'
        });
    }

    // Erster Klick fuellt nur leere Sprachfelder; sind alle befuellt, erzeugt ein
    // weiterer Klick alle Sprachen neu.
    function allInputsFilled($inputs) {
        return $inputs.length > 0 && $inputs.toArray().every(function(el) {
            return ($(el).val() || '').toString().trim() !== '';
        });
    }

    // Konfiguration (gleiche API wie Upload-Modal). mediaplaceOwnAltActive/Key
    // kommen zusaetzlich aus get_ai_target_field() (siehe auto_metainfo.php) --
    // ist MediaPlace's eigenes Alt-Feld aktiv, hat es Vorrang: der Button haengt
    // dann NUR dort, nicht zusaetzlich am klassischen med_alt/ai_target_field
    // (dieselbe Prioritaet wie AltTextStatus::isMissing() in MediaPlace selbst).
    var aiEnabled = false;
    var classicTargetField = 'med_alt';
    var mediaplaceOwnAltActive = false;
    var mediaplaceOwnAltKey = 'alt';
    var languagesMap = window.filepondAiLanguagesMap || {};
    var blockedLanguages = [];
    var fallbackLanguage = 'en';

    // ---- Klassisches med_alt/ai_target_field-Feld (mediapool/media-Formular,
    // auch innerhalb von MediaPlace's nativem Metainfo-Canvas, siehe unten) ----

    function addAiButton(inputField, langCode) {
        var $input = $(inputField);

        // Versteckte Inputs (z.B. von metainfo_lang_fields) überspringen
        if ($input.is('input[type="hidden"]') || $input.is(':hidden')) {
            return;
        }

        // Wenn das Feld innerhalb eines metainfo_lang_fields Containers liegt,
        // dort wird ein separater "alle Sprachen"-Button angehängt.
        if ($input.closest('.meta_lang_field_all, .meta_lang_field').length > 0) {
            return;
        }

        // Felder im FilePond-Metadaten-Dialog ueberspringen -- der bringt mit
        // "AI-Vorschlag" einen eigenen Button mit (filepond_widget.js), und
        // resolveClassicFileName() kennt dort ohnehin noch keine Datei.
        if ($input.closest('.simple-modal').length > 0) {
            return;
        }

        // Prüfen ob Button schon existiert. Das Flag am Input greift auch
        // ohne umgebendes .form-group -- sonst haengt jeder Scan des
        // MutationObservers einen weiteren Button an, und jeder neue Button
        // loest den naechsten Scan aus (Endlosschleife).
        if ($input.data('filepondAiAttached') || $input.closest('.form-group').find('.btn-ai-generate-mp').length > 0) {
            return;
        }
        $input.data('filepondAiAttached', true);

        var inputName = $input.attr('name') || '';
        var btnHtml = '<button class="btn btn-default btn-ai-generate-mp" type="button" title="' + escapeAttr(t('generate', 'AI alt text')) + '" aria-label="' + escapeAttr(t('generate', 'AI alt text')) + '" data-lang="' + escapeAttr(langCode) + '" data-target-name="' + escapeAttr(inputName) + '">' + getButtonContent('AI ALT', false) + '</button>';

        if ($input.is('textarea')) {
            var $wrap = $('<div class="filepond-ai-btn-wrap" style="margin-top:6px;"></div>');
            $wrap.append(btnHtml);
            $input.after($wrap);
            return;
        }

        if (!$input.parent().hasClass('input-group')) {
            $input.wrap('<div class="input-group"></div>');
        }

        $input.after('<span class="input-group-btn">' + btnHtml + '</span>');
    }

    function getInputsByExactName(name) {
        return $('input[type="text"], textarea').filter(function() {
            return ($(this).attr('name') || '') === name;
        });
    }

    // Liefert die Sprachinputs eines metainfo_lang_fields Containers
    // ($container = .meta_lang_field_all oder .meta_lang_field)
    function collectLangInputs($container) {
        if ($container.hasClass('meta_lang_field_all')) {
            var $allRows = $container.find('.meta_lang_field_row').find('.meta_lang_field_input');
            if ($allRows.length > 0) {
                return $allRows;
            }
        }

        // "Alle Sprachen"-Modus: .meta_lang_field_input mit data-clang-id
        var $all = $container.find('.meta_lang_field_input');
        if ($all.length > 0) {
            return $all;
        }
        // Repeater-Modus: Existierende Übersetzungen in .meta_lang_translation_item
        return $container.find('.meta_lang_translation_item').find('.meta_lang_textarea, .meta_lang_input');
    }

    function addAiButtonForLangContainer($container) {
        if ($container.data('filepondAiAttached')) {
            return;
        }
        $container.data('filepondAiAttached', true);

        $container.addClass('filepond-ai-multilang-container');

        // Falls zuvor ein Einzelbutton an Inputs angehängt wurde, im Gruppenmodus wieder entfernen.
        $container.find('.btn-ai-generate-mp').closest('.input-group-btn').remove();
        $container.find('.btn-ai-generate-mp').remove();

        // Defensiv: leere input-group-btn entfernen, die das Hauptfeld schmal drücken können.
        $container.find('.meta_lang_field_row_primary .input-group-btn').each(function() {
            var $btnWrap = $(this);
            if ($btnWrap.find('button').length === 0) {
                $btnWrap.remove();
            }
        });

        var btnHtml = '<button class="btn btn-default btn-ai-generate-mp-lang" type="button" title="' + escapeAttr(t('generateAll', 'AI alt texts')) + '" aria-label="' + escapeAttr(t('generateAll', 'AI alt texts')) + '">' + getButtonContent('AI ALT', false) + '</button>';
        var statusHtml = '<span class="filepond-ai-status"></span>';
        var $wrap = $('<div class="filepond-ai-btn-wrap" style="margin: 4px 0 8px 0;"></div>').append(btnHtml).append(statusHtml);

        var $label = $container.find('> label.meta_lang_main_label').first();
        if ($label.length > 0) {
            $label.after($wrap);
        } else {
            $container.prepend($wrap);
        }
    }

    function attachButtonsForLangContainers(targetField) {
        var selector = '.meta_lang_field_all[data-field-name="' + targetField + '"], .meta_lang_field[data-field-name="' + targetField + '"]';
        $(selector).each(function() {
            addAiButtonForLangContainer($(this));
        });
    }

    function attachButtonsForTarget(targetField) {
        var langContainerSelector = '.meta_lang_field_all[data-field-name="' + targetField + '"], .meta_lang_field[data-field-name="' + targetField + '"]';
        if ($(langContainerSelector).length > 0) {
            return;
        }

        var selector = [
            'input[name="' + targetField + '"]',
            'textarea[name="' + targetField + '"]',
            'input[name^="' + targetField + '_"]',
            'textarea[name^="' + targetField + '_"]',
            'input[name="rex_media[' + targetField + ']"]',
            'textarea[name="rex_media[' + targetField + ']"]',
            'input[name^="rex_media[' + targetField + '_"]',
            'textarea[name^="rex_media[' + targetField + '_"]'
        ].join(', ');
        var $targets = $(selector);
        if ($targets.length === 0) {
            return;
        }

        $targets.each(function() {
            var name = $(this).attr('name') || '';
            var lang = resolveLanguageFromName(name);
            addAiButton(this, lang);
        });
    }

    // Dateiname fuer den klassischen Formular-Kontext -- funktioniert sowohl
    // auf der eigenstaendigen mediapool/media-Seite als auch innerhalb von
    // MediaPlace's nativem Metainfo-Canvas (dort per data-canvas-file am
    // "Metadaten bearbeiten"-Button, siehe detail_panel.php).
    function resolveClassicFileName() {
        var urlParams = new URLSearchParams(window.location.search);
        var fileName = urlParams.get('file_name');
        if (!fileName) {
            fileName = $('input[name="file_name"]').val();
        }
        if (!fileName) {
            var $canvasOpenBtn = $('.mp-metainfo-canvas-open[data-canvas-file]').first();
            if ($canvasOpenBtn.length > 0) {
                fileName = $canvasOpenBtn.attr('data-canvas-file');
            }
        }
        if (!fileName) {
            var action = $('form').first().attr('action');
            if (action && action.indexOf('file_name=') !== -1) {
                var match = action.match(/file_name=([^&]+)/);
                if (match) fileName = decodeURIComponent(match[1]);
            }
        }
        if (!fileName) {
            var fileHref = $('.form-control-static a[href*="/media/"]').first().attr('href') || '';
            if (fileHref !== '') {
                var cleanHref = fileHref.split('?')[0];
                var parts = cleanHref.split('/');
                fileName = decodeURIComponent(parts[parts.length - 1] || '');
            }
        }
        return fileName || '';
    }

    function scanClassicField() {
        if (!aiEnabled || mediaplaceOwnAltActive) {
            return;
        }
        attachButtonsForTarget(classicTargetField);
        attachButtonsForLangContainers(classicTargetField);
    }

    // ---- MediaPlace's eigenes Alt-Feld (JSON-Metadaten, .mp-alt-wrap im
    // Detail-Panel, siehe fragments/mediaplace/detail_field_body_alt.php) ----
    // Nur relevant, wenn mediaplace_own_alt_active (siehe getAiTargetField()
    // serverseitig) -- das Detail-Panel wird von MediaPlace komplett per AJAX
    // nachgeladen (renderDetail()), ein einmaliger Seiten-Scan wuerde dieses
    // Feld also nie finden. Deshalb ueber denselben MutationObserver wie das
    // klassische Feld erkannt (siehe scheduleScan() unten).

    // MediaPlace's Dirty-State-/ALT-Hinweis-Logik haengt per PLAIN
    // addEventListener('input', ...) am Overlay-Root (core.js), nicht per
    // jQuery .on(). jQuery(...).trigger('input') simuliert Bubbling nur fuer
    // jQuery-gebundene Handler bzw. ruft ein natives elem.input()-Methode auf
    // (die es nicht gibt) -- ein reines addEventListener-Listener wie das von
    // MediaPlace wuerde davon NIE erreicht. Deshalb hier ein echtes,
    // bubbelndes DOM-Event auf dem rohen Element ausloesen.
    function dispatchNativeInput(inputEl) {
        inputEl.dispatchEvent(new Event('input', { bubbles: true }));
        inputEl.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function resolveOwnAltFileName($wrap) {
        var $panel = $wrap.closest('#mp-detail');
        var $withFilename = $panel.find('[data-filename]').first();
        return $withFilename.length > 0 ? ($withFilename.attr('data-filename') || '') : '';
    }

    function addOwnAltButton($wrap) {
        if ($wrap.data('filepondAiAttached')) {
            return;
        }
        $wrap.data('filepondAiAttached', true);

        var $langInputs = $wrap.find('.mp-lang-inputs');
        if ($langInputs.length === 0) {
            return;
        }

        var btnHtml = '<button class="btn btn-default btn-ai-generate-mp-own" type="button" title="' + escapeAttr(t('generate', 'AI alt text')) + '" aria-label="' + escapeAttr(t('generate', 'AI alt text')) + '">' + getButtonContent('AI ALT', false) + '</button>';
        var statusHtml = '<span class="filepond-ai-status"></span>';
        var $wrapEl = $('<div class="filepond-ai-btn-wrap" style="margin: 4px 0 8px 0;"></div>').append(btnHtml).append(statusHtml);
        $langInputs.before($wrapEl);
    }

    function scanOwnAltField() {
        if (!aiEnabled || !mediaplaceOwnAltActive) {
            return;
        }
        $('.mp-alt-wrap[data-alt-key="' + mediaplaceOwnAltKey + '"]').each(function() {
            addOwnAltButton($(this));
        });
    }

    // ---- Reaktives Nachladen: MediaPlace's Overlay/Detail-Panel/Metainfo-
    // Canvas werden per innerHTML-Zuweisung nachgeladen (kein rex:ready, kein
    // erneuter Seiten-Load) -- ein einmaliger Scan beim Seitenladen wuerde
    // beide Ziel-Felder in diesen Faellen nie finden. Ein einzelner, gedrosselter
    // MutationObserver auf document.body deckt alle Faelle ab (klassische Seite,
    // MediaPlace-Metainfo-Canvas, MediaPlace-eigenes Feld), ohne dass dieses
    // Skript wissen muss, WELCHER der drei Kontexte gerade aktiv ist. ----
    var scanScheduled = false;
    function scheduleScan() {
        if (scanScheduled) {
            return;
        }
        scanScheduled = true;
        window.setTimeout(function() {
            scanScheduled = false;
            scanClassicField();
            scanOwnAltField();
        }, 120);
    }

    function initObserver() {
        if (!window.MutationObserver) {
            return;
        }
        var observer = new MutationObserver(function() {
            scheduleScan();
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    // Konfiguration kommt per rex_view::setJsProperty() mit der Seite (rex.filepond_ai,
    // siehe boot.php) -- kein eigener Request pro Backend-Seite.
    function applyConfig(data) {
        if (!data || !data.enabled) {
            return;
        }

        aiEnabled = true;
        classicTargetField = typeof data.target_field === 'string' && data.target_field.trim() !== ''
            ? data.target_field.trim()
            : 'med_alt';
        mediaplaceOwnAltActive = !!data.mediaplace_own_alt_active;
        mediaplaceOwnAltKey = typeof data.mediaplace_own_alt_key === 'string' && data.mediaplace_own_alt_key.trim() !== ''
            ? data.mediaplace_own_alt_key.trim()
            : 'alt';

        if (data.languages && typeof data.languages === 'object') {
            languagesMap = data.languages;
            window.filepondAiLanguagesMap = languagesMap;
        }

        if (Array.isArray(data.blocked_languages)) {
            blockedLanguages = data.blocked_languages
                .filter(function(code) { return typeof code === 'string'; })
                .map(function(code) { return normalizeLanguageCode(code); })
                .filter(function(code) { return code !== ''; });
        }

        if (typeof data.fallback_language === 'string' && data.fallback_language.trim() !== '') {
            fallbackLanguage = normalizeLanguageCode(data.fallback_language) || 'en';
        }

        scanClassicField();
        scanOwnAltField();
        initObserver();
    }

    // Skript laeuft im <head> -- Scan/Observer brauchen document.body.
    $(function() {
        if (window.rex && rex.filepond_ai) {
            applyConfig(rex.filepond_ai);
        }
    });

    // ---- Click-Handler (nur einmal binden) ----
    if (!window.aiBtnHandlerBound) {
        $(document).on('click', '.btn-ai-generate-mp', function(e) {
            e.preventDefault();
            var btn = $(this);
            var targetName = btn.attr('data-target-name') || '';
            var input = targetName !== ''
                ? getInputsByExactName(targetName).first()
                : btn.closest('.form-group').find('input[type="text"], textarea').first();
            var lang = btn.data('lang');

            var fileName = resolveClassicFileName();
            if (!fileName) {
                alert(t('noFilename', 'Could not determine the file name.'));
                return;
            }

            // Loading State
            var originalIcon = btn.html();
            btn.prop('disabled', true).html(getButtonContent('AI ALT', true));

            generateForLanguage(fileName, lang, (input.val() || '').toString().trim() !== '')
                .done(function(data) {
                    if (data.success && data.alt_text) {
                        input.val(data.alt_text);
                        // Change Event triggern damit REDAXO merkt dass sich was geändert hat
                        input.trigger('change');
                    } else {
                        alert(t('error', 'Error') + ': ' + (data.error || t('unknown', 'Unknown error')));
                    }
                })
                .fail(function(xhr, status, error) {
                    console.error(xhr.responseText);
                    alert(t('error', 'Error') + ': ' + error);
                })
                .always(function() {
                    btn.prop('disabled', false).html(originalIcon);
                });
        });

        // Handler für metainfo_lang_fields Container (alle Sprachen generieren)
        $(document).on('click', '.btn-ai-generate-mp-lang', function(e) {
            e.preventDefault();
            var btn = $(this);
            var $container = btn.closest('.meta_lang_field_all, .meta_lang_field');
            if ($container.length === 0) {
                return;
            }

            var $inputs = collectLangInputs($container);
            if ($inputs.length === 0) {
                alert(t('noLanguageFields', 'No language fields found.'));
                return;
            }

            var fileName = resolveClassicFileName();
            if (!fileName) {
                alert(t('noFilename', 'Could not determine the file name.'));
                return;
            }

            var originalIcon = btn.html();
            var $statusNode = btn.closest('.filepond-ai-btn-wrap').find('.filepond-ai-status').first();
            if ($statusNode.length > 0) {
                $statusNode.text('');
            }
            btn.prop('disabled', true).html(getButtonContent('AI ALT', true));

            (async function() {
                try {
                    var groupedInputs = {};
                    var regenerate = allInputsFilled($inputs);

                    for (var i = 0; i < $inputs.length; i++) {
                        var $input = $($inputs[i]);
                        var currentVal = ($input.val() || '').toString().trim();
                        if (currentVal !== '' && !regenerate) {
                            continue;
                        }

                        var clangId = $input.data('clang-id') || $input.data('clangId') ||
                                      $input.closest('[data-clang-id]').data('clangId') ||
                                      $input.closest('[data-clang-id]').data('clang-id');

                        var langCode = '';
                        if (clangId && languagesMap[String(clangId)]) {
                            langCode = languagesMap[String(clangId)];
                        }

                        if (!langCode) {
                            var nameAttr = ($input.attr('name') || '').toString();
                            langCode = resolveLanguageFromName(nameAttr);
                        }

                        langCode = normalizeLanguageCode(langCode || 'de') || 'de';

                        if (!groupedInputs[langCode]) {
                            groupedInputs[langCode] = [];
                        }
                        groupedInputs[langCode].push($input);
                    }

                    var languageCodes = Object.keys(groupedInputs);
                    if (languageCodes.length === 0) {
                        return;
                    }

                    // Bei mehrsprachigen Feldern alle Zeilen zusammen anzeigen.
                    var $collapse = $container.find('.collapse').first();
                    if ($collapse.length > 0) {
                        if (typeof $collapse.collapse === 'function') {
                            $collapse.collapse('show');
                        } else {
                            $collapse.addClass('in');
                        }
                    }

                    var skippedCodes = languageCodes.filter(function(code) {
                        return blockedLanguages.indexOf(code) !== -1;
                    });

                    var batchData = null;
                    try {
                        batchData = await generateForLanguages(fileName, languageCodes, regenerate);
                    } catch (batchError) {
                        console.error('AI batch error', batchError);
                    }

                    for (var j = 0; j < languageCodes.length; j++) {
                        var code = languageCodes[j];
                        var suggestion = '';

                        if (batchData && batchData.success && batchData.alt_texts && typeof batchData.alt_texts[code] === 'string') {
                            suggestion = batchData.alt_texts[code].trim();
                        }

                        if (suggestion === '') {
                            try {
                                var fallbackData = await generateForLanguage(fileName, code, regenerate);
                                if (fallbackData && fallbackData.success && fallbackData.alt_text) {
                                    suggestion = String(fallbackData.alt_text).trim();
                                } else if (fallbackData && fallbackData.error) {
                                    console.error('AI error (' + code + '): ' + fallbackData.error);
                                }
                            } catch (fallbackError) {
                                console.error('AI error (' + code + ')', fallbackError);
                            }
                        }

                        if (suggestion !== '') {
                            groupedInputs[code].forEach(function($targetInput) {
                                $targetInput.val(suggestion);
                                $targetInput.trigger('input').trigger('change');
                            });
                        }
                    }

                    if ($statusNode.length > 0) {
                        if (skippedCodes.length > 0) {
                            $statusNode.text(t('skipped', 'Skipped: {0} (fallback: {1})').replace('{0}', skippedCodes.join(', ')).replace('{1}', fallbackLanguage));
                        } else {
                            $statusNode.text('');
                        }
                    }
                } finally {
                    btn.prop('disabled', false).html(originalIcon);
                }
            })();
        });

        // Handler für MediaPlace's eigenes Alt-Feld (alle konfigurierten Sprachen
        // auf einmal, gleiches "nur leere Felder befuellen"-Verhalten wie beim
        // klassischen Mehrsprachen-Button oben). WICHTIG: 'input' statt/zusaetzlich
        // zu 'change' triggern -- MediaPlace's eigene Event-Delegation
        // (updateAltHint()/updateDetailSaveState() in modules/detail.js) haengt
        // auf 'input', nicht auf REDAXOs klassisches 'change'.
        $(document).on('click', '.btn-ai-generate-mp-own', function(e) {
            e.preventDefault();
            var btn = $(this);
            var $wrap = btn.closest('.mp-alt-wrap');
            if ($wrap.length === 0) {
                return;
            }

            var $inputs = $wrap.find('.mp-lang-inputs [data-json-field="' + mediaplaceOwnAltKey + '"][data-clang]');
            if ($inputs.length === 0) {
                alert(t('noLanguageFields', 'No language fields found.'));
                return;
            }

            var fileName = resolveOwnAltFileName($wrap);
            if (!fileName) {
                alert(t('noFilename', 'Could not determine the file name.'));
                return;
            }

            var originalIcon = btn.html();
            var $statusNode = btn.closest('.filepond-ai-btn-wrap').find('.filepond-ai-status').first();
            if ($statusNode.length > 0) {
                $statusNode.text('');
            }
            btn.prop('disabled', true).html(getButtonContent('AI ALT', true));

            (async function() {
                try {
                    var groupedInputs = {};
                    var regenerate = allInputsFilled($inputs);

                    $inputs.each(function() {
                        var $input = $(this);
                        var currentVal = ($input.val() || '').toString().trim();
                        if (currentVal !== '' && !regenerate) {
                            return;
                        }

                        var clangId = $input.attr('data-clang');
                        var langCode = clangId && languagesMap[String(clangId)] ? languagesMap[String(clangId)] : 'de';
                        langCode = normalizeLanguageCode(langCode) || 'de';

                        if (!groupedInputs[langCode]) {
                            groupedInputs[langCode] = [];
                        }
                        groupedInputs[langCode].push($input);
                    });

                    var languageCodes = Object.keys(groupedInputs);
                    if (languageCodes.length === 0) {
                        return;
                    }

                    var skippedCodes = languageCodes.filter(function(code) {
                        return blockedLanguages.indexOf(code) !== -1;
                    });

                    var batchData = null;
                    try {
                        batchData = await generateForLanguages(fileName, languageCodes, regenerate);
                    } catch (batchError) {
                        console.error('AI batch error', batchError);
                    }

                    for (var j = 0; j < languageCodes.length; j++) {
                        var code = languageCodes[j];
                        var suggestion = '';

                        if (batchData && batchData.success && batchData.alt_texts && typeof batchData.alt_texts[code] === 'string') {
                            suggestion = batchData.alt_texts[code].trim();
                        }

                        if (suggestion === '') {
                            try {
                                var fallbackData = await generateForLanguage(fileName, code, regenerate);
                                if (fallbackData && fallbackData.success && fallbackData.alt_text) {
                                    suggestion = String(fallbackData.alt_text).trim();
                                } else if (fallbackData && fallbackData.error) {
                                    console.error('AI error (' + code + '): ' + fallbackData.error);
                                }
                            } catch (fallbackError) {
                                console.error('AI error (' + code + ')', fallbackError);
                            }
                        }

                        if (suggestion !== '') {
                            groupedInputs[code].forEach(function($targetInput) {
                                $targetInput.val(suggestion);
                                dispatchNativeInput($targetInput.get(0));
                            });
                        }
                    }

                    if ($statusNode.length > 0) {
                        if (skippedCodes.length > 0) {
                            $statusNode.text(t('skipped', 'Skipped: {0} (fallback: {1})').replace('{0}', skippedCodes.join(', ')).replace('{1}', fallbackLanguage));
                        } else {
                            $statusNode.text('');
                        }
                    }
                } finally {
                    btn.prop('disabled', false).html(originalIcon);
                }
            })();
        });

        window.aiBtnHandlerBound = true;
    }
})();
