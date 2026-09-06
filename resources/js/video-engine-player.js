/**
 * Filament Video Engine — Plyr + hls.js player.
 *
 * Quality and playback speed live inside Plyr's native settings menu (gear icon).
 */
(function (window, document) {
    'use strict';

    const HEIGHT_LABELS = {
        240: '240p',
        360: '360p',
        480: '480p',
        720: '720p',
        1080: '1080p',
        1440: '1440p',
        2160: '4k',
    };

    function parseJson(value, fallback) {
        if (!value) {
            return fallback;
        }

        try {
            return JSON.parse(value);
        } catch (error) {
            return fallback;
        }
    }

    function normalizeQualityLabel(raw) {
        if (!raw) {
            return null;
        }

        const value = String(raw).trim().toLowerCase();

        if (value === '2160p' || value === 'uhd') {
            return '4k';
        }

        return value;
    }

    function labelForHeight(height) {
        if (!height) {
            return null;
        }

        if (HEIGHT_LABELS[height]) {
            return HEIGHT_LABELS[height];
        }

        if (height >= 2160) {
            return '4k';
        }

        return height + 'p';
    }

    function heightFromLabel(label) {
        const normalized = normalizeQualityLabel(label);

        if (!normalized) {
            return null;
        }

        if (normalized === '4k') {
            return 2160;
        }

        const match = normalized.match(/^(\d+)p$/);

        return match ? parseInt(match[1], 10) : null;
    }

    function seedQualities(root) {
        const list = parseJson(root.dataset.qualities, []);

        return Array.isArray(list)
            ? list
                .map((item) => ({
                    label: normalizeQualityLabel(item.quality || item.label || item.name),
                    url: item.playlist_url || item.url || null,
                    height: item.height || heightFromLabel(item.quality || item.label || item.name) || null,
                    bandwidth: item.bandwidth || null,
                }))
                .filter((item) => item.label || item.height)
            : [];
    }

    function qualitiesFromHlsLevels(levels) {
        return (levels || []).map((level, index) => {
            const named = normalizeQualityLabel(level.name);
            const fromHeight = labelForHeight(level.height);

            return {
                label: named || fromHeight || ('L' + index),
                height: level.height || null,
                bandwidth: level.bitrate || null,
                levelIndex: index,
            };
        });
    }

    function findLevelIndexByHeight(levels, height) {
        return (levels || []).findIndex((level) => level.height === height);
    }

    function buildPlyrQualityConfig(qualities, autoLabel) {
        const labels = { 0: autoLabel };
        const urlByHeight = {};
        const heights = [];

        qualities.forEach((quality) => {
            const height = quality.height || heightFromLabel(quality.label);

            if (!height || heights.includes(height)) {
                return;
            }

            heights.push(height);
            labels[height] = quality.label || labelForHeight(height);

            if (quality.url) {
                urlByHeight[height] = quality.url;
            }
        });

        heights.sort((a, b) => b - a);

        return {
            options: [0, ...heights],
            labels,
            urlByHeight,
            defaultQuality: 0,
        };
    }

    function supportsNativeHls(video) {
        return !!video.canPlayType('application/vnd.apple.mpegurl');
    }

    function shouldUseHlsJs() {
        return !!(window.Hls && window.Hls.isSupported());
    }

    function loadScript(src) {
        return new Promise((resolve, reject) => {
            if (document.querySelector('script[src="' + src + '"]')) {
                resolve();
                return;
            }

            const script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.onload = () => resolve();
            script.onerror = () => reject(new Error('Failed to load ' + src));
            document.head.appendChild(script);
        });
    }

    function loadCss(href) {
        if (document.querySelector('link[href="' + href + '"]')) {
            return;
        }

        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        document.head.appendChild(link);
    }

    async function ensureDependencies(root) {
        const plyrCss = root.dataset.plyrCss || 'https://cdn.jsdelivr.net/npm/plyr@3.7.8/dist/plyr.css';
        const plyrJs = root.dataset.plyrJs || 'https://cdn.jsdelivr.net/npm/plyr@3.7.8/dist/plyr.polyfilled.min.js';
        const hlsJs = root.dataset.hlsJs || 'https://cdn.jsdelivr.net/npm/hls.js@1.5.17/dist/hls.min.js';

        loadCss(plyrCss);

        const tasks = [];

        if (!window.Hls) {
            tasks.push(loadScript(hlsJs));
        }

        if (!window.Plyr) {
            tasks.push(loadScript(plyrJs));
        }

        await Promise.all(tasks);
    }

    function createPlyr(video, root, qualityConfig, onQualityChange) {
        if (!window.Plyr) {
            return null;
        }

        const enablePip = root.dataset.enablePip === '1';
        const enableKeyboard = root.dataset.enableKeyboard === '1';
        const speeds = parseJson(root.dataset.speeds, [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2]);
        const qualityLabel = root.dataset.qualityLabel || 'Quality';
        const speedLabel = root.dataset.speedLabel || 'Speed';

        const controls = [
            'play-large',
            'play',
            'progress',
            'current-time',
            'duration',
            'mute',
            'volume',
            'settings',
            'fullscreen',
        ];

        if (enablePip) {
            controls.splice(controls.length - 1, 0, 'pip');
        }

        return new window.Plyr(video, {
            autoplay: false,
            invertTime: false,
            keyboard: { focused: enableKeyboard, global: false },
            tooltips: { controls: true, seek: true },
            controls: controls,
            settings: ['quality', 'speed'],
            quality: {
                default: qualityConfig.defaultQuality,
                options: qualityConfig.options,
                forced: true,
                onChange: onQualityChange,
            },
            speed: {
                selected: 1,
                options: speeds,
            },
            i18n: {
                quality: qualityLabel,
                speed: speedLabel,
                qualityLabel: qualityConfig.labels,
            },
        });
    }

    function switchNativeQuality(root, video, quality, urlByHeight) {
        const masterUrl = root.dataset.manifestUrl;
        let nextUrl = masterUrl;

        if (quality !== 0) {
            nextUrl = urlByHeight[quality] || masterUrl;
        }

        if (!nextUrl) {
            return;
        }

        const wasPaused = video.paused;
        const currentTime = video.currentTime || 0;

        video.src = nextUrl;
        video.addEventListener(
            'loadedmetadata',
            () => {
                try {
                    video.currentTime = currentTime;
                } catch (error) {
                    // ignore seek races
                }

                if (!wasPaused) {
                    video.play().catch(() => {});
                }
            },
            { once: true },
        );
        video.load();
    }

    const bootPromises = new WeakMap();

    function releaseVideoElement(video) {
        if (!video) {
            return;
        }

        video.pause();

        if (video.src && video.src.startsWith('blob:')) {
            try {
                URL.revokeObjectURL(video.src);
            } catch (error) {
                // ignore revoke races
            }
        }

        video.removeAttribute('src');
    }

    function destroyInstance(root) {
        const instance = root._ve;

        if (!instance) {
            return;
        }

        if (instance.buffering) {
            instance.buffering.destroy();
        }

        if (instance.hls) {
            instance.hls.destroy();
        }

        if (instance.player) {
            instance.player.destroy();
        }

        releaseVideoElement(instance.video);
        delete root._ve;
        delete root.dataset.veBooted;
    }

    function ensureBufferingOverlay(root) {
        const existing = root.querySelector('.fi-ve-player__buffering');

        if (existing) {
            return existing;
        }

        const label = root.dataset.bufferingLabel || 'Buffering…';
        const overlay = document.createElement('div');
        overlay.className = 'fi-ve-player__buffering';
        overlay.setAttribute('role', 'status');
        overlay.setAttribute('aria-live', 'polite');
        overlay.setAttribute('aria-hidden', 'true');
        overlay.innerHTML =
            '<div class="fi-ve-player__buffering-inner">' +
            '<div class="fi-ve-player__buffering-spinner" aria-hidden="true"></div>' +
            '<span class="fi-ve-player__buffering-label"></span>' +
            '</div>';

        overlay.querySelector('.fi-ve-player__buffering-label').textContent = label;

        const mountTarget = root.querySelector('.plyr__video-wrapper') || root;
        mountTarget.appendChild(overlay);

        return overlay;
    }

    /**
     * YouTube-style buffering UI: spinner while stalled, progress naturally pauses with HTML5 video.
     */
    function attachBufferingController(root, video, hls) {
        const overlay = ensureBufferingOverlay(root);
        const showDelayMs = 280;
        const hideDelayMs = 120;
        let showTimer = null;
        let hideTimer = null;
        let seeking = false;
        let hlsLoading = false;
        let destroyed = false;

        const setVisible = (visible) => {
            if (destroyed) {
                return;
            }

            root.classList.toggle('is-buffering', visible);
            overlay.setAttribute('aria-hidden', visible ? 'false' : 'true');
        };

        const clearTimers = () => {
            if (showTimer) {
                clearTimeout(showTimer);
                showTimer = null;
            }

            if (hideTimer) {
                clearTimeout(hideTimer);
                hideTimer = null;
            }
        };

        const shouldTrackBuffering = () => {
            if (video.paused || video.ended) {
                return false;
            }

            return seeking || hlsLoading || video.readyState < HTMLMediaElement.HAVE_FUTURE_DATA;
        };

        const scheduleShow = () => {
            if (!shouldTrackBuffering()) {
                return;
            }

            clearTimers();

            showTimer = window.setTimeout(() => {
                if (shouldTrackBuffering()) {
                    setVisible(true);
                }
            }, showDelayMs);
        };

        const scheduleHide = () => {
            clearTimers();

            hideTimer = window.setTimeout(() => {
                if (!shouldTrackBuffering() && video.readyState >= HTMLMediaElement.HAVE_FUTURE_DATA) {
                    setVisible(false);
                }
            }, hideDelayMs);
        };

        const onWaiting = () => scheduleShow();
        const onStalled = () => scheduleShow();
        const onPlaying = () => scheduleHide();
        const onCanPlay = () => scheduleHide();
        const onCanPlayThrough = () => scheduleHide();
        const onPause = () => {
            seeking = false;
            hlsLoading = false;
            clearTimers();
            setVisible(false);
        };
        const onEnded = () => onPause();
        const onSeeking = () => {
            seeking = true;
            scheduleShow();
        };
        const onSeeked = () => {
            seeking = false;

            if (shouldTrackBuffering()) {
                scheduleShow();
                return;
            }

            scheduleHide();
        };

        video.addEventListener('waiting', onWaiting);
        video.addEventListener('stalled', onStalled);
        video.addEventListener('playing', onPlaying);
        video.addEventListener('canplay', onCanPlay);
        video.addEventListener('canplaythrough', onCanPlayThrough);
        video.addEventListener('pause', onPause);
        video.addEventListener('ended', onEnded);
        video.addEventListener('seeking', onSeeking);
        video.addEventListener('seeked', onSeeked);

        const hlsHandlers = [];

        if (hls && window.Hls) {
            const onFragLoading = () => {
                if (video.paused || video.ended) {
                    return;
                }

                hlsLoading = true;
                scheduleShow();
            };

            const onFragBuffered = () => {
                hlsLoading = false;

                if (shouldTrackBuffering()) {
                    scheduleShow();
                    return;
                }

                scheduleHide();
            };

            hls.on(window.Hls.Events.FRAG_LOADING, onFragLoading);
            hls.on(window.Hls.Events.FRAG_BUFFERED, onFragBuffered);
            hlsHandlers.push([window.Hls.Events.FRAG_LOADING, onFragLoading]);
            hlsHandlers.push([window.Hls.Events.FRAG_BUFFERED, onFragBuffered]);
        }

        return {
            destroy() {
                destroyed = true;
                clearTimers();
                setVisible(false);

                video.removeEventListener('waiting', onWaiting);
                video.removeEventListener('stalled', onStalled);
                video.removeEventListener('playing', onPlaying);
                video.removeEventListener('canplay', onCanPlay);
                video.removeEventListener('canplaythrough', onCanPlayThrough);
                video.removeEventListener('pause', onPause);
                video.removeEventListener('ended', onEnded);
                video.removeEventListener('seeking', onSeeking);
                video.removeEventListener('seeked', onSeeked);

                if (hls) {
                    hlsHandlers.forEach(([event, handler]) => {
                        hls.off(event, handler);
                    });
                }
            },
        };
    }

    const FilamentVideoEnginePlayer = {
        async boot(root) {
            if (!root) {
                return;
            }

            const video = root.querySelector('video');
            const manifestUrl = root.dataset.manifestUrl;

            if (!video || !manifestUrl) {
                return;
            }

            if (bootPromises.has(root)) {
                return bootPromises.get(root);
            }

            if (root.dataset.veBooted === '1') {
                return;
            }

            const bootPromise = (async () => {
                root.dataset.veBooted = '1';
                root.classList.add('is-loading');

                try {
                    await ensureDependencies(root);
                } catch (error) {
                    root.classList.add('is-error');
                    root.classList.remove('is-loading');
                    delete root.dataset.veBooted;
                    console.error('[FilamentVideoEnginePlayer]', error);
                    return;
                }

                const seeded = seedQualities(root);
                const autoLabel = root.dataset.autoLabel || 'Auto';
                const isPreview = root.dataset.veMode === 'preview';
                let hls = null;
                let player = null;
                let buffering = null;

                const mountPlayer = (qualities, onQualityChange) => {
                    const qualityConfig = buildPlyrQualityConfig(qualities, autoLabel);

                    if (player) {
                        return qualityConfig;
                    }

                    player = createPlyr(video, root, qualityConfig, onQualityChange);

                    if (!buffering) {
                        buffering = attachBufferingController(root, video, hls);
                    }

                    return qualityConfig;
                };

                if (shouldUseHlsJs()) {
                    hls = new window.Hls({
                        enableWorker: true,
                        // Preview: start on the lowest rung, then ABR can climb with player size.
                        startLevel: isPreview ? 0 : -1,
                        capLevelToPlayerSize: true,
                        // Do not download segments until the admin hits play.
                        autoStartLoad: !isPreview,
                        maxBufferLength: isPreview ? 8 : 30,
                        maxMaxBufferLength: isPreview ? 20 : 120,
                        maxBufferSize: isPreview ? 20 * 1000 * 1000 : 60 * 1000 * 1000,
                        maxBufferHole: 0.5,
                        abrEwmaDefaultEstimate: isPreview ? 300000 : 500000,
                    });

                    hls.loadSource(manifestUrl);
                    hls.attachMedia(video);

                    if (isPreview) {
                        const startSegments = () => {
                            if (hls) {
                                hls.startLoad(-1);
                            }
                        };
                        const stopSegments = () => {
                            if (hls) {
                                hls.stopLoad();
                            }
                        };

                        video.addEventListener('play', startSegments);
                        video.addEventListener('playing', startSegments);
                        video.addEventListener('pause', stopSegments);
                        video.addEventListener('ended', stopSegments);
                    }

                    hls.on(window.Hls.Events.MANIFEST_PARSED, () => {
                        const fromLevels = qualitiesFromHlsLevels(hls.levels);
                        const merged = fromLevels.length ? fromLevels : seeded;

                        mountPlayer(merged, (quality) => {
                            if (quality === 0) {
                                hls.currentLevel = -1;
                                hls.loadLevel = -1;
                                return;
                            }

                            const index = findLevelIndexByHeight(hls.levels, quality);

                            if (index >= 0) {
                                hls.currentLevel = index;
                            }
                        });

                        root.classList.add('is-ready');
                        root.classList.remove('is-loading');
                    });

                    hls.on(window.Hls.Events.LEVEL_SWITCHED, (_event, data) => {
                        if (!player || !hls) {
                            return;
                        }

                        const level = hls.levels[data.level];

                        if (!level || hls.currentLevel === -1) {
                            player.quality = 0;
                            return;
                        }

                        if (level.height) {
                            player.quality = level.height;
                        }
                    });

                    hls.on(window.Hls.Events.ERROR, (_event, data) => {
                        if (data && data.fatal) {
                            root.classList.add('is-error');
                        }
                    });
                } else if (supportsNativeHls(video)) {
                    const attachNativeSource = () => {
                        if (!video.getAttribute('src')) {
                            video.src = manifestUrl;
                        }
                    };

                    if (isPreview) {
                        const startNative = () => attachNativeSource();
                        video.addEventListener('play', startNative, { once: true });
                    } else {
                        attachNativeSource();
                    }

                    const onReady = () => {
                        const qualityConfig = mountPlayer(seeded, (quality) => {
                            switchNativeQuality(root, video, quality, qualityConfig.urlByHeight);
                        });

                        root.classList.add('is-ready');
                        root.classList.remove('is-loading');
                    };

                    // Preview: mount controls immediately from poster; metadata arrives after play.
                    if (isPreview) {
                        onReady();
                    } else if (video.readyState >= 1) {
                        onReady();
                    } else {
                        video.addEventListener('loadedmetadata', onReady, { once: true });
                    }

                    if (!buffering) {
                        buffering = attachBufferingController(root, video, null);
                    }
                } else {
                    root.classList.add('is-error');
                    root.classList.remove('is-loading');
                    delete root.dataset.veBooted;
                    console.error('[FilamentVideoEnginePlayer] HLS is not supported in this browser.');
                }

                root._ve = {
                    player,
                    hls,
                    video,
                    buffering,
                    destroy() {
                        destroyInstance(root);
                    },
                };
            })();

            bootPromises.set(root, bootPromise);

            try {
                await bootPromise;
            } finally {
                bootPromises.delete(root);
            }
        },

        bootAll() {
            document.querySelectorAll('[data-ve-player]').forEach((el) => {
                this.boot(el);
            });
        },
    };

    window.FilamentVideoEnginePlayer = FilamentVideoEnginePlayer;

    const start = () => FilamentVideoEnginePlayer.bootAll();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }

    document.addEventListener('livewire:navigated', start);
    document.addEventListener('livewire:init', () => {
        if (window.Livewire && window.Livewire.hook) {
            window.Livewire.hook('morph.updated', () => {
                FilamentVideoEnginePlayer.bootAll();
            });
        }
    });
})(window, document);
