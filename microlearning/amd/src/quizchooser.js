/** Choose how to create a native Moodle Quiz, preserving the original destination. */
define(['core/modal', 'core/modal_events', 'core/templates', 'core/str', 'core/config'],
function(Modal, ModalEvents, Templates, Str, Config) {
    return {
        open: async function(originalUrl, activityModal) {
            const original = new URL(originalUrl, window.location.href);
            const courseid = Number(original.searchParams.get('id'));
            if (!Number.isInteger(courseid) || courseid < 1) {
                window.location.href = originalUrl;
                return;
            }
            const modes = ['manual', 'audio', 'record', 'pdf'];
            const ids = ['quizchoose', 'quizchoosehelp', 'quizmanual', 'quizmanualhelp',
                'quizdocument', 'quizdocumenthelp', 'quizrecord', 'quizrecordhelp'];
            const strings = await Str.get_strings([
                ...ids.map(key => ({key, component: 'local_microlearning'})),
                {key: 'quizpdf', component: 'local_pdfquiz'},
                {key: 'quizpdfhelp', component: 'local_pdfquiz'},
            ]);
            const choices = modes.map((mode, index) => {
                const path = mode === 'pdf' ? '/local/pdfquiz/quiz.php' : '/local/microlearning/quiz.php';
                const url = new URL(Config.wwwroot + path);
                url.searchParams.set('courseid', courseid);
                if (original.searchParams.has('sectionid')) {
                    url.searchParams.set('sectionid', original.searchParams.get('sectionid'));
                } else {
                    url.searchParams.set('section', original.searchParams.get('section') || '0');
                }
                url.searchParams.set('beforemod', original.searchParams.get('beforemod') || '0');
                if (mode === 'audio' || mode === 'record') {
                    url.searchParams.set('kind', mode);
                }
                return {
                    label: strings[2 + index * 2],
                    description: strings[3 + index * 2],
                    url: mode === 'manual' ? original.href : url.href,
                };
            });
            const body = await Templates.render('local_microlearning/quizchooser', {help: strings[1], choices});
            const modal = await Modal.create({title: strings[0], body, large: true, removeOnClose: true});
            // Let the original activity chooser close completely, so there is only one modal/focus trap.
            activityModal.getRoot().one(ModalEvents.hidden, () => modal.show());
            activityModal.hide();
        }
    };
});
