{{--
    Behaviour of the phone bottom bar (App\Support\AdminBottomNav), drawn on every
    admin page (SCRIPTS_AFTER) because the bar's markup is swapped in and out by
    wire:navigate while this has to be there first. It runs twice over the life
    of a page, and both halves are idempotent:

    1. In the pop-up. The pop-up is an iframe named like FRAME_NAME that shows a
       resource's own Create page. Inside it the page is marked `zz-embedded`
       (theme.css hides the menu, header and breadcrumbs), a saved form — the
       server answers with a redirect — is reported to the opener instead of
       loading the next page, and Cancel closes the pop-up.
    2. In the page that opened it: `zzQuickCreate()` is the Alpine component of
       resources/views/filament/admin/bottom-nav.blade.php.

    Messages only travel between two pages of this same site (origin checked on
    both ends).
--}}
<script>
    (() => {
        const FRAME = @js(\App\Support\AdminBottomNav::FRAME_NAME);

        if (window.name === FRAME) {
            const mark = () => document.documentElement.classList.add('zz-embedded');
            const tell = (event, extra = {}) => window.parent.postMessage({ type: FRAME, event, ...extra }, window.location.origin);

            mark();
            document.addEventListener('livewire:navigated', mark);

            if (! window.__zzQuickCreateFrame) {
                window.__zzQuickCreateFrame = true;

                // Cancel is `history.back()` or `Livewire.navigate(list)`: in a pop-up both would leave the form
                // (and `history.back()` would even move the opener's own history), so they close the pop-up.
                window.history.back = () => tell('close');
                document.addEventListener('livewire:navigate', (event) => {
                    event.preventDefault();
                    tell('close');
                });

                const watchRedirects = () => window.Livewire.interceptRequest(({ onRedirect }) => {
                    onRedirect(({ url, preventDefault }) => {
                        // A saved form is answered with a redirect to the next page. Do not load it
                        // here; the opener closes the pop-up and decides what to show.
                        preventDefault();
                        tell('saved', { url });
                    });
                });

                if (window.Livewire) {
                    watchRedirects();
                } else {
                    document.addEventListener('livewire:init', watchRedirects, { once: true });
                }
            }

            return;
        }

        window.zzQuickCreate = (config) => ({
            sheet: null,
            open: false,
            loading: false,
            frame: null,
            listener: null,

            init() {
                this.listener = (event) => this.receive(event);
                window.addEventListener('message', this.listener);
            },

            destroy() {
                window.removeEventListener('message', this.listener);
            },

            toggle(sheet) {
                this.sheet = this.sheet === sheet ? null : sheet;
            },

            choose(entry) {
                this.sheet = null;
                this.loading = true;
                this.frame = { ...entry, id: Date.now() };
                this.open = true;
            },

            // Removing the iframe stops whatever it was still loading; the form inside is dropped.
            close() {
                this.open = false;
                this.loading = false;
                this.frame = null;
            },

            receive(event) {
                if (event.origin !== window.location.origin || ! this.frame || event.data?.type !== config.frameName) {
                    return;
                }

                const popup = this.$root.querySelector('iframe');

                if (! popup || event.source !== popup.contentWindow) {
                    return;
                }

                if (event.data.event === 'close') {
                    this.close();
                } else if (event.data.event === 'saved') {
                    this.saved();
                }
            },

            saved() {
                this.close();

                // The save left its "Created" notice in the session (a redirect response does not hand it out):
                // ask this page to show it now.
                window.Livewire?.dispatch('notificationsSent');

                // On a page that lists these records (the Dashboard and the list pages in `refreshPaths`) bring the
                // new record into view by refreshing the page's components. Not a navigation: the app updater blocks
                // every navigation while an upgrade is pending, and a refresh keeps the scroll position and filters.
                const here = window.location.pathname.replace(/\/+$/, '');

                if (config.refreshPaths.includes(here)) {
                    window.Livewire?.all().forEach((component) => component.$wire.$refresh());
                }
            },
        });
    })();
</script>
